<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;

class PlgSystemCsmcpforjj2commerceInstallerScript implements InstallerScriptInterface
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
				'MCP add-on for J2Commerce was installed but has been left DISABLED, because the CS MCP for '
				. 'J extension is not on this site. This add-on only adds tools to MCP for J - on '
				. 'its own it does nothing. Install CS MCP for J first, then enable this plugin in System '
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
				->where($db->quoteName('element') . ' = ' . $db->quote('csmcpforjj2commerce'));
			$db->setQuery($query)->execute();
		} catch (\Throwable $e) {
			$app->enqueueMessage('csmcpforjj2commerce auto-enable failed: ' . $e->getMessage(), 'warning');
		}

		$app->enqueueMessage(
			'MCP add-on for J2Commerce installed and active. New tools are exposed to your connected MCP '
			. 'clients: products and variants, prices, stock, orders and their status, customers, coupons, '
			. 'gift vouchers, store lookups, sales reporting and diagnostics. There is no separate admin UI '
			. 'for this plugin by design — the tools appear in your MCP client automatically.',
			'message'
		);

		$base = JPATH_ADMINISTRATOR . '/components/com_j2commerce';

		if (!is_dir($base)) {
			$legacy = is_dir(JPATH_ADMINISTRATOR . '/components/com_j2store');

			$app->enqueueMessage(
				$legacy
					? 'This site runs J2Store 4 (com_j2store), not J2Commerce 6 (com_j2commerce). They are '
						. 'different products with different databases, and these tools cover J2Commerce 6 '
						. 'only, so they will report "not installed" here.'
					: 'J2Commerce (com_j2commerce) is not installed on this site yet, so these tools will '
						. 'report "not installed" until it is. The J2Commerce core is free.',
				'notice'
			);

			$this->clearCaches();

			return true;
		}

		$app->enqueueMessage(
			'J2Commerce ' . ($this->detectVersion($base) ?? '(version undetermined)') . ' detected. '
			. 'FIRST THINGS TO RUN: get_j2commerce_component_info, then check_j2commerce_health and '
			. 'check_j2commerce_tax_setup. The tax audit matters most: a missing link anywhere between a '
			. 'product\'s tax profile and the customer\'s country makes J2Commerce charge zero tax without '
			. 'any warning.',
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

	/** Read by regular expression; including version.php would define constants into the installer request. */
	private function detectVersion(string $base): ?string
	{
		$file = $base . '/version.php';

		if (!is_file($file)) {
			return null;
		}

		$raw = (string) @file_get_contents($file);

		if (preg_match("/define\\(\\s*'J2COMMERCE_VERSION'\\s*,\\s*'([^']+)'/", $raw, $m) === 1) {
			return $m[1];
		}

		return null;
	}
}
