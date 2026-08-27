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
 * Remove a block from a page.
 *
 * A block is self-contained, so deleting it is genuinely complete: its options
 * (`.ckprops`), its `#id`-scoped stylesheet (`.ckstyle`) and its payload
 * (`.inner`) all live inside the `div.cktype` and all go with it. Nothing is
 * left behind in a page-level stylesheet, because Page Builder CK does not keep
 * one — block CSS is never centralised.
 *
 * The corollary is that the deletion is irreversible from inside the page. The
 * response therefore reports exactly what was removed, including the CSS byte
 * count and the option tabs, so the caller can put it back if this was a
 * mistake. A direct column write leaves no `.pbck` backup behind, unlike the
 * vendor's own save.
 */
final class DeleteBlockTool extends AbstractTool
{
	use PagebuilderckBootTrait;
	use PagebuilderckContentTrait;
	use PageContentEditTrait;

	public function getName(): string { return 'delete_pagebuilderck_block'; }

	public function getDescription(): string
	{
		return 'Remove a block from a Page Builder CK page. Arguments: page_id, block_id (from '
			. 'get_pagebuilderck_page_outline). '
			. 'The removal is complete, because a Page Builder CK block is self-contained: its options '
			. 'div, its payload and its #id-scoped .ckstyle stylesheet all live inside the div.cktype and '
			. 'all go with it. There is no page-level stylesheet to clean up afterwards — Page Builder CK '
			. 'never centralises block CSS. Anything the block\'s markup referenced by id, such as an '
			. 'anchor link elsewhere in the page, is of course now dangling. '
			. 'Reports what was removed — type, byte size, option tab names, CSS byte count, and any '
			. 'blocks or nested rows that were inside it — so the deletion can be reversed by hand if it '
			. 'was a mistake. Reversing it is the only way back: a direct column write leaves no .pbck '
			. 'backup, unlike Page Builder CK\'s own save. '
			. 'Refuses if the page\'s existing content has any fatal validation problem. Deletes exactly '
			. 'one block; to remove a row or a column, use write_pagebuilderck_page_content.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'page_id'  => ['type' => 'integer', 'description' => 'Row id in #__pagebuilderck_pages.'],
				'id'       => ['type' => 'integer', 'description' => 'Alias for page_id.'],
				'block_id' => ['type' => 'string', 'description' => 'The id attribute of the .cktype div to remove.'],
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

		$pageId  = $this->pbckPageId($arguments);
		$blockId = $this->requireString($arguments, 'block_id');

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
				'error' => 'No block with id "' . $blockId . '" on page ' . $pageId . '. Nothing was '
					. 'removed.',
				'block_ids_on_page' => $known,
				'hint'  => 'Columns (div.blockck) and rows (div.rowck) are not blocks and cannot be '
					. 'deleted with this tool.',
				'component' => $this->pbckEditionNotice(),
			], true);
		}

		// Describe it before it is gone — this is the only record of what was here.
		$removed = $this->describe($block, $blockId, $enabled);

		$column        = $this->pbckNearestAncestorWithClass($block, 'blockck');
		$columnId      = $column === null ? '' : (string) $column->getAttribute('id');
		$innerContent  = $this->pbckNearestAncestorWithClass($block, 'innercontent');

		$block->outertext = '';

		$remaining = null;

		if ($innerContent !== null) {
			$remaining = $this->pbckRebuildColumnContent($innerContent, $blockId, null, null)['block_ids'];
		}

		$result = $this->pbckSerialise($dom, $original);
		$dom->clear();

		$resultProblems = $this->pbckValidate($result, $enabled);

		$refusal = $this->pbckAssertResultSafe($resultProblems, 'Deleting block ' . $blockId);

		if ($refusal !== null) {
			return $refusal;
		}

		$modified = $this->pbckStorePageHtml($pageId, $result);

		$payload = [
			'ok'       => true,
			'action'   => 'block deleted',
			'page_id'  => $pageId,
			'removed'  => $removed,
			'modified' => $modified,
			'bytes'    => ['before' => \strlen($original), 'after' => \strlen($result)],
		];

		if ($columnId !== '') {
			$payload['column_id'] = $columnId;
		}

		if ($remaining !== null) {
			$payload['column_block_order'] = $remaining;

			if ($remaining === []) {
				$payload['column_now_empty'] = 'Column "' . $columnId . '" has no blocks left. An empty '
					. 'column still occupies its share of the row width — it does not collapse, and the '
					. 'row\'s data-nb is unchanged and still correct.';
			}
		}

		$payload['css_went_with_it'] = 'The block\'s .ckstyle stylesheet was removed with it, because it '
			. 'lives inside the div.cktype rather than in any page-level stylesheet. Page Builder CK '
			. 'never centralises block CSS, so there is nothing left over to clean up — and nothing to '
			. 'restore from either.';

		$grouped = $this->pbckGroupProblems($resultProblems);

		if (isset($grouped['warning'])) {
			$payload['content_warnings'] = $grouped['warning'];
		}

		$payload['notes']     = $this->pbckWriteNotes();
		$payload['component'] = $this->pbckEditionNotice();

		return ToolResult::json($payload);
	}

	/**
	 * A record of the block, taken before it is removed.
	 *
	 * @return array<string,mixed>
	 */
	private function describe(object $block, string $blockId, array $enabled): array
	{
		$type  = (string) $block->getAttribute('data-type');
		$props = $this->pbckReadProps($block);

		$style = $this->pbckDirectChild($block, 'ckstyle');
		$inner = $this->pbckDirectChild($block, 'inner');

		$record = [
			'block_id'      => $blockId,
			'data_type'     => $type === '' ? null : $type,
			'outer_bytes'   => \strlen((string) $block->outertext()),
			'option_tabs'   => array_keys($props),
			'ckstyle_bytes' => $style === null ? 0 : \strlen((string) $style->innertext),
			'inner_bytes'   => $inner === null ? null : \strlen((string) $inner->innertext),
		];

		if ($type !== '' && !\in_array($type, $enabled, true) && !\in_array($type, $this->pbckCoreTypes(), true)) {
			$record['addon_was_missing'] = 'No enabled plugin provided type "' . $type . '", so this block '
				. 'was rendering its inner markup as static HTML rather than as the addon. Removing it '
				. 'removes that static markup from the page.';
		}

		// A block can legitimately contain nested rows and further blocks. They go
		// too, and that is the sort of thing worth saying out loud.
		$nestedBlocks = 0;

		foreach ($block->find('div.cktype') as $node) {
			if ($node !== $block) {
				$nestedBlocks++;
			}
		}

		$nestedRows = \count($block->find('div.rowck'));

		if ($nestedBlocks > 0 || $nestedRows > 0) {
			$record['also_removed'] = [
				'nested_blocks' => $nestedBlocks,
				'nested_rows'   => $nestedRows,
				'note'          => 'These were inside the deleted block and went with it, along with their '
					. 'own options and CSS.',
			];
		}

		return $record;
	}
}
