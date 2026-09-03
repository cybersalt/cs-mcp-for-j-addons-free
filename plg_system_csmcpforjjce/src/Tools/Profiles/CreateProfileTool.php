<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjce\Tools\Profiles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceBootTrait;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceSafetyTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Create a new, empty-but-valid profile.
 *
 * This tool deliberately does NOT accept a `params` payload, a `rows` string or
 * a `plugins` list. A new profile is created assigned, unpublished and with an
 * empty toolbar; the caller then builds it up through set_jce_profile_toolbar
 * and set_jce_profile_params, both of which validate every token and refuse
 * every dangerous value.
 *
 * That split is the whole point. A single call that accepts an arbitrary
 * profile payload is exactly the primitive behind CVE-2026-48907's second
 * exploitation path — import a profile that permits .php uploads, then upload a
 * webshell. Field-level setters cost one extra round trip and remove the
 * primitive entirely.
 *
 * The write goes straight to the table rather than through
 * `JceModelProfile::save()`, for two reasons. First, `com_jce` is legacy
 * (non-namespaced) MVC, and booting its models from an MCP context on Joomla 6
 * is fragile. Second, the model's save() MERGES params rather than replacing
 * them (`models/profile.php:908`), so owning the write means owning the merge
 * semantics honestly rather than inheriting a surprise. The vendor's own
 * sanitisers from `prepareTable()` are applied here explicitly instead.
 */
final class CreateProfileTool extends AbstractTool
{
	use JceBootTrait;
	use JceSafetyTrait;

	public function getName(): string { return 'create_jce_profile'; }

