<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjce\Tools\Profiles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceBootTrait;
use Joomla\CMS\User\User;

/**
 * The index of `#__wf_profiles`, in the order JCE itself evaluates it.
 *
 * Ordering is not cosmetic here. `WFApplication::getProfiles()` runs
 * `SELECT * FROM #__wf_profiles WHERE published = 1 ORDER BY ordering ASC`
 * (`classes/application.php:367`) and returns the FIRST row that matches the
 * request context, so a broad profile with a low `ordering` shadows every more
 * specific profile beneath it. This listing therefore always sorts by
 * `ordering` and never offers an alternative sort — presenting these rows in
 * any other order would misrepresent what the site does.
 *
 * `params` is deliberately not returned. It is a JSON blob that on a real
 * profile runs to several kilobytes across dozens of plugin keys, and it may be
 * encrypted, in which case it needs the handling in get_jce_profile_params
 * rather than a truncated preview here.
 */
final class ListProfilesTool extends AbstractTool
{
	use JceBootTrait;

	public function getName(): string { return 'list_jce_profiles'; }

	public function getDescription(): string
	{
		return 'List every JCE editor profile from #__wf_profiles, in `ordering ASC` — the exact order '
			. 'JCE evaluates them at runtime. '
			. 'A JCE profile answers one question: "for this set of users, in this application area, on '
			. 'this device, inside these components, show this toolbar with these plugins enabled and '
			. 'this configuration." It is JCE\'s entire authorisation model — JceControllerPlugin::execute() '
			. 'gates every editor dialog on checkProfile() — so this list is also the list of who can do '
			. 'what in the editor. '
			. 'ORDER MATTERS AND FIRST MATCH WINS. classes/application.php:367 selects published profiles '
			. 'ordered by `ordering` ascending and returns the first one whose group/user, component, '
			. 'device and area tests all pass. A broad profile placed above a specific one shadows it '
			. 'completely. Each row here reports its position and any profile it shadows is visible '
			. 'simply by reading down the list. '
			. 'Filters: search (substring on name and description), published, area, group_id (matches '
			. 'the comma-separated `types` list), user_id (matches `users`), component (matches the '
			. 'comma-separated `components` list). Supports limit (default 50, max 200) and offset. '
			. 'Two silent-failure states are flagged per row: a profile with both `types` and `users` '
			. 'empty is skipped entirely by JCE (application.php:394) while still showing as published; '
			. 'and an empty `device` is treated as all three devices at runtime (application.php:434-441) '
			. 'even though the stored value is blank. '
			. 'params is NOT returned here — use get_jce_profile or get_jce_profile_params. '
			. 'Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'search'    => ['type' => 'string', 'description' => 'Case-insensitive substring match on name and description.'],
				'published' => ['type' => 'boolean', 'description' => 'true for published only, false for unpublished only. Omit for both.'],
				'area'      => ['type' => 'string', 'description' => 'both | site | admin, or the integer 0 | 1 | 2. Note area 0 means "both" and matches every request.'],
				'group_id'  => ['type' => 'integer', 'description' => 'Only profiles whose `types` list contains this #__usergroups id.'],
				'user_id'   => ['type' => 'integer', 'description' => 'Only profiles whose `users` list contains this #__users id.'],
				'component' => ['type' => 'string', 'description' => 'Only profiles whose `components` list contains this option string, e.g. com_content. Profiles with an empty `components` apply everywhere and are NOT matched by this filter.'],
				'limit'     => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'    => ['type' => 'integer', 'description' => 'Rows to skip.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->jceAdminBase() === null) {
			return $this->jceNotInstalledError();
		}

		if (!$this->jceTableExists()) {
			return $this->jceMissingTableError();
		}

		$table  = $this->db->quoteName($this->jceTable());
		$limit  = $this->jceLimit($arguments);
		$offset = $this->jceOffset($arguments);

