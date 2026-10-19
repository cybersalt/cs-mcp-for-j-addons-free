<?php

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;

class PlgSystemCsmcpforjmaximenuckfreeInstallerScript implements InstallerScriptInterface
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

		$app = Factory::getApplication();

		// Without MCP for Joomla there is nothing to add tools TO. Joomla installs
		// plugins disabled; leave it that way and say so.
		if (!$this->coreInstalled()) {
			$app->enqueueMessage(
				'MCP add-on for Maximenu CK was installed but has been left DISABLED, because the MCP for '
				. 'Joomla extension is not on this site. This add-on only adds tools to MCP for Joomla - on '
				. 'its own it does nothing. Install MCP for Joomla first, then enable this plugin in System '
				. '-> Plugins. Nothing is broken and the site is unaffected.',
				'warning'
			);

			$this->clearCaches();

			return true;
		}

		try {
			$db    = Factory::getContainer()->get(DatabaseInterface::class);
			$query = $db->getQuery(true)
				->update($db->quoteName('#__extensions'))
				->set($db->quoteName('enabled') . ' = 1')
				->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
				->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
				->where($db->quoteName('element') . ' = ' . $db->quote('csmcpforjmaximenuckfree'));
			$db->setQuery($query)->execute();
		} catch (\Throwable $e) {
			$app->enqueueMessage('csmcpforjmaximenuckfree auto-enable failed: ' . $e->getMessage(), 'warning');
		}

		$app->enqueueMessage(
			'MCP add-on for Maximenu CK installed and active. 11 tools are exposed: modules, styles and their '
			. 'custom CSS, the compiled-CSS cache, each menu\'s mega-menu column layout and a panel-overflow check.',
			'message'
		);

		if (!is_dir(JPATH_ADMINISTRATOR . '/components/com_maximenuck')) {
			$app->enqueueMessage(
				'Maximenu CK is not installed on this site yet, so these tools will report "not installed" until it is.',
				'notice'
			);
		}

		$this->clearCaches();

		return true;
	}

	/** Fails OPEN on a DB error — the runtime guard in the plugin is the real protection. */
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
	 * Drop the caches that can hide a freshly installed add-on: the PSR-4
	 * autoload map (a plugin installed after it was written is not in it, which
	 * produced an HTTP 500 on the JCE add-on, 2026-10-07), opcache, and Joomla's
	 * cache groups. All best-effort.
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
					// Best effort.
				}
			}
		}
	}
}
