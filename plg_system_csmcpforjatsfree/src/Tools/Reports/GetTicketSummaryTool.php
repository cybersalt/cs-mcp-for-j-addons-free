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
 * Headline numbers for a helpdesk: counts by status, category, assignee and
 * priority, recent volume, and two derived service-level figures.
 *
 * FOUR THINGS SHAPED THIS TOOL.
 *
 * 1. ATS STORES NO LIFECYCLE TIMESTAMPS AT ALL.
 *    There is no first_reply_at, no closed_at, no reopened_at — the schema is
 *    the thirteen columns in #__ats_tickets and nothing else. So "average time
 *    to first manager reply" and "average time to close" cannot be read; they
 *    have to be reconstructed from #__ats_posts, and the reconstruction has
 *    limits that are stated in the payload rather than hidden. Time to close
 *    in particular is a PROXY: it is the newest enabled post on a closed
 *    ticket, which is correct when a ticket was closed by replying to it and
 *    wrong when it was closed from the list view's state control, because
 *    closing from the list writes no post and does not touch `modified`
 *    either (TicketTable::onBeforeStore() leaves modified alone — the
 *    assignment is commented out precisely so that an edit is not mistaken
 *    for a reply).
 *
 * 2. `modified` IS "LAST REPLY", NOT "LAST EDITED".
 *    PostTable::onAfterStore() is the only thing that writes it
 *    (PostTable.php:299-301), and only for a new post on a ticket that is not
 *    already Closed. Every figure here that needs "when did something last
 *    happen" is therefore computed from MAX(#__ats_posts.created), never from
 *    #__ats_tickets.modified.
 *
 * 3. "MANAGER" IS AN ACL QUESTION, NOT A COLUMN.
 *    There is no staff flag on #__users and assigned_to does not mean "replied
 *    by". Permissions::isManager($catid, $userId) is the only authority, it is
 *    per-category, and it is a live ACL lookup — so the first-reply pass
 *    memoises (catid, user) pairs and runs over a bounded sample rather than
 *    every ticket on the site.
 *
 * 4. THE COUNTS ARE SITE-WIDE BY DEFAULT.
 *    A GROUP BY over #__ats_tickets sees every ticket, including private ones
 *    in categories the caller cannot read. That is the right answer for "how
 *    is the helpdesk doing" and the wrong answer for "what can I see", so the
 *    default is stated plainly and `visibility_filtered: true` switches to a
 *    per-row pass through Permissions::getTicketPrivileges() — the same gate
 *    ATS 5.6.0 shipped to make its own list and single-ticket endpoints agree.
 */
final class GetTicketSummaryTool extends AbstractTool
{
	use ATSBootTrait;

	private const DEFAULT_TIMING_SAMPLE = 1000;

	private const MAX_TIMING_SAMPLE = 5000;

	private const DEFAULT_GATE_CAP = 2000;

	private const MAX_GATE_CAP = 10000;

	/**
	 * Memoised Permissions::isManager() answers, keyed "catid:userid". Cleared
	 * with the object, so it never outlives one tool call.
	 *
	 * @var array<string, bool>
	 */
	private array $managerCache = [];

	public function getName(): string { return 'get_ats_ticket_summary'; }

