<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\People;

\defined('_JEXEC') or die;

use Akeeba\Component\ATS\Administrator\Helper\Permissions;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;

/**
 * The valid values for a ticket's assigned_to, with each candidate's current workload.
 *
 * WHY THIS IS A DIFFERENT LIST FROM list_ats_managers
 * ----------------------------------------------------
 * Permissions::getAssignees() (Permissions.php:417) is getManagers() with one extra term:
 * its group pre-filter also accepts `ats.assignee` (line 451), and its authoritative
 * per-user pass is canBeAssignedTickets() (line 466 → line 1266), which is
 * core.admin OR core.manage OR ats.assignee. So assignees are a SUPERSET of managers —
 * every manager can be assigned a ticket, plus anyone granted ats.assignee alone. That
 * extra person is the useful one: an agent who should work tickets without gaining the
 * manager's ability to read private tickets or receive every notification.
 *
 * Note the deliberate asymmetry with ats.assign: `ats.assign` is the right to hand a ticket
 * to somebody (canAssignTickets, line 1243), `ats.assignee` the right to receive one. This
 * tool answers the second question, which is the one that matters when writing assigned_to.
 *
 * IT SHARES getManagers()' COST AND ITS STALENESS
 * -----------------------------------------------
 * Same shape, same expense: every user group, two-or-three Access::checkGroup() calls per
 * group, every member of every matching group, then a per-user re-check that calls
 * Permissions::getUser($id) — which does not memoise, because atsBoot() sets
 * setCacheIdentities(false) so one MCP process can serve several actors. And the same
 * `static $cache` keyed by category at line 419, which setCacheIdentities(false) does not
 * clear; in a long-lived process the answer can predate a permissions change. Scope with
 * category_id wherever possible.
 *
 * THE WORKLOAD NUMBERS, AND THE COUNTER YOU MIGHT REACH FOR BY MISTAKE
 * ---------------------------------------------------------------------
 * A bare list of names is not enough to triage with, so each row carries the candidate's
 * current queue, computed here in a single grouped query over #__ats_tickets rather than
 * per user. Two decisions worth stating:
 *
 *   - The counts are keyed on `assigned_to`. Permissions::getTicketsCount()
 *     (Permissions.php:944) looks superficially like the right helper and is NOT: it counts
 *     tickets a user SUBMITTED (`created_by = :user_id`), which is a customer-side metric.
 *     Same for getTimeSpentPerUser() at line 973 — time spent supporting a customer, not
 *     time a staffer logged.
 *   - `enabled = 1` only. Unpublished (trashed/archived) tickets are excluded, because an
 *     unpublished ticket is not work in anybody's queue. getTicketsCount() makes the
 *     opposite choice and its own docblock says so.
 *
 * "Open" means status <> 'C'. ATS' status column is an ENUM of 'O', 'P', 'C' plus the
 * strings '1'..'99' for site-defined statuses whose meaning lives in the customStatuses
 * component param — so counting O and P alone would silently under-report on a site that
 * uses custom statuses. by_status is returned raw and unaggregated for exactly that reason.
 */
final class ListAssigneesTool extends AbstractTool
{
	use ATSBootTrait;

	public function getName(): string { return 'list_ats_assignees'; }

