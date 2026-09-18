<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Lookups;

\defined('_JEXEC') or die;

use Akeeba\Component\ATS\Administrator\Helper\Permissions;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;

/**
 * One ATS ticket category, plus the per-status breakdown of what is in it.
 *
 * Same shape as list_ats_categories, same reasons — see that class for why the
 * `extension = 'com_ats'` predicate is mandatory, why the params block is worth
 * decoding, and why global staff bypass the view-level filter here while the
 * per-ticket check in 5.6.0 does not.
 *
 * THE BREAKDOWN IS COUNTED IN SQL, NOT GATED PER TICKET.
 * ------------------------------------------------------
 * A GROUP BY over #__ats_tickets is the whole point of this tool: it answers
 * "how much is open in this queue" in one query. Running every ticket through
 * Permissions::getTicketPrivileges() to make the numbers personal would cost two
 * queries per ticket and would still not be the number anyone wanted. So the
 * counts are a property of the category, they include private and unpublished
 * tickets, and the response says so in as many words rather than quietly
 * implying they are a preview of list_ats_tickets.
 *
 * The status labels come from Permissions::getStatuses() via the trait, which is
 * the only authority on what a numeric status means on a given site. A code that
 * appears in the data but not in that list is reported as `orphaned` — see
 * list_ats_statuses, where the same situation is explained at length.
 */
final class GetCategoryTool extends AbstractTool
{
	use ATSBootTrait;

	public function getName(): string { return 'get_ats_category'; }

