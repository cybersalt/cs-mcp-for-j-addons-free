<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Reference;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckBootTrait;
use Joomla\CMS\User\User;

/**
 * List rows in `#__pagebuilderck_categories`.
 *
 * These are Page Builder CK's OWN categories. They have nothing to do with
 * Joomla's `#__categories` — no `extension` column, no nested set, no ACL, no
 * asset row. Nothing in Joomla's category tooling can see them and nothing here
 * can see Joomla's. A caller that assumed one and got the other would silently
 * be filtering against the wrong table.
 *
 * Two columns on `#__pagebuilderck_pages` look like they point here and only one
 * of them does:
 *
 *   - `categories` varchar(255) is the real association: a comma-separated id
 *     list, queried with FIND_IN_SET (administrator/models/pages.php:75).
 *   - `catid` varchar(255) is vestigial. It is a VARCHAR, not an int foreign
 *     key, it has no index and no constraint, and the page save controller
 *     hardcodes it back to '' on every save (administrator/controllers/page.php:64).
 *     Any value written there survives only until the next human Save.
 *
 * The usage counts below therefore report both, separately, rather than
 * pretending the two agree.
 */
final class ListCategoriesTool extends AbstractTool
{
	use PagebuilderckBootTrait;

	public function getName(): string { return 'list_pagebuilderck_categories'; }

	public function getDescription(): string
	{
		return 'List Page Builder CK categories from #__pagebuilderck_categories. '
			. 'IMPORTANT: these are Page Builder CK\'s OWN categories and are NOT Joomla categories. '
			. 'They live in a separate flat table with no extension column, no nested set, no access '
			. 'level and no #__assets row, so Joomla\'s category tools cannot see them and this tool '
			. 'cannot see Joomla\'s. The column names differ too — this table has `name`, not `title`. '
			. 'Filters: search (substring of name), state (published/unpublished/trashed/archived or the '
			. 'integer), limit (default 50, max 200) and offset. '
			. 'Each row reports how many pages reference it, counted TWO ways because Page Builder CK '
			. 'stores the association in two places that legitimately disagree: `pages.categories` is a '
			. 'comma-separated id list and is the one the admin list screen actually filters on '
			. '(FIND_IN_SET, administrator/models/pages.php:75), while `pages.catid` is a varchar(255) '
			. 'with no index and no foreign key that the page save controller blanks to \'\' on every '
			. 'save (administrator/controllers/page.php:64) — so catid is usually empty on a site that '
			. 'has ever been edited through the builder, and a non-empty value there is not durable. '
			. 'Read-only. Does not create, rename or delete categories.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'search' => ['type' => 'string', 'description' => 'Case-insensitive substring match on the category name.'],
				'state'  => ['type' => 'string', 'description' => 'published | unpublished | trashed | archived, or the integer (1, 0, -2, 2). Omit for any state.'],
				'limit'  => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset' => ['type' => 'integer', 'description' => 'Rows to skip.'],
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

		if (!$this->pbckTableExists('categories')) {
			return $this->pbckMissingTableError('categories');
		}

		$table  = $this->db->quoteName($this->pbckTable('categories'));
		$limit  = $this->pbckLimit($arguments);
		$offset = $this->pbckOffset($arguments);

		$where = [];

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$where[] = $this->db->quoteName('name') . ' LIKE '
				. $this->db->quote('%' . $this->db->escape($search, true) . '%', false);
		}

		$state = $this->pbckNormaliseState($arguments['state'] ?? null);

		if ($state !== null) {
			$where[] = $this->db->quoteName('state') . ' = ' . $state;
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		$total = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $table . $whereSql)->loadResult();

		$rows = $this->db->setQuery(
			'SELECT * FROM ' . $table . $whereSql
			. ' ORDER BY ' . $this->db->quoteName('ordering') . ' ASC, ' . $this->db->quoteName('id') . ' ASC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$usage = $this->pageUsageCounts();

		$categories = [];

		foreach ($rows as $row) {
			$id = (int) $row['id'];

			$entry = [
				'id'          => $id,
				'name'        => (string) ($row['name'] ?? ''),
				'description' => $this->pbckPreview((string) ($row['description'] ?? ''), 200),
				'ordering'    => (int) ($row['ordering'] ?? 0),
				'state'       => $this->pbckStateLabel((int) ($row['state'] ?? 0)),
				'created'     => (string) ($row['created'] ?? ''),
				'modified'    => (string) ($row['modified'] ?? ''),
				'used_by_pages' => [
					'via_categories_column' => $usage['categories'][$id] ?? 0,
					'via_catid_column'      => $usage['catid'][$id] ?? 0,
				],
			];

			$checkedOut = $this->pbckCheckedOutBy($row);

			if ($checkedOut !== null) {
				$entry['checked_out_by'] = $checkedOut;
				$entry['warning'] = 'This category is checked out to user ' . $checkedOut . '. Page Builder '
					. 'CK never releases a check-out automatically, so an abandoned editing session leaves '
					. 'it locked until checked_out is cleared by hand.';
			}

			$categories[] = $entry;
		}

		return ToolResult::json([
			'ok'         => true,
			'total'      => $total,
			'limit'      => $limit,
			'offset'     => $offset,
			'showing'    => \count($categories),
			'categories' => $categories,
			'note'       => 'These are Page Builder CK categories from #__pagebuilderck_categories, not '
				. 'Joomla categories from #__categories. The two systems are unrelated.',
			'association_note' => 'A page belongs to a category through `pages.categories`, a '
				. 'comma-separated id list queried with FIND_IN_SET. The separate `pages.catid` column is '
				. 'a varchar(255), not an int foreign key, and the builder\'s save controller resets it to '
				. 'an empty string on every save, so treat any value found there as transient.',
			'component'  => $this->pbckEditionNotice(),
		]);
	}

	/**
	 * How many pages reference each category id, counted separately per column.
	 *
	 * Done in PHP rather than as two correlated subqueries because both columns
	 * are varchars holding comma lists; FIND_IN_SET per category per row would be
	 * a full scan for every category listed.
	 *
	 * @return array{categories: array<int,int>, catid: array<int,int>}
	 */
	private function pageUsageCounts(): array
	{
		$out = ['categories' => [], 'catid' => []];

		if (!$this->pbckTableExists('pages')) {
			return $out;
		}

		$columns = $this->pbckColumns('pages');
		$select  = [];

		foreach (['categories', 'catid'] as $column) {
			if (\in_array($column, $columns, true)) {
				$select[] = $this->db->quoteName($column);
			}
		}

		if ($select === []) {
			return $out;
		}

		$rows = $this->db->setQuery(
			'SELECT ' . implode(', ', $select) . ' FROM ' . $this->db->quoteName($this->pbckTable('pages'))
			. ' WHERE ' . $this->db->quoteName('state') . ' <> -2'
		)->loadAssocList() ?: [];

		foreach ($rows as $row) {
			foreach (['categories', 'catid'] as $column) {
				if (!\array_key_exists($column, $row)) {
					continue;
				}

				foreach (explode(',', (string) $row[$column]) as $piece) {
					$piece = trim($piece);

					if ($piece === '' || !ctype_digit($piece)) {
						continue;
					}

					$id = (int) $piece;
					$out[$column][$id] = ($out[$column][$id] ?? 0) + 1;
				}
			}
		}

		return $out;
	}
}
