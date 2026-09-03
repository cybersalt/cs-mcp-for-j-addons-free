<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjce\Tools\Assignment;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceBootTrait;
use Joomla\CMS\User\User;

/**
 * Who a profile applies to, and where — with the runtime rules spelled out
 * rather than left for the caller to infer from raw columns.
 *
 * Five independent tests decide whether a profile matches a request
 * (`WFApplication::getProfiles()`, `classes/application.php:389-465`), and
 * three of them have a counter-intuitive empty case:
 *
 *   `components` empty  → matches EVERY component, not none
 *   `device` empty      → matches all three devices, not none
 *   `area` = 0          → matches both site and administrator, because the test
 *                         is `!empty($item->area) && ...` and 0 short-circuits
 *
 * while the fourth has the opposite one:
 *
 *   `types` AND `users` both empty → the profile is SKIPPED entirely
 *
 * The response therefore reports stored and effective values separately.
 */
final class GetProfileAssignmentTool extends AbstractTool
{
	use JceBootTrait;

	public function getName(): string { return 'get_jce_profile_assignment'; }

	public function getDescription(): string
	{
		return 'Report exactly who a JCE profile applies to and in what contexts: user groups, '
			. 'individual users, components, application area and devices. '
			. 'Returns both the STORED column values and the EFFECTIVE ones, because three of the five '
			. 'matching tests treat "empty" as "everything": an empty `components` list matches every '
			. 'component, an empty `device` list matches desktop, tablet and phone '
			. '(classes/application.php:434-441), and area 0 matches both the site and the administrator '
			. 'because the runtime test is `!empty($item->area) && (int) $item->area != $vars[area]` '
			. '(application.php:444), which 0 short-circuits. Meanwhile the fourth test goes the other '
			. 'way: a profile with BOTH `types` and `users` empty is skipped entirely '
			. '(application.php:394) and matches nobody. '
			. 'User-group ids are resolved to their titles, and ids that no longer exist in '
			. '#__usergroups are flagged — JCE cleans `types` on its own saves but never repairs an '
			. 'existing row, so a deleted group can linger in the column indefinitely. '
			. 'Also reports the two com_jce component parameters that override all of this: '
			. 'allow_profile_guests, which when off denies guests any profile regardless of assignment '
			. '(application.php:331-337), and profile_groups_whitelist, a hard cap added in 2.9.99.7 '
			. 'that is intersected with the user\'s groups during matching and with `types` on save. '
			. 'Read-only. Use resolve_jce_profile_for to answer "which profile does user X actually get".';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => ['type' => 'integer', 'description' => 'The #__wf_profiles id.'],
			],
			'required' => ['id'],
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

		$id  = $this->requirePositiveInt($arguments, 'id');
		$row = $this->jceProfileRow($id);

		if ($row === null) {
			return $this->jceProfileNotFoundError($id);
		}

		$types      = $this->jceSplitIntList($row['types'] ?? '');
		$users      = $this->jceSplitIntList($row['users'] ?? '');
		$components = $this->jceSplitList($row['components'] ?? '');
		$devices    = $this->jceSplitList($row['device'] ?? '');
		$area       = (int) $row['area'];
		$whitelist  = $this->jceGroupsWhitelist();

		$groupDetail = $this->jceDescribeGroups($types);
		$staleGroups = array_values(array_filter(
			array_map(static fn ($g) => $g['exists'] ? null : $g['id'], $groupDetail)
		));

