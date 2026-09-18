<?php

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;

class PlgSystemCsmcpforjatsfreeInstallerScript implements InstallerScriptInterface
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
				->where($db->quoteName('element') . ' = ' . $db->quote('csmcpforjatsfree'));
			$db->setQuery($query)->execute();
		} catch (\Throwable $e) {
			$app->enqueueMessage(
				'csmcpforjatsfree auto-enable failed: ' . $e->getMessage(),
				'warning'
			);
		}

		$app->enqueueMessage(
			'MCP add-on for Akeeba Ticket System installed and active. New tools are exposed to your '
			. 'connected MCP clients: tickets, posts, categories, collaborators, managers and reporting. '
			. 'Writes go through ATS\' own model and table classes so its status rules, permission checks '
			. 'and notification emails all fire. There is no separate admin UI for this plugin by design — '
			. 'the tools appear in your MCP client automatically.',
			'message'
		);

		// ATS is not a hard dependency at install time — someone may install the
		// add-on first — but silently doing nothing afterwards is a confusing
		// outcome, so say plainly what is missing.
		if (!is_dir(JPATH_ADMINISTRATOR . '/components/com_ats')) {
			$app->enqueueMessage(
				'Akeeba Ticket System (com_ats) is not installed on this site yet, so these tools will '
				. 'report "not installed" until it is. Install Akeeba Ticket System — the free Core '
				. 'edition is enough — and the tools start working with no further configuration.',
				'notice'
			);
		}

		return true;
	}
}
