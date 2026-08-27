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
 * Insert a new `.cktype` block into a column.
 *
 * Emits the canonical shape and nothing more:
 *
 *   <div id="ID…" class="cktype" data-type="…">
 *     <div class="ckstyle"></div>
 *     <div class="inner">…</div>
 *   </div>
 *
 * No `.ckprops` divs. Options are added afterwards with
 * `update_pagebuilderck_block`, which is also what builds `fieldslist`. An
 * empty `.ckstyle` is emitted because the editor needs somewhere to write the
 * block's `#id`-scoped CSS the first time a human styles it, and Page Builder
 * CK's own addon templates all emit one.
 *
 * ---------------------------------------------------------------------------
 * WE WARN, WE DO NOT REFUSE
 * ---------------------------------------------------------------------------
 *
 * If no enabled `pagebuilderck` plugin provides the requested `data-type`, this
 * tool still writes the block. Page Builder CK fails OPEN: `renderElement()`
 * returns '' and `replaceElement()` falls through to the raw inner markup,
 * which renders as static HTML with the block's CSS still applied. So the block
 * is not broken, only inert — and a block whose `inner_html` you supplied
 * yourself renders exactly what you supplied. That is a legitimate thing to
 * want, and it is the opposite of the SP Page Builder add-on, where a missing
 * addon renders literally nothing and adding one is refused.
 */
final class AddBlockTool extends AbstractTool
{
	use PagebuilderckBootTrait;
	use PagebuilderckContentTrait;
	use PageContentEditTrait;

	public function getName(): string { return 'add_pagebuilderck_block'; }

	public function getDescription(): string
	{
		return 'Insert a new block into a column of a Page Builder CK page. Arguments: page_id, '
			. 'column_id (the id of an existing div.blockck — get it from '
			. 'get_pagebuilderck_page_outline), data_type (the block type, e.g. text, image, icon, audio '
			. '— this is the element name of the pagebuilderck plugin that renders it), optional '
			. 'inner_html (the block\'s visible payload) and optional position. '
			. 'position is a zero-based index into that column\'s BLOCK list as '
			. 'get_pagebuilderck_page_outline reports it; omit it to append at the end. Nested rows are '
			. 'siblings of the blocks in the markup but are not counted, so an index taken from the '
			. 'outline means what you think it means. '
			. 'The block id is generated in the vendor\'s own ID<epoch-ms> format and checked against '
			. 'every id already in the page. Ids are load-bearing here: all block CSS is #id-scoped and '
			. 'lives inside the block. '
			. 'Emits the canonical shape — <div id class="cktype" data-type><div class="ckstyle"></div>'
			. '<div class="inner">…</div></div> — with NO .ckprops divs. Add options afterwards with '
			. 'update_pagebuilderck_block; that is also what builds the fieldslist attribute. The new '
			. 'block has no CSS of its own until somebody styles it in the builder, because Page Builder '
			. 'CK generates block CSS in the editor and never at render time. '
			. 'If no enabled plugin provides data_type this tool WARNS and writes the block anyway. Page '
			. 'Builder CK fails open — the block renders its inner markup as static HTML rather than '
			. 'rendering nothing — so an inert block with markup you supplied is still a useful block. '
			. 'It DOES refuse data_type "row" and "rowinrow": a row is a div.rowck, not a div.cktype, and '
			. 'writing one as a block produces markup the builder cannot edit. Use add_pagebuilderck_row. '
			. 'Refuses if the page\'s existing content has any fatal validation problem, and refuses '
			. 'again if the inner_html you supplied would introduce one. Nothing partial is written.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'page_id'    => ['type' => 'integer', 'description' => 'Row id in #__pagebuilderck_pages.'],
				'id'         => ['type' => 'integer', 'description' => 'Alias for page_id.'],
				'column_id'  => ['type' => 'string', 'description' => 'Id of the div.blockck column to add the block to. From get_pagebuilderck_page_outline.'],
				'data_type'  => ['type' => 'string', 'description' => 'Block type, e.g. text, image, icon, audio. Lowercase letters, digits, underscore and hyphen only.'],
				'inner_html' => ['type' => 'string', 'description' => 'Markup for the block\'s div.inner. Use real site URLs; the |URIROOT| tokenisation is applied for you. Omit for an empty block.'],
				'position'   => ['type' => 'integer', 'description' => 'Zero-based index in the column\'s block list. Omit to append at the end.'],
			],
			'required'             => ['page_id', 'column_id', 'data_type'],
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
		$columnId = $this->requireString($arguments, 'column_id');
		$type     = strtolower($this->requireString($arguments, 'data_type'));

		if (!$this->pbckIsIdentifier($type)) {
			return ToolResult::error(
				'Invalid data_type "' . $type . '". A block type is the element name of a pagebuilderck '
				. 'plugin — letters, digits, underscore and hyphen only.'
			);
		}

		if ($type === 'row' || $type === 'rowinrow') {
			return ToolResult::error(
				'Refused: "' . $type . '" is not a block type. A row is a div.rowck carrying data-gutter '
				. 'and data-nb, with its own generated width stylesheet and its own columns — it is not a '
				. 'div.cktype and the builder cannot edit one written as a block. Use '
				. 'add_pagebuilderck_row.'
			);
		}

		$row = $this->pbckLoadPageRow($pageId);

		if ($row === null) {
			return $this->pbckPageNotFoundError($pageId);
		}

		$original = (string) $row['htmlcode'];

