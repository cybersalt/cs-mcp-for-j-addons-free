<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Reports;

\defined('_JEXEC') or die;

use Akeeba\Component\ATS\Administrator\Helper\Permissions;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;

/**
 * Who is carrying what, including the people carrying nothing.
 *
 * THE ROSTER COMES FROM ACL, NOT FROM THE TICKETS.
 * ------------------------------------------------
 * GROUP BY assigned_to answers "who holds tickets" and quietly omits the two
 * people you most want to see: the agent with an empty queue and the agent who
 * has just joined. ATS has no staff table and no staff flag on #__users — being
 * an assignee is an ACL state, `core.admin` OR `core.manage` OR `ats.assignee`
 * on `com_ats` or on `com_ats.category.<id>` (Permissions::canBeAssignedTickets(),
 * Permissions.php:1266-1277). So the roster is taken from
 * Permissions::getAssignees(), which does exactly that check, and the ticket
 * counts are merged onto it. Anyone holding tickets who is NOT on the roster is
 * reported too, under stale_assignees — that is a real and common state, since
 * removing somebody's ats.assignee privilege or blocking their account does not
 * reassign their queue.
 *
 * ONE CACHE CAVEAT. getAssignees() memoises per category in a FUNCTION static
 * that Permissions::setCacheIdentities(false) does not clear (see ATSBootTrait,
 * point 3). Within one long-lived MCP process the roster can therefore go stale
 * after an ACL change. It is re-read on every call, which is the most this tool
 * can do about it, and the payload says so.
 *
 * TIMESPENT IS DERIVED AND CAN LIE ABOUT THE PAST.
 * ------------------------------------------------
 * #__ats_tickets.timespent is not written by whoever spent the time. It is
 * recomputed wholesale by PostTable::onAfterStore() as SUM(timespent) over that
 * ticket's posts WHERE enabled = 1, on each new post to a non-closed ticket.
 * The total here is SUM over the tickets currently ASSIGNED to each person, so
 * it is "time logged against this person's queue", not "time this person
 * logged" — a reassignment moves the whole history with the ticket.
 *
 * VISIBILITY. The counts are site-wide aggregates and say so. The one place
 * this tool exposes ticket CONTENT — the oldest unanswered ticket per agent —
 * is gated through Permissions::getTicketPrivileges()['view'] and withheld
 * rather than shown when the caller may not read it.
 */
final class GetAgentWorkloadTool extends AbstractTool
{
	use ATSBootTrait;

	/** Cap on the per-agent "oldest unanswered" lookups, which are one query each. */
	private const MAX_AGENT_PROBES = 100;

	public function getName(): string { return 'get_ats_agent_workload'; }

