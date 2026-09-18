<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;

/**
 * List Akeeba Ticket System tickets, filtered in SQL and then gated row by row.
 *
 * WHY THE TWO-STAGE FILTER.
 * -------------------------
 * The SQL WHERE clause is an optimisation, not the access control. The access
 * control is Permissions::getTicketPrivileges()['view'], applied to every row
 * that survives the query. ATS 5.6.0 exists in large part because the list query
 * and the single-resource check used to disagree: the collection endpoint
 * correctly omitted a private ticket that the single-resource endpoint happily
 * served through the JSON:API. The fix was to move the category's view access
 * level into getTicketPrivileges() (Helper/Permissions.php:619-777). Re-deriving
 * that predicate in our own SQL would re-create exactly the class of bug the
 * vendor just spent a release fixing, so we do not: we filter cheaply, then ask
 * the vendor about every row, and report how many rows that removed.
 *
 * WHY WE BIND INSTEAD OF load().
 * ------------------------------
 * getTicketPrivileges() is typed to take a TicketTable, so each candidate row
 * needs one. The obvious `$table->load($id)` is NOT read-only: TicketTable's
 * onAfterLoad() calls ensureUcmRecord(), which INSERTs a #__ucm_content row when
 * one is missing (Table/TicketTable.php:710-719, 1008-1039). It self-disables on
 * Joomla later than 5.4 — which is why nothing happens on a current site — but a
 * listing tool that writes N rows to #__ucm_content on an older Joomla is not
 * something to ship. We already have the full `t.*` payload from the list query,
 * so we hand it to the vendor's own bindAsLoadEquivalent() (added in 5.3.10 for
 * this purpose), which populates valuesOnLoad the way load() would but skips the
 * UCM write. bind() also normalises `params` into a Registry via onBeforeBind(),
 * and Joomla's Table::bind() ignores keys that are not table columns, so the
 * joined category and user columns riding along in the same row are harmless.
 *
 * COST. The gate is not free: getTicketPrivileges() runs one isInvited() query
 * per row and, because ATSBootTrait turns Permissions' identity memoisation off
 * (one process can serve several actors), one User load per row as well. Large
 * pages are correspondingly expensive; the `note` in the response says so.
 *
 * PAGINATION. Rows are withheld AFTER the LIMIT is applied, so a page can come
 * back shorter than `limit` while more rows still exist. Page on `offset`, never
 * on `count`.
 */
final class ListTicketsTool extends AbstractTool
{
	use ATSBootTrait;

	private const ORDER_COLUMNS = ['id', 'created', 'modified', 'title', 'status', 'priority'];

	public function getName(): string { return 'list_ats_tickets'; }

