<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Backups;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckBootTrait;
use Joomla\CMS\User\User;

/**
 * List the `.pbck` snapshots Page Builder CK writes on every save.
 *
 * This is free version history that nothing in the vendor's UI surfaces. On
 * EVERY save — Light as well as Pro — `PagebuilderckHelper::makeBackup()`
 * (administrator/helpers/pagebuilderck.php:263-288) JSON-encodes the WHOLE row
 * and writes it to
 *
 *     administrator/components/com_pagebuilderck/backup/<id>_bak/backup_<id>_<d-m-Y-G-i-s>.pbck
 *
 * with the site root re-collapsed to `|URIROOT|` on the way out
 * (`getExportFile()`, :295-300).
 *
 * ---------------------------------------------------------------------------
 * TWO THINGS THAT SURPRISE PEOPLE
 * ---------------------------------------------------------------------------
 *
 * 1. THE SNAPSHOT IS THE STATE *BEFORE* THE SAVE. `makeBackup($this->getItem())`
 *    runs at administrator/models/page.php:106, two lines before the
 *    `CKFof::dbStore()` at :108, and `getItem()` reads from the database. So a
 *    file stamped 18:55 holds what the page looked like at 18:54, not the result
 *    of the 18:55 save. The most recent `.pbck` is the previous version, never
 *    the current one.
 *
 * 2. THE FILENAME IS NOT LEXICOGRAPHICALLY SORTABLE. The stamp is
 *    `date("d-m-Y-G-i-s")`: day first, and `G` is the hour with NO leading zero.
 *    Sorting these by name is wrong twice over — day-first ignores month and
 *    year, and an unpadded hour puts "10" before "9". We measured it: a plain
 *    `sort()` on these stamps orders 10:05 ahead of 09:55. The vendor's own
 *    prune only escapes this because it happens to reach for `natsort()`
 *    (:316), which compares digit runs numerically. Nothing else that reads this
 *    directory gets that for free, so this tool parses the stamp into a real
 *    timestamp and sorts on that.
 *
 * @see RestoreBackupTool for putting one back.
 */
final class ListBackupsTool extends AbstractTool
{
	use PagebuilderckBootTrait;

	/**
	 * `backup_<id>_<d>-<m>-<Y>-<G>-<i>-<s>.pbck`.
	 *
	 * The id group is `\d*` and not `\d+` on purpose: saving a brand new item
	 * runs the backup before the row has an id, producing a `_bak` directory and
	 * a `backup__…` filename with an empty id. The Light package ships one
	 * (administrator/backup/_bak/backup__27-12-2023-9-54-24.pbck), so this is
	 * real output, not a hypothetical.
	 */
	private const FILENAME_PATTERN = '/^backup_(\d*)_(\d{1,2})-(\d{1,2})-(\d{4})-(\d{1,2})-(\d{1,2})-(\d{1,2})\.pbck$/';

	public function getName(): string { return 'list_pagebuilderck_backups'; }

