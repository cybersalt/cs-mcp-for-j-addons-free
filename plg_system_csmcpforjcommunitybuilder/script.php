<?php
/**
 * @package     MCP for Community Builder
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;

class PlgSystemCsmcpforjcommunitybuilderInstallerScript implements InstallerScriptInterface
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

		// MCP for Joomla is what gives this add-on somewhere to register its
		// tools. Without it there is nothing to add on TO, so do not switch the
		// plugin on — Joomla installs plugins disabled, and we simply leave it
		// that way and explain. See coreInstalled().
		if (!$this->coreInstalled()) {
			Factory::getApplication()->enqueueMessage(
				'MCP add-on for Community Builder was installed but has been left DISABLED, because the CS MCP '
				. 'for J extension is not on this site. This add-on only adds tools to MCP for J - '
				. 'on its own it does nothing. Install CS MCP for J first, then enable this plugin in System '
				. '-> Plugins. Nothing is broken and the site is unaffected.',
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
				->where($db->quoteName('element') . ' = ' . $db->quote('csmcpforjcommunitybuilder'));
			$db->setQuery($query)->execute();
		} catch (\Throwable $e) {
			$app->enqueueMessage('csmcpforjcommunitybuilder auto-enable failed: ' . $e->getMessage(), 'warning');
		}

		$app->enqueueMessage(
			'MCP add-on for Community Builder installed and active. New tools are exposed to your connected '
			. 'MCP clients: members and their CB profile fields, registration moderation, custom fields and '
			. 'tabs, connections, reports, lists, plugins, configuration and diagnostics. There is no separate '
			. 'admin UI for this plugin by design - the tools appear in your MCP client automatically.',
			'message'
		);

		if (!is_dir(JPATH_ADMINISTRATOR . '/components/com_comprofiler')) {
			$app->enqueueMessage(
				'Community Builder (com_comprofiler) is not installed on this site yet, so these tools will '
				. 'report "not installed" until it is. Community Builder is free from joomlapolis.com.',
				'notice'
			);

			$this->clearCaches();

			return true;
		}

		$app->enqueueMessage(
			'Community Builder ' . ($this->detectVersion() ?? '(version undetermined)') . ' detected. '
			. 'FIRST THINGS TO RUN: get_cb_component_info, then check_cb_health and '
			. 'get_cb_registration_config. The registration audit matters most: it catches the configurations '
			. 'in which Community Builder sends sign-up emails with an empty confirmation link, or none at all.',
			'notice'
		);

		// A newly installed add-on's classes are invisible to a cached
		// autoloader and a warm opcache. See clearCaches().
		$this->clearCaches();

		return true;
	}

	/**
	 * Is the MCP for Joomla component present on this site?
	 *
	 * Fails OPEN on a database error: if we cannot tell, assume the component is
	 * there and let the install proceed normally. The runtime guard in the
	 * plugin's getSubscribedEvents() still covers the case where we guessed wrong.
	 */
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

	/**
	 * Drop the caches that can hide a freshly installed add-on: Joomla's PSR-4
	 * autoload map (a plugin installed after it was written is not in it, and the
	 * first request that dispatches to the new classes fatals), opcache, and
	 * Joomla's cache groups. All best-effort — a cache that cannot be cleared
	 * must never fail an install that otherwise succeeded.
	 */
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
				try {
					$cache = Factory::getCache(basename($dir), '');
					$cache->setCaching(true);
					$cache->clean();
				} catch (\Throwable $e) {
					// Best effort - keep going through the other groups.
				}
			}
		}
	}

	/** Read from the package's manifest cache; including CB's libraries would boot CB into the installer request. */
	private function detectVersion(): ?string
	{
		try {
			$db = Factory::getContainer()->get(DatabaseInterface::class);

			foreach (['pkg_communitybuilder', 'com_comprofiler'] as $element) {
				$cache = (string) ($db->setQuery(
					$db->getQuery(true)
						->select($db->quoteName('manifest_cache'))
						->from($db->quoteName('#__extensions'))
						->where($db->quoteName('element') . ' = ' . $db->quote($element))
				)->loadResult() ?? '');

				$decoded = json_decode($cache, true);

				if (\is_array($decoded) && !empty($decoded['version'])) {
					return (string) $decoded['version'];
				}
			}
		} catch (\Throwable $e) {
			// Fall through.
		}

		return null;
	}
}
