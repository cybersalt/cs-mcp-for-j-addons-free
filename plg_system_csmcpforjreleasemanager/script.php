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

		return true;
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
