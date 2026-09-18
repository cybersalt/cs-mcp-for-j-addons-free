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
 * The ATS ticket categories — the ids you pass as catid, and the settings that
 * silently change what a ticket in them does.
 *
 * WHY THE `extension` PREDICATE IS NOT OPTIONAL.
 * ----------------------------------------------
 * ATS has no categories table. It reuses #__categories with extension =
 * 'com_ats', and #__ats_tickets.catid is a bare bigint with no foreign key
 * pointing at it. Any query that forgets `extension = 'com_ats'` will cheerfully
 * return com_content categories that happen to share an id.
 *
 * WHY THE PARAMS BLOCK IS WORTH RETURNING.
 * -----------------------------------------
 * A category's params JSON is where several behaviours that look like bugs
 * actually come from. `forcetype` ('PUB' or 'PRIV') overrides the submitter's
 * public/private choice outright; `defaultprivate` only changes the default.
 * `default_priority` decides what priority a ticket gets when the form does not
 * supply one, which matters because #__ats_tickets.priority has NO DEFAULT in the
 * schema — an INSERT that omits it errors under STRICT_TRANS_TABLES.
 * `defposttext` is pre-filled into the opening post. `category_email` becomes the
 * Reply-To on notification mail (EmailSending.php:533, 579-585).
 * `notify_managers` / `exclude_managers` decide who gets that mail at all
 * (EmailSending.php:309, 376), and notify_managers defaults to the string 'all',
 * not to an array — so "empty" and "everyone" are not the same value.
 *
 * THE ACCESS FILTER MIRRORS THE COMPONENT, INCLUDING ITS INCONSISTENCY.
 * ---------------------------------------------------------------------
 * Permissions::getPostableCategories() and getManagerCategories() both skip the
 * view-level filter entirely for anyone holding core.admin or core.manage on
 * com_ats (Permissions.php:319-325 and 502-509). getTicketPrivileges() does the
 * opposite as of 5.6.0: the category's view level binds managers and Super Users
 * too. So on ATS a global administrator can be entitled to CREATE a ticket in a
 * category whose tickets they are not entitled to READ. We reproduce the listing
 * side of that — global staff see every category — and expose per-category
 * can_access / can_create / is_manager flags so the asymmetry is visible rather
 * than baffling. can_access is the one that predicts whether list_ats_tickets
 * will return anything from the category.
 *
 * The per-category flags are computed from the actor's own User object rather
 * than through Permissions::isManager(), which takes a user ID and would reload
 * the account from the database on every call — ATSBootTrait deliberately turns
 * the helper's identity memoisation off, so those lookups are not cached. The
 * expressions are identical to the vendor's (Permissions.php:1084-1092); only
 * the visibility answer, where being wrong is an access-control failure rather
 * than a cosmetic one, goes through the vendor's own code.
 */
final class ListCategoriesTool extends AbstractTool
{
	use ATSBootTrait;

	private const ORDER_COLUMNS = ['lft', 'title', 'id', 'level', 'published', 'ticket_count'];

	public function getName(): string { return 'list_ats_categories'; }

