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
 * Change an existing row's column count, per-column widths and/or gutter.
 *
 * Column geometry lives in four places that must agree — `.rowck[data-gutter]`,
 * `.rowck[data-nb]`, `.blockck[data-width]` and `.blockck[data-real-width]` —
 * and the width actually rendered comes from a generated stylesheet inside the
 * row whose selectors are keyed on the first three. Changing any one of them on
 * its own makes every selector in that stylesheet miss, and the whole row then
 * renders with no width at all: no error, nothing in the log, just a collapsed
 * row. So this tool always rewrites all four together and regenerates the
 * `<style class="ckcolumnwidth">` block from scratch, exactly as the vendor's
 * `ckSetColumnsWidth()` does (media/assets/pagebuilderck.js:2091).
 *
 * Reducing the column count destroys the trailing columns and everything in
 * them. That is refused outright unless `force` is set, and the refusal names
 * every block that would be lost.
 */
final class SetRowColumnsTool extends AbstractTool
{
	use PagebuilderckBootTrait;
	use PagebuilderckGeometryTrait;
	use PagebuilderckContentTrait;

	private const MAX_COLUMNS = 12;

	public function getName(): string { return 'set_pagebuilderck_row_columns'; }

	public function getDescription(): string
	{
		return 'Change the column count, the per-column widths and/or the gutter of one row on a Page Builder '
			. 'CK page. Arguments: page_id and row_id (both required), then any of columns, widths '
			. '(explicit percentages, one per column) and gutter. '
			. 'Rewrites all four places the geometry lives — .rowck[data-gutter], .rowck[data-nb], '
			. '.blockck[data-width], .blockck[data-real-width] — and regenerates the row\'s '
			. '<style class="ckcolumnwidth"> block, because the rendered width comes from that stylesheet and '
			. 'its selectors are keyed on the other three. Rewriting fewer than all four leaves a row whose '
			. 'columns silently render with no width. '
			. 'INCREASING the count appends empty columns. DECREASING it deletes the TRAILING columns and '
			. 'everything inside them; that is refused unless force is true, and the refusal lists every '
			. 'block and nested row that would be lost. '
			. 'Does not touch responsive-range overrides (data-width-1 … data-width-4 and the matching '
			. '.ckcolumnwidth1 … .ckcolumnwidth4 style blocks) — those are reported but left alone, so a row '
			. 'with responsive overrides may still use its old widths below the relevant breakpoint. '
			. 'Pass dry_run to see the new geometry and what would be removed, without writing.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'page_id' => ['type' => 'integer', 'description' => 'Row id in #__pagebuilderck_pages.'],
				'row_id'  => ['type' => 'string', 'description' => 'The row\'s id, with or without the row_ prefix.'],
				'columns' => ['type' => 'integer', 'description' => 'Target column count, 1 to 12. Omit to keep the current count.'],
				'widths'  => [
					'type'        => 'array',
					'items'       => ['type' => 'number'],
					'description' => 'Explicit logical widths as percentages, one per column. Sets the column count '
						. 'when columns is not given, and must agree with it when it is.',
				],
				'gutter'  => ['type' => 'string', 'description' => 'Inter-column gap as a percentage, e.g. "2%". Omit to keep the row\'s current gutter.'],
				'force'   => ['type' => 'boolean', 'description' => 'Allow the removal of trailing columns that still contain blocks or nested rows. Destructive.'],
				'dry_run' => ['type' => 'boolean', 'description' => 'Report the result without writing.'],
			],
			'required'             => ['page_id', 'row_id'],
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
		$rowRef = $this->requireString($arguments, 'row_id');

		$page = $this->db->setQuery(
			'SELECT ' . $this->db->quoteName('id') . ', ' . $this->db->quoteName('title')
			. ', ' . $this->db->quoteName('htmlcode') . ', ' . $this->db->quoteName('checked_out')
			. ' FROM ' . $this->db->quoteName($this->pbckTable('pages'))
			. ' WHERE ' . $this->db->quoteName('id') . ' = ' . $pageId
		)->loadAssoc();

