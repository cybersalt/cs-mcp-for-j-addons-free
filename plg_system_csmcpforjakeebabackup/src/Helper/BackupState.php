<?php
/**
 * @package     MCP for Akeeba Backup Core
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjakeebabackup\Helper;

\defined('_JEXEC') or die;

/**
 * Where a backup record's archive actually is, in words an AI can repeat.
 *
 * Akeeba's `filesexist` means "the archive is on THIS server". When a profile
 * uploads to remote storage (Google Drive, S3, Dropbox…) and deletes the local
 * copy, which is the usual setup, every good backup has filesexist = 0 and its
 * copy is named in `remote_filename`. Reported raw, that reads as "the latest
 * backups are missing", while the only record with a local file can be a
 * failed one. Seen on a customer site (esportealternativo.com.br, 2026-11-02):
 * five complete daily backups, all filesexist = 0, four on Google Drive; the one
 * record with filesexist = 1 was a failed backup. addons-free#2.
 */
final class BackupState
{
	public const ON_SERVER          = 'on_server';
	public const ON_SERVER_AND_REMOTE = 'on_server_and_remote';
	public const REMOTE_ONLY        = 'remote_only';
	public const NO_COPY_FOUND      = 'no_copy_found';
	public const RUNNING            = 'running';
	public const FAILED             = 'failed';

	/** A record still marked "run" after this long was almost certainly abandoned. */
	private const STALLED_AFTER_SECONDS = 6 * 3600;

	/** Akeeba sometimes stores the literal string "NULL" when no upload happened. */
	public static function remoteFilename(array $row): string
	{
		$remote = trim((string) ($row['remote_filename'] ?? ''));

		return strcasecmp($remote, 'NULL') === 0 ? '' : $remote;
	}

	/**
	 * @return array{stored: string, restorable: bool, stored_note: string}
	 */
	public static function describe(array $row): array
	{
		$status = (string) ($row['status'] ?? '');
		$local  = (int) ($row['filesexist'] ?? 0) === 1;
		$remote = self::remoteFilename($row);

		if ($status === 'run') {
			$start   = strtotime((string) ($row['backupstart'] ?? '') . ' UTC') ?: 0;
			$stalled = $start > 0 && time() - $start > self::STALLED_AFTER_SECONDS;

			return [
				'stored'      => self::RUNNING,
				'restorable'  => false,
				'stored_note' => $stalled
					? 'Marked as running, but it started more than 6 hours ago: it was almost certainly interrupted and is not a usable backup.'
					: 'Still running. It is not a usable backup until its status is complete.',
			];
		}

		if ($status === 'fail') {
			return [
				'stored'      => self::FAILED,
				'restorable'  => false,
				'stored_note' => $local
					? 'Failed. The file left on the server is a partial archive, not a usable backup.'
					: 'Failed. There is no usable archive.',
			];
		}

		if ($local && $remote !== '') {
			return ['stored' => self::ON_SERVER_AND_REMOTE, 'restorable' => true,
				'stored_note' => 'Complete. The archive is on this server and also in remote storage (' . $remote . ').'];
		}

		if ($local) {
			return ['stored' => self::ON_SERVER, 'restorable' => true,
				'stored_note' => 'Complete. The archive is on this server.'];
		}

		if ($remote !== '') {
			return ['stored' => self::REMOTE_ONLY, 'restorable' => true,
				'stored_note' => 'Complete. The archive was uploaded to remote storage (' . $remote . ') and the copy on this '
					. 'server was removed, which is the normal result of a profile that deletes local files after upload. '
					. 'filesexist=false only means "not on this server"; this is a good backup.'];
		}

		return ['stored' => self::NO_COPY_FOUND, 'restorable' => false,
			'stored_note' => 'Completed, but no copy is recorded anywhere: not on this server (deleted, or removed by a quota) '
				. 'and no remote storage name. Check the remote storage by hand before relying on it.'];
	}
}
