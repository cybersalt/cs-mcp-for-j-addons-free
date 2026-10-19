<?php

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;

class PlgSystemCsmcpforjflexbannersInstallerScript implements InstallerScriptInterface
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

		// Without MCP for Joomla there is nothing to add tools TO. Joomla installs
		// plugins disabled; leave it that way and explain.
		if (!$this->coreInstalled()) {
			Factory::getApplication()->enqueueMessage(
				'MCP add-on for FlexBanners was installed but has been left DISABLED, because the MCP for '
				. 'Joomla extension is not on this site. This add-on only adds tools to MCP for Joomla - on '
				. 'its own it does nothing. Install MCP for Joomla first, then enable this plugin in System '
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
				->where($db->quoteName('element') . ' = ' . $db->quote('csmcpforjflexbanners'));
			$db->setQuery($query)->execute();
		} catch (\Throwable $e) {
			$app->enqueueMessage('csmcpforjflexbanners auto-enable failed: ' . $e->getMessage(), 'warning');
		}

		$app->enqueueMessage(
			'MCP add-on for FlexBanners installed and active. New tools are exposed to your connected MCP '
			. 'clients: banners, locations (ad groups), mod_flexbanners modules - including one-call '
			. '"add another row of ads" - click-through links, clients, size presets, statistics and a health '
			. 'check. There is no separate admin UI for this plugin by design.',
			'message'
		);

		if (!is_dir(JPATH_ADMINISTRATOR . '/components/com_flexbanners')) {
			$app->enqueueMessage(
				'FlexBanners (com_flexbanners) is not installed on this site yet, so these tools will report '
				. '"not installed" until it is. FlexBanners is free on the Joomla Extensions Directory.',
				'notice'
			);
		} else {
			$app->enqueueMessage(
				'FlexBanners detected. FIRST THING TO RUN: check_flexbanners_health. It lists every published '
				. 'banner that is not actually showing on the site, and why.',
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
