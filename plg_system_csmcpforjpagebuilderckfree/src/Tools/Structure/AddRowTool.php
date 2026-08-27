<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Structure;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckBootTrait;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckGeometryTrait;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckContentTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Append or insert a Page Builder CK row, with N empty columns, into a page.
 *
 * Rows are the only structural container Page Builder CK has, and their column
 * geometry is the single most fragile thing in the whole `htmlcode` column,
 * because it is expressed in FOUR places that must agree exactly:
 *
 *   1. `.rowck[data-gutter]`            e.g. "2%"
 *   2. `.rowck[data-nb]`                the number of `.blockck` children
 *   3. `.blockck[data-width]`           the logical width, normally 100 / nb
 *   4. `.blockck[data-real-width]`      data-width − ((nb − 1) × gutter) / nb
 *
 * plus a generated `<style class="ckcolumnwidth">` PREPENDED inside the row,
 * whose rules are keyed on `[data-gutter="G"][data-nb="N"] [data-width="W"]`.
 * The width a column actually gets comes from that stylesheet, not from the
 * attributes — the attributes are only the selector key. So if any one of the
 * four disagrees with the others, NO rule matches and every column in the row
 * renders with no width at all. There is no error and nothing in the log; the
 * page simply collapses.
 *
 * The formulae and the emitted rule shape are taken from the vendor's own
 * editor JavaScript: `media/assets/pagebuilderck.js:2091` (ckSetColumnsWidth)
 * and `:2119` (ckSetColumnWidth). This tool reproduces them in PHP so that the
 * row it writes is indistinguishable from one the builder would have produced.
 */
final class AddRowTool extends AbstractTool
{
	use PagebuilderckBootTrait;
	use PagebuilderckGeometryTrait;
	use PagebuilderckContentTrait;

	/** Sanity ceiling. The builder's own suggestions stop at 6; 12 is generous. */
	private const MAX_COLUMNS = 12;

	public function getName(): string { return 'add_pagebuilderck_row'; }

	public function getDescription(): string
	{
		return 'Add a row containing N empty columns to a Page Builder CK page (#__pagebuilderck_pages.htmlcode). '
			. 'Arguments: page_id (required), columns (default 1, max 12), gutter (default "2%"), '
			. 'widths (optional explicit per-column percentages), position (append | prepend | before | after, '
			. 'default append) and relative_to_row_id (required for before/after). '
			. 'Writes all four places column geometry lives — .rowck[data-gutter], .rowck[data-nb], '
			. '.blockck[data-width] and .blockck[data-real-width] — and regenerates the row\'s '
			. '<style class="ckcolumnwidth"> block, because the actual rendered width comes from that '
			. 'stylesheet and its selectors are keyed on the other three. If they disagree no rule matches '
			. 'and every column in the row renders with no width at all, silently. '
			. 'Ids are minted in the builder\'s own ID<epoch-ms> format and checked against every id already '
			. 'on the page; the row and its first column share a stem (row_IDx / block_IDx) as real builder '
			. 'data does. The columns are created EMPTY — this tool adds no blocks. '
			. 'Refuses if the page\'s existing content does not survive a parse/serialise round-trip byte for '
			. 'byte, or if the resulting content fails validation with any fatal problem. '
			. 'Pass dry_run to see the exact markup and geometry without writing.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'page_id'             => ['type' => 'integer', 'description' => 'Row id in #__pagebuilderck_pages.'],
				'columns'             => ['type' => 'integer', 'description' => 'Number of columns to create, 1 to 12. Default 1. Ignored when widths is given.'],
				'widths'              => [
					'type'        => 'array',
					'items'       => ['type' => 'number'],
					'description' => 'Explicit logical widths as percentages, one per column, e.g. [66.666667, 33.333333]. '
						. 'Sets the column count. Omit for an equal split of 100 / columns.',
				],
				'gutter'              => ['type' => 'string', 'description' => 'Inter-column gap as a percentage, e.g. "2%" or "2". Default "2%", which is the builder\'s own default.'],
				'position'            => ['type' => 'string', 'description' => 'append (default, after the last top-level row), prepend (before the first), before, or after.'],
				'relative_to_row_id'  => ['type' => 'string', 'description' => 'Existing row id, with or without the row_ prefix. Required when position is before or after.'],
				'dry_run'             => ['type' => 'boolean', 'description' => 'Build and validate the markup, report it, but do not write.'],
			],
			'required'             => ['page_id'],
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

		$pageId = $this->requirePositiveInt($arguments, 'page_id');
		$table  = $this->db->quoteName($this->pbckTable('pages'));

		$page = $this->db->setQuery(
			'SELECT ' . $this->db->quoteName('id') . ', ' . $this->db->quoteName('title')
			. ', ' . $this->db->quoteName('htmlcode') . ', ' . $this->db->quoteName('checked_out')
			. ' FROM ' . $table . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $pageId
		)->loadAssoc();