		$response = [
			'ok'         => true,
			'profile_id' => $id,
			'name'       => (string) $row['name'],
			'published'  => (int) $row['published'] === 1,
			'ordering'   => (int) $row['ordering'],
			'assignment' => [
				'user_groups' => [
					'stored'    => $types,
					'resolved'  => $groupDetail,
					'effective' => $whitelist === [] ? $types : array_values(array_intersect($types, $whitelist)),
				],
				'users' => [
					'stored' => $users,
					'note'   => 'Individual users are OR-ed with user groups: JCE first tests group '
						. 'membership, and only if that fails does it check whether the user id is in '
						. 'this list (classes/application.php:411-419).',
				],
				'components' => [
					'stored'    => $components,
					'effective' => $components === [] ? 'all components' : $components,
					'note'      => $components === []
						? 'Empty means the profile applies inside EVERY component. This is the common '
							. 'case and is not a misconfiguration.'
						: 'The profile applies only inside these components. The value compared at '
							. 'runtime is the resolved `option`; inside com_jce itself the real component '
							. 'is recovered from the `context` request variable, or the literal string '
							. '"mediafield" for a media custom field (application.php:164-175).',
				],
				'area' => [
					'stored' => $area,
					'label'  => $this->jceAreaLabel($area),
					'note'   => $area === 0
						? 'Area 0 matches BOTH the site and the administrator. The runtime test is '
							. '`if (!empty($item->area) && (int) $item->area != $vars[area]) continue;` '
							. '(application.php:444), and 0 is empty, so the comparison never runs. '
							. 'Writing 0 intending "neither" gives you "both".'
						: 'The profile applies only in the ' . ($area === 1 ? 'site (front end)' : 'administrator')
							. '. JCE derives the request area as `$app->getClientId() === 0 ? 1 : 2` '
							. '(application.php:178).',
				],
				'devices' => [
					'stored'    => $devices,
					'effective' => $this->jceEffectiveDevices($row['device'] ?? ''),
					'note'      => $devices === []
						? 'The stored column is empty, which JCE treats as all three devices at runtime '
							. '(application.php:434-441). JceTableProfiles::check() only supplies the '
							. 'desktop,tablet,phone default for NEW rows (tables/profiles.php:64-67), so '
							. 'an update that blanked it left it blank. Writing the explicit list is '
							. 'preferable — set_jce_profile_assignment does.'
						: 'WFDeviceDetect resolves each request to exactly one of desktop, tablet or '
							. 'phone (application.php:181-191).',
				],
			],
			'component_overrides' => [
				'allow_profile_guests' => $this->jceAllowProfileGuests(),
				'allow_profile_guests_note' => $this->jceAllowProfileGuests()
					? 'ON. Unauthenticated visitors are eligible for a profile if one matches the Public '
						. 'group. Verify that is deliberate — it means guests can reach editor dialogs.'
					: 'OFF (the default). Guests are returned no profile at all, before any of the '
						. 'matching above is evaluated (application.php:331-337).',
				'profile_groups_whitelist' => $whitelist,
				'profile_groups_whitelist_note' => $whitelist === []
					? 'Not set, so no cap is applied. This parameter was added in 2.9.99.7 as part of '
						. 'the CVE-2026-48907 remediation and is worth setting on a site where only a '
						. 'few groups should ever have an editor profile.'
					: 'SET. It is intersected with the user\'s groups during matching '
						. '(application.php:386-387) AND with incoming `types` on every save '
						. '(models/profile.php:612-616), silently. Groups outside it can never receive '
						. 'this profile no matter what the `types` column says.',
			],
			'component' => $this->jceEditionNotice(),
		];

		$warnings = [];

		if ($types === [] && $users === []) {
			$warnings[] = 'DEAD PROFILE: both `types` and `users` are empty, so JCE skips this row '
				. 'entirely during matching (application.php:394). It applies to nobody regardless of '
				. 'its published state, and nothing in the JCE admin UI indicates this.';
		}

		if ($staleGroups !== []) {
			$warnings[] = 'The `types` column references user group id(s) '
				. implode(', ', $staleGroups) . ' that no longer exist in #__usergroups. Harmless — '
				. 'they simply never match — but they are dead weight, and on a site that has been '
				. 'through a group reorganisation they can mean the profile now covers nobody it was '
				. 'meant to.';
		}

		if ($whitelist !== [] && array_intersect($types, $whitelist) === [] && $types !== []) {
			$warnings[] = 'None of this profile\'s user groups are in the com_jce '
				. 'profile_groups_whitelist, so the intersection at application.php:386-387 is empty '
				. 'and the profile can never match through group membership. Only the `users` list, if '
				. 'any, can still reach it.';
		}

		if ($warnings !== []) {
			$response['warnings'] = $warnings;
		}

		return ToolResult::json($response);
	}
}
