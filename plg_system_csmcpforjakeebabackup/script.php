<?php

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;

/**
 * Standalone-installer postflight: enable the system plugin immediately so
 * the operator doesn't have to go hunting for it in Plugins manager. Without
 * this, the plugin lands at enabled=0 (Joomla's default for newly-installed
 * plugins) and our RegisterToolsEvent subscriber never fires — the add-on
 * tools never appear in the MCP catalogue.
 *
 * Class name MUST be PlgSystem{Element}InstallerScript with the element
 * exactly as declared in the manifest. Joomla's InstallerAdapter only finds
 * the class under this exact spelling.
 */
class PlgSystemCsmcpforjakeebabackupInstallerScript implements InstallerScriptInterface
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
				->where($db->quoteName('element') . ' = ' . $db->quote('csmcpforjakeebabackup'));
			$db->setQuery($query)->execute();
		} catch (\Throwable $e) {
			Factory::getApplication()->enqueueMessage(
				'csmcpforjakeebabackup auto-enable failed: ' . $e->getMessage(),
				'warning'
			);
		}

		return true;
	}
}
