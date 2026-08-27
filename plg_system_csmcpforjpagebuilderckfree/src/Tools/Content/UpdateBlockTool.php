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
 * Change one block's payload markup and/or its options.
 *
 * ---------------------------------------------------------------------------
 * THE VENDOR CONVENTIONS THIS TOOL ENFORCES
 * ---------------------------------------------------------------------------
 *
 * Options are HTML attributes on the block's empty `.ckprops` sibling divs, one
 * div per tab of the options popup. All four conventions are honoured on write,
 * because writing them wrong produces markup the vendor's editor then misreads:
 *
 *   - `"default"` and `""` REMOVE the attribute. Absence is the vendor's only
 *     representation of "no value" — `ckGetPopupFieldslist()` in
 *     `media/assets/pagebuilderck.js:3277` only records fields whose value is
 *     non-empty and not `'default'`.
 *   - `true` is written as the literal string `"checked"`. The editor's
 *     comparison is `cssvalue == 'checked'` (`pagebuilderck.js:2782`) for every
 *     radio and checkbox. `"1"` and `"true"` are simply not checked.
 *   - `fieldslist` is REBUILT from the attributes actually set, which is what
 *     the vendor does on every save. It is not decoration: the editor's CSS
 *     generator walks `fieldslist` and reads only the attributes it names, so
 *     an attribute missing from it produces no style.
 *   - The `.ckprops` div ends up EMPTY. The renderer strips only empty ones —
 *     its regex is `>[^<]*</div>` — so a `.ckprops` with children leaks
 *     verbatim into the rendered page. Rebuilding the div from scratch makes
 *     that structurally impossible.
 *
 * ---------------------------------------------------------------------------
 * WHAT SETTING AN OPTION DOES NOT DO
 * ---------------------------------------------------------------------------
 *
 * It does not regenerate the block's CSS. `administrator/helpers/stylescss.php`
 * — the whole option-to-CSS generator — is included only by the ADMIN views and
 * the editor's AJAX endpoints, never by the site renderer. The `.ckstyle` div
 * inside the block is the only CSS the front end sees.
 *
 * So a presentational option (padding, background, border, shadow, alignment,
 * the whole `tab_effects` set) written here has NO visible effect until a human
 * opens that block in the builder and applies. A functional option DOES take
 * effect immediately, because the addon reads its `.ckprops` at render time —
 * `plugins/pagebuilderck/audio` reads `audiourl` and `autoplayyes` straight off
 * `.tab_audio` in `onPagebuilderckRenderItemAudio()`.
 *
 * That distinction is stated in the response, every time, because it decides
 * whether the caller has finished the job or only half of it.
 */
final class UpdateBlockTool extends AbstractTool
{
	use PagebuilderckBootTrait;
	use PagebuilderckContentTrait;
	use PageContentEditTrait;

	public function getName(): string { return 'update_pagebuilderck_block'; }

