<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjakeebabackup\Tools\Backups;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjakeebabackup\Helper\AkeebaEngineBootstrap;
use Joomla\CMS\User\User;

/**
 * Kicks off an Akeeba backup by driving BackupModel::startBackup().
 *
 * Akeeba's backup engine is chunked: a backup of any non-trivial site can't
 * complete in one tool call because each "step" is bounded by Akeeba's
 * configured max-execution-time and Claude's tool-call timeout is ~60s. So
 * this tool only does the FIRST step (engine setup + initial tick) and
 * returns the engine's status array plus the backup_id + tag that
 * step_akeeba_backup needs to advance from there.
 *
 * Calling pattern:
 *   1. start_akeeba_backup() → returns { backup_id, tag, status }
 *   2. while status.HasRun != 1 and status.Error == '':
 *        status = step_akeeba_backup(backup_id, tag)
 *   3. When status.HasRun == 1 → done. Look up the new record id via
 *      list_akeeba_backups (filter origin='json' or matching backupid).
 *
 * Origin tag defaults to 'json' so the new record is distinguishable from
 * backend / frontend / cli backups in the activity log.
 */
final class StartAkeebaBackupTool extends AbstractTool
{
	public function getName(): string { return 'start_akeeba_backup'; }

	public function getDescription(): string
	{
		return 'Kick off an Akeeba Backup using the specified profile. Akeeba\'s engine '
			. 'is step-chunked: this call does the engine setup + initial tick and returns '
			. 'a backup_id + tag that you MUST pass to step_akeeba_backup repeatedly until '
			. 'status.HasRun == 1 (done) or status.Error is non-empty. Arguments: profile_id '
			. '(default 1 — the Default Backup Profile), description (default auto-generated), '
			. 'comment (default empty), tag (origin label; default "json"). Returns: '
			. '{backup_id, tag, profile_id, status} where status is Akeeba\'s engine status '
			. 'array (HasRun, Domain, Step, Substep, Error, Warnings, Progress).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'profile_id'  => ['type' => 'integer', 'minimum' => 1, 'description' => 'Profile to run the backup under. Defaults to 1 (Default Backup Profile).'],
				'description' => ['type' => 'string', 'description' => 'Human-readable description for this backup. Defaults to Akeeba\'s auto-generated one.'],
				'comment'     => ['type' => 'string', 'description' => 'Optional notes stored on the backup record.'],
				'tag'         => ['type' => 'string', 'description' => 'Origin tag for the backup. Defaults to "json".'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$component = AkeebaEngineBootstrap::boot();

		$profileId   = max(1, (int) ($arguments['profile_id'] ?? 1));
		$description = trim((string) ($arguments['description'] ?? ''));
		$comment     = (string) ($arguments['comment'] ?? '');
		$tag         = trim((string) ($arguments['tag'] ?? 'json')) ?: 'json';

		// Pin the session profile BEFORE asking BackupModel for the profile —
		// startBackup reads akeebabackup.profile from session, defaulting to 1.
		$app = \Joomla\CMS\Factory::getApplication();
		$app->getSession()->set('akeebabackup.profile', $profileId);
		if (!\defined('AKEEBA_PROFILE')) {
			\define('AKEEBA_PROFILE', $profileId);
		}

		// Pin the backup origin tag so it actually lands on the backup record's
		// `origin` column. Without this, Akeeba's Platform::get_backup_origin()
		// detects the running context, finds nothing it recognises (API app
		// isn't backend / frontend / json / cli from its perspective), and
		// silently overwrites our state-supplied tag with 'cli'. Backups taken
		// via MCP then look identical to scheduled cron backups in the
		// activity log. AKEEBA_BACKUP_ORIGIN is the well-known constant that
		// Akeeba's own BackupController defines for the same reason — see
		// Akeeba\Component\AkeebaBackup\Administrator\Controller\BackupController::__construct().
		if (!\defined('AKEEBA_BACKUP_ORIGIN')) {
			\define('AKEEBA_BACKUP_ORIGIN', $tag);
		}

		/** @var \Akeeba\Component\AkeebaBackup\Administrator\Model\BackupModel $model */
		$model = $component->getMVCFactory()->createModel('Backup', 'Administrator', ['ignore_request' => true]);

		$model->setState('tag', $tag);
		$model->setState('description', $description);
		$model->setState('comment', $comment);
		$model->setState('profile', $profileId);

		$status = $model->startBackup();

		// startBackup generates the engine "backupid" itself (format
		// "id-YYYYMMDD-HHMMSS-µs") and writes it back to model state. Read it
		// out so the caller can pass it to step_akeeba_backup.
		$backupId = (string) $model->getState('backupid', '');

		return ToolResult::json([
			'backup_id'  => $backupId,
			'tag'        => $tag,
			'profile_id' => $profileId,
			'status'     => $status,
		]);
	}
}
