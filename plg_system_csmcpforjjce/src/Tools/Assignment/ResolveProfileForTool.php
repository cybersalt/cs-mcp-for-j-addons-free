<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjce\Tools\Assignment;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceBootTrait;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * "Which profile would user X get, in context Y?" — answered by replaying
 * JCE's own matching algorithm read-only, and showing the working.
 *
 * This is a faithful re-implementation of `WFApplication::getProfiles()`
 * (`classes/application.php:311-471`), in order:
 *
 *   1. guest gate on the `allow_profile_guests` component param (`:331-337`)
 *   2. `SELECT * FROM #__wf_profiles WHERE published = 1 ORDER BY ordering ASC`
 *      (`:367`)
 *   3. `profile_groups_whitelist` intersected with the user's groups (`:386-387`)
 *   4. per row: skip when types and users are both empty (`:394`); group match,
 *      falling back to the user list (`:411-419`); component (`:422-431`);
 *      device (`:434-441`); area (`:444-446`); plugin (`:449-451`)
 *   5. first match wins (`:461-464`)
 *
 * What it CANNOT replay, and says so in every response:
 *
 *   - The three `onWf*` filter events. `onWfBeforeEditorProfileItem` and
 *     `onWfAfterEditorProfileItem` can veto any row by setting `$item = false`
 *     (`:403-408`, `:453-458`), and `onWfEditorProfileOptions` can rewrite the
 *     matching context outright (`:352`).
 *   - Consequently, Pro's `setup.custom` query-variable scoping, which is
 *     implemented as exactly such a veto
 *     (`CustomQueryTrait::onWfBeforeEditorProfileItem()`, `:329-372`). A profile
 *     scoped to `?view=form` will be reported as matching here and may not
 *     match in reality.
 *   - `isValidPlugin()`, the installed-plus-optional-checksum test applied
 *     before `checkProfile()` at dispatch (`controller/plugin.php:78-80`).
 */
final class ResolveProfileForTool extends AbstractTool
{
	use JceBootTrait;

	/**
	 * Plugins for which the runtime drops the plugin filter entirely.
	 *
	 * `WFApplication::isCorePlugin()`, `classes/application.php:218-221`.
	 */
	private const CORE_PLUGINS = [
		'core', 'autolink', 'cleanup', 'code', 'format', 'importcss', 'colorpicker', 'upload',
		'branding', 'inlinepopups', 'figure', 'ui', 'help',
	];

	public function getName(): string { return 'resolve_jce_profile_for'; }