	public function getDescription(): string
	{
		return 'Change an existing block\'s inner markup and/or its options. Arguments: page_id, '
			. 'block_id, inner_html, options. Supply at least one of inner_html and options. '
			. 'OPTIONS ARE {tab: {attribute: value}}, for example '
			. '{"tab_blocstyles": {"blocpaddings": "20", "blocalignementcenter": true}}. The tab key is '
			. 'the .ckprops div\'s tab class (tab_blocstyles, tab_effects, tab_edition, tab_audio …) as '
			. 'reported by get_pagebuilderck_block; a tab that does not exist yet on this block is '
			. 'created. The attribute name is the ID of the input in the vendor\'s options popup, not its '
			. 'name — which is why radio groups appear as separate attributes such as '
			. 'iconalignementleft / iconalignementcenter / iconalignementright. '
			. 'Value handling follows Page Builder CK exactly: "default" or "" REMOVES the attribute, '
			. 'because absence is the vendor\'s only way of saying "unset"; boolean true is written as '
			. 'the literal string "checked", which is the only value the editor treats as ticked (not '
			. '"1", not "true"); boolean false and null remove the attribute; everything else is written '
			. 'as a string. The fieldslist attribute is rebuilt from the attributes actually set, exactly '
			. 'as the vendor rebuilds it on save, and each .ckprops div is re-emitted EMPTY because the '
			. 'renderer strips only empty ones and a .ckprops with children leaks into the live page. '
			. 'Options you do not mention are left alone. '
			. 'IMPORTANT: setting an option does NOT regenerate the block\'s CSS. Page Builder CK builds '
			. 'block CSS in the editor, never at render time, so a presentational option — padding, '
			. 'background, border, shadow, alignment, anything in tab_effects — has no visible effect '
			. 'until a human opens the block in the builder and applies. Functional options DO take '
			. 'effect immediately, because the addon reads its .ckprops when it renders. To change '
			. 'appearance right now, change inner_html or the block\'s CSS via '
			. 'write_pagebuilderck_page_content. '
			. 'inner_html replaces the contents of the block\'s div.inner. A few addons have no div.inner '
			. 'wrapper (the icon addon emits div.iconck directly); on those this tool REFUSES rather than '
			. 'guessing where the payload begins. '
			. 'Refuses if the page\'s existing content has any fatal validation problem, and refuses '
			. 'again if your change would introduce one. Nothing partial is written.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'page_id'    => ['type' => 'integer', 'description' => 'Row id in #__pagebuilderck_pages.'],
				'id'         => ['type' => 'integer', 'description' => 'Alias for page_id.'],
				'block_id'   => ['type' => 'string', 'description' => 'The id attribute of the .cktype div. From get_pagebuilderck_page_outline.'],
				'inner_html' => ['type' => 'string', 'description' => 'Replacement markup for the block\'s div.inner. Use real site URLs; |URIROOT| tokenisation is applied for you.'],
				'options'    => ['type' => 'object', 'description' => 'Nested object {tab_id: {attribute: value}}. "default"/""/false/null remove an attribute; true writes the literal string "checked". Unmentioned attributes are left alone.'],
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

		$wantsInner   = \array_key_exists('inner_html', $arguments);
		$suppliedOpts = $arguments['options'] ?? null;

		if (\is_object($suppliedOpts)) {
			$suppliedOpts = (array) $suppliedOpts;
		}

		if ($suppliedOpts !== null && !\is_array($suppliedOpts)) {
			return ToolResult::error('options must be an object of the form {tab_id: {attribute: value}}.');
		}

		$wantsOptions = \is_array($suppliedOpts) && $suppliedOpts !== [];

		if (!$wantsInner && !$wantsOptions) {
			return ToolResult::error(
				'Nothing to do. Supply inner_html, options, or both. An options object with no tabs in it '
				. 'is not a change.'
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
				'hint'  => 'Run get_pagebuilderck_page_outline for the ids that exist.',
				'component' => $this->pbckEditionNotice(),
			], true);
		}

		$type  = (string) $block->getAttribute('data-type');
		$inner = $this->pbckDirectChild($block, 'inner');

		// Refuse BEFORE mutating anything. A block whose payload has no .inner
		// wrapper (the shipped icon addon emits div.iconck directly) gives us no
		// unambiguous boundary between "the payload" and the .ckprops/.ckstyle
		// siblings, and a half-applied update is worse than none.
		if ($wantsInner && $inner === null) {
			$dom->clear();

			return ToolResult::json([
				'ok'      => false,
				'refused' => 'Nothing was written. Block "' . $blockId . '" (type "' . $type . '") has no '
					. 'div.inner wrapper, so there is no unambiguous place to put inner_html.',
				'why'     => 'Not every Page Builder CK addon wraps its payload. The shipped icon addon, '
					. 'for one, emits div.iconck as a direct sibling of .ckstyle. Replacing "everything '
					. 'that is not .ckprops or .ckstyle" would be a guess, and a wrong guess here silently '
					. 'destroys markup.',
				'resolution' => 'Read the block with get_pagebuilderck_block, then rewrite the page with '
					. 'write_pagebuilderck_page_content. Options on this block can still be changed by '
					. 'calling this tool with options only.',
				'component' => $this->pbckEditionNotice(),
			], true);
		}

		// --- validate every option instruction before touching the DOM --------
		$plan = [];

