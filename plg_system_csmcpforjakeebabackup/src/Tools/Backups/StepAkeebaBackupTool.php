<?php
/**
 * @package     MCP for Akeeba Backup Core
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjakeebabackup\Tools\Backups;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjakeebabackup\Helper\AkeebaEngineBootstrap;
use Joomla\CMS\User\User;

/**
 * Advances an in-progress Akeeba backup one step. The agent MUST keep calling
 * this while `done` is false. Each step runs
 * one Akeeba "tick" — bounded by the profile's max-execution-time (typically
 * 5–14s).
 */
final class StepAkeebaBackupTool extends AbstractTool
{
	public function getName(): string { return 'step_akeeba_backup'; }

	public function getDescription(): string
	{
		return 'Advance an in-progress Akeeba Backup by one engine step. Pass the backup_id '
			. 'and tag returned by start_akeeba_backup. Returns state ("running", "complete" or '
			. '"failed"), done (true = stop calling: the backup either finished or failed), '
			. 'succeeded (true only for a finished backup), record (the backup row: id, status, '
			. 'archive path, size), next_action, and Akeeba\'s raw engine status array (Domain, '
			. 'Step, Substep, Error, Warnings, Progress 0-100). A failed backup is returned as '
			. 'an error result. Keep calling while done is false.';
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

		$outcome = AkeebaEngineBootstrap::describeOutcome($status, $backupId);

		return ToolResult::json([
			'backup_id' => $backupId,
			'tag'       => $tag,
		] + $outcome + [
			'status' => $status,
		], $outcome['state'] === 'failed');
	}
}
