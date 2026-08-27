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
 * The structural map of a page: rows, their columns, and the blocks inside.
 *
 * This is the orientation tool. Every other content tool in this add-on takes
 * an id — a block id, a column id — and this is where those ids come from.
 * Nothing else lists them.
 *
 * It deliberately returns no block markup and no CSS, so it stays cheap enough
 * to call on a page of any size. `get_pagebuilderck_block` is the zoom-in.
 *
 * The one thing it reports that the rendered page cannot: blocks whose
 * `data-type` has no enabled `pagebuilderck` plugin. Page Builder CK fails OPEN
 * — `renderElement()` returns '' and `replaceElement()` falls through to the
 * block's raw inner markup, which renders as static HTML with its CSS still
 * applied. No error, no placeholder, no log line. Looking at the page cannot
 * tell you the addon is missing; only this cross-check can.
 */
final class GetPageOutlineTool extends AbstractTool
{
	use PagebuilderckBootTrait;
	use PagebuilderckContentTrait;
	use PageContentEditTrait;

	public function getName(): string { return 'get_pagebuilderck_page_outline'; }

	public function getDescription(): string
	{
		return 'START HERE when working on a Page Builder CK page. Returns the structural tree of one '
			. 'page: every row with its gutter and declared column count, every column with its id and '
			. 'width, and every block with its id, data-type and option tab names. Nested rows appear '
			. 'under their parent column as nested_rows. '
			. 'This is the ONLY tool that lists block ids and column ids, and every other content tool '
			. 'takes one of those, so an outline is the first call in almost any content task. '
			. 'Returns NO block markup and NO CSS — that keeps it cheap on a large page. Use '
			. 'get_pagebuilderck_block for one block\'s inner HTML, parsed options and .ckstyle CSS. '
			. 'Cross-checks every data-type against the enabled pagebuilderck plugins and flags blocks '
			. 'whose addon is missing. This matters more than it sounds: Page Builder CK does NOT blank '
			. 'a block with no addon, it renders the inner markup as static HTML with the CSS still '
			. 'applied, silently. The page looks nearly right and the interactive behaviour is simply '
			. 'gone, so the rendered output cannot tell you about it — only this can. '
			. 'Also flags rows whose data-nb disagrees with their real column count, which collapses '
			. 'every column in that row to no width, and blocks whose data-acl-view is set (that '
			. 'attribute is a DENY list, not an allow list). '
			. 'Arguments: page_id.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'page_id' => ['type' => 'integer', 'description' => 'Row id in #__pagebuilderck_pages. Get it from list_pagebuilderck_pages.'],
				'id'      => ['type' => 'integer', 'description' => 'Alias for page_id, accepted so either name works.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (($guard = $this->pbckContentGuard()) !== null) {
			return $guard;
		}

		$pageId = $this->pbckPageId($arguments);
		$row    = $this->pbckLoadPageRow($pageId);

		if ($row === null) {
			return $this->pbckPageNotFoundError($pageId);
		}

		$html = (string) $row['htmlcode'];

		$result = [
			'ok'    => true,
			'page'  => $this->pbckPageSummary($row),
			'bytes' => \strlen($html),
		];

		if (trim($html) === '') {
			$result['outline'] = ['rows' => [], 'row_count' => 0];
			$result['empty']   = 'This page has no content at all. Its htmlcode column is empty, so it '
				. 'renders as nothing. A page created outside the builder starts in this state — open it '
				. 'in Page Builder CK once, or use write_pagebuilderck_page_content, to give it a first '
				. 'row.';
			$result['component'] = $this->pbckEditionNotice();

			return ToolResult::json($result);
		}

		// The stored bytes, not the |URIROOT|-expanded ones. An outline carries no
		// URLs, and expanding first would only invite a mismatch between the ids
		// reported here and the ids in the column the write tools read.
		$enabled = $this->pbckEnabledAddonTypes();
		$outline = $this->pbckOutline($html, $enabled);

		if (($outline['ok'] ?? false) !== true) {
			return ToolResult::json([
				'ok'      => false,
				'error'   => 'The content of page ' . $pageId . ' could not be parsed, so there is no '
					. 'outline to give. Reason: ' . (string) ($outline['reason'] ?? 'unknown') . '.',
				'page'    => $this->pbckPageSummary($row),
				'bytes'   => \strlen($html),
				'next'    => 'Run validate_pagebuilderck_content on this page — it reports the specific '
					. 'problem. Content over 8 MB is also refused by the parser by design.',
				'component' => $this->pbckEditionNotice(),
			], true);
		}

		$missing = $this->collectMissingAddons($outline);

		$result['outline'] = [
			'rows'       => $outline['rows'],
			'row_count'  => $outline['row_count'],
			'singletons' => $outline['singletons'],
		];

		$result['addon_check'] = [
			'enabled_types'      => $enabled,
			'core_types'         => $this->pbckCoreTypes(),
			'blocks_with_no_addon' => $missing,
		];

		if ($missing !== []) {
			$result['addon_warning'] = sprintf(
				'%d block(s) on this page reference a data-type with no enabled pagebuilderck plugin. '
					. 'They are NOT blank in the rendered page — Page Builder CK falls through to their '
					. 'raw inner markup, which renders as static HTML with the block CSS still applied. '
					. 'The page therefore looks nearly correct while the addon\'s behaviour is gone. '
					. 'Run list_pagebuilderck_addons to see which plugins are installed and enabled.',
				\count($missing)
			);
		}

		// Singleton counts are cheap to get wrong by hand-editing and expensive to
		// notice: two .pagebuilderckparams divs means two competing data-styles
		// lists, and zero means the page's style associations are gone.
		foreach (['googlefontscall', 'pagebuilderckparams', 'ckcustomcssfield'] as $class) {
			$count = (int) ($outline['singletons'][$class] ?? 0);

			if ($count !== 1) {
				$result['singleton_warnings'][] = sprintf(
					'div.%s occurs %d time(s). Page Builder CK emits exactly one of each at the page '
						. 'level. %s',
					$class,
					$count,
					$class === 'pagebuilderckparams'
						? 'This div carries data-styles, the ONLY record of which #__pagebuilderck_styles '
							. 'rows are attached to this page — there is no styles column on the table. '
							. 'Zero of them means no styles apply; more than one means the first wins.'
						: 'The editor recreates it on the next save.'
				);
			}
		}

		$result['note'] = 'Block ids are load-bearing. All Page Builder CK block CSS is #id-scoped and '
			. 'lives inside the block\'s own .ckstyle div, so a block that changes id loses its styling '
			. 'and two blocks sharing an id cross-apply it. move_pagebuilderck_block preserves ids for '
			. 'exactly this reason.';

		$result['component'] = $this->pbckEditionNotice();

		return ToolResult::json($result);
	}

	/**
	 * Flatten the outline down to the blocks the addon check flagged.
	 *
	 * @return array<int, array<string,string>>
	 */
	private function collectMissingAddons(array $outline): array
	{
		$found = [];

		$walkRow = function (array $row) use (&$found, &$walkRow): void {
			foreach ($row['columns'] ?? [] as $column) {
				foreach ($column['blocks'] ?? [] as $block) {
					if (isset($block['addon_missing'])) {
						$found[] = [
							'block_id'  => (string) ($block['id'] ?? ''),
							'type'      => (string) ($block['type'] ?? ''),
							'column_id' => (string) ($column['id'] ?? ''),
						];
					}

					if (isset($block['error'])) {
						$found[] = [
							'block_id'  => (string) ($block['id'] ?? ''),
							'type'      => '(none)',
							'column_id' => (string) ($column['id'] ?? ''),
						];
					}
				}

				foreach ($column['nested_rows'] ?? [] as $nested) {
					$walkRow($nested);
				}
			}
		};

		foreach ($outline['rows'] ?? [] as $row) {
			$walkRow($row);
		}

		return $found;
	}
}
