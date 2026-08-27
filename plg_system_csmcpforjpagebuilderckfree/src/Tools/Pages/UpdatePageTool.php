<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Pages;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckBootTrait;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckContentTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Update an existing row in `#__pagebuilderck_pages`.
 *
 * Only the columns actually supplied are written; `modified` is always stamped,
 * because a page whose content changed while its modified date did not is a page
 * nobody can reason about afterwards.
 *
 * The check-out guard is real rather than decorative. `checked_out` on this table
 * is a varchar holding a user id, set by `CKModel::checkout()` and cleared only
 * by that same user saving or cancelling — Page Builder CK ships no global
 * check-in anywhere in its UI. So a set `checked_out` genuinely means someone has
 * the page open in the builder, and their eventual Save will overwrite whatever
 * is written here from the copy loaded into their browser. Writing underneath
 * them is silent data loss with a delay fuse, which is why it takes `force`.
 *
 * One thing this tool cannot do for you: Page Builder CK snapshots the page to
 * `administrator/components/com_pagebuilderck/backup/<id>_bak/*.pbck` before
 * every editor save, keeping the last five. That snapshot is taken by the
 * vendor's model, not by the table, so a direct write does not produce one. Read
 * the page first if you want a restore point.
 */
final class UpdatePageTool extends AbstractTool
{
	use PagebuilderckBootTrait;
	use PagebuilderckContentTrait;

	/** What `controllers/page.php:59-67` hardcodes into every builder save. */
	private const EDITOR_WRITES = [
		'alias'      => '',
		'ordering'   => 0,
		'state'      => 1,
		'catid'      => '',
		'created_by' => 0,
		'access'     => 1,
	];

	public function getName(): string { return 'update_pagebuilderck_page'; }

	public function getDescription(): string
	{
		return 'Update an existing Page Builder CK page in #__pagebuilderck_pages. Requires `id`. Only '
			. 'the columns you actually supply are written; `modified` is always updated. Supported '
			. 'columns: title, htmlcode, catid, categories, access, state, featured, params. '
			. 'REFUSES if the page is checked out by a different user, unless you pass force: true. '
			. 'checked_out on this table is a varchar user id that Page Builder CK clears only when that '
			. 'same user saves or cancels — there is no global check-in — so a set value means someone '
			. 'has the page open in the builder and their Save will overwrite anything written here. '
			. 'force: true writes anyway and says so in the response. '
			. 'If htmlcode is supplied it is validated first. The site root is collapsed to |URIROOT| for '
			. 'you. Content that does not survive a parse/serialise round-trip byte for byte, or that has '
			. 'any FATAL structural problem (a block with no data-type, duplicated ids, a row whose '
			. 'data-nb disagrees with its column count, a non-empty .ckprops div) is REFUSED and NOTHING '
			. 'is written — not even the columns that were fine. Warnings are reported without blocking. '
			. 'htmlcode REPLACES the whole page: there is no partial content write here, so read the page '
			. 'with get_pagebuilderck_page (raw: true) first and send back the edited whole. '
			. 'WARNING: Page Builder CK\'s save controller hardcodes alias, ordering, state, catid, '
			. 'created_by and access on every save, so values written here for those columns survive only '
			. 'until the next time a human saves the page in the builder. The response names the ones at '
			. 'risk. '
			. 'This tool does NOT create a .pbck backup. Page Builder CK makes one only on its own editor '
			. 'save. '
			. 'The table is utf8mb3, so emoji in the title are refused up front rather than left to fail '
			. 'in MySQL.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'         => ['type' => 'integer', 'description' => 'Page id. Required.'],
				'title'      => ['type' => 'string', 'description' => 'New title. Max 255 bytes, no emoji (the table is utf8mb3).'],
				'htmlcode'   => ['type' => 'string', 'description' => 'Replacement page content as raw Page Builder CK HTML. Replaces the whole page. The site root is tokenised to |URIROOT| for you.'],
				'catid'      => ['type' => 'string', 'description' => 'The raw catid varchar. Vestigial: the builder blanks it on save.'],
				'categories' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Category ids, replacing the existing list. This is what the admin listing filters on.'],
				'access'     => ['type' => 'integer', 'description' => 'Joomla view level id.'],
				'state'      => ['type' => 'string', 'description' => 'published | unpublished | trashed | archived, or the integer.'],
				'featured'   => ['type' => 'boolean', 'description' => 'Mark or unmark the page as featured.'],
				'params'     => ['type' => 'object', 'description' => 'Page options object, stored as JSON in `params`. Replaces the existing value wholesale.'],
				'force'      => ['type' => 'boolean', 'description' => 'Write even when the page is checked out by another user. Default false. Their next Save in the builder will still overwrite this.'],
			],
			'required' => ['id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->pbckAdminBase() === null) {
			return $this->pbckNotInstalledError();
		}

		if (!$this->pbckTableExists('pages')) {
			return $this->pbckMissingTableError('pages');
		}

		$id    = $this->requirePositiveInt($arguments, 'id');
		$force = (bool) ($arguments['force'] ?? false);

