<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjakeebabackup\Tools\Backups;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Single-backup detail — same shape as a list_akeeba_backups row but for one id.
 * Applies the same Pro-empty-column suppression (type='full' hidden, NULL
 * remote_filename hidden) so the response stays Core-clean.
 */
final class GetAkeebaBackupInfoTool extends AbstractTool
{
	public function getName(): string { return 'get_akeeba_backup_info'; }

	public function getDescription(): string
	{
		return 'Get the full record for one Akeeba Backup attempt by id. Returns the '
			. 'description, comment, start/end timestamps, status (run|fail|complete), '
			. 'origin, profile, archive filename + absolute path, multipart count, total '
			. 'size, filesexist flag, frozen flag. Use this after list_akeeba_backups when '
			. 'you need one row\'s detail.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'backup_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The backup record id (NOT the engine "backupid" tag).'],
			],
			'required'             => ['backup_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'backup_id');

		$table  = $this->db->getPrefix() . 'akeebabackup_backups';
		$tables = $this->db->getTableList();
		if (!in_array($table, $tables, true)) {
			return ToolResult::error('Akeeba Backup is not installed on this site.');
		}

		$q = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName('#__akeebabackup_backups'))
			->where($this->db->quoteName('id') . ' = ' . $id);

		$r = $this->db->setQuery($q)->loadAssoc();
		if (!$r) {
			return ToolResult::error('No Akeeba backup record found with id ' . $id);
		}

		$totalSize = (int) $r['total_size'];
		$out = [
			'id'               => (int) $r['id'],
			'description'      => (string) $r['description'],
			'comment'          => (string) $r['comment'],
			'backupstart'      => $r['backupstart'] ?: null,
			'backupend'        => $r['backupend'] ?: null,
			'status'           => (string) $r['status'],
			'origin'           => (string) $r['origin'],
			'profile_id'       => (int) $r['profile_id'],
			'archivename'      => (string) $r['archivename'],
			'absolute_path'    => (string) $r['absolute_path'],
			'multipart'        => (int) $r['multipart'],
			'total_size'       => $totalSize,
			'total_size_human' => self::humanBytes($totalSize),
			'filesexist'       => (int) $r['filesexist'] === 1,
			'frozen'           => (int) $r['frozen'] === 1,
			'tag'              => (string) ($r['tag'] ?? ''),
			'backupid'         => (string) ($r['backupid'] ?? ''),
		];

		$type = (string) $r['type'];
		if ($type !== '' && $type !== 'full') {
			$out['type'] = $type;
		}
		// remote_filename: Akeeba sometimes stores the literal string "NULL"
		// instead of a SQL NULL on records where no remote upload happened. So
		// !empty() alone isn't sufficient — also reject the literal "NULL".
		$remoteFilename = (string) ($r['remote_filename'] ?? '');
		if ($remoteFilename !== '' && strcasecmp($remoteFilename, 'NULL') !== 0) {
			$out['remote_filename'] = $remoteFilename;
		}

		return ToolResult::json($out);
	}

	private static function humanBytes(int $bytes): string
	{
		if ($bytes < 1024) {
			return $bytes . ' B';
		}
		$units = ['KB', 'MB', 'GB', 'TB'];
		$idx   = -1;
		$value = (float) $bytes;
		while ($value >= 1024 && $idx < count($units) - 1) {
			$value /= 1024;
			$idx++;
		}
		return number_format($value, 1) . ' ' . $units[$idx];
	}
}
