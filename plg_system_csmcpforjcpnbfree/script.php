<?php

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;

class PlgSystemCsmcpforjcpnbfreeInstallerScript implements InstallerScriptInterface
{
	private const LABEL = 'MCP add-on for Cookies Policy Notification Bar';

	public function install(InstallerAdapter $adapter): bool   { return true; }
	public function update(InstallerAdapter $adapter): bool    { return true; }
	public function uninstall(InstallerAdapter $adapter): bool { return true; }
	public function preflight(string $type, InstallerAdapter $adapter): bool { return true; }

	public function postflight(string $type, InstallerAdapter $adapter): bool
	{
		if (!\in_array($type, ['install', 'update', 'discover_install'], true)) {
			return true;
		}

		$app = Factory::getApplication();

		// Without MCP for Joomla there is nothing to add tools TO, so leave the
		// plugin disabled (Joomla installs plugins disabled) and explain.
		if (!$this->extensionExists('component', 'com_csmcpforj')) {
			$app->enqueueMessage(
				self::LABEL . ' was installed but has been left DISABLED, because the MCP for Joomla extension is '
				. 'not on this site. This add-on only adds tools to MCP for Joomla - on its own it does nothing. '
				. 'Install MCP for Joomla first, then enable this plugin in System -> Plugins. Nothing is broken '
				. 'and the site is unaffected.',
				'warning'
			);

			$this->clearCaches();

			return true;
		}

		try {
			$db = Factory::getContainer()->get(DatabaseInterface::class);
			$db->setQuery(
				$db->getQuery(true)
					->update($db->quoteName('#__extensions'))
					->set($db->quoteName('enabled') . ' = 1')
					->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
					->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
					->where($db->quoteName('element') . ' = ' . $db->quote('csmcpforjcpnbfree'))
			)->execute();
		} catch (\Throwable $e) {
			$app->enqueueMessage('csmcpforjcpnbfree auto-enable failed: ' . $e->getMessage(), 'warning');
		}

		$app->enqueueMessage(
			self::LABEL . ' installed and active. New tools are exposed to your connected MCP clients: the '
			. 'notification bar\'s position, behaviour, colours and buttons, its texts in your default language, '
			. 'cookie categories, the cookie declarations shown in the Cookies Manager, and diagnostics including a '
			. 'rendered-page check. There is no separate admin UI for this plugin by design.',
			'message'
		);

		if (!$this->extensionExists('plugin', 'cookiespolicynotificationbar', 'system')) {
			$app->enqueueMessage(
				'Cookies Policy Notification Bar (Web357) is not installed on this site yet, so these tools will '
				. 'report "not installed" until it is. CPNB 5.x is required.',
				'notice'
			);
		} else {
			$app->enqueueMessage(
				'Cookies Policy Notification Bar detected. FIRST THINGS TO RUN: get_cpnb_component_info, then '
				. 'check_cpnb_health and check_cpnb_frontend. Cookie blocking, consent logs, GeoIP targeting, '
				. 'texts in additional languages and settings import/export are CPNB PRO capabilities; their '
				. 'tools are in the separate Pro add-on and need CPNB PRO itself.',
				'notice'
			);
		}

		$this->clearCaches();

		return true;
	}

	/** Fails OPEN on a database error, so an install never fails on the check itself. */
	private function extensionExists(string $type, string $element, string $folder = ''): bool
	{
		try {
			$db    = Factory::getContainer()->get(DatabaseInterface::class);
			$query = $db->getQuery(true)
				->select('COUNT(*)')
				->from($db->quoteName('#__extensions'))
				->where($db->quoteName('type') . ' = ' . $db->quote($type))
				->where($db->quoteName('element') . ' = ' . $db->quote($element));

			if ($folder !== '') {
				$query->where($db->quoteName('folder') . ' = ' . $db->quote($folder));
			}

			return (int) $db->setQuery($query)->loadResult() > 0;
		} catch (\Throwable $e) {
			return true;
		}
	}

	/**
	 * Drop the caches that can hide a freshly installed add-on: Joomla's PSR-4
	 * autoload map, opcache, and Joomla's cache groups. Best-effort only.
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
				// Never touch Login as User's pending one-time links, or any other
				// extension's non-cache state that happens to live under cache/.
				if (basename((string) $dir) === 'plg_system_loginasuser') {
					continue;
				}

				try {
					$cache = Factory::getCache(basename((string) $dir), '');
					$cache->setCaching(true);
					$cache->clean();
				} catch (\Throwable $e) {
					// Best effort.
				}
			}
		}
	}
}
