<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjce\Tools\Assignment;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceBootTrait;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceSafetyTrait;
use Joomla\CMS\User\User;

/**
 * Change who a profile applies to, and where.
 *
 * Every supplied field REPLACES its column outright; omitted fields are left
 * alone. That is deliberate: `types`, `users`, `components` and `device` are
 * flat comma lists with no ordering semantics, and an add/remove interface over
 * them invites the caller to lose track of the current state. Read with
 * get_jce_profile_assignment, decide, write the whole list.
 *
 * Three things this tool refuses or reports rather than performing quietly:
 *
 *   - Refuses to leave both `types` and `users` empty, which silently disables
 *     the profile (`classes/application.php:394`).
 *   - Reports groups dropped by the `profile_groups_whitelist` component
 *     parameter, which JCE applies silently on its own saves
 *     (`models/profile.php:612-616`).
 *   - Reports the privilege change when the assignment WIDENS on a profile
 *     whose params already permit uploads or relax content filtering. Widening
 *     an existing permissive profile is how a benign-looking assignment edit
 *     becomes a privilege escalation.
 */
final class SetProfileAssignmentTool extends AbstractTool
{
	use JceBootTrait;
	use JceSafetyTrait;

	public function getName(): string { return 'set_jce_profile_assignment'; }

	public function getDescription(): string
	{
		return 'Set which user groups, users, components, application area and devices a JCE profile '
			. 'applies to. '
			. 'Every field you supply REPLACES the whole column; fields you omit are untouched. There '
			. 'is no add/remove mode — read the current state with get_jce_profile_assignment first. '
			. 'Fields: user_groups (#__usergroups ids), users (#__users ids), components (option '
			. 'strings such as com_content; an empty array means EVERY component), area (both | site | '
			. 'admin), devices (any of desktop, tablet, phone; an empty array is written as all three '
			. 'rather than left blank, because JceTableProfiles::check() only supplies that default for '
			. 'NEW rows). '
			. 'Group and user ids are validated against the live tables and unknown ids are dropped '
			. 'with a report — JCE does the same on its own saves (models/profile.php:608-641) but says '
			. 'nothing. Component option strings are checked against #__extensions and unknown ones are '
			. 'reported but kept, because a component may legitimately be installed later. '
			. 'REFUSES to leave both user_groups and users empty: JCE skips such a profile entirely '
			. 'during matching (application.php:394) while it still shows as published, which is a '
			. 'silent disable. Use set_jce_profile_state if switching the profile off is the intent. '
			. 'WARNS when the change widens the audience of a profile whose params already permit '
			. 'uploads or relax content filtering — that combination is how an innocuous-looking '
			. 'assignment edit becomes a privilege escalation. '
			. 'Refuses entirely if the installed JCE is below 2.9.99.5 (CVE-2026-48907).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'          => ['type' => 'integer', 'description' => 'The #__wf_profiles id.'],
				'user_groups' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Replaces `types`. #__usergroups ids.'],
				'users'       => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Replaces `users`. #__users ids. OR-ed with user_groups.'],
				'components'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Replaces `components`. Option strings. Empty array = every component.'],
				'area'        => ['type' => 'string', 'description' => 'both | site | admin, or 0 | 1 | 2. Note 0 ("both") matches every request.'],
				'devices'     => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Replaces `device`. Any of desktop, tablet, phone. Empty array is stored as all three.'],
			],
			'required' => ['id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->jceAdminBase() === null) {
			return $this->jceNotInstalledError();
		}

		if (!$this->jceTableExists()) {
			return $this->jceMissingTableError();
		}

		if (($refusal = $this->jceRequireWritableVersion()) !== null) {
			return $refusal;
		}

		$id  = $this->requirePositiveInt($arguments, 'id');
		$row = $this->jceProfileRow($id);

		if ($row === null) {
			return $this->jceProfileNotFoundError($id);
		}

		$currentTypes = $this->jceSplitIntList($row['types'] ?? '');
		$currentUsers = $this->jceSplitIntList($row['users'] ?? '');

		$updates  = [];
		$warnings = [];
		$reports  = [];

		$newTypes = $currentTypes;
		$newUsers = $currentUsers;

		if (\array_key_exists('user_groups', $arguments)) {
			$validated = $this->jceValidateGroupIds((array) $arguments['user_groups']);

			if ($validated['invalid'] !== []) {
				$warnings[] = 'Dropped user group id(s) ' . implode(', ', $validated['invalid'])
					. ' — no such row in #__usergroups.';
			}

			$capped = $this->jceApplyGroupsWhitelist($validated['valid']);

			if ($capped['warning'] !== null) {
				$warnings[] = $capped['warning'];
			}

			$newTypes = $capped['effective'];
			$updates['types'] = $this->jceJoinList($newTypes);
		}