	public function getDescription(): string
	{
		return 'List the users who can be assigned Akeeba Ticket System tickets — i.e. THE VALID VALUES FOR '
			. 'A TICKET\'S assigned_to FIELD — with each one\'s current workload, so the list can actually '
			. 'be used to route a ticket. '
			. 'This is a SUPERSET of list_ats_managers: assignees are core.admin OR core.manage OR '
			. 'ats.assignee, so every manager qualifies plus anyone granted ats.assignee on its own (a '
			. 'support agent who works tickets without manager-level visibility). Do not confuse '
			. 'ats.assignee, the right to RECEIVE a ticket, with ats.assign, the right to HAND one to '
			. 'someone else; this tool answers the first. '
			. 'Arguments: category_id (optional — pass the ticket\'s catid; omit or 0 asks about the '
			. 'com_ats component asset, which is ATS\' own default and not an all-categories union), '
			. 'include_blocked (default true), min_open / max_open (optional filters on the not-closed '
			. 'count, for finding who has capacity). '
			. 'Each row returns id, name, username, block, is_manager, assigned_total, assigned_open '
			. '(status <> \'C\'), assigned_closed, and by_status — a raw map of status code to count. '
			. 'Read by_status rather than assuming O/P/C: ATS also allows the string statuses "1".."99" '
			. 'for site-defined statuses, so a site using those would be under-reported by a hand-rolled '
			. 'O+P sum. Counts cover published tickets only (enabled = 1) and are keyed on assigned_to. '
			. 'Do NOT substitute Permissions::getTicketsCount() for these — that counts tickets a user '
			. 'SUBMITTED, not tickets assigned to them. '
			. 'COST: like list_ats_managers, this walks every user group and every member of the matching '
			. 'groups, because Joomla ACL cannot answer the question in SQL. Pass category_id, and do not '
			. 'call it in a loop. Its per-category result is also memoised in a PHP function static for '
			. 'the life of the server process, so a permissions change made after the first call for a '
			. 'category may not be reflected — see cache_warning.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'category_id' => [
					'type'        => 'integer',
					'description' => 'The ATS category id to scope to — normally the catid of the ticket '
						. 'you are about to assign. Omit or pass 0 for the com_ats component asset. '
						. 'STRONGLY RECOMMENDED: it narrows the ACL walk and honours a Deny placed on the '
						. 'category or a parent.',
				],
				'include_blocked' => [
					'type'        => 'boolean',
					'description' => 'Default true. ATS applies no block filter of its own; a disabled '
						. 'account in an assignable group is returned and flagged.',
				],
				'min_open' => [
					'type'        => 'integer',
					'description' => 'Only return candidates with at least this many not-closed assigned '
						. 'tickets.',
				],
				'max_open' => [
					'type'        => 'integer',
					'description' => 'Only return candidates with at most this many not-closed assigned '
						. 'tickets. Use max_open: 0 to find people with an empty queue.',
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

		$categoryId     = max(0, (int) ($arguments['category_id'] ?? 0));
		$includeBlocked = (bool) ($arguments['include_blocked'] ?? true);
		$minOpen        = isset($arguments['min_open']) ? (int) $arguments['min_open'] : null;
		$maxOpen        = isset($arguments['max_open']) ? (int) $arguments['max_open'] : null;

		if ($categoryId > 0 && !$this->atsCategoryExists($categoryId)) {
			return ToolResult::error(
				'Category ' . $categoryId . ' is not an Akeeba Ticket System category (no row in '
				. '#__categories with that id and extension = \'com_ats\'). Passing a category from '
				. 'another component produces an asset name that answers the wrong question rather than '
				. 'erroring, so it is refused here.'
			);
		}

		$assignees = Permissions::getAssignees($categoryId ?: null);

		$ids = array_map('intval', array_keys($assignees));

		if ($ids === []) {
			return ToolResult::json([
				'ok'            => true,
				'category_id'   => $categoryId,
				'scope'         => $categoryId > 0 ? 'category' : 'component',
				'asset_name'    => $categoryId > 0 ? 'com_ats.category.' . $categoryId : 'com_ats',
				'count'         => 0,
				'assignees'     => [],
				'cache_warning' => $this->cacheWarning(),
				'note'          => 'Nobody holds core.admin, core.manage or ats.assignee on this asset, so '
					. 'assigned_to cannot be set to any user here. Grant "Be assigned tickets" '
					. '(ats.assignee) to a group in the ATS or category permissions.',
			]);
		}

		$blocked  = $this->blockFlags($ids);
		$workload = $this->workloadFor($ids);

		$rows = [];

		foreach ($assignees as $id => $def) {
			$id      = (int) $id;
			$isBlock = (int) ($blocked[$id] ?? 0);

			if (!$includeBlocked && $isBlock === 1) {
				continue;
			}

			$byStatus = $workload[$id] ?? [];
			$total    = array_sum($byStatus);
			$closed   = (int) ($byStatus['C'] ?? 0);
			$open     = $total - $closed;

			if ($minOpen !== null && $open < $minOpen) {
				continue;
			}

			if ($maxOpen !== null && $open > $maxOpen) {
				continue;
			}

			$rows[] = [
				'id'              => $id,
				'name'            => (string) ($def->name ?? ''),
				'username'        => (string) ($def->username ?? ''),
				'block'           => $isBlock,
				'is_manager'      => Permissions::isManager($categoryId ?: null, $id),
				'assigned_total'  => (int) $total,
				'assigned_open'   => (int) $open,
				'assigned_closed' => $closed,
				'by_status'       => array_map('intval', $byStatus),
			];
		}

		// Least-loaded first: the order you want when the question is "who should take this?".
		usort($rows, static function (array $a, array $b): int {
			return $a['assigned_open'] <=> $b['assigned_open']
				?: strcasecmp($a['name'], $b['name']);
		});

		$notes = [
			'assigned_to accepts any id in this list. Workload counts published tickets only '
			. '(enabled = 1) and are keyed on assigned_to — NOT on created_by, which is what ATS\' own '
			. 'Permissions::getTicketsCount() counts and is a customer-side metric.',
			'Rows are ordered by assigned_open ascending, so the least-loaded candidate is first.',
			'by_status keys are the raw #__ats_tickets.status values: O (open, waiting on support), '
			. 'P (pending, waiting on the customer), C (closed), and possibly the strings "1".."99" for '
			. 'statuses defined in the customStatuses component param. assigned_open is everything that '
			. 'is not C.',
		];

		$blockedCount = \count(array_filter($blocked, static fn($v): bool => (int) $v === 1));

		if ($blockedCount > 0) {
			$notes[] = $blockedCount . ' candidate(s) have a blocked Joomla account. ATS will still let a '
				. 'ticket be assigned to them and will still mail them; they just cannot log in to work '
				. 'it. Pass include_blocked: false to hide them.';
		}

		return ToolResult::json([
			'ok'            => true,
			'category_id'   => $categoryId,
			'scope'         => $categoryId > 0 ? 'category' : 'component',
			'asset_name'    => $categoryId > 0 ? 'com_ats.category.' . $categoryId : 'com_ats',
			'count'         => \count($rows),
			'assignees'     => $rows,
			'cache_warning' => $this->cacheWarning(),
			'note'          => implode(' ', $notes),
		]);
	}