		if (trim($original) === '') {
			return ToolResult::error(
				'Page ' . $pageId . ' has no content at all, so it has no columns to add a block to. Give '
				. 'it a row first with add_pagebuilderck_row, or write a whole document with '
				. 'write_pagebuilderck_page_content.'
			);
		}

		$enabled = $this->pbckEnabledAddonTypes();

		// The source gate. A page that is already fatally broken cannot be edited
		// around — see pbckAssertSourceEditable.
		$sourceProblems = $this->pbckValidate($original, $enabled);

		if (($refusal = $this->pbckAssertSourceEditable($pageId, $sourceProblems)) !== null) {
			return $refusal;
		}

		$dom = $this->pbckParse($original);

		if ($dom === null) {
			return ToolResult::error('The content of page ' . $pageId . ' could not be parsed.');
		}

		$column = $this->pbckFindColumn($dom, $columnId);

		if ($column === null) {
			$known = $this->pbckColumnIds($dom);
			$block = $this->pbckFindBlock($dom, $columnId);
			$dom->clear();

			return ToolResult::json([
				'ok'    => false,
				'error' => 'No column with id "' . $columnId . '" on page ' . $pageId . '.',
				'hint'  => $block !== null
					? 'That id belongs to a BLOCK (div.cktype), not a column (div.blockck). Blocks live '
						. 'inside columns; pass the id of the column that contains it.'
					: 'Run get_pagebuilderck_page_outline for the column ids on this page.',
				'column_ids_on_page' => $known,
				'component' => $this->pbckEditionNotice(),
			], true);
		}

		$innerContent = $this->pbckColumnContent($column);

		if ($innerContent === null) {
			$dom->clear();

			return ToolResult::error(
				'Column "' . $columnId . '" has no div.innercontent, which is where Page Builder CK keeps '
				. 'a column\'s blocks. This column is malformed; repair it with '
				. 'write_pagebuilderck_page_content.'
			);
		}

		// Seeded from every id in the whole page, not just this column, because
		// block CSS is #id-scoped globally — a collision anywhere cross-applies
		// styling.
		$taken   = $this->pbckCollectIds($original);
		$blockId = $this->pbckNewId($taken);

		$innerHtml = \array_key_exists('inner_html', $arguments)
			? $this->pbckCollapseRoot((string) $arguments['inner_html'])
			: '';

		$markup = sprintf(
			'<div id="%s" class="cktype" data-type="%s"><div class="ckstyle"></div><div class="inner">%s</div></div>',
			$this->pbckAttrValue($blockId),
			$this->pbckAttrValue($type),
			$innerHtml
		);

		$position = \array_key_exists('position', $arguments) ? (int) $arguments['position'] : null;
		$rebuilt  = $this->pbckRebuildColumnContent($innerContent, null, $markup, $position);

		$innerContent->innertext = $rebuilt['html'];

		$result = $this->pbckSerialise($dom, $original);
		$dom->clear();

		// The result gate. The source was proven clean above, so any fatal here
		// came from the markup supplied in inner_html.
		$resultProblems = $this->pbckValidate($result, $enabled);

		$refusal = $this->pbckAssertResultSafe(
			$resultProblems,
			'Adding a "' . $type . '" block to column ' . $columnId
		);

		if ($refusal !== null) {
			return $refusal;
		}

		$modified = $this->pbckStorePageHtml($pageId, $result);

		$isCore    = \in_array($type, $this->pbckCoreTypes(), true);
		$hasPlugin = \in_array($type, $enabled, true);

		$payload = [
			'ok'        => true,
			'action'    => 'block added',
			'page_id'   => $pageId,
			'block_id'  => $blockId,
			'data_type' => $type,
			'column_id' => $columnId,
			'position'  => $rebuilt['inserted_at'],
			'column_block_order' => $rebuilt['block_ids'],
			'inner_bytes' => \strlen($innerHtml),
			'modified'  => $modified,
			'bytes'     => ['before' => \strlen($original), 'after' => \strlen($result)],
		];

		if (!$isCore && !$hasPlugin) {
			$payload['addon_warning'] = 'Written, but no enabled pagebuilderck plugin provides type "'
				. $type . '" on this site. Page Builder CK does NOT blank such a block — it renders the '
				. 'inner markup as static HTML with the block CSS still applied, with no error and no log '
				. 'entry. So this block will show exactly the inner_html you supplied and nothing more; '
				. 'the addon\'s own rendering and any interactive behaviour are absent. Run '
				. 'list_pagebuilderck_addons to see what is installed and enabled.';
			$payload['enabled_types'] = $enabled;
		}

		$payload['no_options_yet'] = 'This block has no .ckprops divs, so it carries no options. Set them '
			. 'with update_pagebuilderck_block — that is also what writes the fieldslist attribute the '
			. 'vendor\'s options popup reads.';

		$payload['no_css_yet'] = 'The block\'s .ckstyle div is empty, so it has no CSS. Page Builder CK '
			. 'generates block CSS in the editor (administrator/helpers/stylescss.php, which the site '
			. 'renderer never includes), so this block stays unstyled until a human opens it in the '
			. 'builder and applies — setting style options with update_pagebuilderck_block will not '
			. 'generate it.';

		$grouped = $this->pbckGroupProblems($resultProblems);

		if (isset($grouped['warning'])) {
			$payload['content_warnings'] = $grouped['warning'];
		}

		$payload['notes']     = $this->pbckWriteNotes();
		$payload['component'] = $this->pbckEditionNotice();

		return ToolResult::json($payload);
	}
}
