<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Pages;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckBootTrait;
use Joomla\CMS\User\User;

/**
 * List rows in `#__pagebuilderck_pages`.
 *
 * Page Builder CK keeps pages in a table of its own, entirely outside
 * `#__content`, and stores each one as raw HTML in a single `htmlcode` longtext
 * column. This listing therefore has to be careful about two things.
 *
 * FIRST, it never returns `htmlcode`. A page that has been worked on is
 * routinely 50–500 KB of markup, and fifty of them would be a multi-megabyte
 * response for what the caller asked to be an index. Only `LENGTH()` comes back,
 * plus two marker counts computed inside MySQL so no bytes cross the wire.
 *
 * SECOND, this table has two different category columns and they do NOT mean the
 * same thing. `categories` is the comma-separated id list the admin Pages screen
 * actually filters on (`models/pages.php:70` uses
 * `FIND_IN_SET(<id>, categories)`), while `catid` is a leftover varchar that the
 * page editor blanks on every save (`controllers/page.php:62`). Filtering by
 * `catid` on a site whose pages have ever been saved in the builder will match
 * nothing at all, so both filters are offered and the difference is stated in
 * the response rather than left as a trap.
 */
final class ListPagesTool extends AbstractTool
{
	use PagebuilderckBootTrait;

	/**
	 * Substrings that mark a row and a block in Page Builder CK's markup.
	 *
	 * Counted with LENGTH()/REPLACE() rather than by parsing, so the count costs
	 * one MySQL string pass and zero transferred bytes. It is an approximation —
	 * see `counts_note` in the response.
	 */
	private const ROW_MARKER = 'class="rowck';

	private const BLOCK_MARKER = 'class="cktype';

	public function getName(): string { return 'list_pagebuilderck_pages'; }

	public function getDescription(): string
	{
		return 'List Page Builder CK pages from #__pagebuilderck_pages. '
			. 'Filters: search (case-insensitive substring on title), state (published/unpublished/'
			. 'trashed/archived, or the integer 1/0/-2/2), catid, created_by, featured. '
			. 'Supports limit (default 50, max 200) and offset. '
			. 'Trashed pages (state = -2) are EXCLUDED unless you ask for them by name, matching the '
			. 'admin Pages screen, which hardcodes `state > -1`. Page Builder CK has no empty-trash '
			. 'action anywhere in its UI, so trashed rows accumulate forever and would otherwise swamp '
			. 'a long-lived site\'s listing. '
			. 'IMPORTANT about categories: this table has TWO category columns that do not agree. '
			. '`categories` is a comma-separated id list and is what the admin screen filters on; '
			. '`catid` is a varchar the page editor overwrites with an empty string on every save, so '
			. 'filtering by catid returns nothing on any page that has been saved in the builder. Use '
			. 'category_id (which searches `categories`) unless you specifically want the raw catid '
			. 'column. '
			. 'This tool NEVER returns htmlcode — a real page is tens to hundreds of kilobytes of raw '
			. 'HTML. Each row reports htmlcode_bytes plus approximate row and block counts derived from '
			. 'a MySQL substring count, not a parse; use get_pagebuilderck_page for the real content and '
			. 'a parsed outline. '
			. 'checked_out is a VARCHAR holding a user id, and Page Builder CK never releases it '
			. 'automatically — a row left checked out by an abandoned session stays locked until it is '
			. 'cleared by hand. It is reported as checked_out_by when set. '
			. 'There is no alias, language, asset_id or ordering-by-category support on this table: '
			. 'pages are addressed purely by id.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'search'      => ['type' => 'string', 'description' => 'Case-insensitive substring match on title.'],
				'state'       => ['type' => 'string', 'description' => 'published | unpublished | trashed | archived, or the integer (1, 0, -2, 2). Omit for any state except trashed.'],
				'category_id' => ['type' => 'integer', 'description' => 'Match pages whose `categories` list contains this id. This is what the admin Pages screen filters on.'],
				'catid'       => ['type' => 'string', 'description' => 'Exact match on the raw `catid` varchar column. Usually empty on real sites because the editor blanks it on every save — prefer category_id.'],
				'created_by'  => ['type' => 'integer', 'description' => 'Joomla user id of the author. Also blanked to 0 by the editor on save.'],
				'featured'    => ['type' => 'boolean', 'description' => 'true for featured pages only, false for non-featured only. Omit for both.'],
				'limit'       => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'      => ['type' => 'integer', 'description' => 'Rows to skip.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->pbckAdminBase() === null) {
			return $this->pbckNotInstalledError();
		}

