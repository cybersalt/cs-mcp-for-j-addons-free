<?php

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;

class PlgSystemCsmcpforjconvertformsfreeInstallerScript implements InstallerScriptInterface
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

		try {
			$db    = Factory::getContainer()->get(DatabaseInterface::class);
			$query = $db->getQuery(true)
				->update($db->quoteName('#__extensions'))
				->set($db->quoteName('enabled') . ' = 1')
				->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
				->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
				->where($db->quoteName('element') . ' = ' . $db->quote('csmcpforjconvertformsfree'));
			$db->setQuery($query)->execute();
		} catch (\Throwable $e) {
			$app->enqueueMessage(
				'csmcpforjconvertformsfree auto-enable failed: ' . $e->getMessage(),
				'warning'
			);
		}

		$app->enqueueMessage(
			'MCP add-on for Convert Forms Free installed and active. 30 new tools are exposed to your '
			. 'connected MCP clients: forms, form fields, submissions, Tasks (email notifications '
			. 'and app integrations) and reporting. Writes go through Convert Forms\' own model and '
			. 'table classes so its validation and encoding rules apply. There is no separate admin '
			. 'UI for this plugin by design — the tools appear in your MCP client automatically.',
			'message'
		);

		// Convert Forms is not a hard dependency at install time — someone may
		// install the add-on first — but silently doing nothing afterwards is a
		// confusing outcome, so say plainly what is missing.
		if (!is_dir(JPATH_ADMINISTRATOR . '/components/com_convertforms')) {
			$app->enqueueMessage(
				'Convert Forms (com_convertforms) is not installed on this site yet, so these tools '
				. 'will report "not installed" until it is. Install Convert Forms — the free edition '
				. 'is enough — and the tools start working with no further configuration.',
				'notice'
			);
		}

		return true;
	}
}