	public function getDescription(): string
	{
		return 'List the .pbck snapshots Page Builder CK writes automatically on every save. '
			. 'This is version history the vendor\'s UI never shows you. On every save — in the Light '
			. '(free) build as well as Pro — PagebuilderckHelper::makeBackup() '
			. '(administrator/helpers/pagebuilderck.php:263-288) JSON-encodes the entire row and writes '
			. 'it to administrator/components/com_pagebuilderck/backup/<id>_bak/'
			. 'backup_<id>_<d-m-Y-G-i-s>.pbck, with the site root re-collapsed to the |URIROOT| token. '
			. 'CRITICAL SEMANTIC: the snapshot holds the state BEFORE that save, not after it. '
			. 'makeBackup() runs at administrator/models/page.php:106 and reads the row from the '
			. 'database; the write happens two lines later at :108. So the newest .pbck is the PREVIOUS '
			. 'version of the page, never the current one. '
			. 'Pass page_id for one page, or omit it for every page. Pass subfolder to reach the other '
			. 'things that back up here: "myelements" for saved elements '
			. '(administrator/models/element.php:89) and "contenttype.<type>" for content types '
			. '(administrator/models/contenttype.php:116). '
			. 'The timestamp is parsed out of the filename and everything is sorted on that, because the '
			. 'filenames are NOT lexicographically sortable: the stamp is day-first and its hour field '
			. 'comes from date("G"), which has no leading zero, so a plain sort() puts 10:05 ahead of '
			. '09:55. Never order these by name. '
			. 'RETENTION WARNING: the vendor prunes only when a directory already holds more than five '
			. 'files and then deletes exactly one (:281-284), so a busy page settles at six, not five. '
			. 'The prune identifies the oldest by string surgery on the filename '
			. '(deleteOldestBackup(), :307-322) — it strips the prefix and extension, reorders the date '
			. 'fields, natsorts, and rebuilds a filename to delete. Anything in the directory that is not '
			. 'a matching backup filename survives that surgery as garbage, sorts to the front as the '
			. '"oldest", and causes the prune to delete a path that does not exist — leaving the real '
			. 'files to accumulate. Two saves in the same second overwrite each other outright, because '
			. 'the stamp resolves only to seconds. '
			. 'THRASH WARNING: every programmatic save writes another snapshot and pushes an older one '
			. 'out of the five-deep window. A loop that saves a page repeatedly will destroy the whole '
			. 'backup history for that page within six iterations. Copy anything you care about out '
			. 'first. '
			. 'Read-only — lists and parses, never writes or deletes.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'page_id' => [
					'type'        => 'integer',
					'description' => 'Restrict to one page id. Omit to list every backup directory.',
				],
				'subfolder' => [
					'type'        => 'string',
					'description' => 'Backup scope. Omit for pages. "myelements" for saved elements, "contenttype.<type>" for content types.',
				],
				'limit' => [
					'type'        => 'integer',
					'description' => 'Maximum files returned. Default 50, max 200.',
				],
				'offset' => [
					'type'        => 'integer',
					'description' => 'Files to skip, after sorting newest first.',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$base = $this->pbckAdminBase();

		if ($base === null) {
			return $this->pbckNotInstalledError();
		}

		$root = $base . '/backup';

		if (!is_dir($root)) {
			return ToolResult::json([
				'ok'        => true,
				'total'     => 0,
				'backups'   => [],
				'note'      => 'administrator/components/com_pagebuilderck/backup does not exist. Page '
					. 'Builder CK creates it on the first save, so nothing has been saved through the '
					. 'builder on this site yet. No item has any automatic snapshot.',
				'component' => $this->pbckEditionNotice(),
			]);
		}

		$subfolder = trim((string) ($arguments['subfolder'] ?? ''));

		if ($subfolder !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $subfolder) !== 1) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'subfolder may contain only letters, digits, dots, underscores and hyphens. '
					. 'Refusing anything that could reach outside the backup directory.',
			], true);
		}

		if (str_contains($subfolder, '..')) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'subfolder must not contain "..".',
			], true);
		}

		$scanRoot = $subfolder === '' ? $root : $root . '/' . $subfolder;

		if (!is_dir($scanRoot)) {
			return ToolResult::json([
				'ok'      => true,
				'total'   => 0,
				'backups' => [],
				'note'    => 'No backup directory for scope "' . ($subfolder === '' ? 'pages' : $subfolder)
					. '". Nothing of that kind has been saved on this site.',
				'available_scopes' => $this->availableScopes($root),
				'component' => $this->pbckEditionNotice(),
			]);
		}

		$hasPageId = \array_key_exists('page_id', $arguments) && $arguments['page_id'] !== null;
		$pageId    = $hasPageId ? (int) $arguments['page_id'] : null;

		$directories = $this->backupDirectories($scanRoot, $pageId);

		$files      = [];
		$dirReports = [];

		foreach ($directories as $itemId => $dirPath) {
			$entries = @scandir($dirPath);

			if ($entries === false) {
				$dirReports[] = [
					'item_id' => $itemId,
					'error'   => 'Directory could not be read.',
				];

				continue;
			}

			$parsed    = 0;
			$unparsed  = [];

			foreach ($entries as $name) {
				if ($name === '.' || $name === '..' || !is_file($dirPath . '/' . $name)) {
					continue;
				}

				$file = $this->describeFile($root, $dirPath, $name, $itemId);

				if ($file === null) {
					$unparsed[] = $name;

					continue;
				}

				$parsed++;
				$files[] = $file;
			}

			$report = [
				'item_id'     => $itemId,
				'directory'   => $this->relative($root, $dirPath),
				'backup_files' => $parsed,
			];

			// The prune reconstructs a filename from whatever it finds. A file it
			// cannot parse yields a nonsense name, sorts to the front as the
			// "oldest", and the delete then silently misses. See the class docblock.
			if ($unparsed !== []) {
				$report['unrecognised_files'] = $unparsed;
				$report['prune_warning'] = 'This directory contains file(s) that do not match '
					. 'backup_<id>_<d-m-Y-G-i-s>.pbck. deleteOldestBackup() '
					. '(administrator/helpers/pagebuilderck.php:307-322) runs string surgery on every '
					. 'filename it finds and rebuilds a name to delete; a non-matching file mangles into '
					. 'a string that sorts first, is picked as the "oldest", and then does not exist when '
					. 'the delete runs. While these files are here the prune is a no-op and snapshots '
					. 'will accumulate without limit.';
			}

			if ($parsed > 6) {
				$report['retention_warning'] = sprintf(
					'%d backup files. The vendor prunes only when the count already exceeds five and then '
						. 'removes exactly one per save, so a healthy directory sits at five or six. More '
						. 'than that means the prune is not firing — usually because of an unrecognised '
						. 'file in the directory.',
					$parsed
				);
			}

			$dirReports[] = $report;
		}

		// Sort on the parsed timestamp, never on the filename. sort_key is null
		// only when the stamp was unparseable, which describeFile() rejects, so
		// this is total.
		usort($files, static fn (array $a, array $b): int => $b['sort_key'] <=> $a['sort_key']);

		$total  = \count($files);
		$limit  = $this->pbckLimit($arguments);
		$offset = $this->pbckOffset($arguments);
		$page   = \array_slice($files, $offset, $limit);

		foreach ($page as &$entry) {
			unset($entry['sort_key']);
		}

		unset($entry);

		$response = [
			'ok'      => true,
			'scope'   => $subfolder === '' ? 'pages' : $subfolder,
			'total'   => $total,
			'limit'   => $limit,
			'offset'  => $offset,
			'showing' => \count($page),
			'backups' => $page,
			'directories' => $dirReports,
			'snapshot_semantics' => 'Each file holds the state of the row BEFORE the save that created '
				. 'it. makeBackup() reads the row and writes the file at '
				. 'administrator/models/page.php:106; the database write happens at :108. The newest '
				. 'snapshot is therefore the previous version, not the current one.',
			'ordering_note' => 'Sorted newest first on the timestamp parsed out of each filename. Do not '
				. 'sort these filenames as strings — the stamp is date("d-m-Y-G-i-s"), which is day-first '
				. 'and whose hour has no leading zero, so a lexical sort puts 10:05 ahead of 09:55.',
			'retention_note' => 'Page Builder CK deletes one file per save, and only once a directory '
				. 'already holds more than five (administrator/helpers/pagebuilderck.php:281-284), so a '
				. 'page keeps roughly six versions. Frequent programmatic saves will thrash this '
				. 'directory and can wipe a page\'s entire history in six saves. Two saves inside the '
				. 'same second overwrite each other, because the filename stamp resolves only to seconds.',
			'component' => $this->pbckEditionNotice(),
		];

		if ($subfolder === '') {
			$response['available_scopes'] = $this->availableScopes($root);
		}

		if ($hasPageId && $directories === []) {
			$response['note'] = 'No backup directory for id ' . $pageId . '. That item has never been '
				. 'saved through Page Builder CK, or its directory has been removed.';
		}

		return ToolResult::json($response);
	}

	/**
	 * `<id>_bak` directories under `$scanRoot`, optionally narrowed to one id.
	 *
	 * @return array<string,string> item id (as written in the directory name) => absolute path
	 */
	private function backupDirectories(string $scanRoot, ?int $pageId): array
	{
		if ($pageId !== null) {
			$path = $scanRoot . '/' . $pageId . '_bak';

			return is_dir($path) ? [(string) $pageId => $path] : [];
		}

		$entries = @scandir($scanRoot);

		if ($entries === false) {
			return [];
		}

		$out = [];

		foreach ($entries as $name) {
			if ($name === '.' || $name === '..' || !is_dir($scanRoot . '/' . $name)) {
				continue;
			}

			if (!str_ends_with($name, '_bak')) {
				continue; // a scope directory such as myelements, not a backup set
			}

			$out[substr($name, 0, -4)] = $scanRoot . '/' . $name;
		}

		ksort($out, SORT_NATURAL);

		return $out;
	}

	/**
	 * Scope directories present under the backup root.
	 *
	 * @return array<int,string>
	 */
	private function availableScopes(string $root): array
	{
		$entries = @scandir($root);

		if ($entries === false) {
			return [];
		}

		$out = [];

		foreach ($entries as $name) {
			if ($name === '.' || $name === '..' || !is_dir($root . '/' . $name)) {
				continue;
			}

			if (str_ends_with($name, '_bak')) {
				continue;
			}

			$out[] = $name;
		}

		sort($out);

		return $out;
	}

	/**
	 * Parse one filename into a described backup, or null when it is not one.
	 *
	 * @return array<string,mixed>|null
	 */
	private function describeFile(string $root, string $dirPath, string $name, string $itemId): ?array
	{
		if (preg_match(self::FILENAME_PATTERN, $name, $m) !== 1) {
			return null;
		}

		[, $fileId, $day, $month, $year, $hour, $minute, $second] = $m;

		$timestamp = sprintf(
			'%04d-%02d-%02d %02d:%02d:%02d',
			(int) $year,
			(int) $month,
			(int) $day,
			(int) $hour,
			(int) $minute,
			(int) $second
		);

		$path  = $dirPath . '/' . $name;
		$bytes = (int) @filesize($path);
		$mtime = (int) @filemtime($path);

		$entry = [
			'file'        => $this->relative($root, $path),
			'filename'    => $name,
			'item_id'     => $fileId === '' ? null : (int) $fileId,
			'directory_id' => $itemId === '' ? null : (int) $itemId,
			'saved_at'    => $timestamp,
			'bytes'       => $bytes,
			'mtime'       => $mtime > 0 ? date('Y-m-d H:i:s', $mtime) : null,
			// Sorted on, then stripped before the response goes out.
			'sort_key'    => $timestamp,
		];

		if ($fileId === '') {
			$entry['warning'] = 'This snapshot was written with an empty id, which happens when a brand '
				. 'new item is saved and makeBackup() runs before the row has been inserted. Its contents '
				. 'are the empty template, not a real previous version.';
		}

		if ($bytes === 0) {
			$entry['warning'] = 'This snapshot is zero bytes. It cannot be restored.';
		}

		return $entry;
	}

	/** Path relative to the backup root, with forward slashes, for use as a handle. */
	private function relative(string $root, string $path): string
	{
		$root = str_replace('\\', '/', $root);
		$path = str_replace('\\', '/', $path);

		return str_starts_with($path, $root . '/') ? substr($path, \strlen($root) + 1) : $path;
	}
}