	public function getDescription(): string
	{
		return 'Per-agent workload across Akeeba Ticket System: for every person who can be assigned '
			. 'tickets, how many Open and Pending tickets they hold, how many Closed and site-defined-status '
			. 'tickets, the total timespent logged against their queue, and their oldest unanswered ticket. '
			. 'THE ROSTER IS THE POINT. It comes from ATS\' own Permissions::getAssignees(), which resolves '
			. 'who holds core.admin, core.manage or ats.assignee on com_ats (or on one category\'s asset when '
			. 'category_id is passed) — so agents with an EMPTY QUEUE still appear, which a GROUP BY over '
			. 'assigned_to could never show. People who hold tickets but are no longer on the roster are '
			. 'listed separately under stale_assignees: revoking someone\'s privileges or blocking their '
			. 'Joomla account does not reassign their tickets, and ATS never notices. An "unassigned" bucket '
			. 'is included because assigned_to is NOT NULL DEFAULT 0, so 0 is a real value and not a null. '
			. 'SCOPE: THE COUNTS ARE SITE-WIDE. They are SQL aggregates over #__ats_tickets and include '
			. 'private tickets in categories the calling user cannot read — that is the right answer for a '
			. 'workload report. The only field that exposes ticket content, oldest_open_ticket, IS gated '
			. 'through ATS\' per-ticket privilege check and is withheld (age still reported, id and title '
			. 'not) when the caller may not view it. '
			. 'OLDEST UNANSWERED is the Open ticket assigned to that person whose newest ENABLED post is the '
			. 'oldest. It is computed from the posts, not from #__ats_tickets.modified, because modified '
			. 'means "last reply" and is not written on an edit, is not written when someone posts to a '
			. 'closed ticket, and is not rolled back when a post is unpublished. '
			. 'TIMESPENT is "time logged against this person\'s current queue", not "time this person '
			. 'logged". #__ats_tickets.timespent is derived — PostTable::onAfterStore() recomputes it as '
			. 'SUM over the ticket\'s enabled posts on each new reply to a non-closed ticket — so '
			. 'reassigning a ticket moves its entire logged history to the new assignee. If the '
			. 'timespent_hide component parameter is 1 the feature is switched off in the UI but the stored '
			. 'data is NOT deleted, and the payload flags that. '
			. 'Filters: category_id (also narrows the roster to that category\'s assignees), '
			. 'include_unpublished (default false), include_zero (default true — set false to hide agents '
			. 'with no tickets at all).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'category_id' => [
					'type' => 'integer',
					'description' => 'Restrict counts to one ticket category AND resolve the roster against '
						. 'that category\'s asset (com_ats.category.<id>) rather than the component. Omit for '
						. 'the whole site.',
				],
				'include_unpublished' => [
					'type' => 'boolean',
					'description' => 'Count tickets with enabled = 0. Default false. `enabled` is the publish '
						. 'flag; TicketTable aliases it as `published`.',
				],
				'include_zero' => [
					'type' => 'boolean',
					'description' => 'Include roster members holding no tickets at all. Default TRUE — they '
						. 'are usually the reason to run this report.',
				],
				'include_closed' => [
					'type' => 'boolean',
					'description' => 'Include closed-ticket counts per agent. Default true. Closed tickets '
						. 'are never counted in the open/pending load figures.',
				],
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

		$categoryId = isset($arguments['category_id']) ? (int) $arguments['category_id'] : null;
		$inclUnpub  = (bool) ($arguments['include_unpublished'] ?? false);
		$inclZero   = (bool) ($arguments['include_zero'] ?? true);
		$inclClosed = (bool) ($arguments['include_closed'] ?? true);

		$prefix  = $this->db->getPrefix();
		$tickets = $this->db->quoteName($prefix . 'ats_tickets', 't');

		$where = [];

		if (!$inclUnpub) {
			$where[] = $this->db->quoteName('t.enabled') . ' = 1';
		}

		if ($categoryId !== null) {
			$where[] = $this->db->quoteName('t.catid') . ' = ' . (int) $categoryId;
		}

		$clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		// ---- aggregates -------------------------------------------------------
		$tally = [];

		foreach ($this->db->setQuery(
			'SELECT ' . $this->db->quoteName('t.assigned_to') . ' AS uid, '
			. $this->db->quoteName('t.status') . ' AS s, COUNT(*) AS c, '
			. 'SUM(' . $this->db->quoteName('t.timespent') . ') AS ts'
			. ' FROM ' . $tickets . $clause
			. ' GROUP BY ' . $this->db->quoteName('t.assigned_to') . ', ' . $this->db->quoteName('t.status')
		)->loadAssocList() ?: [] as $row) {
			$uid = (int) $row['uid'];

			$tally[$uid]['by_status'][(string) $row['s']] = (int) $row['c'];
			$tally[$uid]['timespent'] = ($tally[$uid]['timespent'] ?? 0.0) + (float) $row['ts'];
		}

		// ---- roster -----------------------------------------------------------
		$roster      = [];
		$rosterError = null;

		try {
			foreach (Permissions::getAssignees($categoryId) as $id => $def) {
				$roster[(int) $id] = [
					'name'     => (string) ($def->name ?? ''),
					'username' => (string) ($def->username ?? ''),
				];
			}
		} catch (\Throwable $e) {
			$rosterError = 'Permissions::getAssignees() failed (' . $e->getMessage() . '), so the roster '
				. 'could not be resolved and only people who currently hold tickets are listed. Agents with '
				. 'an empty queue are therefore missing from this response.';
		}