	private function cacheWarning(): string
	{
		return 'Permissions::getAssignees() memoises per category in a PHP function static '
			. '(Permissions.php:419) that survives for the life of the server process and is not cleared '
			. 'by setCacheIdentities(false). A permissions or group change made after the first call for '
			. 'this category in this process may not be reflected here.';
	}

	/**
	 * Tickets per assignee per status, in one grouped query.
	 *
	 * Deliberately not a per-user call: the per-user helpers ATS offers count the wrong
	 * thing (see the class docblock) and a loop would add a query per candidate to a tool
	 * that is already expensive.
	 *
	 * @param array<int, int> $ids
	 * @return array<int, array<string, int>>
	 */
	private function workloadFor(array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		$query = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('assigned_to'),
				$this->db->quoteName('status'),
				'COUNT(*) AS ' . $this->db->quoteName('cnt'),
			])
			->from($this->db->quoteName($this->db->getPrefix() . 'ats_tickets'))
			->whereIn($this->db->quoteName('assigned_to'), array_map('intval', $ids))
			// enabled is the publish flag (TicketTable aliases 'published' to it). An
			// unpublished ticket is nobody's live workload.
			->where($this->db->quoteName('enabled') . ' = 1')
			->group([$this->db->quoteName('assigned_to'), $this->db->quoteName('status')]);

		$out = [];

		foreach ($this->db->setQuery($query)->loadAssocList() ?: [] as $row) {
			$out[(int) $row['assigned_to']][(string) $row['status']] = (int) $row['cnt'];
		}

		return $out;
	}

	/** See ListManagersTool: the `extension` predicate is mandatory on #__categories. */
	private function atsCategoryExists(int $categoryId): bool
	{
		$query = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName('#__categories'))
			->where($this->db->quoteName('id') . ' = ' . (int) $categoryId)
			->where($this->db->quoteName('extension') . ' = ' . $this->db->quote('com_ats'));

		return (int) $this->db->setQuery($query)->loadResult() > 0;
	}

	/**
	 * block flags for a set of user ids, in one query. ATS' assignee query selects only
	 * id, name and username, so a disabled account is otherwise indistinguishable.
	 *
	 * @param array<int, int> $ids
	 * @return array<int, int>
	 */
	private function blockFlags(array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		$query = $this->db->getQuery(true)
			->select([$this->db->quoteName('id'), $this->db->quoteName('block')])
			->from($this->db->quoteName('#__users'))
			->whereIn($this->db->quoteName('id'), array_map('intval', $ids));

		$out = [];

		foreach ($this->db->setQuery($query)->loadAssocList() ?: [] as $row) {
			$out[(int) $row['id']] = (int) $row['block'];
		}

		return $out;
	}
}