		$existing = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('id'),
					$this->db->quoteName('title'),
					$this->db->quoteName('state'),
					$this->db->quoteName('checked_out'),
					'LENGTH(' . $this->db->quoteName('htmlcode') . ') AS htmlcode_bytes',
				])
				->from($this->db->quoteName($this->pbckTable('pages')))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();

		if (!\is_array($existing)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No Page Builder CK page with id ' . $id . '. Nothing was written.',
			], true);
		}

		$checkedOut = $this->pbckCheckedOutBy($existing);

		if ($checkedOut !== null && $checkedOut !== (int) $actor->id && !$force) {
			return ToolResult::json([
				'ok'             => false,
				'error'          => 'Refusing to update page ' . $id . ': it is checked out by user '
					. $checkedOut . '. That user has it open in Page Builder CK, and when they save, the '
					. 'copy in their browser overwrites the whole row — so anything written now would be '
					. 'lost without warning at an unpredictable moment.',
				'checked_out_by' => $checkedOut,
				'resolution'     => 'Wait for them to save or cancel, or pass force: true to write anyway '
					. 'in full knowledge that their Save will win. Page Builder CK has no global check-in, '
					. 'so if the session was abandoned this value will never clear on its own.',
			], true);
		}

		// --- assemble the update ---------------------------------------------
		$data     = [];
		$warnings = [];

		if (\array_key_exists('title', $arguments)) {
			$title = trim((string) $arguments['title']);

			if ($title === '') {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'title was supplied but is empty. Refusing — an untitled page is '
						. 'unfindable in the admin listing. Omit title to leave it unchanged.',
				], true);
			}

			if (($refusal = $this->assertStorable($title, 'title', 255)) !== null) {
				return $refusal;
			}

			$data['title'] = $title;
		}

		if (\array_key_exists('catid', $arguments)) {
			$catid = (string) $arguments['catid'];

			if (($refusal = $this->assertStorable($catid, 'catid', 255)) !== null) {
				return $refusal;
			}

			$data['catid'] = $catid;
		}

		if (\array_key_exists('categories', $arguments)) {
			$categories = $this->joinIdList($arguments['categories']);

			if (($refusal = $this->assertStorable($categories, 'categories', 255)) !== null) {
				return $refusal;
			}

			$data['categories'] = $categories;
		}

		if (\array_key_exists('access', $arguments)) {
			$data['access'] = (int) $arguments['access'];
		}

		if (\array_key_exists('featured', $arguments)) {
			$data['featured'] = (bool) $arguments['featured'] ? 1 : 0;
		}

		if (\array_key_exists('state', $arguments)) {
			$state = $this->pbckNormaliseState($arguments['state']);

			if ($state === null) {
				return ToolResult::json([
					'ok'    => false,
					'error' => sprintf(
						'Unrecognised state "%s". Use published, unpublished, trashed, archived, or the '
							. 'integer 1, 0, -2, 2.',
						(string) $arguments['state']
					),
				], true);
			}

			$data['state'] = $state;
		}

		if (\array_key_exists('params', $arguments)) {
			$params = $this->encodeParams($arguments['params']);

			if ($params instanceof ToolResult) {
				return $params;
			}

			$data['params'] = $params;
		}

		if (\array_key_exists('htmlcode', $arguments)) {
			// Collapse first so both guards see the bytes that would actually land
			// in the column.
			$htmlcode = $this->pbckCollapseRoot((string) $arguments['htmlcode']);

			if (!$this->pbckIsLossless($htmlcode)) {
				return ToolResult::json([
					'ok'       => false,
					'error'    => 'Refusing to write this content: it does not survive a parse/serialise '
						. 'round-trip byte for byte, so this add-on cannot guarantee the page it stores is '
						. 'the page you sent. NOTHING was written — not even the other columns in this '
						. 'call.',
					'lossless' => false,
					'hint'     => 'If this content came from get_pagebuilderck_page, check whether that '
						. 'call already reported lossless: false. A page can arrive in that state through '
						. 'markup pasted in from outside the builder; re-saving it once in Page Builder '
						. 'CK\'s own editor rewrites it in the parser\'s dialect.',
				], true);
			}

			$problems = $this->pbckValidate($htmlcode, $this->pbckEnabledAddonTypes());

			if ($this->pbckHasFatal($problems)) {
				return ToolResult::json([
					'ok'       => false,
					'error'    => 'Refusing to update page ' . $id . ': the supplied htmlcode has fatal '
						. 'structural problems that would visibly break the rendered page. NOTHING was '
						. 'written — not even the other columns in this call.',
					'problems' => $problems,
				], true);
			}

			if ($problems !== []) {
				$warnings['content'] = $problems;
			}

			$data['htmlcode'] = $htmlcode;
		}

		if ($data === []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Nothing to update. Supply at least one of: title, htmlcode, catid, '
					. 'categories, access, state, featured, params. `modified` alone is not written, '
					. 'because stamping a page as changed when it was not is worse than doing nothing.',
			], true);
		}

		$now              = Factory::getDate()->toSql();
		$data['modified'] = $now;

		$object     = new \stdClass();
		$object->id = $id;

		foreach ($data as $column => $value) {
			$object->{$column} = $value;
		}

		$this->db->updateObject($this->pbckTable('pages'), $object, 'id');

		$response = [
			'ok'              => true,
			'id'              => $id,
			'updated_columns' => array_keys($data),
			'modified'        => $now,
		];

		if (\array_key_exists('htmlcode', $data)) {
			$response['htmlcode_bytes']        = \strlen((string) $data['htmlcode']);
			$response['htmlcode_bytes_before'] = (int) $existing['htmlcode_bytes'];
			$response['style_ids']             = $this->pbckGetPageStyleIds((string) $data['htmlcode']);
			$response['content_note'] = 'htmlcode was replaced in full. Style association lives in '
				. 'div.pagebuilderckparams[data-styles] inside the content, not in a column, so replacing '
				. 'the content also replaces which styles the page uses.';
		}

		if ($warnings !== []) {
			$response['warnings'] = $warnings;
			$response['warnings_note'] = 'These did not block the write. A block whose addon plugin is '
				. 'disabled still renders — Page Builder CK fails open and emits the inner markup as '
				. 'static HTML with no error — but its interactive behaviour is gone.';
		}

		if ($checkedOut !== null && $checkedOut !== (int) $actor->id) {
			$response['forced'] = 'Written with force: true while page ' . $id . ' was checked out by '
				. 'user ' . $checkedOut . '. If that user still has the builder open, their next Save '
				. 'will overwrite everything written here with the copy in their browser.';
		}

		$clobberNotice = $this->pbckEditorClobberNotice($this->columnsAtRisk($data));

		if ($clobberNotice !== null) {
			$response['editor_clobber'] = $clobberNotice;
		}

		$hazards = $this->pbckNumericHazards(array_intersect_key(
			$data,
			['title' => true, 'catid' => true, 'categories' => true]
		));

		if ($hazards !== []) {
			$response['numeric_hazards'] = $hazards;
			$response['numeric_hazards_note'] = 'This UPDATE quoted the values properly and stored them '
				. 'as given. The hazard is the NEXT save through Page Builder CK itself: CKFof::dbStore() '
				. 'does `is_numeric($v) ? (int) $v : quote($v)` on UPDATE, so these varchar values will '
				. 'change the moment the component writes the row.';
		}

		$response['backup_note'] = 'No .pbck backup was created. Page Builder CK snapshots a page into '
			. 'administrator/components/com_pagebuilderck/backup/' . $id . '_bak/ only when saving through '
			. 'its own editor, keeping the last five. Any snapshots already there predate this write.';

		$response['component'] = $this->pbckEditionNotice();

		return ToolResult::json($response);
	}

	/**
	 * The clobbered columns whose written value actually differs from what the
	 * builder will overwrite it with.
	 *
	 * @param  array<string,mixed> $data
	 * @return array<int,string>
	 */
	private function columnsAtRisk(array $data): array
	{
		$atRisk = [];

		foreach (self::EDITOR_WRITES as $column => $editorValue) {
			if (\array_key_exists($column, $data) && (string) $data[$column] !== (string) $editorValue) {
				$atRisk[] = $column;
			}
		}

		return $atRisk;
	}

	/** Refuse a value this utf8mb3 table cannot store, or that would be truncated. */
	private function assertStorable(string $value, string $label, int $maxBytes): ?ToolResult
	{
		if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $value) === 1) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf(
					'%s contains a character outside the Basic Multilingual Plane (an emoji or similar). '
						. '#__pagebuilderck_pages is DEFAULT CHARSET=utf8, which is MySQL\'s 3-byte utf8mb3 '
						. 'and cannot store it. Refusing; nothing was written.',
					$label
				),
			], true);
		}

		if (\strlen($value) > $maxBytes) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf(
					'%s is %d bytes, over the %d-byte limit of its column. MySQL truncates silently in '
						. 'non-strict mode. Refusing; nothing was written.',
					$label,
					\strlen($value),
					$maxBytes
				),
			], true);
		}

		return null;
	}

	private function joinIdList(mixed $raw): string
	{
		if (\is_string($raw)) {
			$raw = explode(',', $raw);
		}

		if (!\is_array($raw)) {
			return '';
		}

		$ids = [];

		foreach ($raw as $piece) {
			$id = (int) $piece;

			if ($id > 0 && !\in_array($id, $ids, true)) {
				$ids[] = $id;
			}
		}

		return implode(',', $ids);
	}

	/** @return string|ToolResult The JSON to store, or a refusal. */
	private function encodeParams(mixed $raw): string|ToolResult
	{
		if ($raw === null || $raw === '') {
			return '';
		}

		if (\is_string($raw)) {
			json_decode($raw);

			if (json_last_error() !== \JSON_ERROR_NONE) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'params was supplied as a string but is not valid JSON. Page Builder CK '
						. 'loads this column into a registry, and an unparseable value silently becomes an '
						. 'empty options set — the page would lose every option it had. Refusing; nothing '
						. 'was written.',
				], true);
			}

			return $raw;
		}

		return (string) json_encode($raw, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
	}
}
