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
 * The dry run for every content write in this add-on.
 *
 * Takes either a stored page or a candidate `htmlcode` string and reports what
 * the write tools would object to, without writing anything. `safe_to_write` is
 * the same boolean those tools compute internally: false means at least one
 * fatal, and every write path refuses on a fatal.
 *
 * Worth running against a page before any in-place edit, because
 * add/update/move/delete all refuse on a fatal in the SOURCE — a page that is
 * already broken cannot be safely edited around, only replaced wholesale.
 */
final class ValidateContentTool extends AbstractTool
{
	use PagebuilderckBootTrait;
	use PagebuilderckContentTrait;
	use PageContentEditTrait;

	public function getName(): string { return 'validate_pagebuilderck_content'; }

	public function getDescription(): string
	{
		return 'Check Page Builder CK content for the things that silently break a rendered page. Pass '
			. 'EITHER page_id (validate what is stored) OR htmlcode (validate a candidate string before '
			. 'writing it) — exactly one, not both. Writes nothing. '
			. 'Returns problems grouped by severity plus safe_to_write, which is the same boolean every '
			. 'write tool in this add-on computes internally: any fatal means the write is refused. '
			. 'FATAL problems, and why each one matters: '
			. '(1) NOT ROUND-TRIP SAFE — the content does not survive a parse and re-serialise byte for '
			. 'byte. Page Builder CK stores raw HTML with no encoder in front of it, so an edit to a '
			. 'document like this would silently rewrite markup nobody asked to change. '
			. '(2) UNPARSEABLE — including anything over 8 MB, which the parser refuses by design. '
			. '(3) DUPLICATE ids — all Page Builder CK block CSS is #id-scoped and lives inside the '
			. 'block, so two blocks sharing an id cross-apply each other\'s styling, and "the block with '
			. 'this id" stops having one answer. '
			. '(4) A BLOCK WITH NO data-type — Page Builder CK renders a literal red "ELEMENT TYPE NOT '
			. 'FOUND" paragraph into the live page for it. '
			. '(5) A NON-EMPTY .ckprops div — the renderer strips only EMPTY ones (its regex is '
			. '`>[^<]*</div>`), so anything inside one leaks verbatim into the front end. '
			. '(6) data-nb DISAGREEING with a row\'s real column count — the generated width rules are '
			. 'keyed on [data-gutter][data-nb][data-width], so when data-nb is wrong no rule matches and '
			. 'every column in that row renders with no width at all. '
			. 'WARNINGS, which do not block a write: a data-type with no enabled plugin (the block still '
			. 'renders its inner markup as static HTML — Page Builder CK fails OPEN, unlike SP Page '
			. 'Builder); a row with no data-nb; a block with no id, whose .ckstyle CSS therefore cannot '
			. 'be scoped; and a literal site root left in a URL instead of the |URIROOT| token, which '
			. 'breaks the page the moment the site moves domain or subdirectory.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'page_id'  => ['type' => 'integer', 'description' => 'Validate the stored content of this page. Mutually exclusive with htmlcode.'],
				'id'       => ['type' => 'integer', 'description' => 'Alias for page_id.'],
				'htmlcode' => ['type' => 'string', 'description' => 'Validate this candidate content instead. Mutually exclusive with page_id. Pass it exactly as you would to write_pagebuilderck_page_content.'],
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

		$hasPage = (int) ($arguments['page_id'] ?? $arguments['id'] ?? 0) > 0;
		$hasHtml = \array_key_exists('htmlcode', $arguments);

		if ($hasPage === $hasHtml) {
			return ToolResult::error(
				$hasPage
					? 'Pass either page_id or htmlcode, not both. Validating a stored page and validating '
						. 'a candidate string are different questions and the answers would be confused.'
					: 'Pass either page_id (validate what is stored) or htmlcode (validate a candidate '
						. 'string). Neither was supplied.'
			);
		}

		if ($hasPage) {
			$pageId = $this->pbckPageId($arguments);
			$row    = $this->pbckLoadPageRow($pageId);

			if ($row === null) {
				return $this->pbckPageNotFoundError($pageId);
			}

			$html   = (string) $row['htmlcode'];
			$source = ['kind' => 'page', 'page' => $this->pbckPageSummary($row)];
		} else {
			$html   = (string) $arguments['htmlcode'];
			$source = ['kind' => 'supplied string'];
		}

		$enabled  = $this->pbckEnabledAddonTypes();
		$problems = $this->pbckValidate($html, $enabled);
		$fatal    = $this->pbckHasFatal($problems);

		$grouped = $this->pbckGroupProblems($problems);

		$result = [
			'ok'             => true,
			'source'         => $source,
			'bytes'          => \strlen($html),
			'safe_to_write'  => !$fatal,
			'fatal_count'    => \count($grouped['fatal'] ?? []),
			'warning_count'  => \count($grouped['warning'] ?? []),
			'problems'       => $grouped,
			'round_trip_safe' => $this->pbckIsLossless($html),
			'enabled_types'  => $enabled,
		];

		if ($html === '' || trim($html) === '') {
			$result['empty'] = 'The content is empty. That validates clean because there is nothing to be '
				. 'wrong, but writing it to a page blanks that page. '
				. 'write_pagebuilderck_page_content requires allow_empty: true before it will do that.';
		}

		if (!$fatal && $problems === []) {
			$result['verdict'] = 'No problems found. This content is safe to write and safe to edit in '
				. 'place.';
		} elseif (!$fatal) {
			$result['verdict'] = 'No fatal problems, so this content is safe to write and safe to edit in '
				. 'place. The warnings are real but the page renders.';
		} else {
			$result['verdict'] = 'Fatal problems present. Every write tool in this add-on refuses on a '
				. 'fatal, and the in-place edit tools (add/update/move/delete block) also refuse when the '
				. 'page they are asked to edit is in this state. Repair it by replacing the whole '
				. 'document with write_pagebuilderck_page_content, which validates only what you supply.';
		}

		if ($hasHtml) {
			$result['note'] = 'This string was validated exactly as given. '
				. 'write_pagebuilderck_page_content runs pbckCollapseRoot() first, which replaces the '
				. 'literal site root with the |URIROOT| token — so a "contains the literal site root" '
				. 'warning here is fixed for you at write time and is not a reason to change the string.';
		}

		$result['component'] = $this->pbckEditionNotice();

		return ToolResult::json($result);
	}
}