		if (\array_key_exists('users', $arguments)) {
			$validated = $this->jceValidateUserIds((array) $arguments['users']);

			if ($validated['invalid'] !== []) {
				$warnings[] = 'Dropped user id(s) ' . implode(', ', $validated['invalid'])
					. ' — no such row in #__users. JCE performs the same live-table validation on its '
					. 'own saves (models/profile.php:622-641) and reports nothing.';
			}

			$newUsers = $validated['valid'];
			$updates['users'] = $this->jceJoinList($newUsers);
		}

		if (($refusal = $this->jceAssertProfileReachable($newTypes, $newUsers)) !== null) {
			return $refusal;
		}

		if (\array_key_exists('components', $arguments)) {
			$components = [];

			foreach ((array) $arguments['components'] as $option) {
				$option = trim((string) $option);

				if ($option !== '') {
					$components[] = $option;
				}
			}

			$components = array_values(array_unique($components));
			$unknown    = $this->unknownComponents($components);

			if ($unknown !== []) {
				$warnings[] = 'Component option(s) ' . implode(', ', $unknown) . ' are not present in '
					. '#__extensions on this site. They have been written anyway — a component may be '
					. 'installed later, and JCE simply never matches an option string that does not '
					. 'occur in a request — but check for typos.';
			}

			if ($components === []) {
				$reports[] = 'components was set to an empty list, which means the profile now applies '
					. 'inside EVERY component. That is the widest possible component scope, not the '
					. 'narrowest.';
			}

			$updates['components'] = $this->jceJoinList($components);
		}

