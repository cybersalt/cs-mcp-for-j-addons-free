<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Content;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Factory;

/**
 * The shared write contract for every tool in this folder.
 *
 * Page Builder CK stores a page as raw HTML in one `htmlcode` column. There is
 * no encoder between us and the renderer, so the bytes we write are the bytes
 * the front end parses. Every mutation therefore runs the same sequence, and it
 * lives here rather than being retyped eight times:
 *
 *   1. `pbckCollapseRoot()` — tokenise the site root. Always, on the way in.
 *   2. `pbckValidate()` on the SOURCE — refuse on any fatal. A fatal includes
 *      "does not survive a parse/serialise round-trip", so this is also the
 *      losslessness gate for in-place edits.
 *   3. mutate the parsed DOM, then `pbckSerialise($dom, $original)` so the edge
 *      whitespace the parser drops is restored.
 *   4. `pbckValidate()` on the RESULT — refuse if the edit introduced a fatal.
 *      Since step 2 guaranteed the source was clean, any fatal here is ours.
 *   5. only then UPDATE `htmlcode`, and `modified` with it.
 *
 * ---------------------------------------------------------------------------
 * WHAT A DIRECT COLUMN WRITE DOES NOT DO
 * ---------------------------------------------------------------------------
 *
 *   - It writes NO `.pbck` backup. `administrator/models/page.php:106` calls
 *     `PagebuilderckHelper::makeBackup()` immediately before its own store, so
 *     the vendor keeps five rolling pre-save snapshots per page. A direct
 *     UPDATE bypasses that entirely — there is no restore point for our change.
 *     Read the current content first if you want one.
 *   - It does NOT regenerate any block's `.ckstyle` CSS.
 *     `administrator/helpers/stylescss.php` is included only by the ADMIN views
 *     and the editor's AJAX endpoints (`administrator/interfaces/rendercss.php`)
 *     — never by the site renderer. The CSS stored inside each block is the
 *     only CSS the front end ever sees.
 *
 * Both facts are surfaced in the response of every write tool, not just noted
 * here, because a caller consuming JSON never reads this file.
 */
trait PageContentEditTrait
{
	// -----------------------------------------------------------------------
	// Loading
	// -----------------------------------------------------------------------

	/** Component installed and `#__pagebuilderck_pages` present. Null when clear. */
	protected function pbckContentGuard(): ?ToolResult
	{
		if ($this->pbckAdminBase() === null) {
			return $this->pbckNotInstalledError();
		}

		if (!$this->pbckTableExists('pages')) {
			return $this->pbckMissingTableError('pages');
		}

		return null;
	}

	/**
	 * The page id from arguments, accepting `page_id` or the shorter `id`.
	 *
	 * @throws \InvalidArgumentException
	 */
	protected function pbckPageId(array $arguments): int
	{
		$id = (int) ($arguments['page_id'] ?? $arguments['id'] ?? 0);

		if ($id <= 0) {
			throw new \InvalidArgumentException('page_id is required and must be a positive integer.');
		}

		return $id;
	}

