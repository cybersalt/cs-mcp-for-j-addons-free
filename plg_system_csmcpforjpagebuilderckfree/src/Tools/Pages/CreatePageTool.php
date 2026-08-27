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
 * Create a row in `#__pagebuilderck_pages`.
 *
 * This inserts directly rather than going through `PagebuilderckModelPage`, and
 * that is a deliberate choice rather than a shortcut. The vendor's model is
 * unusable from anywhere but a live admin request: `getItem()` reads
 * `$this->input`, `save()` calls `PagebuilderckHelper::makeBackup($this->getItem())`
 * on a model whose item is the CURRENT request's page, and `CKFof::dbStore()`
 * casts every numeric-looking string to an int on UPDATE. A direct INSERT with
 * properly quoted values is both safer and more predictable. There is no
 * `#__assets` node for a Page Builder CK page — the component has no per-item
 * ACL — so nothing is lost by not using the Table layer.
 *
 * Three properties of this table have to be got right or the row is subtly wrong:
 *
 *   - `checked_out` is varchar(10), and empty string means free. Writing an int
 *     0 leaves the literal string "0", which the vendor's own `checkout()`
 *     treats as falsy and so does work — but `pbckCheckedOutBy()` and every
 *     comparison in this add-on expect '', and consistency is worth more here
 *     than tolerating both.
 *   - `created` and `modified` are `datetime NOT NULL DEFAULT '1970-01-02
 *     00:00:00'`. Both are set explicitly; writing '0000-00-00 00:00:00' fails
 *     outright under NO_ZERO_DATE, which is on by default in MySQL 5.7+.
 *   - `access` and `ordering` are NOT NULL with no default at all, so they must
 *     be supplied on INSERT or MySQL rejects the row in strict mode.
 */
final class CreatePageTool extends AbstractTool
{
	use PagebuilderckBootTrait;
	use PagebuilderckContentTrait;

	/**
	 * What `controllers/page.php:59-67` hardcodes into every builder save.
	 *
	 * Any value we write into one of these columns that differs from what is here
	 * is destined to be lost, so the response says so specifically instead of
	 * warning about all six regardless.
	 */
	private const EDITOR_WRITES = [
		'alias'      => '',
		'ordering'   => 0,
		'state'      => 1,
		'catid'      => '',
		'created_by' => 0,
		'access'     => 1,
	];

	public function getName(): string { return 'create_pagebuilderck_page'; }

	public function getDescription(): string
	{
		return 'Create a new Page Builder CK page in #__pagebuilderck_pages. Only `title` is required. '
			. 'Optional: htmlcode, catid, categories, access, state, featured, params. '
			. 'If htmlcode is supplied it is validated before anything is written. The site root is '
			. 'collapsed to the |URIROOT| token automatically — pass ordinary absolute or relative URLs '
			. 'and they will be stored the way Page Builder CK stores them, so the page survives a domain '
			. 'or subdirectory move. Content that does not survive a parse/serialise round-trip byte for '
			. 'byte, or that has any FATAL structural problem (a block with no data-type, duplicated ids, '
			. 'a row whose data-nb disagrees with its column count, a non-empty .ckprops div), is REFUSED '
			. 'rather than written; the problems are listed in the response. Non-fatal warnings are '
			. 'reported and do not block the write. A block whose addon plugin is disabled is a warning, '
			. 'not an error — Page Builder CK fails open and renders such a block\'s inner markup as '
			. 'static HTML. '
			. 'WARNING that applies to alias, ordering, state, catid, created_by and access: Page Builder '
			. 'CK\'s own save controller hardcodes all six on every save, so any value set here for those '
			. 'columns lasts only until the next time a human opens the page in the builder and clicks '
			. 'Save. The response names the specific columns at risk. '
			. 'Category membership goes in `categories` (a comma-separated id list) — that is what the '
			. 'admin Pages screen filters on. `catid` is a separate vestigial varchar. '
			. 'The pages table is utf8mb3, so emoji and other astral-plane characters in the title will '
			. 'be rejected by MySQL; this tool refuses them up front rather than letting the INSERT fail. '
			. 'Page Builder CK pages have no alias and no router of their own: reach the new page with a '
			. 'menu item pointing at index.php?option=com_pagebuilderck&view=page&id=<id>.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'title'      => ['type' => 'string', 'description' => 'Page title. Required. Max 255 bytes, no emoji (the table is utf8mb3).'],
				'htmlcode'   => ['type' => 'string', 'description' => 'Page content as raw Page Builder CK HTML. Optional — omit to create an empty page. The site root is tokenised to |URIROOT| for you.'],
				'catid'      => ['type' => 'string', 'description' => 'The raw catid varchar. Vestigial: the builder blanks it on save. Prefer categories.'],
				'categories' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Category ids. Stored as a comma-separated list in `categories`, which is what the admin listing filters on.'],
				'access'     => ['type' => 'integer', 'description' => 'Joomla view level id. Defaults to 1 (Public), matching what the builder forces on every save.'],
				'state'      => ['type' => 'string', 'description' => 'published | unpublished | trashed | archived, or the integer. Defaults to published.'],
				'featured'   => ['type' => 'boolean', 'description' => 'Mark the page featured. Default false.'],
				'params'     => ['type' => 'object', 'description' => 'Page options object, stored as JSON in `params`. Known keys: showtitle, titletag, contentprepare.'],
			],
			'required' => ['title'],
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

