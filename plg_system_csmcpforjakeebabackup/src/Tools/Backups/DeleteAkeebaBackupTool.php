<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjakeebabackup\Tools\Backups;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjakeebabackup\Helper\AkeebaEngineBootstrap;
use Joomla\CMS\User\User;

/**
 * Two modes:
 *  - files_only=true (default false) → keep the backup record, unlink the
 *    on-disk archive (StatisticModel::deleteFiles).
 *  - files_only=false → delete record + archive (StatisticModel::delete).
 *
 * Akeeba protects "frozen" records — its model throws FrozenRecordError when
 * you try to delete one. We surface that as a clean error message instead of
 * letting the throw propagate.
 */
final class DeleteAkeebaBackupTool extends AbstractTool
{
	public function getName(): string { return 'delete_akeeba_backup'; }

	public function getDescription(): string
	{
		return 'Delete one Akeeba Backup. Default behaviour deletes BOTH the record + the '
			. 'archive files. Pass files_only=true to keep the record (Akeeba will mark '
			. 'filesexist=0) and just unlink the archive — useful for pruning disk after '
			. 'you\'ve copied the archive off-site. Frozen records are refused (set frozen=0 '
			. 'in the admin first if you really want to delete a frozen backup).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'backup_id'  => ['type' => 'integer', 'minimum' => 1, 'description' => 'The backup record id.'],
				'files_only' => ['type' => 'boolean', 'description' => 'If true, delete only the archive files and keep the record. Default false.'],
			],
			'required'             => ['backup_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$component = AkeebaEngineBootstrap::boot();

		$id        = $this->requirePositiveInt($arguments, 'backup_id');
		$filesOnly = (bool) ($arguments['files_only'] ?? false);

		/** @var \Akeeba\Component\AkeebaBackup\Administrator\Model\StatisticModel $model */
		$model = $component->getMVCFactory()->createModel('Statistic', 'Administrator', ['ignore_request' => true]);

		// Akeeba's delete()/deleteFiles() take a reference to an array of ids,
		// matching Joomla's MVC delete signature. Pass single-item array.
		$ids = [$id];

		try {
			$ok = $filesOnly ? $model->deleteFiles($ids) : $model->delete($ids);
		} catch (\RuntimeException $e) {
			// FrozenRecordError extends RuntimeException; any other runtime
			// error coming out of the model (file-permission failures, etc.)
			// also lands here. Surface the message verbatim.
			return ToolResult::error('Akeeba refused to delete backup ' . $id . ': ' . $e->getMessage());
		}

		if (!$ok) {
			return ToolResult::error('Akeeba returned false for backup ' . $id . ' delete (no further detail available).');
		}

		return ToolResult::json([
			'backup_id'   => $id,
			'files_only'  => $filesOnly,
			'deleted'     => true,
		]);
	}
}
