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
 * Replace a page's entire `htmlcode` with a document you supply.
 *
 * The blunt instrument, and deliberately so. The block-level tools refuse to
 * edit a page whose stored content is already fatally broken, because editing
 * around a document that will not round-trip means silently rewriting markup
 * nobody asked to change. This tool validates only the content you supply, so
 * it is the way out of that state — and the only way to change the page-level
 * singletons (`.googlefontscall`, `.pagebuilderckparams`, `.ckcustomcssfield`)
 * or to rebuild row geometry by hand.
 *
 * The guard order is not negotiable:
 *
 *   1. collapse the site root to `|URIROOT|`
 *   2. `pbckIsLossless()` on the INCOMING content — refuse if false, because a
 *      document we cannot reproduce byte for byte is one we would corrupt on
 *      the next edit
 *   3. `pbckValidate()` — refuse on any fatal
 *   4. UPDATE `htmlcode` and `modified`
 *
 * There is no partial success. Either the whole document is written or nothing
 * is.
 */
final class WritePageContentTool extends AbstractTool
{
	use PagebuilderckBootTrait;
	use PagebuilderckContentTrait;
	use PageContentEditTrait;

	public function getName(): string { return 'write_pagebuilderck_page_content'; }

	public function getDescription(): string
	{
		return 'Replace the ENTIRE htmlcode of a Page Builder CK page with the document you supply. This '
			. 'overwrites everything — rows, columns, blocks, block CSS and the page-level singleton '
			. 'divs. For a single block use add/update/move/delete_pagebuilderck_block instead; they are '
			. 'far harder to get wrong. '
			. 'Before writing, this tool: collapses the site root to the |URIROOT| token (Page Builder CK '
			. 'stores every internal URL that way, and an absolute URL breaks the page the moment the '
			. 'site moves domain or subdirectory — so do NOT tokenise it yourself, just pass real URLs); '
			. 'checks the content survives a parse and re-serialise byte for byte and REFUSES if it does '
			. 'not; and runs the full validator and REFUSES on any fatal. Warnings are reported and do '
			. 'not block the write. Nothing partial is ever written. '
			. 'Returns the outline of what was actually written, so you can confirm the rows, columns and '
			. 'blocks landed as intended without a second call. '
			. 'Two things this write does NOT do, both of which the vendor\'s own save does: it writes no '
			. '.pbck backup, so there is no restore point — read the current content first if you want '
			. 'one; and it does not regenerate any block\'s .ckstyle CSS, which is produced by the editor '
			. 'and not at render time, so whatever CSS is inside the blocks you supply is exactly what '
			. 'the front end will use. '
			. 'Writing empty content blanks the page and requires allow_empty: true. '
			. 'Arguments: page_id, htmlcode, allow_empty. Run validate_pagebuilderck_content with the '
			. 'same htmlcode first if you want a dry run.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'page_id'  => ['type' => 'integer', 'description' => 'Row id in #__pagebuilderck_pages.'],
				'id'       => ['type' => 'integer', 'description' => 'Alias for page_id.'],
				'htmlcode' => ['type' => 'string', 'description' => 'The complete new document. Use real site URLs; the |URIROOT| tokenisation is applied for you.'],
				'allow_empty' => ['type' => 'boolean', 'description' => 'Required to be true before empty content will be written, because that blanks the page. Default false.'],
			],
			'required'             => ['page_id', 'htmlcode'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

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

		if (!\array_key_exists('htmlcode', $arguments) || !\is_string($arguments['htmlcode'])) {
			return ToolResult::error('htmlcode is required and must be a string.');
		}

		$previous = (string) $row['htmlcode'];
		$incoming = (string) $arguments['htmlcode'];

		if (trim($incoming) === '' && ($arguments['allow_empty'] ?? false) !== true) {
			return ToolResult::json([
				'ok'      => false,
				'refused' => 'Nothing was written. The supplied htmlcode is empty, which would blank page '
					. $pageId . ' — it currently holds ' . \strlen($previous) . ' bytes of content.',
				'override' => 'Pass allow_empty: true if blanking the page is genuinely what you want.',
				'page'     => $this->pbckPageSummary($row),
				'component' => $this->pbckEditionNotice(),
			], true);
		}

		// Step 1. Always on the way in. A stored absolute URL is a page that breaks
		// when the site moves.
		$html      = $this->pbckCollapseRoot($incoming);
		$collapsed = $html !== $incoming;

		// Step 2. The losslessness gate, on the INCOMING content. A document we
		// cannot reproduce byte for byte is one that no later edit could touch
		// safely, so it does not get to become the stored content.
		if (!$this->pbckIsLossless($html)) {
			return ToolResult::json([
				'ok'      => false,
				'refused' => 'Nothing was written. The supplied content does not survive a parse and '
					. 're-serialise byte for byte.',
				'why'     => 'Page Builder CK stores raw HTML with no encoder in front of it, and every '
					. 'later edit re-parses what is stored. Accepting a document the parser cannot '
					. 'reproduce would mean the first block-level edit silently rewrites markup nobody '
					. 'asked to change. Unbalanced tags are the usual cause; content over 8 MB is refused '
					. 'outright by the parser.',
				'bytes'   => \strlen($html),
				'next'    => 'Run validate_pagebuilderck_content with this same htmlcode — it reports the '
					. 'same check alongside everything else it finds.',
				'page'    => $this->pbckPageSummary($row),
				'component' => $this->pbckEditionNotice(),
			], true);
		}

		// Step 3. Refuse on any fatal; report warnings.
		$enabled  = $this->pbckEnabledAddonTypes();
		$problems = $this->pbckValidate($html, $enabled);

		if ($this->pbckHasFatal($problems)) {
			return ToolResult::json([
				'ok'       => false,
				'refused'  => 'Nothing was written. The supplied content contains fatal problems and page '
					. $pageId . ' is unchanged.',
				'problems' => $this->pbckGroupProblems($problems),
				'next'     => 'Each fatal is described with the path it was found at. Fix them and call '
					. 'again, or run validate_pagebuilderck_content to iterate without touching the page.',
				'page'     => $this->pbckPageSummary($row),
				'component' => $this->pbckEditionNotice(),
			], true);
		}

		// Step 4.
		$modified = $this->pbckStorePageHtml($pageId, $html);

		$outline = $this->pbckOutline($html, $enabled);

		$result = [
			'ok'       => true,
			'action'   => 'page content replaced',
			'page_id'  => $pageId,
			'title'    => (string) $row['title'],
			'modified' => $modified,
			'bytes'    => ['before' => \strlen($previous), 'after' => \strlen($html)],
			'written'  => [
				'rows'       => $outline['rows'] ?? [],
				'row_count'  => $outline['row_count'] ?? 0,
				'singletons' => $outline['singletons'] ?? [],
			],
		];

		if ($collapsed) {
			$result['uriroot_collapsed'] = 'The literal site root "' . $this->pbckSiteRoot() . '" was '
				. 'replaced with the |URIROOT| token before storing, which is how Page Builder CK stores '
				. 'internal URLs. Reading the content back gives you the token; the renderer expands it.';
		}

		$grouped = $this->pbckGroupProblems($problems);

		if (isset($grouped['warning'])) {
			$result['warnings'] = $grouped['warning'];
			$result['warnings_note'] = 'These did not block the write. The page renders, but each one is '
				. 'a real defect — a data-type with no enabled plugin renders as static HTML rather than '
				. 'as the addon, and an untokenised absolute URL breaks if the site moves.';
		}

		$result['notes'] = $this->pbckWriteNotes();

		$result['notes']['block_css_untouched'] = 'The .ckstyle CSS inside each block you supplied is what '
			. 'the front end will use verbatim. Page Builder CK generates that CSS in the editor '
			. '(administrator/helpers/stylescss.php is included only by the admin views), never at render '
			. 'time, so nothing regenerates it after this write.';

		$result['component'] = $this->pbckEditionNotice();

		return ToolResult::json($result);
	}
}
