<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Reports;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;

/**
 * The triage tool: what has gone quiet, and on whose side of the fence.
 *
 * WHY THIS DOES NOT USE #__ats_tickets.modified
 * ---------------------------------------------
 * `modified` looks like the obvious "last activity" column and is the wrong
 * one. It is written in exactly one place — PostTable::onAfterStore(), when a
 * NEW post lands on a ticket whose status is not already 'C' (PostTable.php:
 * 290-301). Three consequences, each of which would silently corrupt a stale
 * list built on it:
 *
 *   - Editing a ticket does not update it. TicketTable::onBeforeStore() and
 *     TicketModel::prepareTable() both leave it alone deliberately, with the
 *     assignment commented out, so that an edit is never mistaken for a reply.
 *   - Posting to a CLOSED ticket does not update it either, because the whole
 *     onAfterStore() block is skipped when the status is 'C'. A closed ticket
 *     that someone is still writing into looks frozen at the moment it closed.
 *   - Unpublishing the newest post does not roll it back. The column keeps the
 *     timestamp of a reply nobody can read any more.
 *
 * So "last activity" here is MAX(created) over that ticket's ENABLED posts,
 * which is the thing a human means. Both values are returned side by side, and
 * where they disagree the row says so — the disagreement is usually the most
 * informative field in the response.
 *
 * WHY 'O' AND 'P' ARE REPORTED SEPARATELY
 * ---------------------------------------
 * They are opposite problems. 'O' means the ball is with support: a stale Open
 * ticket is an SLA breach. 'P' means the ball is with the customer: a stale
 * Pending ticket is a candidate for a chase-up or a close, not a failure. Only
 * a manager's reply can set 'P' — a reply from the ticket owner, an invited
 * collaborator or any other non-manager sets 'O' — so the status genuinely does
 * encode whose turn it is.
 *
 * Every row is gated through Permissions::getTicketPrivileges()['view'] before
 * it is returned, and the count of rows withheld is reported so the numbers
 * stay honest.
 *
 * THE GATE BINDS, IT DOES NOT LOAD
 * --------------------------------
 * TicketTable::load() is not read-only on a Joomla 5 host below 5.5:
 * onAfterLoad() calls ensureUcmRecord(), which INSERTs a #__ats_tickets row's
 * missing #__ucm_content record (TicketTable.php:710-719 and 1008-1039) and
 * only self-disables above Joomla 5.4. Gating a page of a hundred stale tickets
 * with load() would therefore write up to a hundred rows on every call to a
 * report. So the row is SELECTed and handed to the vendor's own
 * bindAsLoadEquivalent(), which sets the table up exactly as load() would minus
 * the UCM write. The TicketTable object is still required, because
 * getTicketPrivileges() is type-hinted to take one.
 */
final class ListStaleTicketsTool extends AbstractTool
{
	use ATSBootTrait;

	private const DEFAULT_DAYS = 7;

	private const DEFAULT_LIMIT = 100;

	private const MAX_LIMIT = 500;

	/** How many candidate rows to pull before gating, so `limit` can still be filled. */
	private const SCAN_MULTIPLIER = 4;

	private const MAX_SCAN = 2000;

	public function getName(): string { return 'list_ats_stale_tickets'; }

