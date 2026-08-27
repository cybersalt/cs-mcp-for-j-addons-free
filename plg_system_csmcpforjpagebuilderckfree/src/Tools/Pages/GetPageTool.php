<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Pages;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckBootTrait;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckContentTrait;
use Joomla\CMS\User\User;

/**
 * Read one row of `#__pagebuilderck_pages`, content included.
 *
 * Two things this reports that the raw row does not tell you.
 *
 * FIRST, `lossless`. Page Builder CK content is raw HTML, not a JSON tree, so
 * every write path has to parse and re-serialise it — and a page containing
 * markup that does not survive that round-trip byte for byte cannot be edited
 * safely by any tool here. That property belongs at the top of a read, not
 * discovered at the moment a write is refused, so it is reported up front.
 *
 * SECOND, the page's style ids. There is no `styles` column on this table,
 * despite `models/page.php:49` reading one and `controllers/page.php:72` writing
 * one — the property never exists and the write is silently discarded. The real
 * association is a comma-separated list in `div.pagebuilderckparams[data-styles]`
 * inside `htmlcode`, which is why it can only be answered by parsing content.
 *
 * `htmlcode` is stored with the site root collapsed to the `|URIROOT|` token and
 * is expanded on read by the vendor's own model. This tool does the same by
 * default; `raw: true` returns the stored bytes instead, which is what you want
 * if you intend to diff against, or write back to, the column.
 */
final class GetPageTool extends AbstractTool
{
	use PagebuilderckBootTrait;
	use PagebuilderckContentTrait;

	public function getName(): string { return 'get_pagebuilderck_page'; }

	public function getDescription(): string
	{
		return 'Get one Page Builder CK page from #__pagebuilderck_pages by id, including its full '
			. 'htmlcode content. '
			. 'By default htmlcode is returned EXPANDED — the |URIROOT| token Page Builder CK stores '
			. 'internal URLs with is replaced by the live site root, exactly as the vendor\'s own model '
			. 'does on read. Pass raw: true to get the stored, tokenised bytes instead; that is the form '
			. 'you must diff against or feed back to update_pagebuilderck_page if you want a '
			. 'byte-accurate comparison. Pass include_content: false to omit htmlcode entirely and get '
			. 'just the row plus the structural summary — a real page is tens to hundreds of kilobytes. '
			. 'The response reports `lossless`: whether this page\'s stored content survives a '
			. 'parse/serialise round-trip byte for byte. When it is false, no tool here will write this '
			. 'page\'s content, because doing so would silently alter markup nobody asked to change. '
			. 'Check it BEFORE planning an edit. '
			. 'Also returned: an outline summary (row, column and block counts, block types in use, and '
			. 'any structural problems), and the page\'s style ids. Those ids do NOT live in a column — '
			. 'Page Builder CK has no styles column at all, despite its model appearing to read one — '
			. 'they are parsed out of div.pagebuilderckparams[data-styles] inside the content itself. '
			. 'checked_out is a varchar holding a user id and is reported as checked_out_by when set.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'              => ['type' => 'integer', 'description' => 'Page id. Required.'],
				'include_content' => ['type' => 'boolean', 'description' => 'Return the htmlcode column. Default true.'],
				'raw'             => ['type' => 'boolean', 'description' => 'Return htmlcode exactly as stored, with |URIROOT| left tokenised, instead of expanded to the live site root. Default false.'],
			],
			'required' => ['id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->pbckAdminBase() === null) {
			return $this->pbckNotInstalledError();
		}

		if (!$this->pbckTableExists('pages')) {
			return $this->pbckMissingTableError('pages');
		}

