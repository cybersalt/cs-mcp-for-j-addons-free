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

final class ListBackupsTool extends AbstractTool
{
	use OverrideCheckerBootTrait;

	public function getName(): string { return 'list_override_checker_backups'; }

	public function getDescription(): string
	{
		return 'List Override Checker\'s file backups (File backups in its admin), newest first by default: one is taken '
			. 'before every fix and every restore. Each row says whether the live file still matches it '
			. '(live_matches_backup), so you can see which backup holds the state before a fix. Filter by path text or '
			. 'review session. Contents: get_override_checker_backup.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'search'     => ['type' => 'string', 'description' => 'Part of the file path, e.g. "mod_menu" or "cassiopeia/html/com_content".'],
				'session_id' => ['type' => 'integer', 'description' => 'Only backups linked to this review session.'],
				'no_session' => ['type' => 'boolean', 'description' => 'true: only backups not linked to any session.'],
				'sort'       => ['type' => 'string', 'enum' => ['saved', 'session', 'file', 'size'], 'description' => 'Default saved (date).'],
				'direction'  => ['type' => 'string', 'enum' => ['desc', 'asc'], 'description' => 'Default desc.'],
				'limit'      => ['type' => 'integer', 'description' => '1-500, default 50.'],
			],
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

		$filter = [];

		if (isset($arguments['search']) && trim((string) $arguments['search']) !== '') {
			$filter['search'] = trim((string) $arguments['search']);
		}

		if (!empty($arguments['no_session'])) {
			if (isset($arguments['session_id'])) {
				throw new \InvalidArgumentException('Pass session_id or no_session, not both.');
			}

			$filter['session_id'] = 'none';
		} elseif (isset($arguments['session_id'])) {
			$filter['session_id'] = (int) $arguments['session_id'];
		}

		$sort = $this->ocEnum($arguments, 'sort', ['saved', 'session', 'file', 'size'], 'saved');
		$dir  = $this->ocEnum($arguments, 'direction', ['desc', 'asc'], 'desc');

		$rows      = BackupsHelper::listFiltered($filter, $sort, $dir, $this->ocLimit($arguments, 50, 500));
		$liveHash  = [];
		$out       = [];

		foreach ($rows as $row) {
			$relative = ltrim((string) $row->file_path, '/\\');

			if (!\array_key_exists($relative, $liveHash)) {
				$liveHash[$relative] = $this->ocLiveHash($relative);
			}

			$out[] = [
				'id'                  => (int) $row->id,
				'file_path'           => $relative,
				'file_size'           => (int) $row->file_size,
				'sha256'              => (string) $row->file_hash,
				'session_id'          => $row->session_id !== null ? (int) $row->session_id : null,
				'created_by'          => (int) $row->created_by,
				'created_at'          => (string) $row->created_at,
				'live_file_exists'    => $liveHash[$relative] !== null,
				'live_matches_backup' => $liveHash[$relative] !== null && hash_equals((string) $row->file_hash, $liveHash[$relative]),
			];
		}

		return ToolResult::json([
			'ok'       => true,
			'returned' => \count($out),
			'backups'  => $out,
		]);
	}
}