		$where = [];

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$like = $this->db->quote('%' . $this->db->escape($search, true) . '%', false);
			$where[] = '(' . $this->db->quoteName('name') . ' LIKE ' . $like
				. ' OR ' . $this->db->quoteName('description') . ' LIKE ' . $like . ')';
		}

		if (\array_key_exists('published', $arguments)) {
			$where[] = $this->db->quoteName('published') . ' = ' . ((bool) $arguments['published'] ? 1 : 0);
		}

		if (\array_key_exists('area', $arguments) && trim((string) $arguments['area']) !== '') {
			$area = $this->jceNormaliseArea($arguments['area']);

			if ($area === null) {
				return ToolResult::json([
					'ok'    => false,
					'error' => sprintf(
						'Unrecognised area "%s". Use both, site or admin, or the integer 0, 1 or 2.',
						(string) $arguments['area']
					),
				], true);
			}

			$where[] = $this->db->quoteName('area') . ' = ' . $area;
		}

		// FIND_IN_SET is the right operator for these comma columns and is what
		// JCE's own admin list model uses.
		if (\array_key_exists('group_id', $arguments)) {
			$where[] = 'FIND_IN_SET(' . (int) $arguments['group_id'] . ', ' . $this->db->quoteName('types') . ') > 0';
		}

		if (\array_key_exists('user_id', $arguments)) {
			$where[] = 'FIND_IN_SET(' . (int) $arguments['user_id'] . ', ' . $this->db->quoteName('users') . ') > 0';
		}

		if (($component = trim((string) ($arguments['component'] ?? ''))) !== '') {
			$where[] = 'FIND_IN_SET(' . $this->db->quote($component) . ', ' . $this->db->quoteName('components') . ') > 0';
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		$total = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $table . $whereSql)->loadResult();

		$select = [
			$this->db->quoteName('id'),
			$this->db->quoteName('name'),
			$this->db->quoteName('description'),
			$this->db->quoteName('users'),
			$this->db->quoteName('types'),
			$this->db->quoteName('components'),
			$this->db->quoteName('area'),
			$this->db->quoteName('device'),
			$this->db->quoteName('plugins'),
			$this->db->quoteName('published'),
			$this->db->quoteName('ordering'),
			$this->db->quoteName('checked_out'),
			'LENGTH(' . $this->db->quoteName('rows') . ') AS rows_bytes',
			'LENGTH(' . $this->db->quoteName('params') . ') AS params_bytes',
			'LEFT(' . $this->db->quoteName('params') . ', 12) AS params_prefix',
		];

		$rows = $this->db->setQuery(
			'SELECT ' . implode(', ', $select) . ' FROM ' . $table . $whereSql
			. ' ORDER BY ' . $this->db->quoteName('ordering') . ' ASC, ' . $this->db->quoteName('id') . ' ASC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$profiles = [];

		foreach ($rows as $row) {
			$types = $this->jceSplitIntList($row['types']);
			$users = $this->jceSplitIntList($row['users']);

			$entry = [
				'id'          => (int) $row['id'],
				'name'        => (string) $row['name'],
				'description' => (string) $row['description'],
				'published'   => (int) $row['published'] === 1,
				'ordering'    => (int) $row['ordering'],
				'area'        => (int) $row['area'],
				'area_label'  => $this->jceAreaLabel((int) $row['area']),
				'user_groups' => $types,
				'users'       => $users,
				'components'  => $this->jceSplitList($row['components']),
				'devices'     => $this->jceSplitList($row['device']),
				'plugin_count' => \count($this->jceSplitList($row['plugins'])),
				'rows_bytes'  => (int) $row['rows_bytes'],
				'params_bytes' => (int) $row['params_bytes'],
			];

			if ($entry['components'] === []) {
				$entry['components_note'] = 'Empty `components` means the profile applies inside every '
					. 'component, not none.';
			}

			if ($entry['devices'] === []) {
				$entry['devices_effective'] = $this->jceEffectiveDevices($row['device']);
				$entry['devices_note'] = 'The stored `device` column is empty. At runtime JCE treats that '
					. 'as desktop,tablet,phone (application.php:434-441), so this profile matches all '
					. 'three — but the stored value is not what the JCE admin UI would have written. '
					. 'JceTableProfiles::check() only defaults `device` for NEW rows '
					. '(tables/profiles.php:64-72), so an update that blanked it left it blank.';
			}

			if ($types === [] && $users === []) {
				$entry['dead'] = true;
				$entry['dead_note'] = 'BOTH `types` and `users` are empty, so JCE skips this profile '
					. 'entirely during matching (application.php:394) even though it is '
					. ($entry['published'] ? 'published' : 'unpublished') . '. It matches nobody and '
					. 'there is nothing in the JCE admin UI that indicates this.';
			}

			if (\in_array((string) $row['params_prefix'], ['###AES128###', '###CTR128###', '###DEFUSE###'], true)) {
				$entry['params_encrypted'] = (string) $row['params_prefix'];
				$entry['params_encrypted_note'] = 'This profile\'s params are stored with the legacy '
					. $row['params_prefix'] . ' encryption. Reading them needs the per-site WF_SERVERKEY, '
					. 'and get_jce_profile_params will refuse rather than return a wrong answer.';
			}

			$checkedOut = (int) ($row['checked_out'] ?? 0);

			if ($checkedOut > 0) {
				$entry['checked_out_by'] = $checkedOut;
			}

			$profiles[] = $entry;
		}

		return ToolResult::json([
			'ok'       => true,
			'total'    => $total,
			'limit'    => $limit,
			'offset'   => $offset,
			'showing'  => \count($profiles),
			'ordered_by' => 'ordering ASC — the runtime evaluation order. First published match wins.',
			'profiles' => $profiles,
			'matching_note' => 'JCE resolves a profile per request by iterating published profiles in '
				. '`ordering` order and returning the first whose user-group/user, component, device and '
				. 'area tests all pass. Use resolve_jce_profile_for to see which profile a specific user '
				. 'would actually get in a specific context — reading down this list by hand gets the '
				. 'area rule wrong, because area 0 means "any" and short-circuits the comparison.',
			'guests_note' => $this->jceAllowProfileGuests()
				? 'The com_jce parameter allow_profile_guests is ON, so unauthenticated visitors are '
					. 'eligible for a profile if one matches the Public group. Check that deliberately.'
				: 'The com_jce parameter allow_profile_guests is OFF (the default), so guests are '
					. 'returned no profile at all regardless of what these rows say '
					. '(application.php:331-337).',
			'component' => $this->jceEditionNotice(),
		]);
	}
}