		// array_merge, not "+": the union operator keys on position and would drop
		// ids from the shorter list.
		$blocked = $this->blockedFlags(array_merge(array_keys($roster), array_keys($tally)));

		// ---- oldest unanswered, per agent ------------------------------------
		$candidates = array_values(array_unique(array_merge(array_keys($roster), array_keys($tally))));
		$probeIds   = [];

		foreach ($candidates as $uid) {
			if ((int) ($tally[$uid]['by_status']['O'] ?? 0) > 0) {
				$probeIds[] = (int) $uid;
			}
		}

		$probeCapped = \count($probeIds) > self::MAX_AGENT_PROBES;
		$probeIds    = \array_slice($probeIds, 0, self::MAX_AGENT_PROBES);
		$oldest      = [];

		foreach ($probeIds as $uid) {
			$oldest[$uid] = $this->oldestOpen($prefix, $uid, $categoryId, $inclUnpub, $actor);
		}

		// ---- assemble ---------------------------------------------------------
		$agents = [];
		$stale  = [];

		foreach ($candidates as $uid) {
			$uid      = (int) $uid;
			$onRoster = isset($roster[$uid]);
			$counts   = $tally[$uid]['by_status'] ?? [];
			$open     = (int) ($counts['O'] ?? 0);
			$pending  = (int) ($counts['P'] ?? 0);
			$closed   = (int) ($counts['C'] ?? 0);

			$siteDefined = 0;

			foreach ($counts as $code => $n) {
				if (!\in_array((string) $code, ['O', 'P', 'C'], true)) {
					$siteDefined += (int) $n;
				}
			}

			if ($uid === 0) {
				// The unassigned bucket is not a person and is reported apart.
				continue;
			}

			$total = $open + $pending + $closed + $siteDefined;

			if (!$inclZero && $total === 0) {
				continue;
			}

			$entry = [
				'user_id'  => $uid,
				'name'     => $roster[$uid]['name'] ?? null,
				'username' => $roster[$uid]['username'] ?? null,
				'on_assignee_roster' => $onRoster,
				'blocked'  => $blocked[$uid] ?? null,
				'open'     => $open,
				'pending'  => $pending,
				'site_defined_status' => $siteDefined,
				'active_load' => $open + $pending,
				'total_assigned' => $total,
				'timespent'   => round((float) ($tally[$uid]['timespent'] ?? 0.0), 3),
				'oldest_open_ticket' => $oldest[$uid] ?? null,
			];

			if ($inclClosed) {
				$entry['closed'] = $closed;
			}

			if (!$onRoster) {
				$entry['stale_note'] = 'This user holds tickets but is not on the assignee roster for this '
					. 'scope — they no longer have core.admin, core.manage or ats.assignee here, or their '
					. 'Joomla account is gone. ATS does not reassign a departing agent\'s queue, so these '
					. 'tickets are effectively orphaned even though assigned_to is non-zero.';
				$stale[] = $uid;
			}

			$agents[] = $entry;
		}

		usort($agents, static fn (array $a, array $b): int => $b['active_load'] <=> $a['active_load']);

		$unassignedCounts = $tally[0]['by_status'] ?? [];
		$unassignedOther  = 0;

		foreach ($unassignedCounts as $code => $n) {
			if (!\in_array((string) $code, ['O', 'P', 'C'], true)) {
				$unassignedOther += (int) $n;
			}
		}

		$params        = $this->atsParams();
		$timespentHide = (int) $params->get('timespent_hide', 0) === 1;

