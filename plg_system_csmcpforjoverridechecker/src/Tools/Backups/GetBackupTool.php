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
use Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\OverrideCheckerBootTrait;
use Joomla\CMS\User\User;

/**
 * Not in the issue's list, which has list and restore only. Without it an AI
 * restores a backup it has never seen, so it gets to read one first.
 */
final class GetBackupTool extends AbstractTool
{
	use OverrideCheckerBootTrait;

	public function getName(): string { return 'get_override_checker_backup'; }

	public function getDescription(): string
	{
		return 'Read one Override Checker backup: the saved file contents (whole, up to 1 MB) and whether the live file '
			. 'still matches it. Use it to check what restore_override_checker_backup would put back.';
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

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($error = $this->ocCheck()) {
			return $error;
		}

		$this->ocRequireAccess($actor, 'view');

		$id  = $this->requirePositiveInt($arguments, 'backup_id');
		$row = BackupsHelper::find($id);

		if ($row === null) {
			throw new \InvalidArgumentException('No backup #' . $id . '. list_override_checker_backups shows the current ones.');
		}

		$contents = BackupsHelper::decodeContents($row);

		if (\strlen($contents) > self::OC_MAX_READ_BYTES) {
			throw new \RuntimeException('Backup #' . $id . ' is ' . \strlen($contents) . ' bytes, over the 1 MB read cap. Open it in Override Checker -> File backups.');
		}

		$relative = ltrim((string) $row->file_path, '/\\');
		$liveHash = $this->ocLiveHash($relative);
		$utf8    = mb_check_encoding($contents, 'UTF-8');

		return ToolResult::json([
			'ok'     => true,
			'backup' => [
				'id'         => (int) $row->id,
				'file_path'  => $relative,
				'file_size'  => (int) $row->file_size,
				'sha256'     => (string) $row->file_hash,
				'session_id' => $row->session_id !== null ? (int) $row->session_id : null,
				'created_by' => (int) $row->created_by,
				'created_at' => (string) $row->created_at,
				'encoding'   => $utf8 ? 'utf-8' : 'base64',
				'contents'   => $utf8 ? $contents : base64_encode($contents),
			],
			'live_file' => [
				'exists'          => $liveHash !== null,
				'sha256'          => $liveHash,
				'matches_backup'  => $liveHash !== null && hash_equals((string) $row->file_hash, $liveHash),
			],
			'note' => $this->ocUntrustedNote(),
		]);
	}
}
