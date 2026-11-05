<?php
/**
 * @package     MCP for Override Checker
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\Backups;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\BackupsHelper;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\UnsafeContentsException;
use Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\OverrideCheckerBootTrait;
use Joomla\CMS\User\User;

final class RestoreBackupTool extends AbstractTool
{
	use OverrideCheckerBootTrait;

	public function getName(): string { return 'restore_override_checker_backup'; }

	public function getDescription(): string
	{
		return 'Put a backed-up file back, through Override Checker\'s Restore: it first backs up the file as it is now '
			. '(pre_restore_backup_id), so a restore can be undone too. Override Checker only writes inside '
			. 'templates/<template>/html/. Use it to undo apply_override_checker_fix (its pre_fix_backup_id). Only for a '
			. 'backup the user has agreed to restore. A backup holding code such as eval or shell commands that the '
			. 'current file does not have is refused, because it may be a backdoor a fix removed; only a person can '
			. 'restore that, from Override Checker\'s File backups screen.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'backup_id' => ['type' => 'integer', 'description' => 'Backup id from list_override_checker_backups or a fix\'s pre_fix_backup_id.'],
			],
			'required'             => ['backup_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($error = $this->ocCheck()) {
			return $error;
		}

		$this->ocRequireAccess($actor, 'write');

		$id  = $this->requirePositiveInt($arguments, 'backup_id');
		$row = BackupsHelper::find($id);

		if ($row === null) {
			throw new \InvalidArgumentException('No backup #' . $id . '. list_override_checker_backups shows the current ones.');
		}

		$relative = ltrim((string) $row->file_path, '/\\');
		$liveHash = $this->ocLiveHash($relative);

		if ($liveHash !== null && hash_equals((string) $row->file_hash, $liveHash)) {
			return ToolResult::json([
				'ok'       => true,
				'restored' => false,
				'note'     => 'The live file already matches backup #' . $id . '. Nothing to restore, no backup taken.',
				'path'     => $relative,
			]);
		}

		// Pre-restore backup, path allow-list, size cap, write, opcache, action log: all Override Checker's.
		try {
			$result = BackupsHelper::restore($id);
		} catch (UnsafeContentsException $e) {
			// Could be the backdoor a fix removed. Only a person may bring it back, from the admin.
			return ToolResult::json([
				'ok'       => false,
				'restored' => false,
				'error'    => $e->getMessage(),
				'path'     => $relative,
			], true);
		}

		clearstatcache();
		$liveHash = $this->ocLiveHash($relative);
		$verified = $liveHash !== null && hash_equals((string) $row->file_hash, $liveHash);

		return ToolResult::json([
			'ok'                    => $verified,
			'restored'              => true,
			'backup_id'             => $id,
			'path'                  => $result['restored_path'],
			'bytes_written'         => $result['bytes_written'],
			'pre_restore_backup_id' => $result['pre_restore_backup_id'],
			'verified'              => $verified,
			'undo'                  => $result['pre_restore_backup_id'] !== null
				? 'restore_override_checker_backup with backup_id ' . $result['pre_restore_backup_id'] . '.'
				: 'There was no file at this path before the restore, so there is nothing to undo to.',
			'warning'               => $verified ? null : 'The file on disk does not match the backup after the restore. Check it before going on.',
		], !$verified);
	}
}