		if ($wantsOptions) {
			$existing = [];

			foreach ($block->children() as $child) {
				if (!$this->pbckNodeHasClass($child, 'ckprops')) {
					continue;
				}

				$tabId = $this->tabIdOf($child);

				if ($tabId !== '') {
					$existing[$tabId] = $child;
				}
			}

			foreach ($suppliedOpts as $tabId => $changes) {
				$tabId = (string) $tabId;

				if (!$this->pbckIsIdentifier($tabId)) {
					$dom->clear();

					return ToolResult::error(
						'Invalid tab id "' . $tabId . '". A tab id is the .ckprops div\'s second class, '
						. 'e.g. tab_blocstyles — letters, digits, underscore and hyphen only.'
					);
				}

				if (\is_object($changes)) {
					$changes = (array) $changes;
				}

				if (!\is_array($changes)) {
					$dom->clear();

					return ToolResult::error(
						'options["' . $tabId . '"] must be an object of {attribute: value} pairs.'
					);
				}

				foreach ($changes as $attr => $value) {
					$attr = (string) $attr;

					if (!$this->pbckIsAttrName($attr)) {
						$dom->clear();

						return ToolResult::error(
							'Invalid option name "' . $attr . '" in tab "' . $tabId . '". Option names are '
							. 'the ids of inputs in the vendor\'s options popup, so they are plain '
							. 'identifiers. An attribute name cannot be escaped — a stray quote or space '
							. 'would silently become a different attribute — so this is refused rather '
							. 'than sanitised.'
						);
					}

					if ($attr === 'class' || $attr === 'fieldslist') {
						$dom->clear();

						return ToolResult::error(
							'"' . $attr . '" is structural, not an option. class carries the tab id and '
							. 'fieldslist is rebuilt from the attributes actually set, exactly as Page '
							. 'Builder CK rebuilds it on save.'
						);
					}

					if ($value !== null && !\is_scalar($value)) {
						$dom->clear();

						return ToolResult::error(
							'options["' . $tabId . '"]["' . $attr . '"] must be a string, number, boolean '
							. 'or null. Page Builder CK options are HTML attributes and an attribute holds '
							. 'a string.'
						);
					}
				}

				$plan[$tabId] = ['changes' => $changes, 'node' => $existing[$tabId] ?? null];
			}
		}

		// --- apply -----------------------------------------------------------
		$report      = [];
		$innerReport = null;

		if ($wantsInner && $inner !== null) {
			$before = (string) $inner->innertext;
			$after  = $this->pbckCollapseRoot((string) $arguments['inner_html']);

			$inner->innertext = $after;

			$innerReport = [
				'wrapper_class' => (string) $inner->getAttribute('class'),
				'bytes'         => ['before' => \strlen($before), 'after' => \strlen($after)],
				'changed'       => $before !== $after,
			];
		}

		$created = [];

		foreach ($plan as $tabId => $entry) {
			$node    = $entry['node'];
			$changes = $entry['changes'];

			if ($node === null) {
				$attrs   = [];
				$classes = $tabId . ' ckprops';
			} else {
				$attrs = [];

				foreach (($node->getAllAttributes() ?: []) as $name => $value) {
					if ($name === 'class' || $name === 'fieldslist' || $value === null || $value === false) {
						continue;
					}

					$attrs[(string) $name] = (string) $value;
				}

				$classes = (string) $node->getAttribute('class');
			}

			$before  = $attrs;
			$set     = [];
			$removed = [];

			foreach ($changes as $attr => $value) {
				$attr       = (string) $attr;
				$normalised = $this->normaliseOptionValue($value);

				if ($normalised === null) {
					if (\array_key_exists($attr, $attrs)) {
						$removed[] = $attr;
					}

					unset($attrs[$attr]);

					continue;
				}

				$attrs[$attr] = $normalised;
				$set[$attr]   = $normalised;
			}

			$markup = $this->renderProps($classes, $attrs);

			if ($node === null) {
				$created[$tabId] = $markup;
			} else {
				$node->outertext = $markup;
			}

			$report[$tabId] = [
				'created'          => $node === null,
				'attributes_set'   => $set,
				'attributes_removed' => $removed,
				'unchanged_count'  => \count(array_diff_key($before, $set, array_flip($removed))),
				'fieldslist'       => implode(',', array_keys($attrs)),
			];
		}

		// New tab divs go in front of the first non-.ckprops child, which is where
		// Page Builder CK emits them — before .ckstyle and before the payload.
		// Done last so it picks up the outertext/innertext edits above.
		if ($created !== []) {
			$block->innertext = $this->insertProps($block, implode('', $created));
		}

		$result = $this->pbckSerialise($dom, $original);
		$dom->clear();

		$resultProblems = $this->pbckValidate($result, $enabled);

		$refusal = $this->pbckAssertResultSafe($resultProblems, 'Updating block ' . $blockId);

		if ($refusal !== null) {
			return $refusal;
		}

		if ($result === $original) {
			return ToolResult::json([
				'ok'      => true,
				'action'  => 'no change',
				'page_id' => $pageId,
				'block_id' => $blockId,
				'note'    => 'The requested values are already what the block carries, so nothing was '
					. 'written and `modified` was not touched.',
				'options' => $report,
				'component' => $this->pbckEditionNotice(),
			]);
		}

		$modified = $this->pbckStorePageHtml($pageId, $result);

		$payload = [
			'ok'        => true,
			'action'    => 'block updated',
			'page_id'   => $pageId,
			'block_id'  => $blockId,
			'data_type' => $type,
			'modified'  => $modified,
			'bytes'     => ['before' => \strlen($original), 'after' => \strlen($result)],
		];