	public function getDescription(): string
	{
		return 'Get one Akeeba Ticket System ticket category in full, plus a per-status breakdown of '
			. 'the tickets in it. Required: id (a row in #__categories with extension = \'com_ats\'). '
			. 'Returns the same fields as list_ats_categories — id, title, alias, path, level, '
			. 'parent_id, published, access + access_title, language, note, ticket_count, can_access, '
			. 'is_manager, can_create, and the decoded ats_params (forcetype, defaultprivate, '
			. 'default_priority, defposttext, category_email, notify_managers, exclude_managers) — and '
			. 'adds: `parent` and `children`; `status_breakdown`, one entry per status code actually '
			. 'present with its label, count and whether it is one of the three built-ins; and '
			. '`totals`, splitting the category\'s tickets by published/unpublished, public/private, '
			. 'assigned/unassigned, plus the oldest and newest created timestamps and the summed '
			. 'timespent. '
			. 'The breakdown is a SQL GROUP BY over #__ats_tickets, so it counts every ticket with this '
			. 'catid including private and unpublished ones and ones you are not permitted to read; it '
			. 'is a property of the queue, not a preview of what list_ats_tickets will hand you. A '
			. 'status code present in the data but absent from the site\'s configured status list is '
			. 'flagged orphaned — that happens when a custom status is deleted from the customStatuses '
			. 'component parameter while tickets are still sitting on it. '
			. 'ACCESS: you must hold the category\'s view access level, or hold core.admin / '
			. 'core.manage on com_ats — which is how ATS\' own category lookups behave. Note that being '
			. 'allowed to see the category here does not mean you may read its tickets: since 5.6.0 the '
			. 'per-ticket check applies the category view level to managers and Super Users too, and '
			. 'can_access is the flag that tracks that.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['id'],
			'properties' => [
				'id' => ['type' => 'integer', 'description' => 'The #__categories id. This is the value stored as #__ats_tickets.catid.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');

		if (!$this->atsBoot()) {
			return $this->notInstalledError();
		}

		$db = $this->db;

		$row = $db->setQuery(
			$db->getQuery(true)
				->select([
					$db->quoteName('c.id'),
					$db->quoteName('c.title'),
					$db->quoteName('c.alias'),
					$db->quoteName('c.path'),
					$db->quoteName('c.level'),
					$db->quoteName('c.parent_id'),
					$db->quoteName('c.published'),
					$db->quoteName('c.access'),
					$db->quoteName('c.language'),
					$db->quoteName('c.note'),
					$db->quoteName('c.description'),
					$db->quoteName('c.params'),
					$db->quoteName('v.title', 'access_title'),
				])
				->from($db->quoteName('#__categories', 'c'))
				->join('LEFT', $db->quoteName('#__viewlevels', 'v'), $db->quoteName('v.id') . ' = ' . $db->quoteName('c.access'))
				->where($db->quoteName('c.id') . ' = ' . $id)
				// Mandatory: without it a com_content category of the same id would be returned.
				->where($db->quoteName('c.extension') . ' = ' . $db->quote('com_ats'))
		)->loadAssoc();

		if (!$row) {
			return ToolResult::error(
				'Category ' . $id . ' is not an Akeeba Ticket System category. It must be a row in '
				. '#__categories with extension = \'com_ats\'. Call list_ats_categories to see the ids '
				. 'that are.'
			);
		}

		$isGlobalStaff = $actor->authorise('core.admin', 'com_ats') || $actor->authorise('core.manage', 'com_ats');
		$canAccess     = Permissions::canAccessCategory($id, $actor);

		if (!$canAccess && !$isGlobalStaff) {
			return ToolResult::error(
				'You are not allowed to see ATS category ' . $id . ': its view access level is not one '
				. 'you hold, and you do not have core.admin or core.manage on com_ats.'
			);
		}

		$asset  = 'com_ats.category.' . $id;
		$params = $this->decodeParams($row['params'] ?? null);

		$category = [
			'id'           => (int) $row['id'],
			'title'        => (string) $row['title'],
			'alias'        => (string) $row['alias'],
			'path'         => (string) $row['path'],
			'level'        => (int) $row['level'],
			'parent_id'    => (int) $row['parent_id'],
			'published'    => (int) $row['published'],
			'access'       => (int) $row['access'],
			'access_title' => $row['access_title'] !== null ? (string) $row['access_title'] : null,
			'language'     => (string) $row['language'],
			'note'         => (string) $row['note'],
			'description'  => (string) $row['description'],
			'asset_name'   => $asset,
			'can_access'   => $canAccess,
			'is_manager'   => $actor->authorise('core.admin', $asset) || $actor->authorise('core.manage', $asset),
			'can_create'   => (bool) $actor->authorise('core.create', $asset),
			'ats_params'   => [
				'forcetype'        => $params['forcetype'] ?? '',
				'defaultprivate'   => $params['defaultprivate'] ?? null,
				'default_priority' => $params['default_priority'] ?? '',
				'defposttext'      => $params['defposttext'] ?? '',
				'category_email'   => $params['category_email'] ?? '',
				'notify_managers'  => $params['notify_managers'] ?? null,
				'exclude_managers' => $params['exclude_managers'] ?? null,
			],
		];

		return ToolResult::json([
			'ok'               => true,
			'id'               => $id,
			'category'         => $category,
			'parent'           => $this->loadParent((int) $row['parent_id']),
			'children'         => $this->loadChildren($id),
			'status_breakdown' => $this->statusBreakdown($id),
			'totals'           => $this->totals($id),
			'note'             => [
				'counts'       => 'status_breakdown and totals are SQL aggregates over #__ats_tickets for this catid. They include private tickets, unpublished tickets and tickets you are not permitted to read. They are a property of the queue, not a preview of list_ats_tickets.',
				'orphaned'     => 'A status flagged orphaned is a code sitting on real tickets that Permissions::getStatuses() does not know about — normally a custom status removed from the customStatuses component parameter while tickets were still on it. Those tickets keep the code; only the label is gone.',
				'forcetype'    => 'A non-empty forcetype OVERRIDES the submitter\'s public/private choice; defaultprivate only sets the default.',
				'priority'     => 'default_priority fills #__ats_tickets.priority when the submitter does not choose. That column has no database default, so an insert omitting it errors under STRICT_TRANS_TABLES.',
				'managers'     => 'notify_managers defaults to the STRING "all", not a list. exclude_managers is subtracted from that selection.',
				'access'       => 'can_create and is_manager ignore the category view level, matching ATS\' own lookups, but reading tickets does not since 5.6.0 — can_access is the flag that predicts reading.',
				'timespent'    => 'timespent_total sums the ticket-level timespent column, which is itself derived from each ticket\'s published posts and only refreshed when a reply is saved.',
			],
		]);
	}

	/** @return array<string, mixed>|null */
	private function loadParent(int $parentId): ?array
	{
		if ($parentId <= 0) {
			return null;
		}

		$db = $this->db;

		// Deliberately not constrained to extension = 'com_ats': the parent of a
		// top-level ATS category is the shared nested-set ROOT node (normally id 1),
		// whose extension is 'system'. Filtering it out would report "no parent".
		$row = $db->setQuery(
			$db->getQuery(true)
				->select([$db->quoteName('id'), $db->quoteName('title'), $db->quoteName('alias'), $db->quoteName('level'), $db->quoteName('extension')])
				->from($db->quoteName('#__categories'))
				->where($db->quoteName('id') . ' = ' . $parentId)
		)->loadAssoc();

		if (!$row) {
			return null;
		}

		return [
			'id'        => (int) $row['id'],
			'title'     => (string) $row['title'],
			'alias'     => (string) $row['alias'],
			'level'     => (int) $row['level'],
			'extension' => (string) $row['extension'],
			'is_root'   => (int) $row['level'] === 0,
		];
	}

	/** @return array<int, array<string, mixed>> */
	private function loadChildren(int $id): array
	{
		$db = $this->db;

		$rows = $db->setQuery(
			$db->getQuery(true)
				->select([
					$db->quoteName('c.id'),
					$db->quoteName('c.title'),
					$db->quoteName('c.alias'),
					$db->quoteName('c.level'),
					$db->quoteName('c.published'),
					$db->quoteName('c.access'),
					'(SELECT COUNT(*) FROM ' . $db->quoteName('#__ats_tickets', 'tk')
						. ' WHERE ' . $db->quoteName('tk.catid') . ' = ' . $db->quoteName('c.id')
						. ') AS ' . $db->quoteName('ticket_count'),
				])
				->from($db->quoteName('#__categories', 'c'))
				->where($db->quoteName('c.parent_id') . ' = ' . $id)
				->where($db->quoteName('c.extension') . ' = ' . $db->quote('com_ats'))
				->order($db->quoteName('c.lft') . ' ASC')
		)->loadAssocList() ?: [];

		return array_map(static fn(array $r): array => [
			'id'           => (int) $r['id'],
			'title'        => (string) $r['title'],
			'alias'        => (string) $r['alias'],
			'level'        => (int) $r['level'],
			'published'    => (int) $r['published'],
			'access'       => (int) $r['access'],
			'ticket_count' => (int) $r['ticket_count'],
		], $rows);
	}

	/**
	 * Tickets per status code, labelled.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function statusBreakdown(int $id): array
	{
		$db = $this->db;

		$rows = $db->setQuery(
			$db->getQuery(true)
				->select([
					$db->quoteName('status'),
					'COUNT(*) AS ' . $db->quoteName('total'),
					'SUM(CASE WHEN ' . $db->quoteName('enabled') . ' = 1 THEN 1 ELSE 0 END) AS ' . $db->quoteName('published'),
					'SUM(CASE WHEN ' . $db->quoteName('public') . ' = 1 THEN 1 ELSE 0 END) AS ' . $db->quoteName('public_count'),
				])
				->from($db->quoteName('#__ats_tickets'))
				->where($db->quoteName('catid') . ' = ' . $id)
				->group($db->quoteName('status'))
		)->loadAssocList() ?: [];

		$known = $this->knownStatusCodes();

		$out = array_map(function (array $r) use ($known): array {
			$code = (string) $r['status'];

			return [
				'status'          => $code,
				'label'           => $this->atsStatusLabel($code),
				'builtin'         => \in_array($code, ['O', 'P', 'C'], true),
				'orphaned'        => !\in_array($code, $known, true),
				'count'           => (int) $r['total'],
				'published_count' => (int) $r['published'],
				'public_count'    => (int) $r['public_count'],
				'private_count'   => (int) $r['total'] - (int) $r['public_count'],
			];
		}, $rows);

		// Codes in the order ATS presents them (O, P, custom…, C), then anything unknown.
		usort($out, static function (array $a, array $b) use ($known): int {
			$ia = array_search($a['status'], $known, true);
			$ib = array_search($b['status'], $known, true);
			$ia = $ia === false ? PHP_INT_MAX : $ia;
			$ib = $ib === false ? PHP_INT_MAX : $ib;

			return $ia <=> $ib ?: strcmp($a['status'], $b['status']);
		});

		return $out;
	}

	/**
	 * The status codes this site defines, in the component's own order.
	 *
	 * @return array<int, string>
	 */
	private function knownStatusCodes(): array
	{
		try {
			// A flat map of code => label. The custom entries carry INTEGER keys while the
			// column stores them as strings, hence the cast.
			return array_map('strval', array_keys(Permissions::getStatuses()));
		} catch (\Throwable) {
			return ['O', 'P', 'C'];
		}
	}

	/** @return array<string, mixed> */
	private function totals(int $id): array
	{
		$db = $this->db;

		$row = $db->setQuery(
			$db->getQuery(true)
				->select([
					'COUNT(*) AS ' . $db->quoteName('total'),
					'SUM(CASE WHEN ' . $db->quoteName('enabled') . ' = 1 THEN 1 ELSE 0 END) AS ' . $db->quoteName('published'),
					'SUM(CASE WHEN ' . $db->quoteName('public') . ' = 1 THEN 1 ELSE 0 END) AS ' . $db->quoteName('public_count'),
					'SUM(CASE WHEN ' . $db->quoteName('assigned_to') . ' > 0 THEN 1 ELSE 0 END) AS ' . $db->quoteName('assigned'),
					'SUM(' . $db->quoteName('timespent') . ') AS ' . $db->quoteName('timespent_total'),
					'MIN(' . $db->quoteName('created') . ') AS ' . $db->quoteName('oldest_created'),
					'MAX(' . $db->quoteName('created') . ') AS ' . $db->quoteName('newest_created'),
					'MAX(' . $db->quoteName('modified') . ') AS ' . $db->quoteName('newest_reply'),
				])
				->from($db->quoteName('#__ats_tickets'))
				->where($db->quoteName('catid') . ' = ' . $id)
		)->loadAssoc() ?: [];

		$total  = (int) ($row['total'] ?? 0);
		$public = (int) ($row['public_count'] ?? 0);

		return [
			'ticket_count'       => $total,
			'published_count'    => (int) ($row['published'] ?? 0),
			'unpublished_count'  => $total - (int) ($row['published'] ?? 0),
			'public_count'       => $public,
			'private_count'      => $total - $public,
			'assigned_count'     => (int) ($row['assigned'] ?? 0),
			'unassigned_count'   => $total - (int) ($row['assigned'] ?? 0),
			'timespent_total'    => (float) ($row['timespent_total'] ?? 0),
			'oldest_created'     => $row['oldest_created'] ?? null,
			'newest_created'     => $row['newest_created'] ?? null,
			'newest_reply'       => $row['newest_reply'] ?? null,
		];
	}

	/** @return array<string, mixed> */
	private function decodeParams(?string $params): array
	{
		if ($params === null || trim($params) === '') {
			return [];
		}

		$decoded = json_decode($params, true);

		return \is_array($decoded) ? $decoded : [];
	}
}
