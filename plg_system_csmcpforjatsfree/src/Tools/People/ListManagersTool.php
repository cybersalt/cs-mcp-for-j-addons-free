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
 * List the ticket managers for the component, or for one category.
 *
 * THIS TOOL IS EXPENSIVE, AND THE EXPENSE IS THE VENDOR'S
 * --------------------------------------------------------
 * Permissions::getManagers() (Permissions.php:346) does not have a "who are the managers"
 * query, because Joomla's ACL cannot answer that question in SQL. What it does instead:
 *
 *   :357  SELECT id FROM #__usergroups                    — every group on the site
 *   :375  foreach group: Access::checkGroup(group, 'core.admin', $asset)
 *                        Access::checkGroup(group, 'core.manage', $asset)
 *   :384  SELECT id, name, username FROM #__users JOIN #__user_usergroup_map
 *         WHERE group_id IN (matching groups)             — every member of those groups
 *   :400  array_filter(..., fn => isManager($category, $id))  — per user, authoritative
 *
 * The group loop is bounded by the number of user groups and Access caches the asset rules,
 * so it is the last step that hurts: isManager() calls Permissions::getUser($id), and
 * ATSBootTrait::atsBoot() has deliberately called setCacheIdentities(false) so that one MCP
 * process can serve several actors safely. With that flag off, getUser() drops its
 * $usersCache (Permissions.php:1048) and every candidate is a fresh loadUserById() — a
 * #__users read plus a group-map read each. On a site where a broad group such as
 * Registered somehow holds core.manage, the candidate set is the whole membership of that
 * group and this becomes hundreds of user loads in one call.
 *
 * Hence the category scope, which is not a convenience filter: assetNameFor() (line 81)
 * builds 'com_ats.category.N' and a Deny on that category or a parent prunes whole groups
 * out of the pre-filter, so scoping usually shrinks the candidate set before the expensive
 * part rather than after it.
 *
 * THE FUNCTION STATIC
 * --------------------
 * getManagers() memoises into a `static $cache` keyed by category (line 348), and
 * setCacheIdentities(false) does NOT reach it — the trait's docblock, point 3, says as
 * much. In a web request that static dies with the request. In a long-lived MCP process it
 * survives, so a manager added, removed or re-grouped after the first call for a given
 * category will not appear in a later one. The returned payload therefore carries
 * cache_warning: the answer is a snapshot taken the first time this process asked about
 * this category.
 *
 * A NOTE ON WHO COUNTS
 * ---------------------
 * "Manager" here means core.admin OR core.manage on the asset (Permissions.php:1090), which
 * is the same predicate ATS uses to decide who gets notification mail, who may read private
 * tickets, and whose reply flips a ticket to Pending. It is not the same as "can be
 * assigned a ticket" — that is ats.assignee and a superset; use list_ats_assignees.
 * The vendor's user query applies no block filter, so a disabled Joomla account that still
 * belongs to a managing group is returned; `block` is included here so that is visible.
 */
final class ListManagersTool extends AbstractTool
{
	use ATSBootTrait;

	public function getName(): string { return 'list_ats_managers'; }