	/** @return array<string,mixed>|null */
	protected function pbckLoadPageRow(int $id): ?array
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'title', 'state', 'created', 'modified', 'checked_out', 'htmlcode']))
			->from($this->db->quoteName($this->pbckTable('pages')))
			->where($this->db->quoteName('id') . ' = ' . (int) $id);

		$row = $this->db->setQuery($query)->loadAssoc();

		return \is_array($row) ? $row : null;
	}

	protected function pbckPageNotFoundError(int $id): ToolResult
	{
		return ToolResult::json([
			'ok'    => false,
			'error' => sprintf(
				'No row with id %d in %s. Run list_pagebuilderck_pages to see the ids that exist.',
				$id,
				$this->pbckTable('pages')
			),
		], true);
	}

	/** Compact identity block for a response. */
	protected function pbckPageSummary(array $row): array
	{
		$summary = [
			'id'       => (int) $row['id'],
			'title'    => (string) $row['title'],
			'state'    => $this->pbckStateLabel((int) $row['state']),
			'modified' => (string) $row['modified'],
		];

		$checkedOut = $this->pbckCheckedOutBy($row);

		if ($checkedOut !== null) {
			$summary['checked_out_by'] = $checkedOut;
			$summary['checked_out_note'] = 'This page is checked out to user ' . $checkedOut . '. Page '
				. 'Builder CK never releases a check-out automatically, so this may be a stale lock — but '
				. 'it may equally be someone with the builder open right now, whose next Save would '
				. 'overwrite anything written here.';
		}

		return $summary;
	}

	// -----------------------------------------------------------------------
	// The guard sequence
	// -----------------------------------------------------------------------

	/**
	 * Refuse an IN-PLACE edit of content that is already broken.
	 *
	 * A pre-existing fatal is not our doing, but editing around it is not safe:
	 * a non-lossless document would be silently rewritten by the round-trip, and
	 * duplicated ids make "the block with id X" ambiguous. Replacing the whole
	 * document with write_pagebuilderck_page_content is the way out, because
	 * that path validates only what you supply.
	 */
	protected function pbckAssertSourceEditable(int $pageId, array $problems): ?ToolResult
	{
		if (!$this->pbckHasFatal($problems)) {
			return null;
		}

		return ToolResult::json([
			'ok'       => false,
			'refused'  => 'The stored content of page ' . $pageId . ' already contains fatal problems, so '
				. 'editing it in place is not safe.',
			'why'      => 'A fatal means either the document does not survive a parse/serialise round-trip '
				. 'byte for byte — in which case any edit would silently rewrite markup nobody asked to '
				. 'change — or its structure is ambiguous, for instance duplicated ids, which makes '
				. '"the block with this id" mean more than one thing.',
			'problems' => $this->pbckGroupProblems($problems),
			'resolution' => 'Run validate_pagebuilderck_content on this page for the full list. To repair '
				. 'it, read the content, fix it, and replace the whole document with '
				. 'write_pagebuilderck_page_content — that tool validates only the content you supply, so '
				. 'it is the way out of this state.',
			'component' => $this->pbckEditionNotice(),
		], true);
	}

	/**
	 * Refuse to persist a result we broke ourselves.
	 *
	 * The source was already proven clean, so any fatal here was introduced by
	 * this call — almost always by caller-supplied markup in `inner_html`.
	 */
	protected function pbckAssertResultSafe(array $problems, string $action): ?ToolResult
	{
		if (!$this->pbckHasFatal($problems)) {
			return null;
		}

		return ToolResult::json([
			'ok'       => false,
			'refused'  => 'Nothing was written. ' . $action . ' would have produced content with fatal '
				. 'problems, and the stored page is unchanged.',
			'why'      => 'The page validated cleanly before this call, so these problems came from this '
				. 'edit. Markup supplied in inner_html is the usual cause — an unbalanced tag breaks the '
				. 'round-trip, and an id that already exists elsewhere in the page cross-applies '
				. 'block CSS.',
			'problems' => $this->pbckGroupProblems($problems),
			'component' => $this->pbckEditionNotice(),
		], true);
	}

	/**
	 * Group `pbckValidate()` output by severity, dropping empty buckets.
	 *
	 * @return array<string, array<int, array<string,string>>>
	 */
	protected function pbckGroupProblems(array $problems): array
	{
		$grouped = [];

		foreach ($problems as $problem) {
			$severity = (string) ($problem['severity'] ?? 'unknown');

			$grouped[$severity][] = [
				'path'    => (string) ($problem['path'] ?? ''),
				'problem' => (string) ($problem['problem'] ?? ''),
			];
		}

		return $grouped;
	}

	/**
	 * Persist new content and stamp `modified`.
	 *
	 * Written through Joomla's query builder rather than the vendor's
	 * `CKFof::dbStore()`, which casts numeric-looking values to int on UPDATE.
	 * `htmlcode` is longtext, so there is no size guard to apply here — the
	 * 64 KB `text` trap is on `#__pagebuilderck_styles`, not this table.
	 *
	 * @return string The timestamp written to `modified`.
	 */
	protected function pbckStorePageHtml(int $id, string $html): string
	{
		$modified = Factory::getDate()->toSql();

		$query = $this->db->getQuery(true)
			->update($this->db->quoteName($this->pbckTable('pages')))
			->set($this->db->quoteName('htmlcode') . ' = ' . $this->db->quote($html))
			->set($this->db->quoteName('modified') . ' = ' . $this->db->quote($modified))
			->where($this->db->quoteName('id') . ' = ' . (int) $id);

		$this->db->setQuery($query)->execute();

		return $modified;
	}

	/**
	 * Hazards that apply to every direct `htmlcode` write, for the payload.
	 *
	 * @return array<string,string>
	 */
	protected function pbckWriteNotes(): array
	{
		return [
			'no_vendor_backup' => 'Page Builder CK writes a rolling .pbck snapshot of the PREVIOUS content '
				. 'on each of its own saves (administrator/models/page.php:106, "make a backup before '
				. 'save"). A direct column write does not go through that path, so this change has no '
				. 'restore point. list_pagebuilderck_backups still shows the snapshots taken by earlier '
				. 'editor saves.',
			'editor_reserialises' => 'The next time a human opens this page in the builder and saves, the '
				. 'editor re-serialises the whole document from its own DOM. Anything written here that '
				. 'the editor does not understand is rewritten at that point.',
		];
	}

	// -----------------------------------------------------------------------
	// DOM location
	// -----------------------------------------------------------------------

	/** True when an element node carries `$class`. Text and comment nodes are false. */
	protected function pbckNodeHasClass(object $node, string $class): bool
	{
		$tag = (string) ($node->tag ?? '');

		if ($tag === '' || $tag === 'text' || $tag === 'comment' || $tag === 'unknown') {
			return false;
		}

		$raw = $node->getAttribute('class');

		if (!\is_string($raw) || trim($raw) === '') {
			return false;
		}

		return \in_array($class, preg_split('/\s+/', trim($raw)) ?: [], true);
	}

	/** The `.cktype` block with this id, anywhere in the document. */
	protected function pbckFindBlock(object $dom, string $blockId): ?object
	{
		foreach ($dom->find('div.cktype') as $node) {
			if ((string) $node->getAttribute('id') === $blockId) {
				return $node;
			}
		}

		return null;
	}

	/** The `.blockck` column with this id. Columns, not blocks — they are different things. */
	protected function pbckFindColumn(object $dom, string $columnId): ?object
	{
		foreach ($dom->find('div.blockck') as $node) {
			if ((string) $node->getAttribute('id') === $columnId) {
				return $node;
			}
		}

		return null;
	}

	/**
	 * A column's OWN `.innercontent`, which is where its blocks live.
	 *
	 * Filtered by nearest ancestor column so a nested row's `.innercontent`
	 * inside this column is not mistaken for it.
	 */
	protected function pbckColumnContent(object $columnNode): ?object
	{
		foreach ($columnNode->find('div.innercontent') as $node) {
			if ($this->pbckNearestAncestorWithClass($node, 'blockck') === $columnNode) {
				return $node;
			}
		}

		return null;
	}

	/** A direct element child carrying `$class`, or null. */
	protected function pbckDirectChild(object $node, string $class): ?object
	{
		foreach ($node->children() as $child) {
			if ($this->pbckNodeHasClass($child, $class)) {
				return $child;
			}
		}

		return null;
	}

	/** True when `$candidate` sits anywhere inside `$ancestor`. */
	protected function pbckIsDescendantOf(object $candidate, object $ancestor): bool
	{
		$parent = $candidate->parent();

		while ($parent !== null && (string) ($parent->tag ?? '') !== 'root') {
			if ($parent === $ancestor) {
				return true;
			}

			$parent = $parent->parent();
		}

		return false;
	}

	/**
	 * Every block id in the document, for a helpful "no such block" refusal.
	 *
	 * @return array<int,string>
	 */
	protected function pbckBlockIds(object $dom): array
	{
		$ids = [];

		foreach ($dom->find('div.cktype') as $node) {
			$id = (string) $node->getAttribute('id');

			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Every column id in the document.
	 *
	 * @return array<int,string>
	 */
	protected function pbckColumnIds(object $dom): array
	{
		$ids = [];

		foreach ($dom->find('div.blockck') as $node) {
			$id = (string) $node->getAttribute('id');

			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	// -----------------------------------------------------------------------
	// Column content surgery
	// -----------------------------------------------------------------------

	/**
	 * Rebuild a `.innercontent` div's markup, optionally removing one block and
	 * optionally inserting fresh block markup at a block index.
	 *
	 * Iterates `->nodes` rather than `->children()` because `nodes` is what
	 * `innertext()` concatenates: it includes the text nodes carrying the
	 * whitespace between blocks. Rebuilding from `children()` would quietly
	 * delete that whitespace and break the round-trip.
	 *
	 * `$insertAt` indexes the column's BLOCK list — the same order
	 * get_pagebuilderck_page_outline reports in `blocks`. Nested rows are
	 * siblings of those blocks in the markup but are not counted, so an index
	 * taken from the outline means what the caller thinks it means.
	 *
	 * @return array{html: string, removed: bool, inserted_at: int|null, block_ids: array<int,string>}
	 */
	protected function pbckRebuildColumnContent(
		object $innerContent,
		?string $removeBlockId = null,
		?string $insertMarkup = null,
		?int $insertAt = null
	): array {
		$pieces  = [];
		$removed = false;

		foreach ($innerContent->nodes as $node) {
			$isBlock = $this->pbckNodeHasClass($node, 'cktype');

			if ($isBlock && $removeBlockId !== null && (string) $node->getAttribute('id') === $removeBlockId) {
				$removed = true;
				continue;
			}

			$pieces[] = ['markup' => (string) $node->outertext(), 'is_block' => $isBlock];
		}

		$blockCount = 0;

		foreach ($pieces as $piece) {
			if ($piece['is_block']) {
				$blockCount++;
			}
		}

		$insertedAt = null;
		$html       = '';

		if ($insertMarkup === null) {
			foreach ($pieces as $piece) {
				$html .= $piece['markup'];
			}
		} else {
			$target     = $insertAt === null ? $blockCount : max(0, min($blockCount, $insertAt));
			$insertedAt = $target;
			$seen       = 0;
			$placed     = false;

			foreach ($pieces as $piece) {
				if ($piece['is_block'] && !$placed && $seen === $target) {
					$html .= $insertMarkup;
					$placed = true;
				}

				if ($piece['is_block']) {
					$seen++;
				}

				$html .= $piece['markup'];
			}

			if (!$placed) {
				$html .= $insertMarkup;
			}
		}

		// Recomputed from the rebuilt string so the caller is told the order that
		// actually exists now, not the order we intended.
		$ids = [];

		if (preg_match_all('/<div[^>]*\sid="([^"]*)"[^>]*\sclass="[^"]*\bcktype\b/i', $html, $m)) {
			$ids = $m[1];
		}

		return [
			'html'        => $html,
			'removed'     => $removed,
			'inserted_at' => $insertedAt,
			'block_ids'   => $ids,
		];
	}

	// -----------------------------------------------------------------------
	// Attribute writing
	// -----------------------------------------------------------------------

	/**
	 * Escape a value for use inside a double-quoted HTML attribute.
	 *
	 * `double_encode` is false so a value that already contains `&amp;` is left
	 * alone rather than becoming `&amp;amp;` — real stored values do contain
	 * entities, particularly in URLs.
	 */
	protected function pbckAttrValue(string $value): string
	{
		return htmlspecialchars($value, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8', false);
	}

	/**
	 * True when a string is usable as an HTML attribute name.
	 *
	 * Page Builder CK's option attribute names are the ids of inputs in its
	 * options popup, so they are always plain identifiers. Anything else is
	 * rejected rather than escaped, because an attribute name cannot be escaped
	 * — a stray quote or space would silently become a different attribute.
	 */
	protected function pbckIsAttrName(string $name): bool
	{
		return preg_match('/^[A-Za-z_][A-Za-z0-9_.:-]*$/', $name) === 1;
	}

	/** True when a string is usable as a `.ckprops` tab class or a `data-type`. */
	protected function pbckIsIdentifier(string $value): bool
	{
		return preg_match('/^[A-Za-z0-9_-]+$/', $value) === 1;
	}
}
