<?php

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;

class PlgSystemCsmcpforjstageitInstallerScript implements InstallerScriptInterface
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
				->where($db->quoteName('element') . ' = ' . $db->quote('csmcpforjstageit'));
			$db->setQuery($query)->execute();
		} catch (\Throwable $e) {
			Factory::getApplication()->enqueueMessage(
				'csmcpforjstageit auto-enable failed: ' . $e->getMessage(),
				'warning'
			);
		}

		Factory::getApplication()->enqueueMessage(
			'MCP add-on for StageIt installed and active. 11 new tools exposed to your connected MCP '
			. 'clients: 3 status/inspection (status, prechecks, list backups) + 8 orchestration tools '
			. 'covering the 4 long-running StageIt operations (deploy / sync / remove / restore-backup) '
			. 'with a start / continue chunking pattern so each operation runs safely within your PHP '
			. 'timeout budget. There is no separate admin UI for this plugin by design — tools appear '
			. 'in your MCP client automatically.',
			'message'
		);

		return true;
	}
}
