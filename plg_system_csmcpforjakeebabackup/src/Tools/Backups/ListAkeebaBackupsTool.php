<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjakeebabackup\Tools\Backups;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Lists Akeeba Backup records (#__akeebabackup_backups).
 *
 * Akeeba calls these "statistics" internally (cf. StatisticsModel). Each row
 * is one backup attempt: when it ran, what profile, where the archive lives,
 * how big, whether it succeeded. The schema is:
 *
 *   id           — backup record PK
 *   description  — human-entered description (or auto-generated)
 *   comment      — optional notes
 *   backupstart  — when the engine kicked off this attempt
 *   backupend    — when it finished (NULL if still running)
 *   status       — 'run' | 'fail' | 'complete'
 *   origin       — 'backend' | 'frontend' | 'cli' | 'json' | etc.
 *   type         — 'full' (Core only supports full; Pro adds incremental)
 *   profile_id   — which profile drove this backup
 *   archivename  — final archive filename
 *   absolute_path — full on-disk path to the archive
 *   multipart    — number of parts (0 = single file)
 *   total_size   — bytes
 *   filesexist   — 1 if archive still on disk, 0 if deleted/pruned
 *   frozen       — 1 if "frozen" (pruning skips it)
 */
final class ListAkeebaBackupsTool extends AbstractTool
{
	private const ALLOWED_STATUS = ['run', 'fail', 'complete'];

	public function getName(): string { return 'list_akeeba_backups'; }

	public function getDescription(): string
	{
		return 'List Akeeba Backup records (Akeeba calls these "backup statistics"). '
			. 'Each entry is one backup attempt — when it ran, which profile, status '
			. '(run/fail/complete), origin, archive filename + path, size, multipart '
			. 'count, whether the archive still exists on disk. Sorted newest-first. '
			. 'Optional filters: status (run|fail|complete), origin (backend|frontend|'
			. 'cli|json|…), profile_id, only_existing (true to drop pruned backups). '
			. 'Capped at 100 rows; pass limit/offset for paging.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'status'         => [
					'type'        => 'string',
					'enum'        => self::ALLOWED_STATUS,
					'description' => 'Filter to backups in this status.',
				],
				'origin'         => [
					'type'        => 'string',
					'description' => 'Filter to backups taken from this origin (backend, frontend, cli, json, …).',
				],
				'profile_id'     => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Filter to backups taken under this profile.',
				],
				'only_existing'  => [
					'type'        => 'boolean',
					'description' => 'If true, only return backups whose archive still exists on disk (filesexist=1).',
				],
				'limit'          => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => 'Page size (default 25, max 100).',
				],
				'offset'         => [
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'Pagination offset.',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$table = $this->db->getPrefix() . 'akeebabackup_backups';
		$tables = $this->db->getTableList();
		if (!in_array($table, $tables, true)) {
			return ToolResult::error(
				'Akeeba Backup is not installed on this site (table ' . $table . ' missing). '
				. 'Install com_akeebabackup from https://www.akeeba.com/download/akeeba-backup.html.'
			);
		}

		$limit  = max(1, min(100, (int) ($arguments['limit'] ?? 25)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));

		$q = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('id'),
				$this->db->quoteName('description'),
				$this->db->quoteName('comment'),
				$this->db->quoteName('backupstart'),
				$this->db->quoteName('backupend'),
				$this->db->quoteName('status'),
				$this->db->quoteName('origin'),
				$this->db->quoteName('type'),
				$this->db->quoteName('profile_id'),
				$this->db->quoteName('archivename'),
				$this->db->quoteName('absolute_path'),
				$this->db->quoteName('multipart'),
				$this->db->quoteName('total_size'),
				$this->db->quoteName('filesexist'),
				$this->db->quoteName('frozen'),
				$this->db->quoteName('remote_filename'),
			])
			->from($this->db->quoteName('#__akeebabackup_backups'))
			->order($this->db->quoteName('id') . ' DESC');

		if (!empty($arguments['status'])) {
			$status = (string) $arguments['status'];
			if (!in_array($status, self::ALLOWED_STATUS, true)) {
				throw new \InvalidArgumentException('status must be one of: ' . implode(', ', self::ALLOWED_STATUS));
			}
			$q->where($this->db->quoteName('status') . ' = ' . $this->db->quote($status));
		}

		if (!empty($arguments['origin'])) {
			$origin = trim((string) $arguments['origin']);
			$q->where($this->db->quoteName('origin') . ' = ' . $this->db->quote($origin));
		}

		if (!empty($arguments['profile_id'])) {
			$q->where($this->db->quoteName('profile_id') . ' = ' . (int) $arguments['profile_id']);
		}

		if (!empty($arguments['only_existing'])) {
			$q->where($this->db->quoteName('filesexist') . ' = 1');
		}

		$rows = $this->db->setQuery($q, $offset, $limit)->loadAssocList() ?: [];

		$out = array_map(static function (array $r): array {
			$totalSize = (int) $r['total_size'];
			$row = [
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
			];

			// Pro-only columns: only surface when they carry non-default data,
			// so a clean Core install's response doesn't visually advertise
			// features the operator can't use.
			//   - type='incremental'|'differential' → Pro. 'full' (Core default) hidden.
			//   - remote_filename populated → Pro cloud post-processing ran.
			$type = (string) $r['type'];
			if ($type !== '' && $type !== 'full') {
				$row['type'] = $type;
			}
			// remote_filename: Akeeba sometimes stores the literal string "NULL"
			// when a backup is marked as "no remote storage" (seen on
			// mcpfree.basicjoomla.com 2026-06-12 on a failed backup record). So
			// !empty() alone isn't enough — also drop the literal "NULL" string.
			$remoteFilename = (string) ($r['remote_filename'] ?? '');
			if ($remoteFilename !== '' && strcasecmp($remoteFilename, 'NULL') !== 0) {
				$row['remote_filename'] = $remoteFilename;
			}

			return $row;
		}, $rows);

		return ToolResult::json([
			'count'   => count($out),
			'limit'   => $limit,
			'offset'  => $offset,
			'backups' => $out,
		]);
	}

	/**
	 * "12.3 MB" instead of "12894569" so Claude's text replies to the user
	 * don't bury them in raw byte counts.
	 */
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