		$response = [
			'ok' => true,
			'scope' => [
				'category_id' => $categoryId,
				'asset'       => $categoryId === null ? 'com_ats' : 'com_ats.category.' . $categoryId,
				'include_unpublished' => $inclUnpub,
				'counts_are'  => 'SITE-WIDE. These are SQL aggregates over #__ats_tickets and include '
					. 'private tickets in categories the calling user cannot read. They are NOT filtered to '
					. 'what the caller may see. The only per-ticket content exposed — oldest_open_ticket — '
					. 'is gated individually and withheld when it should be.',
			],
			'roster' => [
				'source' => 'Permissions::getAssignees(' . ($categoryId === null ? 'null' : $categoryId)
					. '), i.e. everyone holding core.admin, core.manage or ats.assignee on the asset above. '
					. 'This is why agents with zero tickets appear.',
				'size'   => \count($roster),
				'cache_caveat' => 'getAssignees() memoises its answer in a function static that '
					. 'Permissions::setCacheIdentities(false) does not clear, so inside one long-lived MCP '
					. 'process this roster can lag an ACL change made during that process\' lifetime.',
				'error'  => $rosterError,
			],
			'agents' => $agents,
			'unassigned' => [
				'open'    => (int) ($unassignedCounts['O'] ?? 0),
				'pending' => (int) ($unassignedCounts['P'] ?? 0),
				'closed'  => (int) ($unassignedCounts['C'] ?? 0),
				'site_defined_status' => $unassignedOther,
				'note' => '#__ats_tickets.assigned_to is NOT NULL DEFAULT 0, so 0 means nobody — it is a '
					. 'real value, not a null, and any query looking for unassigned tickets must test for 0 '
					. 'rather than IS NULL. Note that ATS auto-assigns: PostTable::onAfterStore() sets '
					. 'assigned_to to the poster when an UNASSIGNED ticket gets a reply from a manager in '
					. 'that category, so this bucket drains itself as staff answer tickets.',
			],
			'stale_assignees' => [
				'user_ids' => $stale,
				'count'    => \count($stale),
				'note'     => $stale === []
					? 'Everyone holding tickets is on the assignee roster.'
					: 'These users hold tickets but can no longer be assigned them. Their queues will not '
						. 'move on their own.',
			],
			'timespent' => [
				'meaning' => 'SUM of #__ats_tickets.timespent over the tickets CURRENTLY assigned to each '
					. 'person. That column is derived, not authored: PostTable::onAfterStore() recomputes it '
					. 'as SUM(timespent) over the ticket\'s posts WHERE enabled = 1, on every new post to a '
					. 'non-closed ticket. So this is "time logged against this queue", and reassigning a '
					. 'ticket moves its whole history to the new owner.',
				'feature_disabled' => $timespentHide,
				'feature_note' => $timespentHide
					? 'The timespent_hide component parameter is 1: the Time Spent feature is switched OFF '
						. 'in the interface. ATS does not delete the data when you do that, so these totals '
						. 'are historical and will not grow.'
					: 'The Time Spent feature is on (timespent_hide is 0). timespent_mandatory is '
						. ((int) $params->get('timespent_mandatory', 0) === 1 ? 'on' : 'off')
						. ' — when off, staff can reply without logging any time, so zero does not mean no '
						. 'work was done.',
			],
		];

		if ($probeCapped) {
			$response['oldest_open_ticket_cap'] = 'More than ' . self::MAX_AGENT_PROBES . ' agents hold '
				. 'open tickets; oldest_open_ticket was resolved for the first ' . self::MAX_AGENT_PROBES
				. ' only. Narrow with category_id.';
		}