	public function getDescription(): string
	{
		return 'List Akeeba Ticket System tickets (#__ats_tickets) with their category, author and '
			. 'assignee resolved. Returns per ticket: id, catid, category (title, alias, path, access, '
			. 'access_title, language, published), title, alias, status, status_label, priority, public, '
			. 'origin, assigned_to (+ name/username), created, created_by (+ name/username), modified, '
			. 'enabled, timespent, params, post_count and invited_count. '
			. 'FILTERS: catid (integer or array of integers), status (a single value — \'O\' Open, '
			. '\'P\' Pending, \'C\' Closed, or a site-defined numeric status passed as a STRING such as '
			. '"7"), assigned_to (0 means unassigned), created_by, public (0/1), enabled (0/1 — this is '
			. 'the publish flag), priority (integer; ATS\' own category form offers 1 High, 5 Normal, '
			. '10 Low but the column is a plain TINYINT and any value can be stored), search, '
			. 'created_after and created_before (any date string PHP can parse; both compare against '
			. '`created`). SEARCH PREFIXES, matching ATS\' own admin filter: a bare term matches the '
			. 'ticket title (LIKE %term%); "title:foo" is the same thing; "id:42" matches the ticket id '
			. 'exactly; "category:foo" matches the category title; "invited:foo" matches tickets whose '
			. 'INVITED COLLABORATORS include a user whose username, name or email matches (in ATS this '
			. 'prefix belongs to a separate "user" filter, re-exposed here on search for convenience). '
			. 'ORDERING: order_by one of id, created, modified, title, status, priority; order_dir '
			. 'ASC/DESC. Default is modified DESC, which is what ATS itself defaults to. '
			. 'SCHEMA NOTES THAT WILL OTHERWISE MISLEAD YOU: (1) `modified` means LAST REPLY, not last '
			. 'edited — ATS only writes it when someone posts, and it is returned here as both '
			. '`modified` and `last_reply`. (2) A ticket has NO access and NO language column; both are '
			. 'inherited from its category, which is why the category block carries them. (3) `timespent` '
			. 'on the ticket is derived — PostTable::onAfterStore() recomputes it as the SUM of the '
			. 'published posts\' timespent, so it only refreshes on the next reply. (4) The opening '
			. 'message of a ticket is a row in #__ats_posts, not a column here, so post_count includes '
			. 'it and a brand-new ticket has post_count 1. '
			. 'VISIBILITY: every row is individually checked with the component\'s own '
			. 'Permissions::getTicketPrivileges()[\'view\'] before being returned, and withheld_count '
			. 'reports how many of the fetched page that removed. Note that since 5.6.0 the category\'s '
			. 'view access level binds MANAGERS AND SUPER USERS TOO, so an administrator who does not '
			. 'hold a category\'s view level will not see its tickets here — that is the vendor\'s '
			. 'deliberate behaviour, not a bug in this tool. Rows are withheld after the limit is '
			. 'applied, so page on offset, not on count. Default limit 100, max 500.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'catid' => [
					'anyOf' => [
						['type' => 'integer'],
						['type' => 'array', 'items' => ['type' => 'integer']],
					],
					'description' => 'One ATS category id, or a list of them.',
				],
				'status' => [
					'type'        => 'string',
					'description' => 'Exactly one of "O" (Open), "P" (Pending), "C" (Closed), or a site-defined status "1".."99" as a string. Call list_ats_statuses to see which exist on this site.',
				],
				'assigned_to' => ['type' => 'integer', 'description' => 'Joomla user id of the assignee. 0 matches unassigned tickets.'],
				'created_by'  => ['type' => 'integer', 'description' => 'Joomla user id of the ticket author.'],
				'public'      => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1 = public ticket, 0 = private. Not the publish flag; see enabled.'],
				'enabled'     => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'The publish flag (aliased as `published` on TicketTable).'],
				'priority'    => ['type' => 'integer', 'description' => 'Exact match. ATS\' UI offers 1 High / 5 Normal / 10 Low; the column accepts any TINYINT.'],
				'search'      => [
					'type'        => 'string',
					'description' => 'Bare term or "title:", "id:", "category:", "invited:" prefixed term. See the tool description.',
				],
				'created_after'  => ['type' => 'string', 'description' => 'Tickets created on or after this date/time. Any string PHP can parse, e.g. "2026-01-01" or "-30 days".'],
				'created_before' => ['type' => 'string', 'description' => 'Tickets created on or before this date/time.'],
				'order_by'       => ['type' => 'string', 'enum' => self::ORDER_COLUMNS, 'description' => 'Default modified (= last reply).'],
				'order_dir'      => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'description' => 'Default DESC.'],
				'limit'          => ['type' => 'integer', 'description' => 'Default 100, max 500. The per-row visibility gate costs a couple of queries per row, so keep pages modest.'],
				'offset'         => ['type' => 'integer', 'description' => 'Default 0.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->atsBoot()) {
			return $this->notInstalledError();
		}

		$db = $this->db;

		$query = $db->getQuery(true)
			->select([
				$db->quoteName('t') . '.*',
				$db->quoteName('c.title', 'cat_title'),
				$db->quoteName('c.alias', 'cat_alias'),
				$db->quoteName('c.path', 'cat_path'),
				$db->quoteName('c.level', 'cat_level'),
				$db->quoteName('c.access', 'cat_access'),
				$db->quoteName('c.language', 'cat_language'),
				$db->quoteName('c.published', 'cat_published'),
				$db->quoteName('v.title', 'cat_access_title'),
				$db->quoteName('uc.name', 'created_by_name'),
				$db->quoteName('uc.username', 'created_by_username'),
				$db->quoteName('ua.name', 'assigned_to_name'),
				$db->quoteName('ua.username', 'assigned_to_username'),
			])
			->from($db->quoteName('#__ats_tickets', 't'))
			// LEFT, not INNER (ATS' own list INNER JOINs here). The extension predicate lives in the ON
			// clause: catid is a bare bigint with no foreign key, so without it a ticket would happily
			// match a com_content category of the same id. Keeping the join LEFT means a ticket whose
			// category was deleted still appears as a candidate instead of vanishing silently — and the
			// visibility gate then withholds it, because canAccessCategory() fails closed on a catid
			// that no longer resolves. Such tickets are invisible in the ATS UI for the same reason.
			->join(
				'LEFT',
				$db->quoteName('#__categories', 'c'),
				$db->quoteName('c.id') . ' = ' . $db->quoteName('t.catid')
				. ' AND ' . $db->quoteName('c.extension') . ' = ' . $db->quote('com_ats')
			)
			->join('LEFT', $db->quoteName('#__viewlevels', 'v'), $db->quoteName('v.id') . ' = ' . $db->quoteName('c.access'))
			->join('LEFT', $db->quoteName('#__users', 'uc'), $db->quoteName('uc.id') . ' = ' . $db->quoteName('t.created_by'))
			->join('LEFT', $db->quoteName('#__users', 'ua'), $db->quoteName('ua.id') . ' = ' . $db->quoteName('t.assigned_to'));

		$where = $this->buildFilters($arguments);

		foreach ($where as $clause) {
			$query->where($clause);
		}

		$orderBy  = (string) ($arguments['order_by'] ?? 'modified');
		$orderDir = strtoupper((string) ($arguments['order_dir'] ?? 'DESC'));

		if (!\in_array($orderBy, self::ORDER_COLUMNS, true)) {
			$orderBy = 'modified';
		}

		if (!\in_array($orderDir, ['ASC', 'DESC'], true)) {
			$orderDir = 'DESC';
		}

		// `modified` is NULL until someone replies, so a plain sort buries never-answered
		// tickets. Tie-break on id to keep the page stable across calls.
		$query->order($db->quoteName('t.' . $orderBy) . ' ' . $orderDir)
			->order($db->quoteName('t.id') . ' ' . $orderDir);

		$limit  = max(1, min(500, (int) ($arguments['limit'] ?? 100)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));

		$db->setQuery($query, $offset, $limit);
		$rows = $db->loadAssocList() ?: [];

		// How many rows match the filters before the visibility gate. Honest name: it is NOT
		// the number of tickets this actor may see.
		$countQuery = $db->getQuery(true)
			->select('COUNT(*)')
			->from($db->quoteName('#__ats_tickets', 't'))
			->join(
				'LEFT',
				$db->quoteName('#__categories', 'c'),
				$db->quoteName('c.id') . ' = ' . $db->quoteName('t.catid')
				. ' AND ' . $db->quoteName('c.extension') . ' = ' . $db->quote('com_ats')
			);

		foreach ($where as $clause) {
			$countQuery->where($clause);
		}

		$totalMatching = (int) $db->setQuery($countQuery)->loadResult();

		$totalUnfiltered = (int) $db->setQuery(
			$db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__ats_tickets'))
		)->loadResult();

		// ---- the mandatory per-row gate ------------------------------------------------
		$prototype = $this->atsTable('Ticket');

		if ($prototype === null) {
			return $this->notInstalledError();
		}

		$visible  = [];
		$withheld = 0;

		foreach ($rows as $row) {
			$table = clone $prototype;
			$table->reset();

			if (method_exists($table, 'bindAsLoadEquivalent')) {
				$table->bindAsLoadEquivalent($row);
			} else {
				$table->bind($row);
			}

			if (!$this->atsCanViewTicket($table, $actor)) {
				$withheld++;

				continue;
			}

			$visible[] = $this->shapeRow($row);
		}

		$visible = $this->decorateWithCounts($visible);

		return ToolResult::json([
			'ok'                     => true,
			'count'                  => \count($visible),
			'withheld_count'         => $withheld,
			'limit'                  => $limit,
			'offset'                 => $offset,
			'total_matching_filters' => $totalMatching,
			'total_unfiltered'       => $totalUnfiltered,
			'tickets'                => $visible,
			'note'                   => [
				'visibility'  => 'withheld_count is how many of the ' . \count($rows) . ' rows fetched for this page failed the component\'s own Permissions::getTicketPrivileges()[\'view\'] check. Since ATS 5.6.0 the category\'s view access level binds managers and Super Users as well, so a privileged account can legitimately be shown fewer tickets than exist.',
				'pagination'  => 'Rows are withheld after the LIMIT is applied, so count can be smaller than limit while further rows still exist. Advance with offset, never with count.',
				'totals'      => 'total_matching_filters and total_unfiltered are raw SQL counts and are NOT visibility-gated; they can exceed what you are allowed to read.',
				'modified'    => '`modified` / `last_reply` is written only when someone posts a reply, never on a plain edit. A ticket that has never been answered has it NULL.',
				'timespent'   => 'Derived. PostTable::onAfterStore() recomputes the ticket column as SUM(timespent) over that ticket\'s PUBLISHED posts, so it only refreshes when the next reply is saved.',
				'post_count'  => 'Counts every row in #__ats_posts for the ticket, including the opening message (which is a post, not a ticket column) and including unpublished posts. published_post_count excludes the unpublished ones.',
				'no_access'   => 'Tickets have no access and no language column. The values in the category block are what actually apply.',
			],
		]);
	}

	/**
	 * Build the WHERE clauses shared by the page query and the count query.
	 *
	 * Values are quoted rather than bound on purpose: the invited: search builds a
	 * correlated EXISTS sub-query that is spliced into the outer query as a string,
	 * and anything bound to a sub-query object stays on that object — leaving the
	 * outer query with placeholders the database rejects. ATS hit the same wall and
	 * documents it at TicketsModel.php:435-442.
	 *
	 * @param array<string, mixed> $arguments
	 *
	 * @return array<int, string>
	 */
	private function buildFilters(array $arguments): array
	{
		$db    = $this->db;
		$where = [];

		if (array_key_exists('catid', $arguments) && $arguments['catid'] !== null) {
			$catIds = array_values(array_filter(
				array_map('intval', (array) $arguments['catid']),
				static fn(int $x): bool => $x > 0
			));

			if (!empty($catIds)) {
				$where[] = $db->quoteName('t.catid') . ' IN (' . implode(',', $catIds) . ')';
			}
		}

		if (!empty($arguments['status'])) {
			// Quoted as a string deliberately: the column is an ENUM whose numeric members
			// are the STRINGS '1'..'99'. Comparing against an integer makes MySQL treat the
			// value as an ENUM ORDINAL, which silently matches the wrong status.
			$where[] = $db->quoteName('t.status') . ' = ' . $db->quote((string) $arguments['status']);
		}

		// assigned_to and created_by accept 0 ("unassigned" / "guest"), so array_key_exists
		// rather than !empty.
		if (array_key_exists('assigned_to', $arguments) && $arguments['assigned_to'] !== null) {
			$where[] = $db->quoteName('t.assigned_to') . ' = ' . (int) $arguments['assigned_to'];
		}

		if (array_key_exists('created_by', $arguments) && $arguments['created_by'] !== null) {
			$where[] = $db->quoteName('t.created_by') . ' = ' . (int) $arguments['created_by'];
		}

		if (array_key_exists('public', $arguments) && $arguments['public'] !== null) {
			$where[] = $db->quoteName('t.public') . ' = ' . (int) $arguments['public'];
		}

		if (array_key_exists('enabled', $arguments) && $arguments['enabled'] !== null) {
			$where[] = $db->quoteName('t.enabled') . ' = ' . (int) $arguments['enabled'];
		}

		if (array_key_exists('priority', $arguments) && $arguments['priority'] !== null) {
			$where[] = $db->quoteName('t.priority') . ' = ' . (int) $arguments['priority'];
		}

		$search = trim((string) ($arguments['search'] ?? ''));

		if ($search !== '') {
			$where[] = $this->searchClause($search);
		}

		foreach (['created_after' => '>=', 'created_before' => '<='] as $key => $operator) {
			$raw = trim((string) ($arguments[$key] ?? ''));

			if ($raw === '') {
				continue;
			}

			try {
				$date = \Joomla\CMS\Factory::getDate($raw)->toSql();
			} catch (\Throwable) {
				throw new \InvalidArgumentException($key . ' is not a date/time PHP can parse: "' . $raw . '".');
			}

			$where[] = $db->quoteName('t.created') . ' ' . $operator . ' ' . $db->quote($date);
		}

		return $where;
	}

	/** Translate one search term, honouring ATS' own id:/title:/category:/invited: prefixes. */
	private function searchClause(string $search): string
	{
		$db = $this->db;

		$like = static function (string $term) use ($db): string {
			return $db->quote('%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%');
		};

		if (stripos($search, 'id:') === 0) {
			return $db->quoteName('t.id') . ' = ' . (int) substr($search, 3);
		}

		if (stripos($search, 'category:') === 0) {
			return $db->quoteName('c.title') . ' LIKE ' . $like(substr($search, 9));
		}

		if (stripos($search, 'invited:') === 0) {
			$term = $like(substr($search, 8));

			// Correlated EXISTS against the collaborator table, the same shape ATS uses on
			// MySQL (TicketsModel.php:213-238). #__ats_tickets_users has NO index at all —
			// not even on ticket_id — so this is a full scan of that table per outer row.
			// It is small in practice (one row per invitation ever issued), but it is worth
			// knowing before pointing this filter at a very large install.
			return 'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__ats_tickets_users', 'iut')
				. ' INNER JOIN ' . $db->quoteName('#__users', 'iuj')
				. ' ON ' . $db->quoteName('iuj.id') . ' = ' . $db->quoteName('iut.user_id')
				. ' WHERE ' . $db->quoteName('iut.ticket_id') . ' = ' . $db->quoteName('t.id')
				. ' AND (' . $db->quoteName('iuj.username') . ' LIKE ' . $term
				. ' OR ' . $db->quoteName('iuj.name') . ' LIKE ' . $term
				. ' OR ' . $db->quoteName('iuj.email') . ' LIKE ' . $term . '))';
		}

		if (stripos($search, 'title:') === 0) {
			$search = substr($search, 6);
		}

		return $db->quoteName('t.title') . ' LIKE ' . $like($search);
	}

	/**
	 * Cast one raw row into the response shape.
	 *
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>
	 */
	private function shapeRow(array $row): array
	{
		$status = (string) $row['status'];

		return [
			'id'          => (int) $row['id'],
			'title'       => (string) $row['title'],
			'alias'       => (string) $row['alias'],
			'catid'       => (int) $row['catid'],
			'category'    => [
				'id'           => (int) $row['catid'],
				'title'        => $row['cat_title'] !== null ? (string) $row['cat_title'] : null,
				'alias'        => $row['cat_alias'] !== null ? (string) $row['cat_alias'] : null,
				'path'         => $row['cat_path'] !== null ? (string) $row['cat_path'] : null,
				'level'        => $row['cat_level'] !== null ? (int) $row['cat_level'] : null,
				'access'       => $row['cat_access'] !== null ? (int) $row['cat_access'] : null,
				'access_title' => $row['cat_access_title'] !== null ? (string) $row['cat_access_title'] : null,
				'language'     => $row['cat_language'] !== null ? (string) $row['cat_language'] : null,
				'published'    => $row['cat_published'] !== null ? (int) $row['cat_published'] : null,
			],
			'status'       => $status,
			'status_label' => $this->atsStatusLabel($status),
			'priority'     => (int) $row['priority'],
			'public'       => (int) $row['public'],
			'origin'       => (string) $row['origin'],
			'enabled'      => (int) $row['enabled'],
			'timespent'    => (float) $row['timespent'],
			'created'      => $row['created'],
			'created_by'   => (int) $row['created_by'],
			'created_by_name'     => $row['created_by_name'] !== null ? (string) $row['created_by_name'] : null,
			'created_by_username' => $row['created_by_username'] !== null ? (string) $row['created_by_username'] : null,
			'assigned_to'          => (int) $row['assigned_to'],
			'assigned_to_name'     => $row['assigned_to_name'] !== null ? (string) $row['assigned_to_name'] : null,
			'assigned_to_username' => $row['assigned_to_username'] !== null ? (string) $row['assigned_to_username'] : null,
			'modified'    => $row['modified'],
			'last_reply'  => $row['modified'],
			'modified_by' => (int) $row['modified_by'],
			'params'      => $this->decodeParams($row['params'] ?? null),
		];
	}

	/**
	 * Attach post_count / published_post_count / invited_count to the surviving rows
	 * with three grouped queries rather than three queries per row.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function decorateWithCounts(array $rows): array
	{
		if (empty($rows)) {
			return $rows;
		}

		$db  = $this->db;
		$ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
		$in  = implode(',', $ids);

		$posts = $db->setQuery(
			$db->getQuery(true)
				->select([
					$db->quoteName('ticket_id'),
					'COUNT(*) AS ' . $db->quoteName('total'),
					'SUM(CASE WHEN ' . $db->quoteName('enabled') . ' = 1 THEN 1 ELSE 0 END) AS ' . $db->quoteName('published'),
				])
				->from($db->quoteName('#__ats_posts'))
				->where($db->quoteName('ticket_id') . ' IN (' . $in . ')')
				->group($db->quoteName('ticket_id'))
		)->loadAssocList('ticket_id') ?: [];

		$invited = $db->setQuery(
			$db->getQuery(true)
				->select([$db->quoteName('ticket_id'), 'COUNT(*) AS ' . $db->quoteName('total')])
				->from($db->quoteName('#__ats_tickets_users'))
				->where($db->quoteName('ticket_id') . ' IN (' . $in . ')')
				->group($db->quoteName('ticket_id'))
		)->loadAssocList('ticket_id') ?: [];

		foreach ($rows as &$row) {
			$id = (int) $row['id'];

			$row['post_count']           = (int) ($posts[$id]['total'] ?? 0);
			$row['published_post_count'] = (int) ($posts[$id]['published'] ?? 0);
			$row['invited_count']        = (int) ($invited[$id]['total'] ?? 0);
		}

		unset($row);

		return $rows;
	}

	/** Ticket params are a TEXT column holding JSON, and are frequently NULL or ''. */
	private function decodeParams(?string $params): array
	{
		if ($params === null || trim($params) === '') {
			return [];
		}

		$decoded = json_decode($params, true);

		return \is_array($decoded) ? $decoded : [];
	}
}