		$title = $this->requireString($arguments, 'title');

		if (($refusal = $this->assertStorable($title, 'title', 255)) !== null) {
			return $refusal;
		}

		$rawState   = $arguments['state'] ?? null;
		$stateGiven = $rawState !== null && trim((string) $rawState) !== '';
		$state      = $this->pbckNormaliseState($rawState);

		if ($stateGiven && $state === null) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf(
					'Unrecognised state "%s". Use published, unpublished, trashed, archived, or the '
						. 'integer 1, 0, -2, 2.',
					(string) $rawState
				),
			], true);
		}

		$catid = (string) ($arguments['catid'] ?? '');

		if (($refusal = $this->assertStorable($catid, 'catid', 255)) !== null) {
			return $refusal;
		}

		$categories = $this->joinIdList($arguments['categories'] ?? []);

		if (($refusal = $this->assertStorable($categories, 'categories', 255)) !== null) {
			return $refusal;
		}

		$params = $this->encodeParams($arguments['params'] ?? null);

		if ($params instanceof ToolResult) {
			return $params;
		}

		// --- content ---------------------------------------------------------
		$warnings = [];
		$htmlcode = '';

		if (\array_key_exists('htmlcode', $arguments)) {
			$supplied = (string) $arguments['htmlcode'];

			// Collapse FIRST, so validation and the losslessness check both run
			// against the exact bytes that will be stored, not a near-miss.
			$htmlcode = $this->pbckCollapseRoot($supplied);

			if (!$this->pbckIsLossless($htmlcode)) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Refusing to write this content: it does not survive a parse/serialise '
						. 'round-trip byte for byte. Page Builder CK stores raw HTML with no encoder to '
						. 'normalise it, so content this add-on cannot faithfully reproduce is content it '
						. 'must not take responsibility for. Either simplify the markup, or build the page '
						. 'in Page Builder CK\'s editor and save it once so the markup is written in the '
						. 'parser\'s own dialect.',
					'lossless' => false,
				], true);
			}

			$problems = $this->pbckValidate($htmlcode, $this->pbckEnabledAddonTypes());

			if ($this->pbckHasFatal($problems)) {
				return ToolResult::json([
					'ok'       => false,
					'error'    => 'Refusing to create this page: the supplied htmlcode has fatal '
						. 'structural problems that would visibly break the rendered page. Nothing was '
						. 'written.',
					'problems' => $problems,
				], true);
			}

			if ($problems !== []) {
				$warnings['content'] = $problems;
			}
		}

		// --- row -------------------------------------------------------------
		$now       = Factory::getDate()->toSql();
		$createdBy = (int) $actor->id;

		$data = [
			'title'      => $title,
			// Never populated by the builder and never read by it. Written as ''
			// because the column is NOT NULL with no default.
			'alias'      => '',
			'ordering'   => 0,
			'state'      => $state ?? 1,
			'created'    => $now,
			'modified'   => $now,
			'catid'      => $catid,
			'created_by' => $createdBy,
			'params'     => $params,
			'access'     => \array_key_exists('access', $arguments) ? (int) $arguments['access'] : 1,
			'hits'       => 0,
			'featured'   => (bool) ($arguments['featured'] ?? false) ? 1 : 0,
			'htmlcode'   => $htmlcode,
			// varchar(10); '' is free. NOT the integer 0.
			'checked_out' => '',
			'categories' => $categories,
		];

		$object = new \stdClass();

		foreach ($data as $column => $value) {
			$object->{$column} = $value;
		}

		$this->db->insertObject($this->pbckTable('pages'), $object, 'id');

		$id = (int) $this->db->insertid();

		if ($id <= 0) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'The INSERT reported no new id, so the page cannot be confirmed as created. '
					. 'Check #__pagebuilderck_pages before retrying — retrying blind risks a duplicate.',
			], true);
		}

		$response = [
			'ok'         => true,
			'id'         => $id,
			'title'      => $title,
			'state'      => $this->pbckStateLabel((int) $data['state']),
			'access'     => $data['access'],
			'featured'   => $data['featured'] === 1,
			'created'    => $now,
			'created_by' => $createdBy,
			'categories' => $this->splitIdList($categories),
			'htmlcode_bytes' => \strlen($htmlcode),
			'route'      => 'index.php?option=com_pagebuilderck&view=page&id=' . $id,
		];

		if ($htmlcode !== '') {
			$response['style_ids'] = $this->pbckGetPageStyleIds($htmlcode);
		}

		if ($warnings !== []) {
			$response['warnings'] = $warnings;
			$response['warnings_note'] = 'These did not block the write. A block whose addon plugin is '
				. 'disabled still renders — Page Builder CK emits the inner markup as static HTML with no '
				. 'error — but its interactive behaviour is gone.';
		}

		$clobberNotice = $this->pbckEditorClobberNotice($this->columnsAtRisk($data));

		if ($clobberNotice !== null) {
			$response['editor_clobber'] = $clobberNotice;
		}

		$hazards = $this->pbckNumericHazards([
			'title'      => $data['title'],
			'catid'      => $data['catid'],
			'categories' => $data['categories'],
		]);

		if ($hazards !== []) {
			$response['numeric_hazards'] = $hazards;
			$response['numeric_hazards_note'] = 'This INSERT stored the values as given — the hazard is '
				. 'the NEXT save. Page Builder CK\'s CKFof::dbStore() casts numeric-looking strings to int '
				. 'on UPDATE, so these varchar values will change the first time the page is saved through '
				. 'the component.';
		}

		$response['next'] = $htmlcode === ''
			? 'The page is empty. Add content with update_pagebuilderck_page, or open it in the builder.'
			: 'Content stored. Page Builder CK pages have no alias and no router of their own — add a '
				. 'menu item pointing at index.php?option=com_pagebuilderck&view=page&id=' . $id . ' to '
				. 'give it a URL.';

		$response['backup_note'] = 'Page Builder CK writes a .pbck backup into '
			. 'administrator/components/com_pagebuilderck/backup/<id>_bak/ only when the page is saved '
			. 'through its own editor, and keeps the last five. This tool does not create one, because '
			. 'there is no prior state of a brand-new page to back up.';

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

	/**
	 * Refuse a value MySQL cannot store in this utf8mb3 table, or that would be
	 * silently truncated by its varchar length.
	 */
	private function assertStorable(string $value, string $label, int $maxBytes): ?ToolResult
	{
		// utf8mb3 encodes at most 3 bytes per character, so anything outside the
		// Basic Multilingual Plane — every emoji, most CJK extensions — has no
		// representation at all. MySQL either errors or truncates at that byte.
		if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $value) === 1) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf(
					'%s contains a character outside the Basic Multilingual Plane (an emoji or similar). '
						. '#__pagebuilderck_pages is DEFAULT CHARSET=utf8, which is MySQL\'s 3-byte utf8mb3 '
						. 'and cannot store it. The write would either error or be truncated at that '
						. 'character. Refusing.',
					$label
				),
			], true);
		}

		if (\strlen($value) > $maxBytes) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf(
					'%s is %d bytes, over the %d-byte limit of its column. MySQL truncates silently in '
						. 'non-strict mode. Refusing.',
					$label,
					\strlen($value),
					$maxBytes
				),
			], true);
		}

		return null;
	}

	/** @param mixed $raw */
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

	/** @return array<int,int> */
	private function splitIdList(string $raw): array
	{
		$out = [];

		foreach (explode(',', $raw) as $piece) {
			$piece = trim($piece);

			if ($piece !== '' && ctype_digit($piece)) {
				$out[] = (int) $piece;
			}
		}

		return $out;
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
						. 'empty options set. Refusing.',
				], true);
			}

			return $raw;
		}

		return (string) json_encode($raw, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
	}
}