		if (!$this->pbckTableExists('pages')) {
			return $this->pbckMissingTableError('pages');
		}

		$table  = $this->db->quoteName($this->pbckTable('pages'));
		$limit  = $this->pbckLimit($arguments);
		$offset = $this->pbckOffset($arguments);

		$where = [];

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$where[] = $this->db->quoteName('title') . ' LIKE '
				. $this->db->quote('%' . $this->db->escape($search, true) . '%', false);
		}

		$rawState   = $arguments['state'] ?? null;
		$stateGiven = $rawState !== null && trim((string) $rawState) !== '';
		$state      = $this->pbckNormaliseState($rawState);

		if ($stateGiven && $state === null) {
			// Silently ignoring an unrecognised state would hand back "all pages"
			// while the caller believed it had filtered.
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf(
					'Unrecognised state "%s". Use published, unpublished, trashed, archived, or the '
						. 'integer 1, 0, -2, 2.',
					(string) $rawState
				),
			], true);
		}

		if ($state !== null) {
			$where[] = $this->db->quoteName('state') . ' = ' . $state;
		} else {
			$where[] = $this->db->quoteName('state') . ' <> -2';
		}

		if (\array_key_exists('category_id', $arguments)) {
			$where[] = 'FIND_IN_SET(' . (int) $arguments['category_id'] . ', '
				. $this->db->quoteName('categories') . ') > 0';
		}

		// catid is varchar(255), so it is compared as a string, not cast to int.
		if (\array_key_exists('catid', $arguments)) {
			$where[] = $this->db->quoteName('catid') . ' = ' . $this->db->quote((string) $arguments['catid']);
		}

		if (\array_key_exists('created_by', $arguments)) {
			$where[] = $this->db->quoteName('created_by') . ' = ' . (int) $arguments['created_by'];
		}

		if (\array_key_exists('featured', $arguments)) {
			$where[] = $this->db->quoteName('featured') . ' = ' . ((bool) $arguments['featured'] ? 1 : 0);
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		$total = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $table . $whereSql)->loadResult();

		$select = [
			$this->db->quoteName('id'),
			$this->db->quoteName('title'),
			$this->db->quoteName('alias'),
			$this->db->quoteName('state'),
			$this->db->quoteName('ordering'),
			$this->db->quoteName('created'),
			$this->db->quoteName('modified'),
			$this->db->quoteName('catid'),
			$this->db->quoteName('categories'),
			$this->db->quoteName('created_by'),
			$this->db->quoteName('access'),
			$this->db->quoteName('hits'),
			$this->db->quoteName('featured'),
			$this->db->quoteName('checked_out'),
			'LENGTH(' . $this->db->quoteName('htmlcode') . ') AS htmlcode_bytes',
			$this->markerCount(self::ROW_MARKER) . ' AS approx_rows',
			$this->markerCount(self::BLOCK_MARKER) . ' AS approx_blocks',
		];

		$rows = $this->db->setQuery(
			'SELECT ' . implode(', ', $select) . ' FROM ' . $table . $whereSql
			. ' ORDER BY ' . $this->db->quoteName('id') . ' DESC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$pages = [];

		foreach ($rows as $row) {
			$bytes = (int) $row['htmlcode_bytes'];

			$entry = [
				'id'             => (int) $row['id'],
				'title'          => (string) $row['title'],
				'state'          => $this->pbckStateLabel((int) $row['state']),
				'access'         => (int) $row['access'],
				'featured'       => (int) $row['featured'] === 1,
				'created'        => (string) $row['created'],
				'created_by'     => (int) $row['created_by'],
				'modified'       => (string) $row['modified'],
				'hits'           => (int) $row['hits'],
				'ordering'       => (int) $row['ordering'],
				'catid'          => (string) $row['catid'],
				'categories'     => $this->splitIdList((string) $row['categories']),
				'htmlcode_bytes' => $bytes,
				'approx_rows'    => (int) $row['approx_rows'],
				'approx_blocks'  => (int) $row['approx_blocks'],
				'route'          => 'index.php?option=com_pagebuilderck&view=page&id=' . (int) $row['id'],
			];

			$checkedOut = $this->pbckCheckedOutBy($row);

			if ($checkedOut !== null) {
				$entry['checked_out_by'] = $checkedOut;
				$entry['checked_out_note'] = 'This page is checked out by user ' . $checkedOut . '. Page '
					. 'Builder CK only clears checked_out when that same user saves or cancels — there is '
					. 'no global check-in, so an abandoned session leaves the page locked indefinitely.';
			}

			if ($bytes === 0) {
				$entry['empty'] = 'htmlcode is empty. This page renders as nothing. It was either just '
					. 'created or saved with no content.';
			}

			// alias is never populated by the builder — it is hardcoded to '' on
			// every save. A non-empty one means something wrote the row directly.
			if (trim((string) $row['alias']) !== '') {
				$entry['alias'] = (string) $row['alias'];
				$entry['alias_note'] = 'This page has a non-empty alias, which Page Builder CK itself '
					. 'never produces (it hardcodes alias = "" on save) and never reads. It was written '
					. 'out of band and will be wiped the next time the page is saved in the builder.';
			}

			$pages[] = $entry;
		}

		return ToolResult::json([
			'ok'      => true,
			'total'   => $total,
			'limit'   => $limit,
			'offset'  => $offset,
			'showing' => \count($pages),
			'filter'  => [
				'state' => $state === null ? 'any except trashed' : $this->pbckStateLabel($state),
			],
			'pages'   => $pages,
			'counts_note' => 'approx_rows and approx_blocks count the literal substrings '
				. '\'' . self::ROW_MARKER . '\' and \'' . self::BLOCK_MARKER . '\' inside htmlcode using '
				. 'MySQL, so the listing stays cheap. They are approximations: nested rows are included '
				. 'in approx_rows, and the same substring appearing inside a text block\'s own content '
				. 'would be counted too. Call get_pagebuilderck_page for a parsed outline.',
			'category_note' => 'Page Builder CK stores category membership in the comma-separated '
				. '`categories` column, which is what its own Pages screen filters on. The separate '
				. '`catid` varchar is vestigial — the save controller blanks it every time — so a catid '
				. 'value in these results is almost certainly stale.',
			'component' => $this->pbckEditionNotice(),
		]);
	}

	/**
	 * SQL that counts occurrences of a literal marker without transferring the blob.
	 */
	private function markerCount(string $marker): string
	{
		$column = $this->db->quoteName('htmlcode');

		return '(LENGTH(' . $column . ') - LENGTH(REPLACE(' . $column . ', '
			. $this->db->quote($marker) . ', ' . $this->db->quote('') . '))) / ' . \strlen($marker);
	}

	/** @return array<int,int> */
	private function splitIdList(string $raw): array
	{
		$out = [];

		foreach (explode(',', $raw) as $piece) {
			$piece = trim($piece);

			if ($piece !== '' && ctype_digit($piece)) {
				$out[] = (int) $piece;
			}
		}

		return array_values(array_unique($out));
	}
}