		if (!$page) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf('No row with id %d in %s.', $pageId, $this->pbckTable('pages')),
			], true);
		}

		$html = (string) ($page['htmlcode'] ?? '');

		if ($html === '') {
			return ToolResult::json(['ok' => false, 'error' => sprintf('Page %d has no content.', $pageId)], true);
		}

		if (!$this->pbckIsLossless($html)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf(
					'The stored content of page %d does not survive a parse/serialise round-trip byte for byte, '
						. 'so rewriting a row would silently alter markup nobody asked to change. Refusing.',
					$pageId
				),
			], true);
		}

		$dom = $this->pbckParse($html);

		if ($dom === null) {
			return ToolResult::json(['ok' => false, 'error' => 'The page content could not be parsed.'], true);
		}

		$rowNode = null;

		foreach ($dom->find('div.rowck') as $node) {
			$id = (string) ($node->getAttribute('id') ?? '');

			if ($id === $rowRef || $id === 'row_' . $rowRef) {
				$rowNode = $node;
				break;
			}
		}

		if ($rowNode === null) {
			$dom->clear();

			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf('No .rowck with id "%s" (or "row_%s") on page %d.', $rowRef, $rowRef, $pageId),
			], true);
		}

		$rowId    = (string) ($rowNode->getAttribute('id') ?? '');
		$existing = $this->pbckOwnColumns($rowNode);
		$current  = \count($existing);

		// --- resolve the target geometry --------------------------------------
		$gutterRaw = \array_key_exists('gutter', $arguments)
			? (string) $arguments['gutter']
			: (string) ($rowNode->getAttribute('data-gutter') ?? '');

		$gutter = $this->pbckNormaliseGutter($gutterRaw);

		if ($gutter === null) {
			$dom->clear();

			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf(
					'"%s" is not a usable gutter. It must be a non-negative percentage such as "2%%" or "2", '
						. 'because it is written verbatim into a [data-gutter="…"] attribute selector.',
					$gutterRaw
				),
			], true);
		}

		$widthsGiven = \array_key_exists('widths', $arguments) && \is_array($arguments['widths']);
		$countGiven  = \array_key_exists('columns', $arguments);

		if ($widthsGiven && $countGiven && \count($arguments['widths']) !== (int) $arguments['columns']) {
			$dom->clear();

			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf(
					'columns is %d but widths has %d entries. Supply one or the other, or make them agree.',
					(int) $arguments['columns'],
					\count($arguments['widths'])
				),
			], true);
		}

		$target = $widthsGiven ? \count($arguments['widths']) : ($countGiven ? (int) $arguments['columns'] : $current);

		if ($target < 1 || $target > self::MAX_COLUMNS) {
			$dom->clear();

			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf(
					'The target column count must be between 1 and %d; got %d. A row with no columns has '
						. 'nowhere to put content — delete the row instead.',
					self::MAX_COLUMNS,
					$target
				),
			], true);
		}

		$warnings = [];

		if ($widthsGiven) {
			$widths = [];

			foreach ($arguments['widths'] as $raw) {
				if (!is_numeric($raw) || (float) $raw <= 0 || (float) $raw > 100) {
					$dom->clear();

					return ToolResult::json([
						'ok'    => false,
						'error' => 'Every entry in widths must be a number greater than 0 and no more than 100.',
					], true);
				}

				$widths[] = (float) $raw;
			}

			$sum = array_sum($widths);

			if (abs($sum - 100.0) > 0.01) {
				$warnings[] = sprintf(
					'The supplied widths total %s%%, not 100%%. Page Builder CK will honour that, but unless '
						. 'the row is an advanced layout the columns will not fill their container.',
					$this->pbckNum($sum)
				);
			}
		} elseif ($target !== $current) {
			// The count changed, so any previous widths are meaningless. This is
			// what ckInitBlocksSize() does (pagebuilderck.js:1379).
			$widths = array_fill(0, $target, 100.0 / $target);
		} else {
			// Count unchanged — a gutter-only edit. Keep each column's own
			// logical width and just recompute the gutter-adjusted real widths,
			// which is exactly ckUpdateGutter() (pagebuilderck.js:2085).
			$widths = [];

			foreach ($existing as $i => $colNode) {
				$raw      = trim((string) ($colNode->getAttribute('data-width') ?? ''));
				$widths[] = is_numeric($raw) ? (float) $raw : 100.0 / $target;

				if (!is_numeric($raw)) {
					$warnings[] = sprintf(
						'Column %d (%s) had no usable data-width, so it has been given an equal share. Its '
							. 'previous rendered width, if any, is lost.',
						$i + 1,
						(string) ($colNode->getAttribute('id') ?? 'no id')
					);
				}
			}
		}

		// --- destructive-reduction guard --------------------------------------
		$doomed  = \array_slice($existing, $target);
		$losses  = [];

		foreach ($doomed as $colNode) {
			$loss = [
				'column_id' => (string) ($colNode->getAttribute('id') ?? ''),
				'blocks'    => [],
			];

			foreach ($colNode->find('div.cktype') as $blockNode) {
				$loss['blocks'][] = [
					'id'   => (string) ($blockNode->getAttribute('id') ?? ''),
					'type' => (string) ($blockNode->getAttribute('data-type') ?? ''),
				];
			}

			$nested = \count($colNode->find('div.rowck') ?: []);

			if ($nested > 0) {
				$loss['nested_rows'] = $nested;
			}

			$losses[] = $loss;
		}

		$hasContent = false;

		foreach ($losses as $loss) {
			if ($loss['blocks'] !== [] || isset($loss['nested_rows'])) {
				$hasContent = true;
				break;
			}
		}

		if ($hasContent && !($arguments['force'] ?? false)) {
			$dom->clear();

			return ToolResult::json([
				'ok'           => false,
				'error'        => sprintf(
					'Reducing row %s from %d columns to %d would delete the trailing column(s) listed in '
						. 'would_be_lost, which still contain content. Nothing was written. Move the content '
						. 'out first, or pass force: true to delete it.',
					$rowId,
					$current,
					$target
				),
				'row_id'       => $rowId,
				'from_columns' => $current,
				'to_columns'   => $target,
				'would_be_lost' => $losses,
				'note'         => 'Columns are removed from the END of the row. There is no way to choose which '
					. 'ones go; if you need to keep the last column and drop an earlier one, move the content '
					. 'between columns first.',
			], true);
		}

		// --- responsive overrides we are deliberately not touching -------------
		$responsive = $this->pbckResponsiveOverrides($rowNode);

		if ($responsive !== []) {
			$warnings[] = 'This row carries responsive-range overrides (' . implode(', ', $responsive) . '). '
				. 'They are stored separately, in data-width-N attributes and .ckcolumnwidthN style blocks, and '
				. 'have NOT been regenerated. Below the relevant breakpoints the row will keep its old widths, '
				. 'and if the column count changed those overrides now name a data-nb that no longer exists, so '
				. 'they will simply stop matching.';
		}

		// --- mutate ------------------------------------------------------------
		$rowNode->setAttribute('data-gutter', $gutter);
		$rowNode->setAttribute('data-nb', (string) $target);

		$survivors = \array_slice($existing, 0, $target);

		foreach ($survivors as $i => $colNode) {
			$colNode->setAttribute('data-width', $this->pbckNum($widths[$i]));
			$colNode->setAttribute('data-real-width', $this->pbckNum($this->pbckRealWidth($widths[$i], $target, $gutter)) . '%');
		}

		$removed = [];

		foreach ($doomed as $colNode) {
			$removed[]           = (string) ($colNode->getAttribute('id') ?? '');
			$colNode->outertext = '';
		}

		$addedIds = [];

		if ($target > $current) {
			$taken  = $this->pbckCollectIds($html);
			$fresh  = '';

			for ($i = $current; $i < $target; $i++) {
				do {
					$stem = $this->pbckNewId($taken);
				} while (\in_array('block_' . $stem, $taken, true) || \in_array('row_' . $stem, $taken, true));

				$taken[]    = 'block_' . $stem;
				$taken[]    = 'row_' . $stem;
				$addedIds[] = 'block_' . $stem;
				$fresh     .= $this->pbckBuildColumn('block_' . $stem, $widths[$i], $target, $gutter);
			}

			// Read outertext only AFTER the attribute changes above, or assigning
			// it here would freeze the pre-change markup and silently drop them.
			$attachResult = $this->pbckAttachColumns($rowNode, $survivors, $fresh);

			if ($attachResult !== null) {
				$dom->clear();

				return ToolResult::json(['ok' => false, 'error' => $attachResult], true);
			}
		}

		$styleHtml = $this->pbckColumnWidthStyle($gutter, $target, $widths);
		$styleNode = null;

		foreach ($rowNode->find('style.ckcolumnwidth') as $node) {
			if ($node->parent() === $rowNode) {
				$styleNode = $node;
				break;
			}
		}

		if ($styleNode !== null) {
			// Replace the whole element, not just its text, so the class list and
			// any stray attributes are normalised at the same time.
			$styleNode->outertext = $styleHtml;
		} else {
			$first = $rowNode->first_child();

			if ($first === null) {
				$rowNode->innertext = $styleHtml;
			} else {
				$first->outertext = $styleHtml . $first->outertext;
			}
		}

		$updated = $this->pbckSerialise($dom, $html);
		$dom->clear();

		$updated = $this->pbckCollapseRoot($updated);

		// --- validate ----------------------------------------------------------
		$enabledTypes = array_merge($this->pbckEnabledAddonTypes(), $this->pbckCoreTypes());
		$problems     = $this->pbckValidate($updated, $enabledTypes);

		if ($this->pbckHasFatal($problems)) {
			$before = $this->pbckValidate($html, $enabledTypes);

			return ToolResult::json([
				'ok'                    => false,
				'error'                 => 'The page would have a fatal structural problem after this change, so nothing was '
					. 'written. Page Builder CK content is raw HTML with no encoder to normalise it, so a '
					. 'half-correct write is not recoverable — refusing is the only safe answer.',
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

		$geometry = [];

		foreach ($widths as $i => $width) {
			$geometry[] = [
				'id'         => $addedIds !== [] && $i >= $current
					? $addedIds[$i - $current]
					: (string) ($survivors[$i]->getAttribute('id') ?? ''),
				'width'      => $this->pbckNum($width),
				'real_width' => $this->pbckNum($this->pbckRealWidth($width, $target, $gutter)) . '%',
			];
		}

		$result = [
			'ok'           => true,
			'page'         => ['id' => $pageId, 'title' => (string) $page['title']],
			'row_id'       => $rowId,
			'from_columns' => $current,
			'to_columns'   => $target,
			'gutter'       => $gutter,
			'columns'      => $geometry,
			'style_block'  => $styleHtml,
		];

		if ($addedIds !== []) {
			$result['added_columns'] = $addedIds;
			$result['note']          = 'The new columns are empty. Page Builder CK renders an empty column as a '
				. 'zero-height div, so they take up their share of the row width but show nothing until '
				. 'blocks are added.';
		}

		if ($removed !== []) {
			$result['removed_columns'] = $removed;
			$result['destroyed']       = $losses;
			$result['warning']         = 'Trailing columns were deleted along with everything inside them. '
				. 'Page Builder CK keeps per-page backups under administrator/components/com_pagebuilderck/'
				. 'backup only when the change is made through its own editor, so this deletion is not in '
				. 'that history.';
		}

		if ($warnings !== []) {
			$result['warnings'] = $warnings;
		}

		if (($checkedOut = $this->pbckCheckedOutBy($page)) !== null) {
			$result['checked_out'] = sprintf(
				'This page is checked out to user %d. Page Builder CK never releases a checkout '
					. 'automatically, and if that session is still open its next Save will overwrite this '
					. 'change with the content it loaded beforehand.',
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

		if (($clobber = $this->pbckEditorClobberNotice(['htmlcode', 'modified'])) !== null) {
			$result['editor_clobber'] = $clobber;
		}

		$result['component'] = $this->pbckEditionNotice();

		return ToolResult::json($result);
	}

	// -----------------------------------------------------------------------
	// Row inspection
	// -----------------------------------------------------------------------

	/**
	 * The columns this row owns, in document order.
	 *
	 * A `.blockck` found under a row may belong to a NESTED row instead, so
	 * membership is decided by nearest-ancestor row rather than by depth.
	 *
	 * @return array<int,object>
	 */
	private function pbckOwnColumns(object $rowNode): array
	{
		$columns = [];

		foreach ($rowNode->find('div.blockck') as $node) {
			if ($this->pbckNearestRow($node) === $rowNode) {
				$columns[] = $node;
			}
		}

		return $columns;
	}

	private function pbckNearestRow(object $node): ?object
	{
		$parent = $node->parent();

		while ($parent !== null && $parent->tag !== 'root') {
			$classes = preg_split('/\s+/', trim((string) ($parent->getAttribute('class') ?? ''))) ?: [];

			if (\in_array('rowck', $classes, true)) {
				return $parent;
			}

			$parent = $parent->parent();
		}

		return null;
	}

	/**
	 * Responsive-range overrides present on this row, which we do not rewrite.
	 *
	 * @return array<int,string>
	 */
	private function pbckResponsiveOverrides(object $rowNode): array
	{
		$found = [];

		for ($range = 1; $range <= 4; $range++) {
			foreach ($rowNode->find('style.ckcolumnwidth' . $range) as $node) {
				if ($node->parent() === $rowNode) {
					$found[] = 'style.ckcolumnwidth' . $range;
					break;
				}
			}

			foreach ($this->pbckOwnColumns($rowNode) as $colNode) {
				if (trim((string) ($colNode->getAttribute('data-width-' . $range) ?? '')) !== '') {
					$found[] = 'data-width-' . $range;
					break;
				}
			}
		}

		return array_values(array_unique($found));
	}

	/**
	 * Splice freshly built columns in after the last surviving one.
	 *
	 * @param array<int,object> $survivors
	 * @return string|null Error message, or null on success.
	 */
	private function pbckAttachColumns(object $rowNode, array $survivors, string $fresh): ?string
	{
		if ($survivors !== []) {
			$last             = $survivors[\count($survivors) - 1];
			$last->outertext  = $last->outertext . $fresh;

			return null;
		}

		// No surviving columns to hang them off, so append into the row's own
		// .inner wrapper — the element ckAddBlock() appends to.
		foreach ($rowNode->find('div.inner') as $node) {
			if ($node->parent() === $rowNode) {
				$node->innertext = $node->innertext . $fresh;

				return null;
			}
		}

		return 'This row has no columns and no direct child div.inner to put them in, so its markup does not '
			. 'match the shape Page Builder CK produces. Refusing to guess where the columns belong.';
	}

	// -----------------------------------------------------------------------
	// Markup generation and geometry maths
	//
	// Deliberately mirrors AddRowTool. The two are kept side by side rather than
	// shared because the plugin manifest enumerates tool classes only; if a
	// third tool needs this, hoist it into PagebuilderckContentTrait.
	// -----------------------------------------------------------------------

}
