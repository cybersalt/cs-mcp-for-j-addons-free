<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;

class PlgSystemCsmcpforjgantry5InstallerScript implements InstallerScriptInterface
{
	public function install(InstallerAdapter $adapter): bool   { return true; }
	public function update(InstallerAdapter $adapter): bool    { return true; }
	public function uninstall(InstallerAdapter $adapter): bool { return true; }
	public function preflight(string $type, InstallerAdapter $adapter): bool { return true; }

	public function postflight(string $type, InstallerAdapter $adapter): bool
	{
		if (!\in_array($type, ['install', 'update', 'discover_install'], true)) {
			return true;
		}

		// Without CS MCP for J there is nothing to add tools TO. Joomla installs
		// plugins disabled; leave it that way and explain.
		if (!$this->coreInstalled()) {
			Factory::getApplication()->enqueueMessage(
				'MCP add-on for Gantry 5 was installed but has been left DISABLED, because the CS MCP for J extension is not on '
				. 'this site. This add-on only adds tools to MCP for J - on its own it does nothing. Install CS MCP for J first, '
				. 'then enable this plugin in System -> Plugins. Nothing is broken and the site is unaffected.',
				'warning'
			);

			$this->clearCaches();

			return true;
		}

		$app = Factory::getApplication();

		try {
			$db    = Factory::getContainer()->get(DatabaseInterface::class);
			$query = $db->getQuery(true)
				->update($db->quoteName('#__extensions'))
				->set($db->quoteName('enabled') . ' = 1')
				->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
				->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
				->where($db->quoteName('element') . ' = ' . $db->quote('csmcpforjgantry5'));
			$db->setQuery($query)->execute();
		} catch (\Throwable $e) {
			$app->enqueueMessage('csmcpforjgantry5 auto-enable failed: ' . $e->getMessage(), 'warning');
		}

		$app->enqueueMessage(
			'MCP add-on for Gantry 5 installed and active. New tools are exposed to your connected MCP clients: Gantry themes, '
			. 'outlines and their page assignments, layouts, particle settings per outline, module positions per outline, Styles, '
			. 'Gantry 5 Particle modules, backups and a health check. There is no separate admin UI for this plugin by design.',
			'message'
		);

		if (!is_dir(JPATH_LIBRARIES . '/gantry5')) {
			$app->enqueueMessage(
				'Gantry 5 is not installed on this site yet, so these tools will report "not installed" until it is. Gantry 5 is '
				. 'free from github.com/gantry/gantry5/releases; on Joomla 6 use 5.6.2 or later.',
				'notice'
			);
		} else {
			$app->enqueueMessage(
				'Gantry 5 detected. FIRST THING TO RUN: check_gantry5_health. It checks Joomla 6 support, writable folders and '
				. 'outline problems, including styles Gantry\'s own admin is about to overwrite.',
				'notice'
			);
		}

		// A newly installed add-on's classes are invisible to a cached autoloader and a warm opcache.
		$this->clearCaches();

		return true;
	}

	/** Fails OPEN on a database error; the runtime guard in getSubscribedEvents() covers a wrong guess. */
	private function coreInstalled(): bool
	{
		try {
			$db = Factory::getContainer()->get(DatabaseInterface::class);

			$query = $db->getQuery(true)
				->select('COUNT(*)')
				->from($db->quoteName('#__extensions'))
				->where($db->quoteName('type') . ' = ' . $db->quote('component'))
				->where($db->quoteName('element') . ' = ' . $db->quote('com_csmcpforj'));

			return (int) $db->setQuery($query)->loadResult() > 0;
		} catch (\Throwable $e) {
			return true;
		}
	}

	/** Best effort: the PSR-4 autoload map, opcache and Joomla's cache groups. Never fails an install. */
	private function clearCaches(): void
	{
		foreach ([JPATH_SITE . '/cache', JPATH_ADMINISTRATOR . '/cache'] as $cachePath) {
			$map = $cachePath . '/autoload_psr4.php';

			if (is_file($map)) {
				@unlink($map);
			}
		}

		if (function_exists('opcache_reset')) {
			@opcache_reset();
		}

		foreach ([JPATH_SITE . '/cache', JPATH_ADMINISTRATOR . '/cache'] as $cachePath) {
			if (!is_dir($cachePath)) {
				continue;
			}

			foreach ((array) glob($cachePath . '/*', GLOB_ONLYDIR) as $dir) {
				// Gantry's own cache folder is not a Joomla cache group.
				if (basename((string) $dir) === 'gantry5') {
					continue;
				}

				try {
					$cache = Factory::getCache(basename($dir), '');
					$cache->setCaching(true);
					$cache->clean();
				} catch (\Throwable $e) {
					// Best effort.
				}
			}
		}
	}
}