		if ($innerReport !== null) {
			$payload['inner'] = $innerReport;
		}

		if ($report !== []) {
			$payload['options'] = $report;

			$payload['css_not_regenerated'] = 'Options were written, but the block\'s .ckstyle CSS was '
				. 'NOT regenerated and nothing will regenerate it. Page Builder CK turns options into CSS '
				. 'in the editor only — administrator/helpers/stylescss.php is included by the admin '
				. 'views and the editor\'s AJAX endpoints, never by the site renderer. So a presentational '
				. 'option (padding, background, border, shadow, alignment, everything in tab_effects) has '
				. 'no visible effect until a human opens this block in the builder and applies. A '
				. 'functional option DOES apply immediately, because the addon reads its .ckprops when it '
				. 'renders — the audio addon, for instance, reads audiourl and autoplayyes straight off '
				. '.tab_audio. If you need a visible change now, change inner_html or write the CSS '
				. 'yourself with write_pagebuilderck_page_content.';

			$payload['fieldslist_note'] = 'fieldslist was rebuilt from the attributes actually set on each '
				. 'tab, which is what the vendor does (ckGetPopupFieldslist in '
				. 'media/assets/pagebuilderck.js records only fields whose value is non-empty and not '
				. '"default"). Any name that was declared there but had no attribute has therefore gone. '
				. 'That is intentional — the editor\'s CSS generator reads only the attributes fieldslist '
				. 'names, so a declared-but-unset name contributed nothing.';
		}

		$grouped = $this->pbckGroupProblems($resultProblems);

		if (isset($grouped['warning'])) {
			$payload['content_warnings'] = $grouped['warning'];
		}

		$payload['notes']     = $this->pbckWriteNotes();
		$payload['component'] = $this->pbckEditionNotice();

		return ToolResult::json($payload);
	}

	/**
	 * The tab id of a `.ckprops` div: its first class that is not `ckprops` or
	 * `ckresponsive`. Matches how PagebuilderckContentTrait reads them.
	 */
	private function tabIdOf(object $node): string
	{
		$classes = preg_split('/\s+/', trim((string) $node->getAttribute('class'))) ?: [];

		foreach ($classes as $class) {
			if ($class !== '' && $class !== 'ckprops' && $class !== 'ckresponsive') {
				return $class;
			}
		}

		return '';
	}

	/**
	 * Page Builder CK's value conventions, applied on write.
	 *
	 * @return string|null Null means "remove this attribute".
	 */
	private function normaliseOptionValue(mixed $value): ?string
	{
		if ($value === null || $value === false) {
			return null;
		}

		if ($value === true) {
			// The only value the editor treats as ticked. pagebuilderck.js:2782
			// compares `cssvalue == 'checked'` for every radio and checkbox.
			return 'checked';
		}

		$string = (string) $value;

		// "default" is not a value the caller can store: Page Builder CK uses it as
		// the popup's placeholder for "unset", and its own save drops such fields.
		if ($string === '' || $string === 'default') {
			return null;
		}

		return $string;
	}

	/**
	 * Emit a `.ckprops` div from scratch.
	 *
	 * Always self-closed with nothing inside. Page Builder CK strips only EMPTY
	 * ckprops divs at render — the regex is `>[^<]*</div>` — so building the div
	 * rather than editing it in place is what makes a content leak impossible.
	 *
	 * @param array<string,string> $attrs
	 */
	private function renderProps(string $classes, array $attrs): string
	{
		$markup = '<div class="' . $this->pbckAttrValue($classes) . '"';

		foreach ($attrs as $name => $value) {
			$markup .= ' ' . $name . '="' . $this->pbckAttrValue($value) . '"';
		}

		// fieldslist last, matching the stored shape the editor produces.
		$markup .= ' fieldslist="' . $this->pbckAttrValue(implode(',', array_keys($attrs))) . '"';

		return $markup . '></div>';
	}

	/**
	 * The block's inner markup with `$markup` spliced in before the first child
	 * that is not a `.ckprops`.
	 *
	 * Iterates `->nodes` rather than `->children()` so the whitespace text nodes
	 * between the block's parts survive.
	 */
	private function insertProps(object $block, string $markup): string
	{
		$html   = '';
		$placed = false;

		foreach ($block->nodes as $node) {
			$tag = (string) ($node->tag ?? '');

			if (!$placed && $tag !== '' && $tag !== 'text' && $tag !== 'comment'
				&& !$this->pbckNodeHasClass($node, 'ckprops')) {
				$html  .= $markup;
				$placed = true;
			}

			$html .= (string) $node->outertext();
		}

		return $placed ? $html : $html . $markup;
	}
}