	public function getDescription(): string
	{
		return 'List the Akeeba Ticket System ticket categories. ATS has no categories table of its own '
			. '— these are rows in #__categories with extension = \'com_ats\', and their id is what you '
			. 'pass as catid on a ticket. Returns per category: id, title, alias, path, level, '
			. 'parent_id, published, access and the resolved access_title from #__viewlevels, language, '
			. 'note, ticket_count, and ats_params — the ATS-specific settings decoded out of the '
			. 'category params JSON: forcetype, defaultprivate, default_priority, defposttext, '
			. 'category_email, notify_managers, exclude_managers. Those matter because they change what '
			. 'a ticket in the category does: forcetype (\'PUB\'/\'PRIV\'/empty) OVERRIDES the '
			. 'submitter\'s public/private choice outright, while defaultprivate merely sets the '
			. 'default; default_priority supplies the priority when the form does not (the priority '
			. 'column has no database default, so an insert that omits it is an error); defposttext is '
			. 'pre-filled into the opening post; category_email becomes the Reply-To on notification '
			. 'mail; notify_managers and exclude_managers decide who receives it, and notify_managers '
			. 'defaults to the STRING "all" rather than to a list. Also returned per category: '
			. 'can_access (does the actor hold the category\'s view level), is_manager, and can_create. '
			. 'FILTERS: published (0/1, or -2 for trashed), access, language, parent_id, level, search '
			. '(title, alias or path LIKE). ORDERING: order_by one of lft, title, id, level, published, '
			. 'ticket_count; order_dir ASC/DESC. Default lft ASC, which is tree order. '
			. 'COUNTS: ticket_count counts EVERY row in #__ats_tickets with that catid — private ones, '
			. 'unpublished ones, and ones you personally may not read. It is a category-level statistic, '
			. 'not a promise about what list_ats_tickets will show you. '
			. 'VISIBILITY: a user holding core.admin or core.manage on com_ats sees every category, '
			. 'which is how ATS\' own category lookups behave; everyone else sees only categories whose '
			. 'view level they hold, and withheld_count says how many were removed. Be aware of the '
			. 'component\'s own asymmetry: those global-staff lookups ignore view levels, but the '
			. 'per-ticket check since 5.6.0 does not — so an administrator can be allowed to create a '
			. 'ticket in a category whose tickets they cannot read. can_access is the flag that predicts '
			. 'reading. Default limit 100, max 500.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'published' => ['type' => 'integer', 'description' => '1 published, 0 unpublished, -2 trashed. Omit for all.'],
				'access'    => ['type' => 'integer', 'description' => 'Exact match on the view access level id.'],
				'language'  => ['type' => 'string', 'description' => 'Exact match, e.g. "en-GB" or "*".'],
				'parent_id' => ['type' => 'integer', 'description' => 'Direct children of this category. The ATS root is normally id 1.'],
				'level'     => ['type' => 'integer', 'description' => 'Nesting depth. Top-level ATS categories are level 1.'],
				'search'    => ['type' => 'string', 'description' => 'Matches title OR alias OR path (LIKE %term%).'],
				'order_by'  => ['type' => 'string', 'enum' => self::ORDER_COLUMNS, 'description' => 'Default lft (tree order).'],
				'order_dir' => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'description' => 'Default ASC.'],
				'limit'     => ['type' => 'integer', 'description' => 'Default 100, max 500.'],
				'offset'    => ['type' => 'integer', 'description' => 'Default 0.'],
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
				$db->quoteName('c.params'),
				$db->quoteName('c.lft'),
				$db->quoteName('v.title', 'access_title'),
				'(SELECT COUNT(*) FROM ' . $db->quoteName('#__ats_tickets', 'tk')
					. ' WHERE ' . $db->quoteName('tk.catid') . ' = ' . $db->quoteName('c.id')
					. ') AS ' . $db->quoteName('ticket_count'),
			])
			->from($db->quoteName('#__categories', 'c'))
			->join('LEFT', $db->quoteName('#__viewlevels', 'v'), $db->quoteName('v.id') . ' = ' . $db->quoteName('c.access'))
			// Mandatory. Without it this returns every category on the site.
			->where($db->quoteName('c.extension') . ' = ' . $db->quote('com_ats'));

		if (array_key_exists('published', $arguments) && $arguments['published'] !== null) {
			$query->where($db->quoteName('c.published') . ' = ' . (int) $arguments['published']);
		}

		if (array_key_exists('access', $arguments) && $arguments['access'] !== null) {
			$query->where($db->quoteName('c.access') . ' = ' . (int) $arguments['access']);
		}

		if (!empty($arguments['language'])) {
			$query->where($db->quoteName('c.language') . ' = ' . $db->quote((string) $arguments['language']));
		}

		if (array_key_exists('parent_id', $arguments) && $arguments['parent_id'] !== null) {
			$query->where($db->quoteName('c.parent_id') . ' = ' . (int) $arguments['parent_id']);
		}

		if (array_key_exists('level', $arguments) && $arguments['level'] !== null) {
			$query->where($db->quoteName('c.level') . ' = ' . (int) $arguments['level']);
		}

		if (!empty($arguments['search'])) {
			$search = $db->quote('%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $arguments['search']) . '%');
			$query->where(
				'(' . $db->quoteName('c.title') . ' LIKE ' . $search
				. ' OR ' . $db->quoteName('c.alias') . ' LIKE ' . $search
				. ' OR ' . $db->quoteName('c.path') . ' LIKE ' . $search . ')'
			);
		}

		$orderBy  = (string) ($arguments['order_by'] ?? 'lft');
		$orderDir = strtoupper((string) ($arguments['order_dir'] ?? 'ASC'));

		if (!\in_array($orderBy, self::ORDER_COLUMNS, true)) {
			$orderBy = 'lft';
		}

		if (!\in_array($orderDir, ['ASC', 'DESC'], true)) {
			$orderDir = 'ASC';
		}

		// ticket_count is a select alias, not a column on c.
		$query->order(
			($orderBy === 'ticket_count' ? $db->quoteName('ticket_count') : $db->quoteName('c.' . $orderBy))
			. ' ' . $orderDir
		);

		$limit  = max(1, min(500, (int) ($arguments['limit'] ?? 100)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));

		$db->setQuery($query, $offset, $limit);
		$rows = $db->loadAssocList() ?: [];

		// ATS' own category lookups skip the view-level filter for global staff. See the class docblock.
		$isGlobalStaff = $actor->authorise('core.admin', 'com_ats') || $actor->authorise('core.manage', 'com_ats');

		$categories = [];
		$withheld   = 0;

		foreach ($rows as $row) {
			$id = (int) $row['id'];

			// Authoritative, memoised, and fails closed on a category that does not resolve.
			$canAccess = Permissions::canAccessCategory($id, $actor);

			if (!$canAccess && !$isGlobalStaff) {
				$withheld++;

				continue;
			}

			$categories[] = $this->shapeRow($row, $actor, $canAccess);
		}

		$totalUnfiltered = (int) $db->setQuery(
			$db->getQuery(true)
				->select('COUNT(*)')
				->from($db->quoteName('#__categories'))
				->where($db->quoteName('extension') . ' = ' . $db->quote('com_ats'))
		)->loadResult();

		return ToolResult::json([
			'ok'               => true,
			'count'            => \count($categories),
			'withheld_count'   => $withheld,
			'limit'            => $limit,
			'offset'           => $offset,
			'total_unfiltered' => $totalUnfiltered,
			'access_filter'    => $isGlobalStaff
				? 'Not applied: you hold core.admin or core.manage on com_ats, and ATS\' own category lookups (getManagerCategories, getPostableCategories) skip the view-level filter for exactly that case.'
				: 'Applied: only categories whose view access level you hold are returned.',
			'categories'       => $categories,
			'note'             => [
				'extension'    => 'These are #__categories rows with extension = \'com_ats\'. #__ats_tickets.catid has no foreign key, so any hand-written query must repeat that predicate or it will match com_content categories.',
				'ticket_count' => 'Counts every ticket with this catid, including private and unpublished ones and ones you are not allowed to read. It is not a preview of what list_ats_tickets will return.',
				'forcetype'    => 'A non-empty forcetype OVERRIDES the submitter\'s public/private choice. defaultprivate only changes the default, so the two are not interchangeable.',
				'priority'     => 'default_priority is what fills #__ats_tickets.priority when the submitter does not choose. That column has NO database default, so an insert omitting it errors under STRICT_TRANS_TABLES. ATS\' UI offers 1 High, 5 Normal, 10 Low.',
				'managers'     => 'notify_managers defaults to the STRING "all", not to a list — an empty value and "everyone" are different things. exclude_managers is subtracted from whoever notify_managers selected.',
				'asymmetry'    => 'can_create and is_manager ignore the category view level (that is how ATS\' own lookups behave), but reading tickets does not since 5.6.0. can_access is the flag that predicts whether list_ats_tickets will return anything here.',
			],
		]);
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>
	 */
	private function shapeRow(array $row, User $actor, bool $canAccess): array
	{
		$id     = (int) $row['id'];
		$asset  = 'com_ats.category.' . $id;
		$params = $this->decodeParams($row['params'] ?? null);

		return [
			'id'           => $id,
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
			'asset_name'   => $asset,
			'ticket_count' => (int) $row['ticket_count'],
			'can_access'   => $canAccess,
			// Same expressions the vendor uses, evaluated against the actor we already hold.
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
