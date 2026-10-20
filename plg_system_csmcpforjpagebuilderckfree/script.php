<?php
/**
 * @package     MCP for Page Builder CK
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;

class PlgSystemCsmcpforjpagebuilderckfreeInstallerScript implements InstallerScriptInterface
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

		// MCP for Joomla is what gives this add-on somewhere to register its
		// tools. Without it there is nothing to add on TO, so do not switch the
		// plugin on — Joomla installs plugins disabled, and we simply leave it
		// that way and explain. See coreInstalled().
		if (!$this->coreInstalled()) {
			Factory::getApplication()->enqueueMessage(
				'MCP add-on for Page Builder CK was installed but has been left DISABLED, because '
				. 'the MCP for Joomla extension is not on this site. This add-on only adds tools '
				. 'to MCP for Joomla - on its own it does nothing. Install MCP for Joomla first, '
				. 'then enable this plugin in System -> Plugins. Nothing is broken and the site is '
				. 'unaffected.',
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
				->where($db->quoteName('element') . ' = ' . $db->quote('csmcpforjpagebuilderckfree'));
			$db->setQuery($query)->execute();
		} catch (\Throwable $e) {
			$app->enqueueMessage(
				'csmcpforjpagebuilderckfree auto-enable failed: ' . $e->getMessage(),
				'warning'
			);
		}

		$app->enqueueMessage(
			'MCP add-on for Page Builder CK installed and active. 30 new tools are exposed to your '
			. 'connected MCP clients: pages, the block tree inside htmlcode, rows and columns, styles, '
			. 'saved elements, fonts and the .pbck backup history. There is no separate admin UI for '
			. 'this plugin by design — the tools appear in your MCP client automatically.',
			'message'
		);

		$base = JPATH_ADMINISTRATOR . '/components/com_pagebuilderck';

		if (!is_dir($base)) {
			$app->enqueueMessage(
				'Page Builder CK (com_pagebuilderck) is not installed on this site yet, so these tools '
				. 'will report "not installed" until it is. The free Light edition is enough.',
				'notice'
			);

			return true;
		}

		$this->reportEdition($app, $base);
		$this->warnOnVulnerableVersion($app, $base);

		// A newly installed add-on's classes are invisible to a cached
		// autoloader and a warm opcache. See clearCaches().
		$this->clearCaches();

		return true;
	}

	/**
	 * Is the MCP for Joomla component present on this site?
	 *
	 * Checked at install time so an add-on installed on its own does not get
	 * switched on. The runtime guard in the plugin's getSubscribedEvents() is
	 * the one that actually protects the site; this is what makes the situation
	 * visible to whoever installed it, instead of leaving them with a plugin
	 * that is enabled and silently inert.
	 *
	 * Fails OPEN on a database error: if we cannot tell, assume the component is
	 * there and let the install proceed normally. Refusing a legitimate install
	 * over a transient hiccup would be the worse outcome, and the runtime guard
	 * still covers the case where we guessed wrong.
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
	 * Say which edition is present, and which tools that unlocks.
	 *
	 * The `pro` directory IS Page Builder CK's entire Pro gate — there is no
	 * licence key anywhere in the installed code.
	 */
	private function reportEdition(object $app, string $base): void
	{
		if (is_dir($base . '/pro')) {
			$app->enqueueMessage(
				'Page Builder CK PRO detected. All 30 tools here work, and the separate Pro add-on '
				. '(export/import, block library, presets) will also work on this site.',
				'message'
			);

			return;
		}

		$app->enqueueMessage(
			'Page Builder CK Light (free) detected. All 30 tools in this add-on work against it. '
			. 'Light ships 11 of the 29 block addons — a page referencing a Pro-only type such as '
			. 'heading or button still renders its inner markup as static HTML rather than blanking, '
			. 'with no error anywhere, so the tools report which types have no enabled plugin.',
			'notice'
		);
	}

	/**
	 * Page Builder CK below 3.6.5 has known, actively exploited RCEs.
	 *
	 * CVE-2026-56290 is on the CISA Known Exploited Vulnerabilities list with
	 * public mass-scanning tooling. Three further security releases followed it,
	 * so 3.6.0 is NOT sufficient — 3.6.5 is the safe floor on the Joomla 5/6
	 * branch.
	 */
	private function warnOnVulnerableVersion(object $app, string $base): void
	{
		$manifest = $base . '/pagebuilderck.xml';

		if (!is_file($manifest)) {
			return;
		}

		$xml = @simplexml_load_file($manifest);

		if ($xml === false) {
			return;
		}

		$version = trim((string) ($xml->version ?? ''));

		if ($version === '' || version_compare($version, '3.6.5', '>=')) {
			return;
		}

		$app->enqueueMessage(
			sprintf(
				'SECURITY: this site runs Page Builder CK %s. Versions below 3.6.5 carry known remote '
				. 'code execution vulnerabilities — CVE-2026-56290 is on the CISA Known Exploited '
				. 'Vulnerabilities list and public mass-exploitation tooling exists. Note that 3.6.0 '
				. 'only partially fixed it; 3.6.3 and 3.6.5 closed two further RCE/SQLi issues. Update '
				. 'to 3.6.5 or later before doing anything else.',
				$version
			),
			'error'
		);
	}
}