	public function getDescription(): string
	{
		return 'Answer "which JCE profile would this user actually get, in this context?" by replaying '
			. 'JCE\'s own matching algorithm read-only and showing the working for every profile it '
			. 'considered. '
			. 'Supply user_id (or omit it for a guest), and optionally component (the `option` string, '
			. 'e.g. com_content), area (site | admin), device (desktop | tablet | phone) and plugin (a '
			. 'plugin name, to ask "would this user reach the image manager here?"). '
			. 'The response reports the winning profile plus a per-profile trace: which of the five '
			. 'tests each candidate passed and exactly which one rejected it. This is the tool to reach '
			. 'for when someone says "the editor toolbar is wrong for this user" — reading the profile '
			. 'list by hand gets it wrong, most often because area 0 means "any" and short-circuits the '
			. 'comparison, and because an empty `device` or `components` means "all" rather than "none". '
			. 'The user\'s groups are taken from Joomla\'s own getAuthorisedGroups(), so inherited '
			. 'groups are included exactly as JCE sees them. '
			. 'LIMITS, stated in every response: this cannot replay the three onWf* filter events, any '
			. 'of which can veto a profile or rewrite the matching context from a plugin '
			. '(application.php:352, :403-408, :453-458). The most important consequence is that Pro\'s '
			. '`setup.custom` query-variable scoping is implemented as exactly such a veto '
			. '(CustomQueryTrait:329-372), so a profile scoped to a particular request variable is '
			. 'reported as matching here and may not match in reality. Profiles using setup.custom are '
			. 'flagged individually. '
			. 'Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'user_id'   => ['type' => 'integer', 'description' => 'Joomla user id. Omit for an unauthenticated guest.'],
				'component' => ['type' => 'string', 'description' => 'The `option` string of the component the editor is being used in, e.g. com_content. Default com_content.'],
				'area'      => ['type' => 'string', 'description' => 'site or admin. Default admin. JCE derives this as getClientId() === 0 ? 1 : 2.'],
				'device'    => ['type' => 'string', 'description' => 'desktop, tablet or phone. Default desktop.'],
				'plugin'    => ['type' => 'string', 'description' => 'Optional plugin name (e.g. imgmanager). When given, a profile only matches if this name is in its `plugins` column — this is how JCE authorises each editor dialog.'],
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

		$userId = (int) ($arguments['user_id'] ?? 0);
		$isGuest = $userId <= 0;

		$component = trim((string) ($arguments['component'] ?? 'com_content')) ?: 'com_content';
		$device    = strtolower(trim((string) ($arguments['device'] ?? 'desktop'))) ?: 'desktop';
		$plugin    = trim((string) ($arguments['plugin'] ?? ''));

		if (!\in_array($device, $this->jceDeviceTokens(), true)) {
			return $this->jceUnknownDeviceError([$device]);
		}

		$areaInput = strtolower(trim((string) ($arguments['area'] ?? 'admin')));
		$area = match ($areaInput) {
			'', 'admin', 'administrator', 'back', 'backend' => 2,
			'site', 'front', 'frontend'                     => 1,
			default                                         => null,
		};

		if ($area === null) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'area must be "site" or "admin". JCE resolves it at runtime as '
					. '`$app->getClientId() === 0 ? 1 : 2` (classes/application.php:178), so there is no '
					. '"both" for a request — only for a profile.',
			], true);
		}

		$groups = [];
		$userName = null;

		if (!$isGuest) {
			$user = Factory::getUser($userId);

			if ((int) $user->id !== $userId) {
				return ToolResult::json([
					'ok'    => false,
					'error' => sprintf('No user with id %d.', $userId),
				], true);
			}

			$userName = (string) $user->username;
			$groups   = array_map('intval', $user->getAuthorisedGroups() ?: []);
		} else {
			// A guest is in the Public group, which JCE would see through
			// getAuthorisedGroups() on the guest user object.
			$groups = array_map('intval', Access::getGroupsByUser(0, true) ?: []);
		}

		$context = [
			'user_id'   => $isGuest ? null : $userId,
			'username'  => $userName,
			'is_guest'  => $isGuest,
			'groups'    => $groups,
			'component' => $component,
			'area'      => $area,
			'area_label' => $area === 1 ? 'site (front end)' : 'administrator',
			'device'    => $device,
			'plugin'    => $plugin === '' ? null : $plugin,
		];

		$limits = [
			'This replays classes/application.php:311-471 against the database. It cannot replay the '
				. 'onWfEditorProfileOptions, onWfBeforeEditorProfileItem or onWfAfterEditorProfileItem '
				. 'events, any of which can rewrite the context or veto a profile outright from a '
				. 'plugin (application.php:352, :403-408, :453-458).',
			'It also does not apply isValidPlugin(), the installed-plus-optional-SHA-256-checksum test '
				. 'that runs before checkProfile() when a dialog is actually dispatched '
				. '(controller/plugin.php:78-80).',
		];

		// The guest gate runs before anything else and returns null outright.
		if ($isGuest && !$this->jceAllowProfileGuests()) {
			return ToolResult::json([
				'ok'      => true,
				'context' => $context,
				'matched' => null,
				'result'  => 'NO PROFILE. The com_jce component parameter allow_profile_guests is off '
					. '(its default), and WFApplication::getProfiles() returns null for a guest before '
					. 'evaluating any profile at all (classes/application.php:331-337). No editor is '
					. 'rendered for unauthenticated visitors on this site, whatever the profiles say.',
				'limits'  => $limits,
				'component' => $this->jceEditionNotice(),
			]);
		}

		$whitelist = $this->jceGroupsWhitelist();
		$effectiveGroups = $whitelist === [] ? $groups : array_values(array_intersect($groups, $whitelist));

		if ($whitelist !== [] && $effectiveGroups === []) {
			$limits[] = 'Every one of this user\'s groups was removed by the com_jce '
				. 'profile_groups_whitelist parameter, so only a profile naming this user id in its '
				. '`users` column can still match.';
		}

		$query = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName($this->jceTable()))
			->where($this->db->quoteName('published') . ' = 1')
			->order($this->db->quoteName('ordering') . ' ASC');

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];

		$trace   = [];
		$matched = null;

		foreach ($rows as $row) {
			$evaluation = $this->evaluate($row, $effectiveGroups, $userId, $component, $device, $area, $plugin);

			$trace[] = $evaluation;

			if ($evaluation['matched'] && $matched === null) {
				$matched = [
					'id'       => (int) $row['id'],
					'name'     => (string) $row['name'],
					'ordering' => (int) $row['ordering'],
				];
			}
		}

		$response = [
			'ok'        => true,
			'context'   => $context,
			'matched'   => $matched,
			'result'    => $matched === null
				? 'NO PROFILE MATCHES. JCE renders no editor at all in this context — not a degraded '
					. 'one, none. There is no error message and nothing in the log.'
				: sprintf(
					'Profile "%s" (id %d, ordering %d) wins. First match in `ordering` order wins '
						. '(application.php:461-464); everything below it in the trace was never reached.',
					$matched['name'],
					$matched['id'],
					$matched['ordering']
				),
			'trace'     => $trace,
			'considered' => \count($rows),
			'whitelist_applied' => $whitelist,
			'limits'    => $limits,
			'component' => $this->jceEditionNotice(),
		];

		if ($plugin !== '' && \in_array(strtolower($plugin), self::CORE_PLUGINS, true)) {
			$response['plugin_note'] = sprintf(
				'"%s" is one of JCE\'s core plugins (application.php:218-221), for which the runtime '
					. 'DROPS the plugin filter entirely rather than requiring the name in `plugins`. '
					. 'The trace reflects that.',
				$plugin
			);
		}

		return ToolResult::json($response);
	}

	/**
	 * Run the five tests against one profile, in the vendor's order.
	 *
	 * @param array<string,mixed> $row
	 * @param array<int,int>      $groups
	 * @return array<string,mixed>
	 */
	private function evaluate(array $row, array $groups, int $userId, string $component, string $device, int $area, string $plugin): array
	{
		$types      = $this->jceSplitIntList($row['types'] ?? '');
		$users      = $this->jceSplitIntList($row['users'] ?? '');
		$components = $this->jceSplitList($row['components'] ?? '');
		$devices    = $this->jceEffectiveDevices($row['device'] ?? '');
		$rowArea    = (int) $row['area'];
		$plugins    = $this->jceSplitList($row['plugins'] ?? '');

		$result = [
			'id'       => (int) $row['id'],
			'name'     => (string) $row['name'],
			'ordering' => (int) $row['ordering'],
			'matched'  => false,
			'tests'    => [],
		];

		// :394 — a profile with no audience at all is skipped before anything else.
		if ($types === [] && $users === []) {
			$result['tests'][] = ['test' => 'audience', 'passed' => false,
				'why' => 'Both `types` and `users` are empty, so application.php:394 skips this row '
					. 'entirely. It matches nobody.'];

			return $result;
		}

		// :411-419 — group match, falling back to the individual user list.
		$groupHit = array_intersect($groups, $types) !== [];
		$userHit  = $userId > 0 && \in_array($userId, $users, true);

		if (!$groupHit && !$userHit) {
			$result['tests'][] = ['test' => 'audience', 'passed' => false,
				'why' => 'None of the user\'s groups (' . implode(', ', $groups) . ') is in `types` ('
					. implode(', ', $types) . '), and the user id is not in `users`.'];

			return $result;
		}

		$result['tests'][] = ['test' => 'audience', 'passed' => true,
			'why' => $groupHit
				? 'Matched by user group: ' . implode(', ', array_values(array_intersect($groups, $types))) . '.'
				: 'Matched by individual user id ' . $userId . ' in `users`. Note JCE only checks '
					. '`users` when the group test has already failed (application.php:411-419).'];

		// :422-431 — an empty components list matches everything.
		if ($components !== [] && !\in_array($component, $components, true)) {
			$result['tests'][] = ['test' => 'component', 'passed' => false,
				'why' => '`components` is restricted to ' . implode(', ', $components)
					. ' and the request component is ' . $component . '.'];

			return $result;
		}

		$result['tests'][] = ['test' => 'component', 'passed' => true,
			'why' => $components === []
				? '`components` is empty, which matches every component.'
				: $component . ' is in the `components` list.'];

		// :434-441 — an empty device list means all three.
		if (!\in_array($device, $devices, true)) {
			$result['tests'][] = ['test' => 'device', 'passed' => false,
				'why' => 'The profile covers ' . implode(', ', $devices) . ' and the request device is '
					. $device . '.'];

			return $result;
		}

		$result['tests'][] = ['test' => 'device', 'passed' => true,
			'why' => $device . ' is covered'
				. (trim((string) ($row['device'] ?? '')) === ''
					? ' (the stored `device` column is empty, which JCE treats as all three).'
					: '.')];

		// :444-446 — area 0 short-circuits the comparison.
		if ($rowArea !== 0 && $rowArea !== $area) {
			$result['tests'][] = ['test' => 'area', 'passed' => false,
				'why' => 'The profile is restricted to ' . $this->jceAreaLabel($rowArea)
					. ' and the request area is ' . ($area === 1 ? 'site' : 'administrator') . '.'];

			return $result;
		}

		$result['tests'][] = ['test' => 'area', 'passed' => true,
			'why' => $rowArea === 0
				? 'area is 0, which matches everything — the runtime test is `!empty($item->area) && ...` '
					. 'and 0 short-circuits it (application.php:444).'
				: 'The profile area matches the request area.'];

		// :449-451 — plugin filter, dropped entirely for core plugins.
		if ($plugin !== '') {
			$normalised = preg_replace('/^editor[-_]/', '', strtolower($plugin));

			if (\in_array($normalised, self::CORE_PLUGINS, true)) {
				$result['tests'][] = ['test' => 'plugin', 'passed' => true,
					'why' => '"' . $normalised . '" is a core plugin, for which application.php:218-221 '
						. 'drops the plugin filter entirely.'];
			} elseif (!\in_array($normalised, $plugins, true)) {
				$result['tests'][] = ['test' => 'plugin', 'passed' => false,
					'why' => '"' . $normalised . '" is not in this profile\'s `plugins` column, so '
						. 'JceControllerPlugin::execute() would refuse the dialog '
						. '(controller/plugin.php:78-80).'];

				return $result;
			} else {
				$result['tests'][] = ['test' => 'plugin', 'passed' => true,
					'why' => '"' . $normalised . '" is enabled on this profile.'];
			}
		}

		$result['matched'] = true;

		$decoded = $this->jceDecodeParams($row['params'] ?? '');

		if ($decoded['ok'] && \array_key_exists('setup', $decoded['params'])) {
			$result['caveat'] = 'This profile has a `setup` params key. Under Pro, '
				. 'CustomQueryTrait::onWfBeforeEditorProfileItem() (:329-372) reads `setup.custom` and '
				. 'sets $item = false — vetoing the profile — when the request query variables do not '
				. 'match. This resolver cannot replay that, so the profile may not actually match at '
				. 'runtime.';
		} elseif (!$decoded['ok'] && $decoded['encrypted']) {
			$result['caveat'] = 'This profile\'s params are encrypted, so it could not be checked for a '
				. '`setup.custom` query-variable veto.';
		}

		return $result;
	}
}