	public function getDescription(): string
	{
		return 'Aggregate report over Akeeba Ticket System tickets: totals by status, by category, by '
			. 'assignee and by priority; open / pending / closed rollups; how many tickets were created in '
			. 'the last 7, 30 and 90 days; and two derived service-level figures — average and median time '
			. 'to first manager reply, and average and median time to close. '
			. 'SCOPE: BY DEFAULT THE COUNTS ARE SITE-WIDE. They come from GROUP BY queries over '
			. '#__ats_tickets and include private tickets in categories the calling user cannot read. That '
			. 'is deliberate — it is the "how is the helpdesk doing" number. Pass visibility_filtered=true '
			. 'to instead count only tickets this user may actually view, which runs every candidate '
			. 'through ATS\' own Permissions::getTicketPrivileges() gate and reports how many were '
			. 'withheld; that mode is capped (gate_cap, default 2000 tickets) because the gate is a live '
			. 'per-ticket ACL evaluation. '
			. 'STATUS: O = Open (waiting on support), P = Pending (waiting on the CUSTOMER — only a '
			. 'manager\'s reply sets it), C = Closed. The status column is an ENUM that also accepts the '
			. 'strings "1".."99" for site-defined statuses whose labels live in the customStatuses '
			. 'component parameter; those are reported separately and are counted in neither open nor '
			. 'closed. '
			. 'DERIVED TIMINGS ARE RECONSTRUCTED, NOT STORED. ATS records no first-reply or closed-at '
			. 'timestamp anywhere. Time to first manager reply = the earliest enabled post on the ticket '
			. 'whose author is not the ticket opener, is not the system user (created_by <= 0) and holds '
			. 'manager rights in that ticket\'s category, minus the ticket\'s created date. Time to close '
			. 'is a PROXY: the newest enabled post on a Closed ticket minus its created date — accurate '
			. 'when the ticket was closed by replying, too short when it was closed from the list view '
			. '(which writes no post). Both run over the most recently created tickets only, up to '
			. 'timing_sample (default 1000, max 5000), and the payload reports the sample size and how '
			. 'many tickets in it could be measured at all. Pass include_timings=false to skip the pass. '
			. 'DO NOT USE #__ats_tickets.modified AS "LAST EDITED". It means "last reply" — it is written '
			. 'only by PostTable::onAfterStore() when a new post lands on a ticket that is not already '
			. 'Closed. Editing a ticket does not touch it, and replying to a closed ticket does not '
			. 'either. '
			. 'Filters: category_id, assigned_to (pass 0 for unassigned), include_unpublished (default '
			. 'false — #__ats_tickets.enabled is the publish flag and unpublished tickets are excluded '
			. 'unless you ask for them). '
			. 'Tickets whose catid does not resolve to a published com_ats category are counted under '
			. 'orphaned_category; run check_ats_health for the detail.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'category_id' => [
					'type' => 'integer',
					'description' => 'Restrict to one ticket category (#__categories.id where extension = '
						. '"com_ats"). Omit for all categories.',
				],
				'assigned_to' => [
					'type' => 'integer',
					'minimum' => 0,
					'description' => 'Restrict to one assignee (a #__users.id). Pass 0 for UNASSIGNED — '
						. '#__ats_tickets.assigned_to is NOT NULL DEFAULT 0, so 0 is the real "nobody" value.',
				],
				'include_unpublished' => [
					'type' => 'boolean',
					'description' => 'Include tickets with enabled = 0. Default false. `enabled` is the '
						. 'publish flag; TicketTable aliases it as `published`.',
				],
				'include_timings' => [
					'type' => 'boolean',
					'description' => 'Compute time-to-first-manager-reply and time-to-close. Default true. '
						. 'This is the expensive part — it reads posts and evaluates category ACL per author.',
				],
				'timing_sample' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => self::MAX_TIMING_SAMPLE,
					'description' => 'How many of the most recently created tickets to measure. Default '
						. self::DEFAULT_TIMING_SAMPLE . ', max ' . self::MAX_TIMING_SAMPLE . '.',
				],
				'visibility_filtered' => [
					'type' => 'boolean',
					'description' => 'Count only tickets the calling user may view, using ATS\' own '
						. 'per-ticket privilege check rather than the raw table. Default false (site-wide). '
						. 'Slower, and capped by gate_cap.',
				],
				'gate_cap' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => self::MAX_GATE_CAP,
					'description' => 'Maximum tickets to evaluate when visibility_filtered is true. Default '
						. self::DEFAULT_GATE_CAP . ', max ' . self::MAX_GATE_CAP . '. If the filter matches '
						. 'more than this, the payload says so and the numbers are a sample, not a total.',
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

		$categoryId  = isset($arguments['category_id']) ? (int) $arguments['category_id'] : null;
		$assignedTo  = isset($arguments['assigned_to']) ? max(0, (int) $arguments['assigned_to']) : null;
		$inclUnpub   = (bool) ($arguments['include_unpublished'] ?? false);
		$inclTimings = (bool) ($arguments['include_timings'] ?? true);
		$gated       = (bool) ($arguments['visibility_filtered'] ?? false);

		$timingSample = isset($arguments['timing_sample'])
			? max(1, min(self::MAX_TIMING_SAMPLE, (int) $arguments['timing_sample']))
			: self::DEFAULT_TIMING_SAMPLE;

		$gateCap = isset($arguments['gate_cap'])
			? max(1, min(self::MAX_GATE_CAP, (int) $arguments['gate_cap']))
			: self::DEFAULT_GATE_CAP;

		$tickets = $this->db->quoteName($this->db->getPrefix() . 'ats_tickets');
		$where   = $this->ticketWhere($categoryId, $assignedTo, $inclUnpub);

		$response = [
			'ok'      => true,
			'filters' => [
				'category_id'         => $categoryId,
				'assigned_to'         => $assignedTo,
				'include_unpublished' => $inclUnpub,
			],
		];

		// ---- the counts -------------------------------------------------------
		if ($gated) {
			$gate = $this->gatedCounts($tickets, $where, $actor, $gateCap);

			if ($gate === null) {
				return ToolResult::error(
					'visibility_filtered was requested but ATS\' TicketTable could not be built through the '
					. 'component\'s MVCFactory, so the privilege gate could not be applied. Rather than fall '
					. 'back to unfiltered counts — which would silently answer a different question — this '
					. 'tool refuses. Retry without visibility_filtered if a site-wide total is what you want.'
				);
			}

			$response['scope'] = [
				'mode'      => 'visibility_filtered',
				'meaning'   => 'Counts below cover ONLY tickets that ' . $this->actorLabel($actor)
					. ' may view, decided per ticket by Permissions::getTicketPrivileges().',
				'scanned'   => $gate['scanned'],
				'visible'   => $gate['visible'],
				'withheld'  => $gate['withheld'],
				'capped'    => $gate['capped'],
				'total_matching_filter' => $gate['total'],
			];

			if ($gate['capped']) {
				$response['scope']['capped_note'] = 'The filter matched ' . $gate['total'] . ' tickets but '
					. 'only the ' . $gate['scanned'] . ' most recently created were evaluated (gate_cap). '
					. 'Everything below is a sample of that slice, not a site total. Narrow with '
					. 'category_id / assigned_to, or raise gate_cap.';
			}

			$response += $gate['report'];
			$measurable = $gate['ids'];
		} else {
			$response['scope'] = [
				'mode'    => 'site_wide',
				'meaning' => 'THESE COUNTS ARE SITE-WIDE. They are SQL aggregates over #__ats_tickets and '
					. 'include private tickets in categories ' . $this->actorLabel($actor) . ' cannot read. '
					. 'They are not filtered to what the caller may see. Pass visibility_filtered=true for '
					. 'the caller-scoped version.',
			];

			$response += $this->sqlCounts($tickets, $where);
			$measurable = null;
		}

		// ---- derived service levels ------------------------------------------
		if ($inclTimings) {
			$response['service_levels'] = $this->timings(
				$tickets,
				$where,
				$timingSample,
				$measurable
			);
		} else {
			$response['service_levels'] = [
				'computed' => false,
				'note'     => 'include_timings was false. ATS stores no first-reply or closed-at timestamp, '
					. 'so these figures only exist when this tool reconstructs them from #__ats_posts.',
			];
		}

		$response['reading_this'] = [
			'status_codes' => [
				'O' => 'Open — waiting on the support team.',
				'P' => 'Pending — waiting on the CUSTOMER. Only a manager\'s reply sets this; a reply from '
					. 'the ticket owner, an invited collaborator or any other non-manager sets O.',
				'C' => 'Closed. Posting to a closed ticket does NOT reopen it, does not touch modified and '
					. 'does not recompute timespent — PostTable::onAfterStore() skips the whole block when '
					. 'the status is already C.',
				'1-99' => 'Site-defined statuses. Labels come from the customStatuses component parameter; '
					. 'get_ats_config returns them. They are neither open nor closed as far as ATS is '
					. 'concerned. If you go on to query one yourself, compare it as a STRING — '
					. '`status = \'7\'`, never `status = 7`. The column is an ENUM, and MySQL reads an '
					. 'unquoted integer as an ENUM ORDINAL, silently matching a different member.',
			],
			'modified_column' => '#__ats_tickets.modified means LAST REPLY, not last edited. Only '
				. 'PostTable::onAfterStore() writes it, and only for a new post on a non-closed ticket. '
				. 'Use list_ats_stale_tickets for genuine last-activity triage.',
			'timespent' => '#__ats_tickets.timespent is DERIVED. PostTable::onAfterStore() recomputes it as '
				. 'SUM(timespent) over that ticket\'s posts WHERE enabled = 1, on every new post to a '
				. 'non-closed ticket. Writing the ticket column directly is pointless, and unpublishing a '
				. 'post only reduces the ticket total on the NEXT reply.',
			'priority' => 'priority is a TINYINT with NO DEFAULT. ATS\' own form offers 0 = High, 5 = Normal '
				. '(the form default), 10 = Low. The field is hidden in the UI unless the ticketPriorities '
				. 'component parameter is 1, and it ships as 0, so on most sites every ticket carries the '
				. 'form default and this breakdown says nothing useful.',
		];

		return ToolResult::json($response);
	}

	/**
	 * The shared WHERE fragment. Returns a string beginning with ' WHERE ' or ''.
	 */
	private function ticketWhere(?int $categoryId, ?int $assignedTo, bool $includeUnpublished): string
	{
		$parts = [];

		if (!$includeUnpublished) {
			$parts[] = $this->db->quoteName('enabled') . ' = 1';
		}

		if ($categoryId !== null) {
			$parts[] = $this->db->quoteName('catid') . ' = ' . (int) $categoryId;
		}

		if ($assignedTo !== null) {
			$parts[] = $this->db->quoteName('assigned_to') . ' = ' . (int) $assignedTo;
		}

		return $parts === [] ? '' : ' WHERE ' . implode(' AND ', $parts);
	}

	/**
	 * Site-wide counts, straight out of GROUP BY.
	 *
	 * @return array<string, mixed>
	 */
	private function sqlCounts(string $tickets, string $where): array
	{
		$total = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $tickets . $where)->loadResult();

		$byStatus = [];

		foreach ($this->db->setQuery(
			'SELECT ' . $this->db->quoteName('status') . ' AS s, COUNT(*) AS c FROM ' . $tickets . $where
			. ' GROUP BY ' . $this->db->quoteName('status')
		)->loadAssocList() ?: [] as $row) {
			$byStatus[(string) $row['s']] = (int) $row['c'];
		}

		$byPriority = [];

		foreach ($this->db->setQuery(
			'SELECT ' . $this->db->quoteName('priority') . ' AS p, COUNT(*) AS c FROM ' . $tickets . $where
			. ' GROUP BY ' . $this->db->quoteName('priority')
			. ' ORDER BY ' . $this->db->quoteName('priority') . ' ASC'
		)->loadAssocList() ?: [] as $row) {
			$byPriority[(string) (int) $row['p']] = (int) $row['c'];
		}

		$byCategory = [];

		foreach ($this->db->setQuery(
			'SELECT ' . $this->db->quoteName('catid') . ' AS cid, COUNT(*) AS c FROM ' . $tickets . $where
			. ' GROUP BY ' . $this->db->quoteName('catid')
			. ' ORDER BY c DESC'
		)->loadAssocList() ?: [] as $row) {
			$byCategory[(int) $row['cid']] = (int) $row['c'];
		}

		$byAssignee = [];

		foreach ($this->db->setQuery(
			'SELECT ' . $this->db->quoteName('assigned_to') . ' AS uid, COUNT(*) AS c FROM ' . $tickets
			. $where . ' GROUP BY ' . $this->db->quoteName('assigned_to') . ' ORDER BY c DESC'
		)->loadAssocList() ?: [] as $row) {
			$byAssignee[(int) $row['uid']] = (int) $row['c'];
		}

		$recent = [];

		foreach ([7, 30, 90] as $days) {
			$cut = gmdate('Y-m-d H:i:s', time() - ($days * 86400));

			$recent['last_' . $days . '_days'] = (int) $this->db->setQuery(
				'SELECT COUNT(*) FROM ' . $tickets
				. ($where === '' ? ' WHERE ' : $where . ' AND ')
				. $this->db->quoteName('created') . ' >= ' . $this->db->quote($cut)
			)->loadResult();
		}

		return $this->assemble($total, $byStatus, $byPriority, $byCategory, $byAssignee, $recent);
	}

	/**
	 * Counts over only the tickets this actor may view.
	 *
	 * Binds each candidate row into a real TicketTable and asks ATS, because the
	 * predicate is not expressible in SQL — it folds together ownership,
	 * invitation, the category's view access level and the ats.private.read
	 * privilege, and 5.6.0 exists specifically because a hand-rolled version of
	 * it disagreed with this one.
	 *
	 * BINDS, DOES NOT LOAD. TicketTable::load() is not read-only below Joomla
	 * 5.5: onAfterLoad() calls ensureUcmRecord(), which INSERTs a #__ucm_content
	 * row when one is missing (TicketTable.php:710-719 and 1008-1039) and only
	 * self-disables above 5.4. A summary report writing rows as a side effect of
	 * being asked for numbers would be indefensible, so the row is SELECTed here
	 * and handed to the vendor's own bindAsLoadEquivalent().
	 *
	 * @return array{report: array<string,mixed>, scanned:int, visible:int, withheld:int, capped:bool, total:int, ids: array<int,int>}|null
	 */
	private function gatedCounts(string $tickets, string $where, User $actor, int $cap): ?array
	{
		$total = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $tickets . $where)->loadResult();

		$rows = $this->db->setQuery(
			'SELECT ' . implode(', ', [
				$this->db->quoteName('id'),
				$this->db->quoteName('catid'),
				$this->db->quoteName('status'),
				$this->db->quoteName('priority'),
				$this->db->quoteName('assigned_to'),
				$this->db->quoteName('created'),
				// getTicketPrivileges() reads created_by and public off the table.
				$this->db->quoteName('created_by'),
				$this->db->quoteName('public'),
			]) . ' FROM ' . $tickets . $where
			. ' ORDER BY ' . $this->db->quoteName('created') . ' DESC, ' . $this->db->quoteName('id') . ' DESC',
			0,
			$cap
		)->loadAssocList() ?: [];

		// One prototype, cloned per row. NEVER TicketTable::load() here:
		// onAfterLoad() calls ensureUcmRecord(), which INSERTs into #__ucm_content
		// when a row is missing (TicketTable.php:710-719, 1008-1039) and only
		// self-disables above Joomla 5.4. A read-only report must not write.
		// Cloning a fresh prototype also keeps each table's valuesOnLoad empty, so
		// bindAsLoadEquivalent() populates it from this row rather than the last.
		$prototype = $this->atsTable('Ticket');

		if ($prototype === null) {
			return null;
		}

		$byStatus   = [];
		$byPriority = [];
		$byCategory = [];
		$byAssignee = [];
		$recent     = ['last_7_days' => 0, 'last_30_days' => 0, 'last_90_days' => 0];
		$visibleIds = [];

		$cut7  = time() - (7 * 86400);
		$cut30 = time() - (30 * 86400);
		$cut90 = time() - (90 * 86400);

		foreach ($rows as $row) {
			$table = clone $prototype;
			$table->reset();

			if (method_exists($table, 'bindAsLoadEquivalent')) {
				$table->bindAsLoadEquivalent($row);
			} else {
				$table->bind($row);
			}

			if (!$this->atsCanViewTicket($table, $actor)) {
				continue;
			}

			$visibleIds[] = (int) $row['id'];

			$status = (string) $row['status'];
			$byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

			$priority = (string) (int) $row['priority'];
			$byPriority[$priority] = ($byPriority[$priority] ?? 0) + 1;

			$catid = (int) $row['catid'];
			$byCategory[$catid] = ($byCategory[$catid] ?? 0) + 1;

			$uid = (int) $row['assigned_to'];
			$byAssignee[$uid] = ($byAssignee[$uid] ?? 0) + 1;

			$created = $this->toTimestamp($row['created'] ?? null);

			if ($created !== null) {
				if ($created >= $cut7) {
					$recent['last_7_days']++;
				}
				if ($created >= $cut30) {
					$recent['last_30_days']++;
				}
				if ($created >= $cut90) {
					$recent['last_90_days']++;
				}
			}
		}

		arsort($byCategory);
		arsort($byAssignee);
		ksort($byPriority);

		return [
			'report'   => $this->assemble(
				\count($visibleIds),
				$byStatus,
				$byPriority,
				$byCategory,
				$byAssignee,
				$recent
			),
			'scanned'  => \count($rows),
			'visible'  => \count($visibleIds),
			'withheld' => \count($rows) - \count($visibleIds),
			'capped'   => $total > \count($rows),
			'total'    => $total,
			'ids'      => $visibleIds,
		];
	}

	/**
	 * Turn the raw tallies into the labelled payload both modes return.
	 *
	 * @param array<string, int> $byStatus
	 * @param array<string, int> $byPriority
	 * @param array<int, int>    $byCategory
	 * @param array<int, int>    $byAssignee
	 * @param array<string, int> $recent
	 *
	 * @return array<string, mixed>
	 */
	private function assemble(
		int $total,
		array $byStatus,
		array $byPriority,
		array $byCategory,
		array $byAssignee,
		array $recent
	): array {
		$statuses = [];
		$custom   = 0;

		foreach ($byStatus as $code => $count) {
			$statuses[] = [
				'status' => $code,
				'label'  => $this->atsStatusLabel((string) $code),
				'count'  => $count,
				'kind'   => \in_array($code, ['O', 'P', 'C'], true) ? 'built_in' : 'site_defined',
			];

			if (!\in_array($code, ['O', 'P', 'C'], true)) {
				$custom += $count;
			}
		}

		$priorities = [];
		$labels     = [0 => 'High', 5 => 'Normal (ATS form default)', 10 => 'Low'];

		foreach ($byPriority as $value => $count) {
			$priorities[] = [
				'priority' => (int) $value,
				'label'    => $labels[(int) $value] ?? 'Out of range — not one of ATS\' three form options.',
				'count'    => $count,
			];
		}

		return [
			'totals' => [
				'tickets'      => $total,
				'open'         => $byStatus['O'] ?? 0,
				'pending'      => $byStatus['P'] ?? 0,
				'closed'       => $byStatus['C'] ?? 0,
				'site_defined' => $custom,
				'note'         => 'open + pending + closed + site_defined = tickets. Site-defined statuses '
					. '("1".."99") are counted separately because ATS treats them as neither open nor closed.',
			],
			'by_status'    => $statuses,
			'by_priority'  => [
				'rows' => $priorities,
				'note' => 'priority has NO DEFAULT in the schema. ATS\' form offers 0 = High, 5 = Normal, '
					. '10 = Low with 5 preselected, and hides the field entirely unless the ticketPriorities '
					. 'component parameter is 1 (it ships as 0). VENDOR QUIRK: the priority badge layout '
					. '(layouts/akeeba/ats/common/priority_badge.php:41-55) renders High only for '
					. '0 < priority < 5, so a ticket set to the form\'s own "High" value of 0 displays as '
					. 'Normal. The counts here are the stored numbers, not what the badge shows.',
			],
			'by_category'  => $this->decorateCategories($byCategory),
			'by_assignee'  => $this->decorateAssignees($byAssignee),
			'created_recently' => $recent + [
				'note' => 'Counted against #__ats_tickets.created, which is a nullable datetime stored in '
					. 'UTC. Tickets with a NULL created date fall out of every bucket.',
			],
		];
	}

	/**
	 * Attach category titles, and flag catids that do not resolve to a com_ats
	 * category. catid is a bare bigint with no foreign key, so "points at a
	 * com_content category" and "points at nothing" are both possible states.
	 *
	 * @param array<int, int> $byCategory
	 *
	 * @return array<string, mixed>
	 */
	private function decorateCategories(array $byCategory): array
	{
		if ($byCategory === []) {
			return ['rows' => [], 'orphaned_category' => 0];
		}

		$ids   = array_map('intval', array_keys($byCategory));
		$known = [];

		foreach ($this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName(['id', 'title', 'published', 'access', 'language', 'extension']))
				->from($this->db->quoteName('#__categories'))
				->whereIn($this->db->quoteName('id'), $ids)
		)->loadAssocList() ?: [] as $row) {
			$known[(int) $row['id']] = $row;
		}

		$rows     = [];
		$orphaned = 0;

		foreach ($byCategory as $catid => $count) {
			$catid = (int) $catid;
			$cat   = $known[$catid] ?? null;
			$isAts = $cat !== null && (string) $cat['extension'] === 'com_ats';

			if (!$isAts) {
				$orphaned += $count;
			}

			$rows[] = [
				'category_id' => $catid,
				'title'       => $cat['title'] ?? null,
				'extension'   => $cat['extension'] ?? null,
				'is_ats_category' => $isAts,
				'published'   => $cat === null ? null : (int) $cat['published'],
				'access'      => $cat === null ? null : (int) $cat['access'],
				'language'    => $cat['language'] ?? null,
				'count'       => (int) $count,
			];
		}

		return [
			'rows' => $rows,
			'orphaned_category' => $orphaned,
			'note' => 'A ticket inherits its access level and language FROM ITS CATEGORY — #__ats_tickets '
				. 'has no access and no language column of its own. catid is a bare bigint with no foreign '
				. 'key, so any query joining it MUST also require #__categories.extension = "com_ats" or it '
				. 'will match com_content categories by id collision. is_ats_category false means exactly '
				. 'that has happened, or the category was deleted; check_ats_health reports it in full.',
		];
	}

	/**
	 * @param array<int, int> $byAssignee
	 *
	 * @return array<string, mixed>
	 */
	private function decorateAssignees(array $byAssignee): array
	{
		if ($byAssignee === []) {
			return ['rows' => []];
		}

		$ids   = array_values(array_filter(array_map('intval', array_keys($byAssignee)), static fn (int $i): bool => $i > 0));
		$users = [];

		if ($ids !== []) {
			foreach ($this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName(['id', 'name', 'username', 'block']))
					->from($this->db->quoteName('#__users'))
					->whereIn($this->db->quoteName('id'), $ids)
			)->loadAssocList() ?: [] as $row) {
				$users[(int) $row['id']] = $row;
			}
		}

		$rows = [];

		foreach ($byAssignee as $uid => $count) {
			$uid  = (int) $uid;
			$user = $users[$uid] ?? null;

			$rows[] = [
				'user_id'  => $uid,
				'name'     => $uid === 0 ? null : ($user['name'] ?? null),
				'username' => $uid === 0 ? null : ($user['username'] ?? null),
				'blocked'  => $user === null ? null : ((int) $user['block'] === 1),
				'unassigned' => $uid === 0,
				'missing_user' => $uid > 0 && $user === null,
				'count'    => (int) $count,
			];
		}

		return [
			'rows' => $rows,
			'note' => 'assigned_to is NOT NULL DEFAULT 0, so user_id 0 is the real "unassigned" bucket, not '
				. 'a null. missing_user true means the assignee\'s Joomla account was deleted and the '
				. 'assignment was left behind — ATS has no cleanup for that. Use get_ats_agent_workload for '
				. 'the full roster including staff who currently hold nothing.',
		];
	}

	/**
	 * Reconstruct the two service-level figures from #__ats_posts.
	 *
	 * @param array<int, int>|null $restrictToIds When visibility_filtered was
	 *                                            used, only these tickets are
	 *                                            measured, so the timings match
	 *                                            the counts above them.
	 *
	 * @return array<string, mixed>
	 */
	private function timings(string $tickets, string $where, int $sample, ?array $restrictToIds): array
	{
		$clause = $where;

		if ($restrictToIds !== null) {
			if ($restrictToIds === []) {
				return [
					'computed' => false,
					'note'     => 'No visible tickets to measure.',
				];
			}

			$list   = implode(', ', array_map('intval', $restrictToIds));
			$clause = ($where === '' ? ' WHERE ' : $where . ' AND ')
				. $this->db->quoteName('id') . ' IN (' . $list . ')';
		}

		$rows = $this->db->setQuery(
			'SELECT ' . implode(', ', [
				$this->db->quoteName('id'),
				$this->db->quoteName('catid'),
				$this->db->quoteName('status'),
				$this->db->quoteName('created'),
				$this->db->quoteName('created_by'),
			]) . ' FROM ' . $tickets . $clause
			. ' ORDER BY ' . $this->db->quoteName('created') . ' DESC, ' . $this->db->quoteName('id') . ' DESC',
			0,
			$sample
		)->loadAssocList() ?: [];

		if ($rows === []) {
			return ['computed' => false, 'note' => 'No tickets matched the filter.'];
		}

		$byId = [];

		foreach ($rows as $row) {
			$byId[(int) $row['id']] = $row;
		}

		$posts = $this->db->setQuery(
			'SELECT ' . implode(', ', [
				$this->db->quoteName('ticket_id'),
				$this->db->quoteName('created'),
				$this->db->quoteName('created_by'),
			]) . ' FROM ' . $this->db->quoteName($this->db->getPrefix() . 'ats_posts')
			. ' WHERE ' . $this->db->quoteName('enabled') . ' = 1'
			. ' AND ' . $this->db->quoteName('ticket_id') . ' IN (' . implode(', ', array_keys($byId)) . ')'
			. ' ORDER BY ' . $this->db->quoteName('ticket_id') . ' ASC, '
			. $this->db->quoteName('created') . ' ASC, ' . $this->db->quoteName('id') . ' ASC'
		)->loadAssocList() ?: [];

		$grouped = [];

		foreach ($posts as $post) {
			$grouped[(int) $post['ticket_id']][] = $post;
		}

		$firstReply   = [];
		$toClose      = [];
		$noPosts      = 0;
		$noReply      = 0;
		$closedNoPost = 0;

		foreach ($byId as $ticketId => $ticket) {
			$ticketCreated = $this->toTimestamp($ticket['created'] ?? null);
			$ticketPosts   = $grouped[$ticketId] ?? [];

			if ($ticketPosts === []) {
				$noPosts++;

				continue;
			}

			if ($ticketCreated === null) {
				continue;
			}

			$catid  = (int) $ticket['catid'];
			$opener = (int) $ticket['created_by'];
			$found  = false;

			foreach ($ticketPosts as $post) {
				$author = (int) $post['created_by'];

				// created_by <= 0 is the synthetic system user: an automated
				// notice, never a human reply.
				if ($author <= 0 || $author === $opener) {
					continue;
				}

				if (!$this->isManagerIn($catid, $author)) {
					continue;
				}

				$at = $this->toTimestamp($post['created'] ?? null);

				if ($at === null || $at < $ticketCreated) {
					continue;
				}

				$firstReply[] = $at - $ticketCreated;
				$found        = true;

				break;
			}

			if (!$found) {
				$noReply++;
			}

			if ((string) $ticket['status'] === 'C') {
				$last = end($ticketPosts);
				$at   = $this->toTimestamp($last['created'] ?? null);

				if ($at !== null && $at >= $ticketCreated) {
					$toClose[] = $at - $ticketCreated;
				} else {
					$closedNoPost++;
				}
			}
		}

		return [
			'computed'    => true,
			'sample_size' => \count($byId),
			'sample_note' => 'The ' . \count($byId) . ' most recently created tickets matching the filter'
				. ($restrictToIds !== null ? ', restricted to the ones this user may view' : '') . '.',
			'time_to_first_manager_reply' => $this->stats($firstReply) + [
				'definition' => 'Earliest enabled post on the ticket whose author is not the ticket opener, '
					. 'is not the system user (created_by <= 0) and holds manager rights in the ticket\'s '
					. 'category per Permissions::isManager(), minus the ticket\'s created date.',
				'tickets_with_no_qualifying_reply' => $noReply,
				'caveat' => 'Manager status is evaluated as it stands TODAY. Someone who answered tickets '
					. 'last year and has since lost the role will not be recognised, and someone promoted '
					. 'since will be credited retroactively. ATS records no per-post role, so this cannot be '
					. 'reconstructed any other way.',
			],
			'time_to_close' => $this->stats($toClose) + [
				'definition' => 'PROXY. Newest enabled post on a Closed ticket, minus the ticket\'s created '
					. 'date.',
				'closed_tickets_not_measurable' => $closedNoPost,
				'caveat' => 'ATS stores no closed-at timestamp. This figure is right when the ticket was '
					. 'closed by replying to it and TOO SHORT when it was closed from the list view or the '
					. 'batch control, because neither writes a post — and #__ats_tickets.modified is no help '
					. 'either, since TicketTable::onBeforeStore() deliberately does not touch it on an edit. '
					. 'Treat it as a floor, not a measurement.',
			],
			'tickets_with_no_posts' => [
				'count' => $noPosts,
				'note'  => 'A ticket\'s opening message IS a post, so a ticket with zero enabled posts is '
					. 'either broken or has had its opening post unpublished. check_ats_health reports these.',
			],
		];
	}

	/**
	 * @param array<int, int> $seconds
	 *
	 * @return array<string, mixed>
	 */
	private function stats(array $seconds): array
	{
		if ($seconds === []) {
			return ['measured' => 0, 'mean_seconds' => null, 'median_seconds' => null,
				'min_seconds' => null, 'max_seconds' => null, 'mean_hours' => null, 'median_hours' => null];
		}

		sort($seconds);

		$n      = \count($seconds);
		$mean   = array_sum($seconds) / $n;
		$median = $n % 2 === 1
			? $seconds[intdiv($n, 2)]
			: ($seconds[intdiv($n, 2) - 1] + $seconds[intdiv($n, 2)]) / 2;

		return [
			'measured'       => $n,
			'mean_seconds'   => (int) round($mean),
			'median_seconds' => (int) round((float) $median),
			'min_seconds'    => (int) $seconds[0],
			'max_seconds'    => (int) $seconds[$n - 1],
			'mean_hours'     => round($mean / 3600, 2),
			'median_hours'   => round(((float) $median) / 3600, 2),
		];
	}

	/** Memoised per-category manager check. A live ACL lookup, so worth caching. */
	private function isManagerIn(int $catid, int $userId): bool
	{
		$key = $catid . ':' . $userId;

		if (!isset($this->managerCache[$key])) {
			try {
				$this->managerCache[$key] = Permissions::isManager($catid, $userId);
			} catch (\Throwable) {
				$this->managerCache[$key] = false;
			}
		}

		return $this->managerCache[$key];
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

	private function actorLabel(User $actor): string
	{
		$name = trim((string) ($actor->username ?: $actor->name));

		return $name === '' ? 'the calling user' : 'user "' . $name . '"';
	}
}
