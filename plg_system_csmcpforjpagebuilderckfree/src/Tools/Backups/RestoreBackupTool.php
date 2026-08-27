<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Backups;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckBootTrait;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckContentTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Put a `.pbck` snapshot back into its page row.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS RESTORES, AND WHAT IT POINTEDLY DOES NOT
 * ---------------------------------------------------------------------------
 *
 * A `.pbck` is `json_encode()` of the WHOLE row (administrator/helpers/
 * pagebuilderck.php:295-300), so it carries title, alias, ordering, state,
 * catid, created_by, access, hits and params alongside `htmlcode`.
 *
 * We restore `htmlcode` and stamp `modified`. Nothing else. Two reasons:
 *
 *   - Restoring `state` would silently republish a page someone had deliberately
 *     unpublished since the snapshot was taken. "Restore the content" must never
 *     quietly mean "restore the visibility".
 *   - alias, ordering, state, catid, created_by and access are all hardcoded on
 *     every save by the vendor's own controller
 *     (administrator/controllers/page.php:59-67), so writing them here would be
 *     theatre — the values would not survive the next human Save anyway.
 *
 * `params` and `categories` are skipped for a third reason: `getItem()` inflates
 * them before the snapshot is taken (administrator/models/page.php:41-45), so
 * the file holds a JSON object and an array where the columns hold a JSON string
 * and a comma list. Writing them back verbatim would corrupt both.
 *
 * ---------------------------------------------------------------------------
 * GUARDS
 * ---------------------------------------------------------------------------
 *
 * The path is resolved with `realpath()` and must sit under the resolved backup
 * directory — no traversal, no symlink out. The file must decode to JSON with an
 * `htmlcode` key. The content is validated with `pbckValidate()` and the restore
 * is refused on any fatal, which includes the lossless round-trip check.
 *
 * The response reports a measured before/after diff rather than asserting
 * success. A restore that changed nothing says so.
 */
final class RestoreBackupTool extends AbstractTool
{
	use PagebuilderckBootTrait;
	use PagebuilderckContentTrait;

	private const FILENAME_PATTERN = '/^backup_(\d*)_(\d{1,2})-(\d{1,2})-(\d{4})-(\d{1,2})-(\d{1,2})-(\d{1,2})\.pbck$/';

	public function getName(): string { return 'restore_pagebuilderck_backup'; }

