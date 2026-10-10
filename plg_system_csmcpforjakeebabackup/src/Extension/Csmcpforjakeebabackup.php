<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjakeebabackup\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * Akeeba Backup MCP add-on plugin. Registers a tool set that drives Akeeba
 * Backup Core (com_akeebabackup) — list/get profiles, list/get backup records,
 * start + step the backup engine, download or delete archives.
 *
 * Why this exists: Akeeba Backup Core ships a JSON API (the same one their
 * RemoteCLI uses) but no in-Joomla "talk to Akeeba" surface that an MCP client
 * can hit directly. The Pro version adds in-admin restore + Site Transfer
 * Wizard; this add-on only covers operations that work on Core. The closest
 * prior art is the Pro JSON-API client library
 * (https://github.com/akeeba/json-backup-api) — read for design reference,
 * no code copied; cs-mcp-for-j is GPL-2-or-later, Akeeba ships GPL-3, both
 * compatible at GPL-3 but the clean-room boundary is preserved.
 *
 * Tools group (7 total — keep this list in step with the TOOLS array below):
 *  - Profiles (read): list_akeeba_profiles, get_akeeba_profile
 *  - Backups  (read): list_akeeba_backups, get_akeeba_backup_info
 *  - Execution (write): start_akeeba_backup, step_akeeba_backup,
 *                       delete_akeeba_backup
 *
 * Backup execution follows Akeeba's own AJAX-stepped design:
 * start_akeeba_backup returns a backup_id + initial progress; the caller
 * repeats step_akeeba_backup(backup_id) until done. Mirrors how the admin
 * UI works. Avoids the 60s-tool-timeout problem on real-size sites.
 */
final class Csmcpforjakeebabackup extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Profiles (read)
		\Cybersalt\Plugin\System\Csmcpforjakeebabackup\Tools\Profiles\ListAkeebaProfilesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjakeebabackup\Tools\Profiles\GetAkeebaProfileTool::class,

		// Backups (read)
		\Cybersalt\Plugin\System\Csmcpforjakeebabackup\Tools\Backups\ListAkeebaBackupsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjakeebabackup\Tools\Backups\GetAkeebaBackupInfoTool::class,

		// Backups (write — drive the Akeeba Engine in-process)
		\Cybersalt\Plugin\System\Csmcpforjakeebabackup\Tools\Backups\StartAkeebaBackupTool::class,
		\Cybersalt\Plugin\System\Csmcpforjakeebabackup\Tools\Backups\StepAkeebaBackupTool::class,
		\Cybersalt\Plugin\System\Csmcpforjakeebabackup\Tools\Backups\DeleteAkeebaBackupTool::class,
	];

	public static function getSubscribedEvents(): array
	{
		/*
		 * This runs on EVERY request, for every enabled system plugin, before
		 * anything has a chance to check whether MCP for Joomla is present.
		 *
		 * RegisterToolsEvent lives in com_csmcpforj. Reading ::EVENT_NAME off it
		 * is a class-constant access, which triggers the autoloader — and if the
		 * component is not installed, PHP raises
		 * `Error: Class "...RegisterToolsEvent" not found`. That is a fatal on
		 * every page load, front end AND administrator, which locks the operator
		 * out of the very screen they would use to disable this plugin. Recovery
		 * means FTP or direct database access.
		 *
		 * class_exists() returns false instead of throwing, so without the core
		 * component this plugin subscribes to nothing and the site is untouched.
		 * Do not "simplify" this back to a bare constant access.
		 */
		if (!class_exists(RegisterToolsEvent::class)) {
			return [];
		}

		return [RegisterToolsEvent::EVENT_NAME => 'onRegisterTools'];
	}

	public function onRegisterTools(RegisterToolsEvent $event): void
	{
		$registry = $event->getRegistry();
		$db       = $this->getDatabase();

		foreach (self::TOOLS as $toolClass) {
			$registry->register(new $toolClass($db));
		}
	}

	/**
	 * Tool list for the dashboard's domain map.
	 */
	public static function getToolClasses(): array
	{
		return self::TOOLS;
	}
}
