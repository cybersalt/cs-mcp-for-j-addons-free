<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Content;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckBootTrait;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckContentTrait;
use Joomla\CMS\User\User;

/**
 * Move a block to a different column and/or a different position in its column.
 *
 * The block is relocated VERBATIM — the same markup, the same id, the same
 * `.ckprops` options and the same `.ckstyle` CSS. It is not rebuilt.
 *
 * ---------------------------------------------------------------------------
 * THE ID IS PRESERVED, AND THAT IS NOT AN IMPLEMENTATION DETAIL
 * ---------------------------------------------------------------------------
 *
 * Every rule in a block's `.ckstyle` is `#id`-scoped, and that stylesheet lives
 * INSIDE the block, so it travels with it. If this tool minted a fresh id on
 * move — as a duplicate operation must, to avoid two blocks sharing one set of
 * rules — the CSS inside the moved block would go on naming the old id and the
 * block would arrive unstyled. Addons that build fragment links from the id
 * (`#id_tabs-N` in tabs and accordion) would break the same way.
 *
 * Moving is therefore the one structural operation where keeping the id is
 * mandatory rather than merely convenient. A move within one page cannot
 * duplicate an id, because the block leaves one place as it arrives in another.
 */
final class MoveBlockTool extends AbstractTool
{
	use PagebuilderckBootTrait;
	use PagebuilderckContentTrait;
	use PageContentEditTrait;

	public function getName(): string { return 'move_pagebuilderck_block'; }

	public function getDescription(): string
	{
		return 'Move a block to a different column and/or a different position within a Page Builder CK '
			. 'page. Arguments: page_id, block_id, column_id (the destination div.blockck — omit to stay '
			. 'in the current column) and position (zero-based index in the destination column\'s block '
			. 'list — omit to append at the end). Supply at least one of column_id and position. '
			. 'Both ids come from get_pagebuilderck_page_outline. Moves are within one page only; there '
			. 'is no cross-page move, because a block\'s styling and the page\'s style associations are '
			. 'separate things. '
			. 'The block moves verbatim: same markup, same options, same CSS, and SAME ID. The id is '
			. 'preserved deliberately — every rule in the block\'s .ckstyle is #id-scoped and that '
			. 'stylesheet travels inside the block, so a re-issued id would land the block in its new '
			. 'column with its styling orphaned. '
			. 'position indexes the destination column\'s BLOCK list as the outline reports it. Nested '
			. 'rows are siblings of those blocks in the markup but are not counted. When the destination '
			. 'is the block\'s current column, the index is read AFTER the block is taken out, so moving '
			. 'the first of three blocks to position 2 puts it last. '
			. 'Refuses if the page\'s existing content has any fatal validation problem. Nothing partial '
			. 'is written. To move a whole row or column, or to move between pages, use '
			. 'write_pagebuilderck_page_content.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'page_id'   => ['type' => 'integer', 'description' => 'Row id in #__pagebuilderck_pages.'],
				'id'        => ['type' => 'integer', 'description' => 'Alias for page_id.'],
				'block_id'  => ['type' => 'string', 'description' => 'The id attribute of the .cktype div to move.'],
				'column_id' => ['type' => 'string', 'description' => 'Destination div.blockck id. Omit to keep the block in its current column.'],
				'position'  => ['type' => 'integer', 'description' => 'Zero-based index in the destination column\'s block list, read after the block has been taken out. Omit to append at the end.'],
			],
			'required'             => ['block_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (($guard = $this->pbckContentGuard()) !== null) {
			return $guard;
		}

		$pageId   = $this->pbckPageId($arguments);
		$blockId  = $this->requireString($arguments, 'block_id');
		$targetId = trim((string) ($arguments['column_id'] ?? ''));
		$position = \array_key_exists('position', $arguments) ? (int) $arguments['position'] : null;

		if ($targetId === '' && $position === null) {
			return ToolResult::error(
				'Nothing to do. Supply column_id to move the block to another column, position to reorder '
				. 'it where it is, or both.'
			);
		}

		$row = $this->pbckLoadPageRow($pageId);

		if ($row === null) {
			return $this->pbckPageNotFoundError($pageId);
		}

		$original = (string) $row['htmlcode'];
		$enabled  = $this->pbckEnabledAddonTypes();

		$sourceProblems = $this->pbckValidate($original, $enabled);

		if (($refusal = $this->pbckAssertSourceEditable($pageId, $sourceProblems)) !== null) {
			return $refusal;
		}

		$dom = $this->pbckParse($original);

		if ($dom === null) {
			return ToolResult::error('The content of page ' . $pageId . ' could not be parsed.');
		}

		$block = $this->pbckFindBlock($dom, $blockId);

		if ($block === null) {
			$known = $this->pbckBlockIds($dom);
			$dom->clear();

			return ToolResult::json([
				'ok'    => false,
				'error' => 'No block with id "' . $blockId . '" on page ' . $pageId . '.',
				'block_ids_on_page' => $known,
				'component' => $this->pbckEditionNotice(),
			], true);
		}

		$sourceContent = $this->pbckNearestAncestorWithClass($block, 'innercontent');
		$sourceColumn  = $this->pbckNearestAncestorWithClass($block, 'blockck');

