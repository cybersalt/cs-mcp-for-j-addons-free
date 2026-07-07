<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Operations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjstageit\Tools\StageItBootTrait;
use Joomla\CMS\User\User;

/**
 * Start restoring a backup snapshot to live. Use this to roll back a sync
 * that went wrong — pick the snapshot from list_stageit_backups by name,
 * pass it here, and StageIt rebuilds live from the snapshot.
 *
 * Stages: init → buildBkMap → prepLive → restoreFiles → restoreDb →
 * finaliseRestore → 0. Chunked internally; may return done=false + a
 * resume_token.
 */
final class RestoreBackupTool extends AbstractTool
{
	use StageItBootTrait;
	use OperationRunTrait;

	public function getName(): string { return 'restore_stageit_backup'; }

	public function getDescription(): string
	{
		return 'Start restoring a StageIt backup snapshot to live. DESTRUCTIVE: overwrites '
			. 'the live filesystem + DB from the named snapshot under <docroot>/stgbackups/. '
			. 'Required: backup_name (the folder name — use list_stageit_backups to see '
			. 'available names). Chunked internally (buildBkMap → prepLive → restoreFiles '
			. '→ restoreDb → finaliseRestore); may return done=false + resume_token — call '
			. 'continue_stageit_restore until done=true. Optional time_budget.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['backup_name'],
			'properties' => [
				'backup_name' => [
					'type' => 'string',
					'description' => 'Folder name under <docroot>/stgbackups/, as returned by list_stageit_backups.',
				],
				'time_budget' => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		return $this->runOperation('RestoreBackup', 'start', $arguments);
	}
}
