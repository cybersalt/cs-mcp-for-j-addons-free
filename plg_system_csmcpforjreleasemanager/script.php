<?php

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;

class PlgSystemCsmcpforjreleasemanagerInstallerScript implements InstallerScriptInterface
{
	public function install(InstallerAdapter $adapter): bool   { return true; }
	public function update(InstallerAdapter $adapter): bool    { return true; }
	public function uninstall(InstallerAdapter $adapter): bool { return true; }
	public function preflight(string $type, InstallerAdapter $adapter): bool { return true; }

	public function postflight(string $type, InstallerAdapter $adapter): bool
	{
		if (!in_array($type, ['install', 'update', 'discover_install'], true)) {
			return true;
		}

		try {
			$db = Factory::getContainer()->get(DatabaseInterface::class);
			$query = $db->getQuery(true)
				->update($db->quoteName('#__extensions'))
				->set($db->quoteName('enabled') . ' = 1')
				->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
				->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
				->where($db->quoteName('element') . ' = ' . $db->quote('csmcpforjreleasemanager'));
			$db->setQuery($query)->execute();
		} catch (\Throwable $e) {
			Factory::getApplication()->enqueueMessage(
				'csmcpforjreleasemanager auto-enable failed: ' . $e->getMessage(),
				'warning'
			);
		}

		$this->removeStaleFiles();

		// Confirmation so the operator knows the install did something — MCP
		// add-ons by design have NO admin UI. Tools appear in connected MCP
		// clients (Claude Desktop / Code / claude.ai) automatically.
		Factory::getApplication()->enqueueMessage(
			'MCP add-on for Cybersalt Release Manager installed and active. New release-management '
			. 'tools (list / get / create / update / delete Packages, PackageVersions and '
			. 'Installations, plus a read-only view of the activity log) are now exposed to your '
			. 'connected MCP clients. There is no separate admin UI by design — the tools appear '
			. 'in your MCP client automatically.',
			'message'
		);

		// A newly installed add-on's classes are invisible to a cached
		// autoloader and a warm opcache. See clearCaches().
		$this->clearCaches();

		return true;
	}

	/**
	 * Drop the caches that can hide a freshly installed add-on.
	 *
	 * Reported by Dragan Subotic 2026-10-07: installing the JCE add-on produced
	 * an HTTP 500 about a minute later, cleared by a manual cache clean. The
	 * install itself was fine — what was stale was everything around it:
	 *
	 *  1. Joomla's PSR-4 autoload map. It is written once and reused; a plugin
	 *     installed after it was generated is simply not in it, so the
	 *     autoloader cannot find the new classes and the first request that
	 *     dispatches to them fatals. This is the one that actually caused the
	 *     500 — delete the map and Joomla rebuilds it on the next request.
	 *  2. opcache, which keeps serving the pre-install compiled files.
	 *  3. Joomla's own cache groups, which can hold a stale plugin list.
	 *
	 * All three are best-effort. A cache that cannot be cleared must never fail
	 * an install that otherwise succeeded, so every step swallows its errors —
	 * the worst case is the operator clearing the cache by hand, which is the
	 * situation before this method existed.
	 */
	private function clearCaches(): void
	{
		// 1. PSR-4 autoload map (both client caches — JPATH_CACHE is whichever
		//    client we happen to be running in).
		foreach ([JPATH_SITE . '/cache', JPATH_ADMINISTRATOR . '/cache'] as $cachePath) {
			$map = $cachePath . '/autoload_psr4.php';

			if (is_file($map)) {
				@unlink($map);
			}
		}

		// 2. opcache. opcache.restrict_api can make this throw or warn when
		//    called from outside the configured prefix; treat that as a no-op.
		if (function_exists('opcache_reset')) {
			@opcache_reset();
		}

		// 3. Joomla's cache groups.
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


	/**
	 * Joomla's installer overwrites files but never deletes ones that were
	 * removed from a later package, so a file that moved leaves its old copy
	 * behind forever. Those orphans are inert here — the plugin registers tools
	 * from an explicit const TOOLS list rather than scanning the directory — but
	 * a stale class sitting at a path the code no longer references is exactly
	 * the kind of thing that costs an hour during a future debug session.
	 *
	 * @var string[] Paths relative to the installed plugin folder.
	 */
	private const STALE_FILES = [
		// Moved into src/Tools/Installations/ in 1.1.0 when the installation
		// tools grew from List-only to full get/update/delete.
		'src/Tools/ListReleaseManagerInstallationsTool.php',
	];

	private function removeStaleFiles(): void
	{
		$base = JPATH_PLUGINS . '/system/csmcpforjreleasemanager/';

		foreach (self::STALE_FILES as $relative) {
			$path = $base . $relative;

			if (is_file($path)) {
				@unlink($path);
			}
		}
	}
}