		return ToolResult::json($response);
	}

	/**
	 * The Open ticket assigned to $uid whose newest ENABLED post is the oldest.
	 *
	 * Gated: when the caller cannot view the ticket, the age is still reported
	 * (it is an aggregate of the same site-wide data as the counts) but the id
	 * and the title are not.
	 *
	 * @return array<string, mixed>|null
	 */
	private function oldestOpen(
		string $prefix,
		int $uid,
		?int $categoryId,
		bool $includeUnpublished,
		User $actor
	): ?array {
		$activity = '(SELECT ' . $this->db->quoteName('ticket_id')
			. ', MAX(' . $this->db->quoteName('created') . ') AS ' . $this->db->quoteName('last_post_at')
			. ' FROM ' . $this->db->quoteName($prefix . 'ats_posts')
			. ' WHERE ' . $this->db->quoteName('enabled') . ' = 1'
			. ' GROUP BY ' . $this->db->quoteName('ticket_id') . ') AS ' . $this->db->quoteName('p');

		$where = [
			$this->db->quoteName('t.assigned_to') . ' = ' . $uid,
			$this->db->quoteName('t.status') . ' = ' . $this->db->quote('O'),
			$this->db->quoteName('p.last_post_at') . ' IS NOT NULL',
		];

		if (!$includeUnpublished) {
			$where[] = $this->db->quoteName('t.enabled') . ' = 1';
		}

		if ($categoryId !== null) {
			$where[] = $this->db->quoteName('t.catid') . ' = ' . (int) $categoryId;
		}

		$row = $this->db->setQuery(
			'SELECT ' . implode(', ', [
				$this->db->quoteName('t.id'),
				$this->db->quoteName('t.title'),
				$this->db->quoteName('t.catid'),
				$this->db->quoteName('t.created'),
				// getTicketPrivileges() reads created_by and public off the table.
				$this->db->quoteName('t.created_by'),
				$this->db->quoteName('t.public'),
				$this->db->quoteName('p.last_post_at'),
			])
			. ' FROM ' . $this->db->quoteName($prefix . 'ats_tickets', 't')
			. ' INNER JOIN ' . $activity
			. ' ON ' . $this->db->quoteName('p.ticket_id') . ' = ' . $this->db->quoteName('t.id')
			. ' WHERE ' . implode(' AND ', $where)
			. ' ORDER BY ' . $this->db->quoteName('p.last_post_at') . ' ASC',
			0,
			1
		)->loadAssoc();

		if ($row === null) {
			return null;
		}

		$lastAt = $this->toTimestamp($row['last_post_at'] ?? null);
		$days   = $lastAt === null ? null : (int) floor((time() - $lastAt) / 86400);

		// BIND, NEVER load(). TicketTable::load() runs onAfterLoad() →
		// ensureUcmRecord(), which INSERTs a #__ucm_content row when one is
		// missing (TicketTable.php:710-719, 1008-1039) and only self-disables
		// above Joomla 5.4. A workload report must not write.
		$table = $this->atsTable('Ticket');

		if ($table !== null) {
			if (method_exists($table, 'bindAsLoadEquivalent')) {
				$table->bindAsLoadEquivalent($row);
			} else {
				$table->bind($row);
			}
		}

		if ($table === null || !$this->atsCanViewTicket($table, $actor)) {
			return [
				'visible' => false,
				'days_since_last_activity' => $days,
				'note' => 'This agent\'s oldest unanswered ticket is not one the calling user may view, so '
					. 'its id and title are withheld. The age is still reported because the workload counts '
					. 'above it are site-wide anyway.',
			];
		}

		return [
			'visible'      => true,
			'id'           => (int) $row['id'],
			'title'        => (string) $row['title'],
			'category_id'  => (int) $row['catid'],
			'created'      => $row['created'],
			'last_post_at' => $row['last_post_at'],
			'days_since_last_activity' => $days,
			'note' => 'Oldest by NEWEST ENABLED POST, not by #__ats_tickets.modified — modified is written '
				. 'only when a reply lands on a non-closed ticket, so it does not move on an edit and does '
				. 'not roll back when a post is unpublished.',
		];
	}

	/**
	 * Which of these user ids are blocked or missing from #__users.
	 *
	 * @param array<int, int> $ids
	 *
	 * @return array<int, bool|null>
	 */
	private function blockedFlags(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));

		if ($ids === []) {
			return [];
		}

		$out = [];

		foreach ($ids as $id) {
			$out[$id] = null;
		}

		foreach ($this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName(['id', 'block']))
				->from($this->db->quoteName('#__users'))
				->whereIn($this->db->quoteName('id'), $ids)
		)->loadAssocList() ?: [] as $row) {
			$out[(int) $row['id']] = (int) $row['block'] === 1;
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