		if (\array_key_exists('area', $arguments) && trim((string) $arguments['area']) !== '') {
			$area = $this->jceNormaliseArea($arguments['area']);

			if ($area === null) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Unrecognised area. Use both, site or admin, or the integer 0, 1 or 2.',
				], true);
			}

			$updates['area'] = $area;

			if ($area === 0) {
				$reports[] = 'area was set to 0 ("both"). At runtime the test is '
					. '`!empty($item->area) && (int) $item->area != $vars[area]` '
					. '(classes/application.php:444), so 0 short-circuits and the profile matches every '
					. 'request in both the site and the administrator.';
			}
		}

		if (\array_key_exists('devices', $arguments)) {
			$devices = $this->jceNormaliseDevices($arguments['devices']);

			if ($devices['unknown'] !== []) {
				return $this->jceUnknownDeviceError($devices['unknown']);
			}

			$tokens = $devices['tokens'] === [] ? $this->jceDeviceTokens() : $devices['tokens'];

			if ($devices['tokens'] === []) {
				$reports[] = 'devices was empty, so the explicit list desktop,tablet,phone was written '
					. 'rather than blanking the column. A blank `device` behaves identically at runtime '
					. '(application.php:434-441) but is not what the JCE admin UI would store, and '
					. 'JceTableProfiles::check() only fills that default in for NEW rows.';
			}

			$updates['device'] = $this->jceJoinList($tokens);
		}

		if ($updates === []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Nothing to update. Supply at least one of user_groups, users, components, '
					. 'area or devices.',
			], true);
		}

		$widening = $this->assessWidening($currentTypes, $currentUsers, $newTypes, $newUsers, $row);

		$updates += $this->jceModifiedColumns((int) $actor->id);

		$set = [];

		foreach ($updates as $column => $value) {
			$set[] = $this->db->quoteName($column) . ' = '
				. (\is_int($value) ? (string) $value : $this->db->quote((string) $value));
		}

		$query = $this->db->getQuery(true)
			->update($this->db->quoteName($this->jceTable()))
			->set($set)
			->where($this->db->quoteName('id') . ' = ' . $id);

		$this->db->setQuery($query)->execute();

		$after = $this->jceProfileRow($id) ?? [];

		$response = [
			'ok'         => true,
			'profile_id' => $id,
			'name'       => (string) $row['name'],
			'updated'    => array_values(array_diff(array_keys($updates), ['modified', 'modified_by'])),
			'assignment_now' => [
				'user_groups' => $this->jceDescribeGroups($this->jceSplitIntList($after['types'] ?? '')),
				'users'       => $this->jceSplitIntList($after['users'] ?? ''),
				'components'  => $this->jceSplitList($after['components'] ?? ''),
				'area'        => (int) ($after['area'] ?? 0),
				'area_label'  => $this->jceAreaLabel((int) ($after['area'] ?? 0)),
				'devices'     => $this->jceSplitList($after['device'] ?? ''),
			],
			'published'  => (int) $row['published'] === 1,
			'component'  => $this->jceEditionNotice(),
		];

		if ($widening !== null) {
			$response['privilege_warning'] = $widening;
		}

		if ($reports !== []) {
			$response['notes'] = $reports;
		}

		if ($warnings !== []) {
			$response['warnings'] = $warnings;
		}

		if ((int) $row['published'] !== 1) {
			$response['notes'][] = 'This profile is unpublished, so none of this takes effect until it '
				. 'is published with set_jce_profile_state.';
		}

		return ToolResult::json($response);
	}

	/**
	 * Flag a widening assignment on a profile that already carries permissive
	 * configuration.
	 *
	 * The map's §7.3 item 7: widening `types`/`users` on a profile with a
	 * permissive params set is a privilege escalation even though the write
	 * itself touches nothing security-related.
	 *
	 * @param array<int,int>      $wasTypes
	 * @param array<int,int>      $wasUsers
	 * @param array<int,int>      $nowTypes
	 * @param array<int,int>      $nowUsers
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>|null
	 */
	private function assessWidening(array $wasTypes, array $wasUsers, array $nowTypes, array $nowUsers, array $row): ?array
	{
		$addedGroups = array_values(array_diff($nowTypes, $wasTypes));
		$addedUsers  = array_values(array_diff($nowUsers, $wasUsers));

		if ($addedGroups === [] && $addedUsers === []) {
			return null;
		}

		$decoded = $this->jceDecodeParams($row['params'] ?? '');

		if (!$decoded['ok']) {
			return [
				'added_user_groups' => $addedGroups,
				'added_users'       => $addedUsers,
				'params_readable'   => false,
				'message'           => 'This profile\'s audience was widened, but its params could not '
					. 'be decoded (' . ($decoded['encrypted'] ? 'encrypted' : 'invalid JSON') . '), so '
					. 'the capabilities just granted could not be assessed. Inspect the profile in the '
					. 'JCE admin UI before relying on this change.',
			];
		}

		$flat        = $this->jceFlattenParams($decoded['params']);
		$capabilities = [];

		foreach ($flat as $path => $value) {
			if (preg_match('/(^|\.)upload$/', $path) === 1 && $this->jceNormaliseFlag($value) === '1') {
				$capabilities[] = $path . ' = 1 (file upload permitted)';
			}

			if (preg_match('/(^|\.)(file_delete|folder_delete|file_rename|file_move|folder_new)$/', $path) === 1
				&& $this->jceNormaliseFlag($value) === '1') {
				$capabilities[] = $path . ' = 1 (file management permitted)';
			}

			if (\in_array($path, ['editor.allow_php', 'editor.allow_javascript', 'editor.allow_event_attributes'], true)
				&& $this->jceNormaliseFlag($value) === '1') {
				$capabilities[] = $path . ' = 1 (content filtering relaxed)';
			}

			if ($path === 'editor.validate_mimetype' && $this->jceNormaliseFlag($value) === '0'
				&& \array_key_exists($path, $flat)) {
				$capabilities[] = $path . ' = 0 (upload MIME validation disabled)';
			}
		}

		if ($capabilities === []) {
			return null;
		}

		return [
			'added_user_groups' => $this->jceDescribeGroups($addedGroups),
			'added_users'       => $addedUsers,
			'capabilities_now_granted' => array_values(array_unique($capabilities)),
			'message' => 'This write WIDENED the audience of a profile that already carries permissive '
				. 'configuration. Everyone newly added now has the capabilities listed above. The write '
				. 'itself changed no security setting, which is exactly what makes this class of change '
				. 'easy to miss: the escalation is in who reaches the existing settings. Review the '
				. 'profile\'s params with get_jce_profile_params, and consider whether the new groups '
				. 'need their own, narrower profile instead.',
		];
	}

	/** @return array<int,string> */
	private function unknownComponents(array $options): array
	{
		if ($options === []) {
			return [];
		}

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName('element'))
			->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
			->whereIn($this->db->quoteName('element'), $options, \Joomla\Database\ParameterType::STRING);

		$found = $this->db->setQuery($query)->loadColumn() ?: [];

		return array_values(array_diff($options, array_map('strval', $found)));
	}
}