	public function getDescription(): string
	{
		return 'Triage list: Akeeba Ticket System tickets with no activity for N days, split by whose turn '
			. 'it is. Open (status O) means the ball is with SUPPORT, so a stale Open ticket is an unanswered '
			. 'customer. Pending (status P) means the ball is with the CUSTOMER, so a stale Pending ticket is '
			. 'a chase-up or a close, not a failure. Only a manager\'s reply sets P; a reply from the ticket '
			. 'owner, an invited collaborator or any other non-manager sets O, so the status really does '
			. 'encode whose turn it is. '
			. 'LAST ACTIVITY IS COMPUTED FROM THE POSTS, NOT FROM #__ats_tickets.modified. `modified` means '
			. '"last reply" and is written only by PostTable::onAfterStore(), only for a NEW post, and only '
			. 'when the ticket is not already Closed — so editing a ticket never updates it, posting to a '
			. 'closed ticket never updates it, and unpublishing the newest post never rolls it back. This '
			. 'tool uses MAX(created) over the ticket\'s ENABLED posts instead and returns both values, '
			. 'flagging rows where they disagree (modified_disagrees_with_posts). '
			. 'RETURNS per ticket: id, title, alias, status and its label, category id / title / access / '
			. 'language (a ticket has no access or language column of its own — both are inherited from the '
			. 'category), public flag, priority, assigned_to with the assignee\'s name, created and '
			. 'created_by with the opener\'s name, modified, last_post_at, last_post_by with name, '
			. 'last_post_was_system (created_by <= 0 is ATS\' synthetic "system" user, an automated notice '
			. 'rather than a person), days_since_last_activity, post_count and timespent. '
			. 'FILTERS: days (default ' . self::DEFAULT_DAYS . ' — the threshold in whole days of silence), '
			. 'status ("O", "P" or "both", default "both"), category_id, assigned_to (pass 0 for unassigned), '
			. 'unassigned_only, include_unpublished (default false; #__ats_tickets.enabled is the publish '
			. 'flag). Ordered oldest-activity-first by default. '
			. 'VISIBILITY: every candidate row is run through ATS\' own per-ticket privilege check before it '
			. 'is returned, even though the SQL already filtered — ATS 5.6.0 shipped precisely because a '
			. 'list query and a single-ticket check disagreed and leaked private tickets. `withheld` reports '
			. 'how many rows the gate removed, so the count is honest rather than quietly short. '
			. 'Default limit ' . self::DEFAULT_LIMIT . ', max ' . self::MAX_LIMIT . '. Tickets with zero '
			. 'enabled posts have no computable last activity; they are excluded by default and can be '
			. 'included with include_postless (they are broken tickets — a ticket\'s opening message IS a '
			. 'post — and check_ats_health counts them).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'days' => [
					'type' => 'integer',
					'minimum' => 0,
					'description' => 'Threshold in whole days of silence. Default ' . self::DEFAULT_DAYS
						. '. A ticket qualifies when its newest enabled post is older than this.',
				],
				'status' => [
					'type' => 'string',
					'enum' => ['O', 'P', 'both'],
					'description' => 'O = Open (waiting on support), P = Pending (waiting on the customer), '
						. 'both = either. Default "both". Closed tickets are never included; neither are '
						. 'site-defined statuses "1".."99", because ATS assigns them no meaning and this tool '
						. 'will not invent one.',
				],
				'category_id' => [
					'type' => 'integer',
					'description' => 'Restrict to one ticket category (#__categories.id with extension = '
						. '"com_ats").',
				],
				'assigned_to' => [
					'type' => 'integer',
					'minimum' => 0,
					'description' => 'Restrict to one assignee. Pass 0 for unassigned — assigned_to is NOT '
						. 'NULL DEFAULT 0, so 0 is the real "nobody" value.',
				],
				'unassigned_only' => [
					'type' => 'boolean',
					'description' => 'Shorthand for assigned_to = 0. Default false.',
				],
				'include_unpublished' => [
					'type' => 'boolean',
					'description' => 'Include tickets with enabled = 0. Default false.',
				],
				'include_postless' => [
					'type' => 'boolean',
					'description' => 'Include tickets with no enabled posts, whose last activity cannot be '
						. 'computed. Default false. These are broken tickets, not quiet ones.',
				],
				'order_by' => [
					'type' => 'string',
					'enum' => ['last_activity', 'created', 'id'],
					'description' => 'Default last_activity.',
				],
				'order_dir' => [
					'type' => 'string',
					'enum' => ['ASC', 'DESC'],
					'description' => 'Default ASC, i.e. the most neglected first.',
				],
				'limit'  => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => self::MAX_LIMIT,
					'description' => 'Default ' . self::DEFAULT_LIMIT . ', max ' . self::MAX_LIMIT . '.',
				],
				'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Default 0.'],
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

		$days = isset($arguments['days']) ? max(0, (int) $arguments['days']) : self::DEFAULT_DAYS;

		$status = (string) ($arguments['status'] ?? 'both');

		if (!\in_array($status, ['O', 'P', 'both'], true)) {
			return ToolResult::error('status must be one of: O, P, both.');
		}

		$limit  = isset($arguments['limit'])
			? max(1, min(self::MAX_LIMIT, (int) $arguments['limit']))
			: self::DEFAULT_LIMIT;
		$offset = isset($arguments['offset']) ? max(0, (int) $arguments['offset']) : 0;

		$orderBy = (string) ($arguments['order_by'] ?? 'last_activity');
		$orderBy = \in_array($orderBy, ['last_activity', 'created', 'id'], true) ? $orderBy : 'last_activity';

		$orderDir = strtoupper((string) ($arguments['order_dir'] ?? 'ASC'));
		$orderDir = \in_array($orderDir, ['ASC', 'DESC'], true) ? $orderDir : 'ASC';

		$inclUnpub    = (bool) ($arguments['include_unpublished'] ?? false);
		$inclPostless = (bool) ($arguments['include_postless'] ?? false);

		$assignedTo = null;

		if (!empty($arguments['unassigned_only'])) {
			$assignedTo = 0;
		} elseif (isset($arguments['assigned_to'])) {
			$assignedTo = max(0, (int) $arguments['assigned_to']);
		}

		$categoryId = isset($arguments['category_id']) ? (int) $arguments['category_id'] : null;

		$prefix  = $this->db->getPrefix();
		$tickets = $this->db->quoteName($prefix . 'ats_tickets', 't');
		$posts   = $this->db->quoteName($prefix . 'ats_posts');
		$cutoff  = gmdate('Y-m-d H:i:s', time() - ($days * 86400));

		// The derived table is the point of the whole query: last activity is the
		// newest ENABLED post, which is not a column anywhere.
		$activity = '(SELECT ' . $this->db->quoteName('ticket_id')
			. ', MAX(' . $this->db->quoteName('created') . ') AS ' . $this->db->quoteName('last_post_at')
			. ', COUNT(*) AS ' . $this->db->quoteName('post_count')
			. ' FROM ' . $posts
			. ' WHERE ' . $this->db->quoteName('enabled') . ' = 1'
			. ' GROUP BY ' . $this->db->quoteName('ticket_id') . ') AS ' . $this->db->quoteName('p');

		$where = [];

		if (!$inclUnpub) {
			$where[] = $this->db->quoteName('t.enabled') . ' = 1';
		}

		$where[] = $status === 'both'
			? $this->db->quoteName('t.status') . ' IN (' . $this->db->quote('O') . ', ' . $this->db->quote('P') . ')'
			: $this->db->quoteName('t.status') . ' = ' . $this->db->quote($status);

		if ($categoryId !== null) {
			$where[] = $this->db->quoteName('t.catid') . ' = ' . (int) $categoryId;
		}

		if ($assignedTo !== null) {
			$where[] = $this->db->quoteName('t.assigned_to') . ' = ' . (int) $assignedTo;
		}

		$staleTest = $this->db->quoteName('p.last_post_at') . ' < ' . $this->db->quote($cutoff);

		if ($inclPostless) {
			$staleTest = '(' . $staleTest . ' OR ' . $this->db->quoteName('p.last_post_at') . ' IS NULL)';
		} else {
			$where[] = $this->db->quoteName('p.last_post_at') . ' IS NOT NULL';
		}

		$where[] = $staleTest;

		$orderColumn = match ($orderBy) {
			'created' => $this->db->quoteName('t.created'),
			'id'      => $this->db->quoteName('t.id'),
			default   => $this->db->quoteName('p.last_post_at'),
		};

		$select = 'SELECT ' . implode(', ', [
			$this->db->quoteName('t.id'),
			$this->db->quoteName('t.catid'),
			$this->db->quoteName('t.title'),
			$this->db->quoteName('t.alias'),
			$this->db->quoteName('t.status'),
			$this->db->quoteName('t.public'),
			$this->db->quoteName('t.priority'),
			$this->db->quoteName('t.origin'),
			$this->db->quoteName('t.assigned_to'),
			$this->db->quoteName('t.timespent'),
			$this->db->quoteName('t.created'),
			$this->db->quoteName('t.created_by'),
			$this->db->quoteName('t.modified'),
			$this->db->quoteName('t.modified_by'),
			$this->db->quoteName('t.enabled'),
			$this->db->quoteName('p.last_post_at'),
			$this->db->quoteName('p.post_count'),
		]);

		$from = ' FROM ' . $tickets
			. ' LEFT JOIN ' . $activity
			. ' ON ' . $this->db->quoteName('p.ticket_id') . ' = ' . $this->db->quoteName('t.id');

		$clause = ' WHERE ' . implode(' AND ', $where);

		$matched = (int) $this->db->setQuery('SELECT COUNT(*)' . $from . $clause)->loadResult();

		// Over-fetch so the gate removing rows does not leave the page short.
		$scan = min(self::MAX_SCAN, ($offset + $limit) * self::SCAN_MULTIPLIER);

		$rows = $this->db->setQuery(
			$select . $from . $clause
			. ' ORDER BY ' . $orderColumn . ' ' . $orderDir . ', ' . $this->db->quoteName('t.id') . ' ASC',
			0,
			$scan
		)->loadAssocList() ?: [];

		// The vendor's gate, applied per row. BIND, NEVER load(): TicketTable::
		// load() is not read-only below Joomla 5.5 — onAfterLoad() calls
		// ensureUcmRecord(), which INSERTs a #__ucm_content row when one is
		// missing (TicketTable.php:710-719 and 1008-1039) and only self-disables
		// above 5.4. A triage list must not write rows as a side effect of being
		// read. Cloning a fresh prototype per row also keeps valuesOnLoad empty,
		// so bindAsLoadEquivalent() populates it from this row and not the last.
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

			$visible[] = $row;
		}

		$page = \array_slice($visible, $offset, $limit);
		$page = $this->decorate($page, $prefix);

		$response = [
			'ok'     => true,
			'count'  => \count($page),
			'limit'  => $limit,
			'offset' => $offset,
			'total_unfiltered' => $matched,
			'filters' => [
				'days'      => $days,
				'cutoff_utc' => $cutoff,
				'status'    => $status,
				'category_id' => $categoryId,
				'assigned_to' => $assignedTo,
				'include_unpublished' => $inclUnpub,
				'include_postless'    => $inclPostless,
				'order_by'  => $orderBy,
				'order_dir' => $orderDir,
			],
			'visibility' => [
				'scanned'  => \count($rows),
				'visible'  => \count($visible),
				'withheld' => $withheld,
				'note'     => $withheld === 0
					? 'No rows were removed by the per-ticket privilege gate.'
					: $withheld . ' ticket(s) matched the SQL filter but were withheld because '
						. 'Permissions::getTicketPrivileges() says this user may not view them. total_unfiltered '
						. 'counts them; the rows below do not.',
				'scan_cap' => $scan,
				'scan_note' => 'The SQL pulled up to ' . $scan . ' candidate rows before gating so that a '
					. 'full page could still be filled. If scanned equals scan_cap, there may be further '
					. 'stale tickets beyond it — narrow the filter or page through.',
			],
			'tickets' => $page,
		];

		$response['note'] = 'days_since_last_activity is measured from last_post_at (the newest ENABLED '
			. 'post), NOT from #__ats_tickets.modified. The two differ whenever a ticket was edited without '
			. 'a reply, replied to after it was closed, or had its newest post unpublished — rows where '
			. 'they disagree carry modified_disagrees_with_posts. timespent is derived: '
			. 'PostTable::onAfterStore() recomputes it as SUM over the ticket\'s enabled posts on each new '
			. 'reply to a non-closed ticket.';

		return ToolResult::json($response);
	}

	/**
	 * Add category, user and derived fields to the page of rows.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function decorate(array $rows, string $prefix): array
	{
		if ($rows === []) {
			return [];
		}

		$catIds  = [];
		$userIds = [];
		$ids     = [];

		foreach ($rows as $row) {
			$ids[]    = (int) $row['id'];
			$catIds[] = (int) $row['catid'];

			foreach (['assigned_to', 'created_by', 'modified_by'] as $key) {
				if ((int) $row[$key] > 0) {
					$userIds[] = (int) $row[$key];
				}
			}
		}

		// The author of each ticket's newest enabled post, which the aggregate
		// above cannot carry (MAX() picks the date, not the row).
		$lastAuthors = [];

		foreach ($this->db->setQuery(
			'SELECT ' . $this->db->quoteName('a.ticket_id') . ', ' . $this->db->quoteName('a.created_by')
			. ', ' . $this->db->quoteName('a.created') . ', ' . $this->db->quoteName('a.origin')
			. ' FROM ' . $this->db->quoteName($prefix . 'ats_posts', 'a')
			. ' INNER JOIN (SELECT ' . $this->db->quoteName('ticket_id')
			. ', MAX(' . $this->db->quoteName('id') . ') AS ' . $this->db->quoteName('mid')
			. ' FROM ' . $this->db->quoteName($prefix . 'ats_posts')
			. ' WHERE ' . $this->db->quoteName('enabled') . ' = 1'
			. ' AND ' . $this->db->quoteName('ticket_id') . ' IN (' . implode(', ', $ids) . ')'
			. ' GROUP BY ' . $this->db->quoteName('ticket_id') . ') AS ' . $this->db->quoteName('m')
			. ' ON ' . $this->db->quoteName('a.id') . ' = ' . $this->db->quoteName('m.mid')
		)->loadAssocList() ?: [] as $row) {
			$lastAuthors[(int) $row['ticket_id']] = $row;

			if ((int) $row['created_by'] > 0) {
				$userIds[] = (int) $row['created_by'];
			}
		}

		$categories = [];

		foreach ($this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName(['id', 'title', 'access', 'language', 'published', 'extension']))
				->from($this->db->quoteName('#__categories'))
				->whereIn($this->db->quoteName('id'), array_values(array_unique($catIds)))
		)->loadAssocList() ?: [] as $row) {
			$categories[(int) $row['id']] = $row;
		}

		$users = [];

		if ($userIds !== []) {
			foreach ($this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName(['id', 'name', 'username']))
					->from($this->db->quoteName('#__users'))
					->whereIn($this->db->quoteName('id'), array_values(array_unique($userIds)))
			)->loadAssocList() ?: [] as $row) {
				$users[(int) $row['id']] = $row;
			}
		}

		$now = time();
		$out = [];

		foreach ($rows as $row) {
			$catid    = (int) $row['catid'];
			$category = $categories[$catid] ?? null;
			$isAts    = $category !== null && (string) $category['extension'] === 'com_ats';

			$lastPostAt = $this->toTimestamp($row['last_post_at'] ?? null);
			$modifiedAt = $this->toTimestamp($row['modified'] ?? null);
			$last       = $lastAuthors[(int) $row['id']] ?? null;
			$lastBy     = $last === null ? null : (int) $last['created_by'];

			$entry = [
				'id'     => (int) $row['id'],
				'title'  => (string) $row['title'],
				'alias'  => (string) $row['alias'],
				'status' => (string) $row['status'],
				'status_label' => $this->atsStatusLabel((string) $row['status']),
				'waiting_on'   => (string) $row['status'] === 'O' ? 'support' : 'customer',
				'public'   => (int) $row['public'] === 1,
				'priority' => (int) $row['priority'],
				'origin'   => (string) $row['origin'],
				'enabled'  => (int) $row['enabled'] === 1,
				'category' => [
					'id'        => $catid,
					'title'     => $category['title'] ?? null,
					'access'    => $category === null ? null : (int) $category['access'],
					'language'  => $category['language'] ?? null,
					'published' => $category === null ? null : (int) $category['published'],
					'is_ats_category' => $isAts,
				],
				'assigned_to' => (int) $row['assigned_to'],
				'assigned_to_name' => (int) $row['assigned_to'] > 0
					? ($users[(int) $row['assigned_to']]['name'] ?? null)
					: null,
				'created'    => $row['created'],
				'created_by' => (int) $row['created_by'],
				'created_by_name' => (int) $row['created_by'] > 0
					? ($users[(int) $row['created_by']]['name'] ?? null)
					: null,
				'modified'    => $row['modified'],
				'modified_by' => (int) $row['modified_by'],
				'last_post_at' => $row['last_post_at'],
				'last_post_by' => $lastBy,
				'last_post_by_name' => $lastBy !== null && $lastBy > 0
					? ($users[$lastBy]['name'] ?? null)
					: null,
				'last_post_was_system' => $lastBy !== null && $lastBy <= 0,
				'last_post_origin'     => $last['origin'] ?? null,
				'post_count' => (int) ($row['post_count'] ?? 0),
				'timespent'  => (float) $row['timespent'],
				'days_since_last_activity' => $lastPostAt === null
					? null
					: (int) floor(($now - $lastPostAt) / 86400),
			];

			if ($lastPostAt === null) {
				$entry['no_posts_note'] = 'This ticket has no enabled posts at all. A ticket\'s opening '
					. 'message IS a post, so this is a broken ticket or one whose opening post was '
					. 'unpublished — not a quiet one. Its last activity cannot be computed.';
			}

			if ($lastPostAt !== null && $modifiedAt !== null && abs($modifiedAt - $lastPostAt) > 60) {
				$entry['modified_disagrees_with_posts'] = 'modified is '
					. ($modifiedAt > $lastPostAt ? 'NEWER' : 'OLDER')
					. ' than the newest enabled post. That happens when the newest post was unpublished '
					. '(modified keeps the old timestamp), or when someone posted to this ticket after it '
					. 'was closed (PostTable::onAfterStore() skips the whole update block on a closed '
					. 'ticket). Trust last_post_at.';
			}

			if ($lastBy !== null && $lastBy <= 0) {
				$entry['system_post_note'] = 'The newest post was written by ATS itself (created_by <= 0 — '
					. 'Permissions::getUser(-1) fabricates a user with username "system"). An automated '
					. 'notice does not change the ticket status and does not mean a human has looked at '
					. 'this ticket.';
			}

			$out[] = $entry;
		}

		return $out;
	}

	/** Nullable datetime column to a unix timestamp, treating the column as UTC. */
	private function toTimestamp(mixed $value): ?int
	{
		$value = trim((string) ($value ?? ''));

		if ($value === '' || str_starts_with($value, '0000-00-00')) {
			return null;
		}

		$ts = strtotime($value . ' UTC');

		return $ts === false ? null : $ts;
	}
}