		if ($sourceContent === null || $sourceColumn === null) {
			$dom->clear();

			return ToolResult::error(
				'Block "' . $blockId . '" does not sit inside a column\'s div.innercontent, so there is no '
				. 'block list to move it within. It may be nested inside another block, which is markup '
				. 'the builder does not produce. Use write_pagebuilderck_page_content.'
			);
		}

		$sourceColumnId = (string) $sourceColumn->getAttribute('id');

		if ($targetId === '') {
			$targetContent = $sourceContent;
			$targetId      = $sourceColumnId;
		} else {
			$targetColumn = $this->pbckFindColumn($dom, $targetId);

			if ($targetColumn === null) {
				$known = $this->pbckColumnIds($dom);
				$dom->clear();

				return ToolResult::json([
					'ok'    => false,
					'error' => 'No column with id "' . $targetId . '" on page ' . $pageId . '.',
					'hint'  => 'Columns are div.blockck; blocks are div.cktype. Run '
						. 'get_pagebuilderck_page_outline for the column ids.',
					'column_ids_on_page' => $known,
					'component' => $this->pbckEditionNotice(),
				], true);
			}

			$targetContent = $this->pbckColumnContent($targetColumn);

			if ($targetContent === null) {
				$dom->clear();

				return ToolResult::error(
					'Column "' . $targetId . '" has no div.innercontent, so it cannot hold blocks. Repair '
					. 'it with write_pagebuilderck_page_content.'
				);
			}
		}

		// A block cannot be moved inside itself. Only reachable when a nested row
		// lives inside the block being moved, which the builder does not produce
		// but hand-edited markup can.
		if ($this->pbckIsDescendantOf($targetContent, $block)) {
			$dom->clear();

			return ToolResult::error(
				'Refused: column "' . $targetId . '" is inside block "' . $blockId . '", so moving the '
				. 'block there would detach it from the document.'
			);
		}

		$sameColumn = $targetContent === $sourceContent;

		if ($sameColumn && $position === null) {
			$dom->clear();

			return ToolResult::error(
				'Nothing to do. Block "' . $blockId . '" is already in column "' . $sourceColumnId
				. '" and no position was given.'
			);
		}

		$markup = (string) $block->outertext();

		// Detach first. The rebuild below skips this node by id when the
		// destination is the same .innercontent; when it is a different one, this
		// emptied outertext is what removes the block from where it was. Doing it
		// before the destination is rebuilt means a destination that happens to
		// contain the source subtree (a nested row) picks up the removal.
		$block->outertext = '';

		$sourceOrder = null;

		if (!$sameColumn) {
			$sourceOrder = $this->pbckRebuildColumnContent($sourceContent, $blockId, null, null)['block_ids'];
		}

		$rebuilt = $this->pbckRebuildColumnContent($targetContent, $blockId, $markup, $position);

		$targetContent->innertext = $rebuilt['html'];

		$result = $this->pbckSerialise($dom, $original);
		$dom->clear();

		$resultProblems = $this->pbckValidate($result, $enabled);

		$refusal = $this->pbckAssertResultSafe(
			$resultProblems,
			'Moving block ' . $blockId . ' to column ' . $targetId
		);

		if ($refusal !== null) {
			return $refusal;
		}

		if ($result === $original) {
			return ToolResult::json([
				'ok'       => true,
				'action'   => 'no change',
				'page_id'  => $pageId,
				'block_id' => $blockId,
				'note'     => 'The block is already at that position in that column, so nothing was '
					. 'written and `modified` was not touched.',
				'component' => $this->pbckEditionNotice(),
			]);
		}

		$modified = $this->pbckStorePageHtml($pageId, $result);

		$payload = [
			'ok'       => true,
			'action'   => 'block moved',
			'page_id'  => $pageId,
			'block_id' => $blockId,
			'from'     => ['column_id' => $sourceColumnId],
			'to'       => ['column_id' => $targetId, 'position' => $rebuilt['inserted_at']],
			'destination_block_order' => $rebuilt['block_ids'],
			'modified' => $modified,
			'bytes'    => ['before' => \strlen($original), 'after' => \strlen($result)],
		];

		if ($sourceOrder !== null) {
			$payload['from']['remaining_block_order'] = $sourceOrder;

			if ($sourceOrder === []) {
				$payload['from']['now_empty'] = 'Column "' . $sourceColumnId . '" has no blocks left. An '
					. 'empty column still occupies its share of the row width — it does not collapse.';
			}
		}

		$payload['id_preserved'] = 'The block kept its id, "' . $blockId . '". Its .ckstyle CSS is '
			. '#' . $blockId . '-scoped and travels inside the block, so re-issuing the id on a move '
			. 'would arrive with the styling orphaned. Duplicating a block is the opposite case and does '
			. 'need fresh ids.';

		$payload['css_unchanged'] = 'Nothing about the block was rebuilt — same markup, same options, '
			. 'same CSS. If the destination column is a different width, any fixed pixel sizing in the '
			. 'block\'s CSS will not adapt; Page Builder CK only regenerates that CSS when a human '
			. 'applies in the builder.';

		$grouped = $this->pbckGroupProblems($resultProblems);

		if (isset($grouped['warning'])) {
			$payload['content_warnings'] = $grouped['warning'];
		}

		$payload['notes']     = $this->pbckWriteNotes();
		$payload['component'] = $this->pbckEditionNotice();

		return ToolResult::json($payload);
	}
}
