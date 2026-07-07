<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Operations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjstageit\Tools\StageItBootTrait;
use Joomla\CMS\User\User;

/**
 * Start a sync (staging → live via a backup snapshot).
 *
 * Sync takes changes made in the staging environment (files + DB) and
 * promotes them to live, snapshotting the current live state to
 * <docroot>/stgbackups/<timestamp>/ first so you can roll back with
 * restore_stageit_backup if the promotion goes wrong.
 *
 * Stages: init → buildStgMap → backupFiles → backupDb → checkBackup →
 * prepLiveFiles → syncLiveFiles → (finalise) → 0. Same chunking pattern
 * as deploy — call continue_stageit_sync with the returned resume_token
 * until done=true.
 */
final class SyncTool extends AbstractTool
{
	use StageItBootTrait;
	use OperationRunTrait;

	public function getName(): string { return 'sync_stageit'; }

	public function getDescription(): string
	{
		return 'Start a StageIt sync (staging → live via backup snapshot). Promotes '
			. 'changes made in the staging environment to the live site, snapshotting '
			. 'the current live state to <docroot>/stgbackups/<timestamp>/ first so you '
			. 'can restore_stageit_backup if the promotion goes wrong. Chunked internally '
			. '(buildStgMap → backupFiles → backupDb → checkBackup → prepLiveFiles → '
			. 'syncLiveFiles). May return done=false + resume_token; call '
			. 'continue_stageit_sync until done=true. Optional time_budget.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'time_budget' => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		return $this->runOperation('Sync', 'start', $arguments);
	}
}