	public function getDescription(): string
	{
		return 'Restore a .pbck snapshot back into its Page Builder CK page row. '
			. 'Pass `file` exactly as list_pagebuilderck_backups returned it — a path relative to '
			. 'administrator/components/com_pagebuilderck/backup, e.g. '
			. '"12_bak/backup_12_19-02-2024-17-39-46.pbck". The path is resolved with realpath() and '
			. 'must land inside that directory; anything that escapes it is refused. '
			. 'RESTORES htmlcode AND STAMPS modified. NOTHING ELSE. The .pbck contains the whole row — '
			. 'title, alias, ordering, state, catid, created_by, access, hits, params — and this '
			. 'deliberately ignores all of it. Restoring `state` would silently republish a page someone '
			. 'unpublished after the snapshot was taken, and alias/ordering/state/catid/created_by/access '
			. 'are hardcoded by the vendor\'s own save controller on every save '
			. '(administrator/controllers/page.php:59-67) so writing them would not survive anyway. '
			. '`params` and `categories` are skipped because getItem() inflates them to an object and an '
			. 'array before the snapshot is written (administrator/models/page.php:41-45), while the '
			. 'columns hold a JSON string and a comma-separated list — writing them back verbatim would '
			. 'corrupt both. '
			. 'REMEMBER WHAT A SNAPSHOT IS: it holds the state BEFORE the save that created it, so '
			. 'restoring the newest .pbck rolls the page back one version, not zero. '
			. 'The content is validated before the write and the restore is REFUSED on any fatal — '
			. 'including markup that does not survive a parse and re-serialise byte for byte, duplicate '
			. 'block ids, a block with no data-type, a non-empty .ckprops div, or a row whose data-nb '
			. 'disagrees with its column count. Warnings are reported without refusing. '
			. 'The response gives a measured diff — bytes before and after, block and row counts before '
			. 'and after, and whether anything actually changed — rather than simply claiming success. '
			. 'Pass dry_run = true to get that diff with no write at all. '
			. 'WARNING: restoring is itself a change, and if the page is later saved through the builder '
			. 'that save writes another snapshot and pushes an older one out of the roughly six-deep '
			. 'window. Copy anything you may still need out of the backup directory first.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'file' => [
					'type'        => 'string',
					'description' => 'Path relative to the backup directory, as returned by list_pagebuilderck_backups.',
				],
				'page_id' => [
					'type'        => 'integer',
					'description' => 'Optional. When given it must agree with the id inside the snapshot and in its filename; a mismatch is refused rather than guessed at.',
				],
				'dry_run' => [
					'type'        => 'boolean',
					'description' => 'Default false. When true, everything is validated and the diff reported, but nothing is written.',
				],
			],
			'required'             => ['file'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$base = $this->pbckAdminBase();

		if ($base === null) {
			return $this->pbckNotInstalledError();
		}

		if (!$this->pbckTableExists('pages')) {
			return $this->pbckMissingTableError('pages');
		}

		$root = realpath($base . '/backup');

		if ($root === false) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'administrator/components/com_pagebuilderck/backup does not exist, so there is '
					. 'nothing to restore. Page Builder CK creates it on the first save.',
			], true);
		}

		$requested = trim((string) ($arguments['file'] ?? ''));

		if ($requested === '') {
			return ToolResult::json(['ok' => false, 'error' => 'file is required.'], true);
		}

		$resolved = $this->resolveInsideRoot($root, $requested);

		if (!\is_string($resolved)) {
			return $resolved;
		}

		$name = basename($resolved);

		if (preg_match(self::FILENAME_PATTERN, $name, $m) !== 1) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'File "' . $name . '" is not a Page Builder CK snapshot. Expected '
					. 'backup_<id>_<d-m-Y-G-i-s>.pbck. Refusing to treat an arbitrary file inside the '
					. 'backup directory as restorable content.',
			], true);
		}

		$raw = @file_get_contents($resolved);

		if ($raw === false || $raw === '') {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Snapshot "' . $requested . '" is empty or could not be read.',
			], true);
		}

		$snapshot = json_decode($raw, true);

		if (!\is_array($snapshot)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Snapshot "' . $requested . '" is not decodable JSON (' . json_last_error_msg()
					. '). Page Builder CK writes these with json_encode() and does not check the result, '
					. 'so a save that hit a JSON encoding error — most often invalid UTF-8 in the page — '
					. 'leaves a truncated or "false" file behind. This one cannot be restored.',
			], true);
		}

		if (!\array_key_exists('htmlcode', $snapshot)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Snapshot "' . $requested . '" decodes to JSON but has no htmlcode key, so it '
					. 'is not a page or element snapshot. Refusing.',
				'keys'  => array_keys($snapshot),
			], true);
		}

		$pageId = $this->resolvePageId($arguments, $snapshot, $m[1], $resolved);

		if (!\is_int($pageId)) {
			return $pageId;
		}

		$current = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([$this->db->quoteName('id'), $this->db->quoteName('title'), $this->db->quoteName('htmlcode')])
				->from($this->db->quoteName($this->pbckTable('pages')))
				->where($this->db->quoteName('id') . ' = ' . $pageId)
		)->loadAssoc();

		if (!$current) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No page with id ' . $pageId . ' in ' . $this->pbckTable('pages') . '. The '
					. 'snapshot exists but the row it belongs to has been deleted. Refusing to recreate '
					. 'a row from a snapshot — that would resurrect a page with a new id and no '
					. 'menu-item, module or ACL association.',
			], true);
		}

		// The snapshot is already tokenised by getExportFile()
		// (administrator/helpers/pagebuilderck.php:295-300), but collapse again
		// anyway: a hand-edited file, or one written before the site moved, can
		// carry an absolute root that would break the page on the next move.
		$before  = (string) ($current['htmlcode'] ?? '');
		$after   = $this->pbckCollapseRoot((string) $snapshot['htmlcode']);

		$enabledTypes = $this->pbckEnabledAddonTypes();
		$problems     = $this->pbckValidate($after, $enabledTypes);

		$diff = [
			'bytes_before'  => \strlen($before),
			'bytes_after'   => \strlen($after),
			'bytes_delta'   => \strlen($after) - \strlen($before),
			'before'        => $this->structureCounts($before, $enabledTypes),
			'after'         => $this->structureCounts($after, $enabledTypes),
			'identical'     => $before === $after,
		];

		if ($this->pbckHasFatal($problems)) {
			return ToolResult::json([
				'ok'       => false,
				'error'    => 'The snapshot content did not pass validation, so it was NOT restored. '
					. 'Writing it would have replaced a working page with markup that breaks or that we '
					. 'cannot faithfully reproduce.',
				'page_id'  => $pageId,
				'file'     => $requested,
				'problems' => $problems,
				'diff'     => $diff,
				'written'  => false,
			], true);
		}

		$warnings = array_values(array_filter(
			$problems,
			static fn (array $p): bool => ($p['severity'] ?? '') !== 'fatal'
		));

		$response = [
			'ok'      => true,
			'page_id' => $pageId,
			'title'   => (string) ($current['title'] ?? ''),
			'file'    => $requested,
			'snapshot_saved_at' => sprintf(
				'%04d-%02d-%02d %02d:%02d:%02d',
				(int) $m[4],
				(int) $m[3],
				(int) $m[2],
				(int) $m[5],
				(int) $m[6],
				(int) $m[7]
			),
			'diff'    => $diff,
			'restored_columns' => ['htmlcode', 'modified'],
			'ignored_columns'  => 'The snapshot also holds title, alias, ordering, state, catid, '
				. 'created_by, access, hits, params and categories. None of them were written. state is '
				. 'excluded so a restore can never silently republish a page; the rest are either '
				. 'hardcoded by the vendor\'s save controller on every save '
				. '(administrator/controllers/page.php:59-67) or were inflated to a different shape '
				. 'before the snapshot was written (administrator/models/page.php:41-45) and would '
				. 'corrupt their columns if written back verbatim.',
			'snapshot_semantics' => 'This file holds the state BEFORE the save that produced it, so '
				. 'restoring the newest snapshot rolls the page back one version.',
		];

		if ($warnings !== []) {
			$response['warnings'] = $warnings;
			$response['warning'] = 'The restored content has non-fatal problems. It will render, but '
				. 'something in it is wrong or inert — read the warnings array.';
		}

		if ($diff['identical']) {
			$response['written'] = false;
			$response['note']    = 'The snapshot content is byte-identical to what the page already '
				. 'holds, so nothing was written and `modified` was left alone. Reporting this rather '
				. 'than claiming a successful restore that changed nothing.';

			return ToolResult::json($response);
		}

		if (($arguments['dry_run'] ?? false) === true) {
			$response['written'] = false;
			$response['dry_run'] = true;

			return ToolResult::json($response);
		}

		$modified = Factory::getDate()->toSql();

		$this->db->setQuery(
			$this->db->getQuery(true)
				->update($this->db->quoteName($this->pbckTable('pages')))
				->set($this->db->quoteName('htmlcode') . ' = ' . $this->db->quote($after))
				->set($this->db->quoteName('modified') . ' = ' . $this->db->quote($modified))
				->where($this->db->quoteName('id') . ' = ' . $pageId)
		)->execute();

		// Read back rather than trusting the affected-row count: an UPDATE that
		// matched but wrote nothing reports 0, and MySQL in non-strict mode will
		// truncate without complaint. Never report a partial write as a success.
		$verify = (int) $this->db->setQuery(
			'SELECT LENGTH(' . $this->db->quoteName('htmlcode') . ') FROM '
			. $this->db->quoteName($this->pbckTable('pages'))
			. ' WHERE ' . $this->db->quoteName('id') . ' = ' . $pageId
		)->loadResult();

		if ($verify !== \strlen($after)) {
			return ToolResult::json([
				'ok'      => false,
				'error'   => sprintf(
					'The restore wrote %d bytes but the column now holds %d. The write was PARTIAL — '
						. 'refusing to report success. The page is now in an inconsistent state; restore '
						. 'it again or re-save it from the builder.',
					\strlen($after),
					$verify
				),
				'page_id' => $pageId,
				'file'    => $requested,
				'diff'    => $diff,
				'written' => 'partial',
			], true);
		}

		$response['written']  = true;
		$response['modified'] = $modified;
		$response['thrash_warning'] = 'The next save of this page through the builder will write another '
			. 'snapshot and push an older one out of the roughly six-deep retention window '
			. '(administrator/helpers/pagebuilderck.php:281-284). Copy anything else you may need out of '
			. 'the backup directory now.';

		return ToolResult::json($response);
	}

	/**
	 * Resolve a caller-supplied relative path and prove it is inside the backup
	 * directory.
	 *
	 * realpath() on both sides, then a prefix test on the resolved strings. That
	 * defeats `..`, an absolute path, and a symlink pointing out of the tree,
	 * which a string-level check on the input would not.
	 *
	 * @return string|ToolResult The absolute path, or the refusal to return.
	 */
	private function resolveInsideRoot(string $root, string $requested): string|ToolResult
	{
		$candidate = realpath($root . '/' . $requested);

		if ($candidate === false || !is_file($candidate)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No such snapshot: "' . $requested . '". Paths are relative to '
					. 'administrator/components/com_pagebuilderck/backup. Use '
					. 'list_pagebuilderck_backups to get a valid one.',
			], true);
		}

		$normalisedRoot = rtrim(str_replace('\\', '/', $root), '/');
		$normalisedFile = str_replace('\\', '/', $candidate);

		if (!str_starts_with($normalisedFile, $normalisedRoot . '/')) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Refusing "' . $requested . '": it resolves to ' . $normalisedFile . ', which '
					. 'is outside the Page Builder CK backup directory. This tool will only read files '
					. 'that are genuinely inside it — after symlinks and .. have been resolved.',
			], true);
		}

		return $candidate;
	}

	/**
	 * Which page row this snapshot belongs to.
	 *
	 * Three independent signals — the caller, the id inside the JSON, and the id
	 * in the filename. They must agree. A mismatch is refused rather than
	 * resolved by precedence, because guessing wrong here overwrites the content
	 * of an innocent page.
	 *
	 * @param  array<string,mixed> $arguments
	 * @param  array<string,mixed> $snapshot
	 * @return int|ToolResult
	 */
	private function resolvePageId(array $arguments, array $snapshot, string $filenameId, string $file): int|ToolResult
	{
		$candidates = [];

		if (\array_key_exists('page_id', $arguments) && $arguments['page_id'] !== null) {
			$candidates['argument'] = (int) $arguments['page_id'];
		}

		if (isset($snapshot['id']) && (is_numeric($snapshot['id']) && (int) $snapshot['id'] > 0)) {
			$candidates['snapshot'] = (int) $snapshot['id'];
		}

		if ($filenameId !== '') {
			$candidates['filename'] = (int) $filenameId;
		}

		// The containing directory is named `<id>_bak`, which is a fourth signal
		// and the only one present when a snapshot was written before its row had
		// an id.
		$dir = basename(\dirname($file));

		if (str_ends_with($dir, '_bak') && ctype_digit(substr($dir, 0, -4))) {
			$candidates['directory'] = (int) substr($dir, 0, -4);
		}

		$distinct = array_values(array_unique(array_filter($candidates, static fn (int $v): bool => $v > 0)));

		if ($distinct === []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'This snapshot carries no usable page id — not in the filename, not in the '
					. 'JSON, and not in its directory name. That is what a snapshot of a brand new, '
					. 'never-inserted page looks like (makeBackup() runs before the row exists). Pass '
					. 'page_id explicitly if you know where it belongs.',
				'candidates' => $candidates,
			], true);
		}

		if (\count($distinct) > 1) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'The page id is ambiguous — the filename, the snapshot JSON, the directory '
					. 'name and/or the page_id argument disagree. Refusing to guess, because restoring '
					. 'to the wrong row would overwrite an unrelated page\'s content.',
				'candidates' => $candidates,
			], true);
		}

		return $distinct[0];
	}

	/**
	 * Row, column and block counts for a piece of content, so the response can
	 * report a real before/after instead of a byte delta alone.
	 *
	 * @param  array<int,string> $enabledTypes
	 * @return array<string,mixed>
	 */
	private function structureCounts(string $html, array $enabledTypes): array
	{
		if ($html === '') {
			return ['rows' => 0, 'columns' => 0, 'blocks' => 0, 'block_types' => [], 'parsed' => true];
		}

		$outline = $this->pbckOutline($html, $enabledTypes);

		if (($outline['ok'] ?? false) !== true) {
			return [
				'parsed' => false,
				'reason' => (string) ($outline['reason'] ?? 'unknown'),
			];
		}

		$rows    = 0;
		$columns = 0;
		$blocks  = 0;
		$types   = [];

		$walk = function (array $rowList) use (&$walk, &$rows, &$columns, &$blocks, &$types): void {
			foreach ($rowList as $row) {
				$rows++;

				foreach ($row['columns'] ?? [] as $column) {
					$columns++;

					foreach ($column['blocks'] ?? [] as $block) {
						$blocks++;

						$type = (string) ($block['type'] ?? '');

						if ($type !== '') {
							$types[$type] = ($types[$type] ?? 0) + 1;
						}
					}

					if (($column['nested_rows'] ?? []) !== []) {
						$walk($column['nested_rows']);
					}
				}
			}
		};

		$walk($outline['rows'] ?? []);

		ksort($types);

		return [
			'parsed'      => true,
			'rows'        => $rows,
			'columns'     => $columns,
			'blocks'      => $blocks,
			'block_types' => $types,
		];
	}
}