	public function getDescription(): string
	{
		return 'Create a new JCE editor profile in #__wf_profiles. '
			. 'Required: name, and at least one of user_groups or users — a profile with both empty is '
			. 'skipped entirely by JCE at runtime (application.php:394) while still appearing healthy in '
			. 'the admin list, so it is refused here. '
			. 'Optional: description, components (empty means every component), area (both | site | '
			. 'admin, default both), devices (default desktop,tablet,phone), ordering (default '
			. 'MAX(ordering)+1, matching models/profile.php:656-668), published (default FALSE). '
			. 'The new profile is created UNPUBLISHED by default and with an EMPTY toolbar and no '
			. 'plugins. That is intentional: build it up afterwards with set_jce_profile_toolbar and '
			. 'set_jce_profile_params, which validate every button token against the installed catalogue '
			. 'and hard-refuse dangerous parameter values. This tool accepts no params, rows or plugins '
			. 'payload of any kind. '
			. 'WHY NO PAYLOAD: JCE ≤ 2.9.99.4 carried CVE-2026-48907, an unauthenticated RCE whose '
			. 'documented in-the-wild use was importing a profile that permitted .php uploads and then '
			. 'uploading a webshell. A create-from-blob tool is that same primitive. There is no profile '
			. 'import tool in this add-on and there will not be one. '
			. 'ORDERING MATTERS: JCE returns the FIRST published profile that matches, in `ordering` '
			. 'ascending order (application.php:367). A broad new profile with a low ordering shadows '
			. 'every more specific profile below it. The response reports which existing profiles this '
			. 'one would now sit above. '
			. 'Refuses entirely if the installed JCE is below 2.9.99.5.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'name'        => ['type' => 'string', 'description' => 'Profile name. Must be unique — JCE enforces uniqueness in PHP only, there is no DB constraint. Avoid the name "Default", which is magic (see the response note).'],
				'description' => ['type' => 'string', 'description' => 'Free text. Optional.'],
				'user_groups' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => '#__usergroups ids this profile applies to. Validated against the live table.'],
				'users'       => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Individual #__users ids. Validated against the live table. OR-ed with user_groups.'],
				'components'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Component option strings, e.g. ["com_content"]. Omit or empty for every component.'],
				'area'        => ['type' => 'string', 'description' => 'both | site | admin, or 0 | 1 | 2. Default both. Note that 0 ("both") short-circuits the runtime comparison and matches every request.'],
				'devices'     => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Any of desktop, tablet, phone. Default all three.'],
				'ordering'    => ['type' => 'integer', 'description' => 'Evaluation position, ascending. Default MAX(ordering)+1 (last).'],
				'published'   => ['type' => 'boolean', 'description' => 'Default false.'],
			],
			'required' => ['name'],
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

		$name = $this->requireString($arguments, 'name');

		if (($clash = $this->nameClash($name)) !== null) {
			return $clash;
		}

		$groups = $this->jceValidateGroupIds((array) ($arguments['user_groups'] ?? []));
		$users  = $this->jceValidateUserIds((array) ($arguments['users'] ?? []));

		$whitelisted = $this->jceApplyGroupsWhitelist($groups['valid']);

		if (($refusal = $this->jceAssertProfileReachable($whitelisted['effective'], $users['valid'])) !== null) {
			return $refusal;
		}

		$area = 0;

		if (\array_key_exists('area', $arguments) && trim((string) $arguments['area']) !== '') {
			$area = $this->jceNormaliseArea($arguments['area']);

			if ($area === null) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Unrecognised area. Use both, site or admin, or the integer 0, 1 or 2.',
				], true);
			}
		}

		$devices = $this->normaliseDevices($arguments['devices'] ?? null);

		if ($devices instanceof ToolResult) {
			return $devices;
		}

		$published = (bool) ($arguments['published'] ?? false);

		$ordering = \array_key_exists('ordering', $arguments)
			? (int) $arguments['ordering']
			: $this->nextOrdering();

		$values = [
			'name'        => $name,
			'description' => (string) ($arguments['description'] ?? ''),
			'users'       => $this->jceJoinList($users['valid']),
			'types'       => $this->jceJoinList($whitelisted['effective']),
			'components'  => $this->jceJoinList(array_map('strval', (array) ($arguments['components'] ?? []))),
			'area'        => $area,
			'device'      => $this->jceJoinList($devices),
			// Empty toolbar and no plugins by design — see the class docblock.
			'rows'        => '',
			'plugins'     => '',
			'published'   => $published ? 1 : 0,
			'ordering'    => $ordering,
			// tables/profiles.php:74-77 defaults params to '{}' for new rows.
			'params'      => '{}',
			'checked_out' => 0,
		];

		$columns = $this->jceColumns();
		$now     = Factory::getDate()->toSql();

		if (\in_array('created', $columns, true)) {
			$values['created'] = $now;
		}

		if (\in_array('created_by', $columns, true)) {
			$values['created_by'] = (int) $actor->id;
		}

		if (\in_array('modified', $columns, true)) {
			$values['modified'] = $now;
		}

		if (\in_array('modified_by', $columns, true)) {
			$values['modified_by'] = (int) $actor->id;
		}

		// SQL Server declares checked_out_time NOT NULL with no default, unlike
		// MySQL and PostgreSQL where it is nullable.
		if (\in_array('checked_out_time', $columns, true)
			&& str_contains(strtolower($this->db->getName()), 'sqlsrv')) {
			$values['checked_out_time'] = $now;
		}

		$names  = [];
		$quoted = [];

		foreach ($values as $column => $value) {
			if (!\in_array($column, $columns, true)) {
				continue;
			}

			$names[]  = $this->db->quoteName($column);
			$quoted[] = \is_int($value) ? (string) $value : $this->db->quote((string) $value);
		}

		$query = $this->db->getQuery(true)
			->insert($this->db->quoteName($this->jceTable()))
			->columns($names)
			->values(implode(',', $quoted));

		$this->db->setQuery($query)->execute();

		$id = (int) $this->db->insertid();

		$response = [
			'ok'         => true,
			'created'    => true,
			'profile_id' => $id,
			'name'       => $name,
			'published'  => $published,
			'ordering'   => $ordering,
			'area'       => $area,
			'area_label' => $this->jceAreaLabel($area),
			'assignment' => [
				'user_groups' => $whitelisted['effective'],
				'users'       => $users['valid'],
				'components'  => $this->jceSplitList($values['components']),
				'devices'     => $devices,
			],
			'toolbar_note' => 'The profile was created with an EMPTY toolbar and no enabled plugins. It '
				. 'will show no editor buttons until set_jce_profile_toolbar is called. This is '
				. 'deliberate — see the tool description.',
			'next_steps' => [
				'set_jce_profile_toolbar to define rows and plugins',
				'set_jce_profile_params for per-plugin and editor configuration',
				'set_jce_profile_state to publish it once it is correct',
			],
			'component'  => $this->jceEditionNotice(),
		];

		$warnings = [];

		if ($groups['invalid'] !== []) {
			$warnings[] = 'Dropped user group id(s) ' . implode(', ', $groups['invalid'])
				. ' — no such row in #__usergroups.';
		}

		if ($users['invalid'] !== []) {
			$warnings[] = 'Dropped user id(s) ' . implode(', ', $users['invalid'])
				. ' — no such row in #__users. JCE validates `users` against the live table on its own '
				. 'saves too (models/profile.php:622-641), silently.';
		}

		if ($whitelisted['warning'] !== null) {
			$warnings[] = $whitelisted['warning'];
		}

		if (!$published) {
			$warnings[] = 'Created UNPUBLISHED. JCE only considers published profiles '
				. '(application.php:367), so this profile has no effect yet.';
		}

		if (strcasecmp($name, 'Default') === 0) {
			$warnings[] = 'The name "Default" is magic in JCE: JcePluginsHelper::postInstall() registers '
				. 'every newly installed JCE editor plugin into the profile matched by '
				. '`WHERE name = \'Default\' OR id = 1` (helpers/plugins.php:408). Having two candidates '
				. 'makes which one receives new plugins depend on row order.';
		}

		$shadowed = $this->shadowedProfiles($ordering, $id);

		if ($shadowed !== []) {
			$response['shadow_warning'] = sprintf(
				'This profile sits at ordering %d, above %d existing published profile(s): %s. JCE '
					. 'returns the FIRST match in ordering order, so once this profile is published, any '
					. 'request it matches will never reach those.',
				$ordering,
				\count($shadowed),
				implode(', ', array_map(static fn ($p) => $p['name'] . ' (#' . $p['id'] . ')', $shadowed))
			);
			$response['shadowed_profiles'] = $shadowed;
		}

		if ($warnings !== []) {
			$response['warnings'] = $warnings;
		}

		return ToolResult::json($response);
	}

	private function nameClash(string $name): ?ToolResult
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName('id'))
			->from($this->db->quoteName($this->jceTable()))
			->where($this->db->quoteName('name') . ' = ' . $this->db->quote($name));

		$existing = (int) $this->db->setQuery($query, 0, 1)->loadResult();

		if ($existing === 0) {
			return null;
		}

		return ToolResult::json([
			'ok'    => false,
			'error' => sprintf(
				'A profile named "%s" already exists (id %d). There is no unique constraint on the '
					. 'column — JCE enforces uniqueness only in PHP, by incrementing the name '
					. '(helpers/profiles.php:329-333). This tool refuses instead of quietly creating '
					. '"%s (2)", because a caller that asked for a specific name should be told it was '
					. 'taken rather than discovering a differently named profile later.',
				$name,
				$existing,
				$name
			),
			'existing_profile_id' => $existing,
		], true);
	}

	/** @return array<int,string>|ToolResult */
	private function normaliseDevices(mixed $raw): array|ToolResult
	{
		if ($raw === null) {
			// tables/profiles.php:64-67 defaults new rows to all three. We write
			// it explicitly rather than leaving the column blank, because
			// check() only applies that default when the row has no id.
			return $this->jceDeviceTokens();
		}

		$result = $this->jceNormaliseDevices($raw);

		if ($result['unknown'] !== []) {
			return $this->jceUnknownDeviceError($result['unknown']);
		}

		return $result['tokens'] === [] ? $this->jceDeviceTokens() : $result['tokens'];
	}

	private function nextOrdering(): int
	{
		$max = $this->db->setQuery(
			'SELECT MAX(' . $this->db->quoteName('ordering') . ') FROM '
			. $this->db->quoteName($this->jceTable())
		)->loadResult();

		return (int) $max + 1;
	}

	/** @return array<int,array<string,mixed>> */
	private function shadowedProfiles(int $ordering, int $excludeId): array
	{
		$query = $this->db->getQuery(true)
			->select([$this->db->quoteName('id'), $this->db->quoteName('name'), $this->db->quoteName('ordering')])
			->from($this->db->quoteName($this->jceTable()))
			->where($this->db->quoteName('published') . ' = 1')
			->where($this->db->quoteName('ordering') . ' > ' . $ordering)
			->where($this->db->quoteName('id') . ' <> ' . $excludeId)
			->order($this->db->quoteName('ordering') . ' ASC');

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];

		return array_map(static fn ($r) => [
			'id'       => (int) $r['id'],
			'name'     => (string) $r['name'],
			'ordering' => (int) $r['ordering'],
		], $rows);
	}
}
