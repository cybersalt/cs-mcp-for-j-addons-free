<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjakeebabackup\Tools\Backups;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjakeebabackup\Helper\AkeebaEngineBootstrap;
use Joomla\CMS\User\User;

/**
 * Advances an in-progress Akeeba backup one step. The agent MUST keep calling
 * this until status.HasRun == 1 or status.Error is non-empty. Each step runs
 * one Akeeba "tick" — bounded by the profile's max-execution-time (typically
 * 5–14s).
 */
final class StepAkeebaBackupTool extends AbstractTool
{
	public function getName(): string { return 'step_akeeba_backup'; }

	public function getDescription(): string
	{
		return 'Advance an in-progress Akeeba Backup by one engine step. Pass the backup_id '
			. 'and tag returned by start_akeeba_backup. Returns Akeeba\'s engine status '
			. 'array: HasRun (0 = more steps remaining, 1 = backup finished), Domain (current '
			. 'phase, e.g. "init"/"DBBackup"/"finale"), Step + Substep (debug labels), Error '
			. '(non-empty = abort), Warnings (array), Progress (0–100 estimate). Keep calling '
			. 'until HasRun==1 or Error is set.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'backup_id' => ['type' => 'string', 'description' => 'The engine backup_id returned by start_akeeba_backup.'],
				'tag'       => ['type' => 'string', 'description' => 'The tag returned by start_akeeba_backup (defaults to "json" if absent).'],
			],
			'required'             => ['backup_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$component = AkeebaEngineBootstrap::boot();

		$backupId = $this->requireString($arguments, 'backup_id');
		$tag      = trim((string) ($arguments['tag'] ?? 'json')) ?: 'json';

		/** @var \Akeeba\Component\AkeebaBackup\Administrator\Model\BackupModel $model */
		$model = $component->getMVCFactory()->createModel('Backup', 'Administrator', ['ignore_request' => true]);

		$model->setState('tag', $tag);
		$model->setState('backupid', $backupId);

		$status = $model->stepBackup();

		return ToolResult::json([
			'backup_id' => $backupId,
			'tag'       => $tag,
			'status'    => $status,
			'done'      => isset($status['HasRun']) && (int) $status['HasRun'] === 1,
			'errored'   => !empty($status['Error']),
		]);
	}
}