	public function getDescription(): string
	{
		return 'List the Akeeba Ticket System ticket managers — the users holding core.admin or core.manage '
			. 'on com_ats, or on one ATS category. Managers are the people who receive new-ticket and '
			. 'reply notification mail, who can read private tickets, and whose reply moves a ticket to '
			. 'Pending. '
			. 'STRONGLY PREFER PASSING category_id. This tool is expensive by construction: ATS cannot '
			. 'answer "who are the managers" in SQL, so Permissions::getManagers() reads EVERY user group, '
			. 'runs two Access::checkGroup() calls per group against the target asset, pulls EVERY member '
			. 'of every matching group, and then re-checks each of those users individually. Because this '
			. 'add-on turns ATS\' identity cache off (one server process can serve several actors), each '
			. 'of those per-user checks is a fresh user load. Without a category the asset is the whole '
			. 'component and the candidate set is as wide as it gets; with a category, a Deny on that '
			. 'category or one of its parents prunes entire groups out before the expensive step. On a '
			. 'large site, call this scoped, and call it rarely. '
			. 'Arguments: category_id (optional; omit or pass 0 for the component as a whole — that is '
			. 'ATS\' own default, not a "all categories" union), include_blocked (default true; ATS\' own '
			. 'query applies no block filter, so disabled accounts that still sit in a managing group are '
			. 'returned and are flagged rather than hidden). '
			. 'Returns count, category_id, asset_name, and managers[] with id, name, username, block, '
			. 'can_assign_tickets (ats.assign — may hand a ticket to someone) and can_be_assigned_tickets '
			. '(ats.assignee — may receive one). Managers hold both implicitly. '
			. 'CACHING CAVEAT: getManagers() memoises per category in a PHP function static that survives '
			. 'for the life of the server process and is not cleared when the acting identity changes. If '
			. 'you change group membership or ATS permissions and immediately re-run this tool, you may '
			. 'get the pre-change answer. cache_warning in the response says so.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'category_id' => [
					'type'        => 'integer',
					'description' => 'An ATS category id (#__categories.id where extension = \'com_ats\'). '
						. 'STRONGLY RECOMMENDED — it narrows the ACL walk and honours any Deny set on that '
						. 'category or its parents. Omit or pass 0 to ask about the com_ats component '
						. 'asset itself, which is what ATS does by default. Note that 0 means "the '
						. 'component", not "every category".',
				],
				'include_blocked' => [
					'type'        => 'boolean',
					'description' => 'Default true. ATS returns blocked accounts; set false to drop them.',
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

		if ($categoryId > 0 && !$this->atsCategoryExists($categoryId)) {
			return ToolResult::error(
				'Category ' . $categoryId . ' is not an Akeeba Ticket System category. ATS categories are '
				. 'rows in #__categories with extension = \'com_ats\'; a com_content category id will '
				. 'silently produce an asset name that resolves to nothing useful. Use the ATS category '
				. 'listing to find the right id.'
			);
		}

		// Ask the vendor. Never re-derive the predicate — see the class docblock.
		$managers = Permissions::getManagers($categoryId ?: null);

		$ids = array_map('intval', array_keys($managers));
		$blocked = $this->blockFlags($ids);

		$rows = [];

		foreach ($managers as $id => $def) {
			$id      = (int) $id;
			$isBlock = (int) ($blocked[$id] ?? 0);

			if (!$includeBlocked && $isBlock === 1) {
				continue;
			}

			$rows[] = [
				'id'                       => $id,
				'name'                     => (string) ($def->name ?? ''),
				'username'                 => (string) ($def->username ?? ''),
				'block'                    => $isBlock,
				'can_assign_tickets'       => Permissions::canAssignTickets($categoryId ?: null, $id),
				'can_be_assigned_tickets'  => Permissions::canBeAssignedTickets($categoryId ?: null, $id),
			];
		}

		usort($rows, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

		$blockedCount = \count(array_filter($blocked, static fn($v): bool => (int) $v === 1));

		$notes = [
			'"Manager" is core.admin OR core.manage on ' . ($categoryId > 0 ? 'this category' : 'com_ats')
			. ' (Permissions::isManager, Permissions.php:1084). That is a narrower set than "can be '
			. 'assigned a ticket" — use list_ats_assignees for the values valid in a ticket\'s '
			. 'assigned_to field.',
			'This answer was assembled by walking every user group and every member of the matching '
			. 'groups. Prefer a category_id and avoid calling it in a loop.',
		];

		if ($blockedCount > 0) {
			$notes[] = $blockedCount . ' of the users ATS considers managers here have block = 1, i.e. '
				. 'their Joomla account is disabled. ATS applies no block filter when building this list, '
				. 'so they are still counted as managers internally — including when deciding who to mail.';
		}

		return ToolResult::json([
			'ok'            => true,
			'category_id'   => $categoryId,
			'scope'         => $categoryId > 0 ? 'category' : 'component',
			'asset_name'    => $categoryId > 0 ? 'com_ats.category.' . $categoryId : 'com_ats',
			'count'         => \count($rows),
			'managers'      => $rows,
			'cache_warning' => 'Permissions::getManagers() memoises per category in a function static that '
				. 'setCacheIdentities(false) does not reach (Permissions.php:348). In a long-lived MCP '
				. 'process this result can be stale with respect to group or permission changes made '
				. 'after the first call for this category in this process.',
			'note'          => implode(' ', $notes),
		]);
	}

	/**
	 * Does this id name a published-or-not ATS category?
	 *
	 * The `extension` predicate is mandatory: #__ats_tickets.catid is a bare bigint with no
	 * foreign key, so a com_content category id looks perfectly valid until the asset name
	 * built from it resolves against the wrong tree.
	 */
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
	 * block flags for a set of user ids, in one query.
	 *
	 * ATS' own manager query selects only id, name and username, so the fact that a manager's
	 * account is disabled is invisible in what it returns.
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