		$id = $this->requirePositiveInt($arguments, 'id');

		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->pbckTable('pages')))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();

		if (!\is_array($row)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No Page Builder CK page with id ' . $id . '. Use list_pagebuilderck_pages to '
					. 'find one; note that trashed pages are hidden from that listing unless you ask for '
					. 'state: trashed.',
			], true);
		}

		$includeContent = !\array_key_exists('include_content', $arguments)
			|| (bool) $arguments['include_content'];
		$raw = (bool) ($arguments['raw'] ?? false);

		// The stored form is the canonical one. Losslessness and style ids are both
		// properties of these bytes, so they are measured here and not on the
		// expanded copy, whose length differs by every URL in the page.
		$stored = (string) ($row['htmlcode'] ?? '');

		$response = [
			'ok'   => true,
			'page' => [
				'id'             => (int) $row['id'],
				'title'          => (string) $row['title'],
				'alias'          => (string) $row['alias'],
				'state'          => $this->pbckStateLabel((int) $row['state']),
				'state_int'      => (int) $row['state'],
				'ordering'       => (int) $row['ordering'],
				'created'        => (string) $row['created'],
				'modified'       => (string) $row['modified'],
				'catid'          => (string) $row['catid'],
				'categories'     => $this->splitIdList((string) $row['categories']),
				'created_by'     => (int) $row['created_by'],
				'access'         => (int) $row['access'],
				'hits'           => (int) $row['hits'],
				'featured'       => (int) $row['featured'] === 1,
				'params'         => $this->decodeParams((string) $row['params']),
				'htmlcode_bytes' => \strlen($stored),
				'route'          => 'index.php?option=com_pagebuilderck&view=page&id=' . (int) $row['id'],
			],
		];

		$checkedOut = $this->pbckCheckedOutBy($row);

		if ($checkedOut !== null) {
			$response['page']['checked_out_by'] = $checkedOut;
			$response['page']['checked_out_note'] = 'Checked out by user ' . $checkedOut . '. '
				. 'update_pagebuilderck_page refuses to write a page checked out by someone else unless '
				. 'force is set. Page Builder CK has no global check-in, so an abandoned session leaves '
				. 'this set forever.';
		}

		$lossless = $this->pbckIsLossless($stored);

		$response['lossless'] = $lossless;

		if (!$lossless) {
			$response['lossless_warning'] = 'This page\'s stored content does NOT survive a '
				. 'parse/serialise round-trip byte for byte. Page Builder CK stores raw HTML, so any '
				. 'content write has to re-serialise it, and re-serialising this page would change markup '
				. 'that was not part of the edit. update_pagebuilderck_page will refuse to write content '
				. 'here. Reading is unaffected. Common causes are markup the bundled simple_html_dom '
				. 'parser cannot reproduce exactly, or content pasted in from outside the builder. '
				. 'The safe route is to fix the page in Page Builder CK\'s own editor and re-save it, '
				. 'which rewrites the markup in the parser\'s own dialect.';
		}

		$styleIds = $this->pbckGetPageStyleIds($stored);

		$response['style_ids'] = $styleIds;
		$response['style_ids_note'] = 'Read from div.pagebuilderckparams[data-styles] inside htmlcode. '
			. '#__pagebuilderck_pages has NO styles column — the admin model reads and the save '
			. 'controller writes a `styles` property that does not exist, and the write is discarded '
			. 'silently. The content attribute is the only real association.';

		$response['outline'] = $this->summariseOutline($stored);

		$problems = $this->pbckValidate($stored, $this->pbckEnabledAddonTypes());

		if ($problems !== []) {
			$response['problems']    = $problems;
			$response['has_fatal']   = $this->pbckHasFatal($problems);
		}

		if ($includeContent) {
			$response['htmlcode'] = $raw ? $stored : $this->pbckExpandRoot($stored);
			$response['htmlcode_form'] = $raw
				? 'raw — exactly as stored, |URIROOT| left tokenised. This is the form to diff against '
					. 'and the form a write is compared with.'
				: 'expanded — |URIROOT| replaced with the live site root "' . $this->pbckSiteRoot() . '", '
					. 'matching what the vendor\'s model returns on read. Do not store this back verbatim; '
					. 'the write tools re-collapse it for you.';
		} else {
			$response['content_omitted'] = 'include_content was false, so htmlcode is not in this '
				. 'response. htmlcode_bytes and the outline still describe it.';
		}

		$response['component'] = $this->pbckEditionNotice();

		return ToolResult::json($response);
	}

	/**
	 * Counts and type inventory from the parsed outline.
	 *
	 * The full outline tree is deliberately not returned here — on a large page it
	 * dwarfs the row itself, and this tool is already carrying the content.
	 *
	 * @return array<string,mixed>
	 */
	private function summariseOutline(string $html): array
	{
		if ($html === '') {
			return ['ok' => true, 'rows' => 0, 'columns' => 0, 'blocks' => 0, 'block_types' => [], 'note' => 'Page is empty.'];
		}

		$outline = $this->pbckOutline($html, $this->pbckEnabledAddonTypes());

		if (($outline['ok'] ?? false) !== true) {
			return [
				'ok'     => false,
				'reason' => (string) ($outline['reason'] ?? 'unknown'),
				'note'   => 'Content could not be parsed, so no structural summary is available. This is '
					. 'itself a strong signal that the page needs attention.',
			];
		}

		$counts = ['rows' => 0, 'columns' => 0, 'blocks' => 0];
		$types  = [];
		$notes  = [];

		$this->walkRows($outline['rows'] ?? [], $counts, $types, $notes);

		arsort($types);

		return [
			'ok'                => true,
			'rows'              => $counts['rows'],
			'columns'           => $counts['columns'],
			'blocks'            => $counts['blocks'],
			'block_types'       => $types,
			'singletons'        => $outline['singletons'] ?? [],
			'top_level_rows'    => (int) ($outline['row_count'] ?? 0),
			'structure_notes'   => $notes,
		];
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<string,int>              $counts
	 * @param array<string,int>              $types
	 * @param array<int,string>              $notes
	 */
	private function walkRows(array $rows, array &$counts, array &$types, array &$notes): void
	{
		foreach ($rows as $row) {
			$counts['rows']++;

			if (isset($row['warning'])) {
				$notes[] = ($row['id'] !== '' ? '#' . $row['id'] . ': ' : '') . (string) $row['warning'];
			}

			foreach ($row['columns'] ?? [] as $column) {
				$counts['columns']++;

				foreach ($column['blocks'] ?? [] as $block) {
					$counts['blocks']++;

					$type = (string) ($block['type'] ?? '');
					$key  = $type === '' ? '(no data-type)' : $type;

					$types[$key] = ($types[$key] ?? 0) + 1;

					// Fails open, so a missing addon is invisible in the rendered
					// page. It has to be surfaced here or nowhere.
					if (isset($block['addon_missing'])) {
						$notes[] = ($block['id'] !== '' ? '#' . $block['id'] . ': ' : '') . (string) $block['addon_missing'];
					}

					if (isset($block['error'])) {
						$notes[] = ($block['id'] !== '' ? '#' . $block['id'] . ': ' : '') . (string) $block['error'];
					}
				}

				if (isset($column['nested_rows'])) {
					$this->walkRows($column['nested_rows'], $counts, $types, $notes);
				}
			}
		}
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

		return array_values(array_unique($out));
	}

	/**
	 * `params` holds a JSON object built from the editor's options fieldset. It is
	 * returned decoded when it decodes and verbatim when it does not, so a corrupt
	 * value is visible rather than flattened to an empty object.
	 */
	private function decodeParams(string $raw): mixed
	{
		if (trim($raw) === '') {
			return new \stdClass();
		}

		$decoded = json_decode($raw);

		return json_last_error() === \JSON_ERROR_NONE ? $decoded : ['raw' => $raw, 'error' => 'params is not valid JSON.'];
	}
}