		if (!$page) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf('No row with id %d in %s.', $pageId, $this->pbckTable('pages')),
			], true);
		}

		// --- geometry arguments ---------------------------------------------
		$gutter = $this->pbckNormaliseGutter((string) ($arguments['gutter'] ?? '2%'));

		if ($gutter === null) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'gutter must be a non-negative percentage such as "2%" or "2". It is written verbatim '
					. 'into the [data-gutter="…"] attribute selector, so anything else would produce a '
					. 'stylesheet that matches nothing.',
			], true);
		}

		$widthsGiven = \array_key_exists('widths', $arguments) && \is_array($arguments['widths']);
		$columns     = $widthsGiven ? \count($arguments['widths']) : (int) ($arguments['columns'] ?? 1);

		if ($columns < 1 || $columns > self::MAX_COLUMNS) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf('columns must be between 1 and %d; got %d.', self::MAX_COLUMNS, $columns),
			], true);
		}

		$warnings = [];

		if ($widthsGiven) {
			$widths = [];

			foreach ($arguments['widths'] as $raw) {
				if (!is_numeric($raw) || (float) $raw <= 0 || (float) $raw > 100) {
					return ToolResult::json([
						'ok'    => false,
						'error' => 'Every entry in widths must be a number greater than 0 and no more than 100.',
					], true);
				}

				$widths[] = (float) $raw;
			}

			$sum = array_sum($widths);

			// Not an error: the builder's "advanced layout" mode deliberately
			// allows rows that do not total 100.
			if (abs($sum - 100.0) > 0.01) {
				$warnings[] = sprintf(
					'The supplied widths total %s%%, not 100%%. Page Builder CK will honour that, but unless '
						. 'the row is an advanced layout the columns will not fill their container.',
					$this->pbckNum($sum)
				);
			}
		} else {
			$widths = array_fill(0, $columns, 100.0 / $columns);
		}

		// --- content guards ---------------------------------------------------
		$html = (string) ($page['htmlcode'] ?? '');

		if ($html !== '' && !$this->pbckIsLossless($html)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf(
					'The stored content of page %d does not survive a parse/serialise round-trip byte for byte, '
						. 'so inserting a row would silently alter markup nobody asked to change. Refusing. '
						. 'This normally means the page contains hand-written or third-party markup the '
						. 'bundled parser cannot reproduce exactly.',
					$pageId
				),
				'page'  => ['id' => $pageId, 'title' => (string) $page['title']],
			], true);
		}

		// --- build ------------------------------------------------------------
		$taken   = $this->pbckCollectIds($html);
		$stems   = $this->pbckMintColumnStems($taken, $columns);
		$rowId   = 'row_' . $stems[0];
		$rowHtml = $this->pbckBuildRow($rowId, $stems, $gutter, $widths);

		$insert = $this->pbckInsert($html, $rowHtml, $arguments);

		if (isset($insert['error'])) {
			return ToolResult::json(['ok' => false, 'error' => $insert['error']], true);
		}

		// Tokenise before anything else looks at the result. Our own markup has
		// no URLs in it, but the surgery re-serialises the whole document and a
		// page that already carried an untokenised absolute URL should not have
		// that entrenched by our write.
		$updated = $this->pbckCollapseRoot($insert['html']);

		$enabledTypes = array_merge($this->pbckEnabledAddonTypes(), $this->pbckCoreTypes());
		$problems     = $this->pbckValidate($updated, $enabledTypes);

		if ($this->pbckHasFatal($problems)) {
			// Distinguish "we broke it" from "it was already broken", because the
			// two need completely different responses from the caller.
			$before = $this->pbckValidate($html, $enabledTypes);

			return ToolResult::json([
				'ok'                    => false,
				'error'                 => 'The page would have a fatal structural problem after this insert, so nothing was '
					. 'written. Page Builder CK content is raw HTML with no encoder to normalise it, so a '
					. 'partial or questionable write is not recoverable — refusing is the only safe answer.',
				'problems_after'        => $problems,
				'problems_before'       => $before,
				'already_broken_before' => $this->pbckHasFatal($before),
			], true);
		}

		foreach ($problems as $problem) {
			if (($problem['severity'] ?? '') === 'warning') {
				$warnings[] = $problem['path'] . ': ' . $problem['problem'];
			}
		}

		$geometry = $this->pbckDescribeGeometry($gutter, $columns, $widths, $stems);

		$result = [
			'ok'         => true,
			'page'       => ['id' => $pageId, 'title' => (string) $page['title']],
			'row_id'     => $rowId,
			'column_ids' => array_map(static fn ($stem) => 'block_' . $stem, $stems),
			'position'   => $insert['position'],
			'geometry'   => $geometry,
			'row_html'   => $rowHtml,
			'note'       => 'The columns are empty. Page Builder CK renders an empty column as a zero-height '
				. 'div, so the row will not be visible on the front end until blocks are added to it.',
		];

		if ($warnings !== []) {
			$result['warnings'] = $warnings;
		}

		if (($checkedOut = $this->pbckCheckedOutBy($page)) !== null) {
			$result['checked_out'] = sprintf(
				'This page is checked out to user %d. Page Builder CK never releases a checkout '
					. 'automatically, and if that session is still open its next Save will overwrite this '
					. 'change with the content it loaded before the change was made.',
				$checkedOut
			);
		}

		if ($arguments['dry_run'] ?? false) {
			$result['dry_run']       = true;
			$result['written']       = false;
			$result['content_bytes'] = \strlen($updated);
			$result['component']     = $this->pbckEditionNotice();

			return ToolResult::json($result);
		}

		$query = $this->db->getQuery(true)
			->update($this->db->quoteName($this->pbckTable('pages')))
			->set($this->db->quoteName('htmlcode') . ' = ' . $this->db->quote($updated))
			->set($this->db->quoteName('modified') . ' = ' . $this->db->quote(Factory::getDate()->toSql()))
			->where($this->db->quoteName('id') . ' = ' . $pageId);

		$this->db->setQuery($query)->execute();

		$result['written']       = true;
		$result['content_bytes'] = \strlen($updated);

		// htmlcode and modified are not in the set the save controller hardcodes,
		// but ask anyway so this stays correct if that ever changes.
		if (($clobber = $this->pbckEditorClobberNotice(['htmlcode', 'modified'])) !== null) {
			$result['editor_clobber'] = $clobber;
		}

		$result['component'] = $this->pbckEditionNotice();

		return ToolResult::json($result);
	}

	// -----------------------------------------------------------------------
	// Markup generation
	// -----------------------------------------------------------------------

	/**
	 * The full row, matching `ckHtmlRow()` at pagebuilderck.js:803 plus the
	 * geometry `ckSetColumnsWidth()` would immediately apply to it.
	 *
	 * @param array<int,string> $stems  One id stem per column; stem 0 is shared with the row.
	 * @param array<int,float>  $widths One logical width per column.
	 */
	private function pbckBuildRow(string $rowId, array $stems, string $gutter, array $widths): string
	{
		$nb      = \count($stems);
		$columns = '';

		foreach ($stems as $i => $stem) {
			$columns .= $this->pbckBuildColumn('block_' . $stem, $widths[$i], $nb, $gutter);
		}

		// ckstack3/2/1 are the responsive stacking classes the builder puts on
		// every new row; without them the row does not stack on small screens.
		return '<div class="rowck ckstack3 ckstack2 ckstack1" id="' . $rowId . '"'
			. ' data-gutter="' . $gutter . '" data-nb="' . $nb . '">'
			. $this->pbckColumnWidthStyle($gutter, $nb, $widths)
			. '<div class="inner animate clearfix">' . $columns . '</div>'
			. '<div class="ckstyle"></div>'
			. '</div>';
	}

	// -----------------------------------------------------------------------
	// Geometry maths
	// -----------------------------------------------------------------------

	/**
	 * Mint one id stem per column, with the row reusing the first.
	 *
	 * `pbckNewId()` guarantees the bare stem is free, but the page's taken-ids
	 * list holds the PREFIXED forms (`row_ID…`, `block_ID…`), so a stem can be
	 * free while its prefixed forms are not. Check both.
	 *
	 * @param array<int,string> $taken Mutated.
	 * @return array<int,string>
	 */
	private function pbckMintColumnStems(array &$taken, int $columns): array
	{
		$stems = [];

		for ($i = 0; $i < $columns; $i++) {
			do {
				$stem = $this->pbckNewId($taken);
			} while (\in_array('row_' . $stem, $taken, true) || \in_array('block_' . $stem, $taken, true));

			$taken[] = 'row_' . $stem;
			$taken[] = 'block_' . $stem;
			$stems[] = $stem;
		}

		return $stems;
	}

	/**
	 * @param array<int,float>  $widths
	 * @param array<int,string> $stems
	 * @return array<string,mixed>
	 */
	private function pbckDescribeGeometry(string $gutter, int $nb, array $widths, array $stems): array
	{
		$columns = [];

		foreach ($widths as $i => $width) {
			$columns[] = [
				'id'         => 'block_' . $stems[$i],
				'width'      => $this->pbckNum($width),
				'real_width' => $this->pbckNum($this->pbckRealWidth($width, $nb, $gutter)) . '%',
			];
		}

		return [
			'gutter'      => $gutter,
			'data_nb'     => $nb,
			'columns'     => $columns,
			'explanation' => 'data-width is only the key the generated stylesheet matches on. The rendered '
				. 'width comes from the <style class="ckcolumnwidth"> block inside the row, whose selectors '
				. 'are [data-gutter][data-nb] [data-width]. All four values are written together here, so '
				. 'they agree; editing any one of them by hand afterwards will break the row silently.',
		];
	}

	// -----------------------------------------------------------------------
	// Insertion
	// -----------------------------------------------------------------------

	/**
	 * Splice the row into the document at the requested position.
	 *
	 * @param array<string,mixed> $arguments
	 * @return array{html?:string, position?:string, error?:string}
	 */
	private function pbckInsert(string $html, string $rowHtml, array $arguments): array
	{
		$position = strtolower(trim((string) ($arguments['position'] ?? 'append')));

		if (!\in_array($position, ['append', 'prepend', 'before', 'after'], true)) {
			return ['error' => 'position must be one of append, prepend, before, after.'];
		}

		if ($html === '') {
			if ($position === 'before' || $position === 'after') {
				return ['error' => 'This page has no content at all, so there is no row to insert relative to. '
					. 'Use position "append".'];
			}

			return [
				'html'     => $rowHtml,
				'position' => 'first row on a previously empty page',
			];
		}

		$dom = $this->pbckParse($html);

		if ($dom === null) {
			return ['error' => 'The page content could not be parsed, so it cannot be edited safely.'];
		}

		$topRows = [];

		foreach ($dom->find('div.rowck') as $node) {
			if (!$this->pbckIsNestedNode($node)) {
				$topRows[] = $node;
			}
		}

		$anchor = null;
		$mode   = 'after';
		$label  = '';

		if ($position === 'before' || $position === 'after') {
			$wanted = trim((string) ($arguments['relative_to_row_id'] ?? ''));

			if ($wanted === '') {
				$dom->clear();

				return ['error' => 'relative_to_row_id is required when position is before or after.'];
			}

			foreach ($dom->find('div.rowck') as $node) {
				$id = (string) ($node->getAttribute('id') ?? '');

				if ($id === $wanted || $id === 'row_' . $wanted) {
					$anchor = $node;
					break;
				}
			}

			if ($anchor === null) {
				$dom->clear();

				return ['error' => sprintf('No .rowck with id "%s" (or "row_%s") on this page.', $wanted, $wanted)];
			}

			$mode  = $position;
			$label = $position . ' row ' . (string) ($anchor->getAttribute('id') ?? '');

			if ($this->pbckIsNestedNode($anchor)) {
				$label .= ' (which is a NESTED row, so the new row becomes a sibling inside the same column)';
			}
		} elseif ($topRows !== []) {
			$anchor = $position === 'append' ? end($topRows) : $topRows[0];
			$mode   = $position === 'append' ? 'after' : 'before';
			$label  = $position === 'append' ? 'after the last top-level row' : 'before the first top-level row';
		} else {
			// No rows yet. Land after the page-level singletons rather than at
			// the very top, because the renderer strips them by position-blind
			// regex but the builder expects them first.
			foreach (['div.pagebuilderckparams', 'div.googlefontscall'] as $selector) {
				$found = $dom->find($selector, 0);

				if ($found !== null) {
					$anchor = $found;
					$mode   = 'after';
					$label  = 'after ' . $selector . ' (the page had no rows)';
					break;
				}
			}

			if ($anchor === null) {
				$dom->clear();

				return [
					'html'     => $html . $rowHtml,
					'position' => 'appended to the end (the page had no rows and no page-level singletons)',
				];
			}
		}

		// simple_html_dom has no insertBefore/insertAfter. Assigning outertext
		// is the supported splice: the getter runs first and returns the node's
		// current markup, so this wraps rather than replaces.
		$anchor->outertext = $mode === 'before'
			? $rowHtml . $anchor->outertext
			: $anchor->outertext . $rowHtml;

		$updated = $this->pbckSerialise($dom, $html);
		$dom->clear();

		return ['html' => $updated, 'position' => $label];
	}

	/**
	 * True when the node sits inside another row or column.
	 *
	 * Top-level rows are the ones the page stacks vertically; a row inside a
	 * `.blockck` is a nested row and belongs to its parent column.
	 */
	private function pbckIsNestedNode(object $node): bool
	{
		$parent = $node->parent();

		while ($parent !== null && $parent->tag !== 'root') {
			$classes = preg_split('/\s+/', trim((string) ($parent->getAttribute('class') ?? ''))) ?: [];

			if (\in_array('rowck', $classes, true) || \in_array('blockck', $classes, true)) {
				return true;
			}

			$parent = $parent->parent();
		}

		return false;
	}
}
