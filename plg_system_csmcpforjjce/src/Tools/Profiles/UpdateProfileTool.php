<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjce\Tools\Profiles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceBootTrait;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceSafetyTrait;
use Joomla\CMS\User\User;

/**
 * Update a profile's identity fields: name, description and ordering.
 *
 * Deliberately narrow. Assignment lives in set_jce_profile_assignment, the
 * toolbar in set_jce_profile_toolbar, configuration in set_jce_profile_params
 * and publication in set_jce_profile_state. Each of those has its own
 * validation and its own refusals, and folding them into one "update anything"
 * call would both weaken the checks and produce a tool whose description nobody
 * could write honestly.
 */
final class UpdateProfileTool extends AbstractTool
{
	use JceBootTrait;
	use JceSafetyTrait;

	public function getName(): string { return 'update_jce_profile'; }

	public function getDescription(): string
	{
		return 'Rename a JCE profile, change its description, or move it in the evaluation order. '
			. 'Supply the profile id plus any of name, description, ordering. At least one is required. '
			. 'ORDERING IS THE CONSEQUENTIAL ONE. JCE evaluates published profiles in `ordering` '
			. 'ascending and returns the FIRST match (classes/application.php:367, :461-464), so moving '
			. 'a profile up can silently take users away from a more specific profile below it. The '
			. 'response lists exactly which published profiles this one now sits above and below. '
			. '`ordering` is not auto-compacted by JCE and duplicate values are legal — ties are broken '
			. 'by whatever order the database returns, which is not deterministic. Use '
			. 'reorder_jce_profiles to renumber the whole set cleanly. '
			. 'RENAMING "Default" IS A TRAP and is refused. JcePluginsHelper::postInstall() registers '
			. 'every newly installed JCE editor plugin into the profile matched by '
			. '`WHERE name = \'Default\' OR id = 1` (helpers/plugins.php:408), and removeFromProfile() '
			. 'unregisters from the same row. Rename it and every future JCE plugin install silently '
			. 'fails to add its button. '
			. 'This tool does NOT touch assignment, the toolbar, plugins or params — use the dedicated '
			. 'tools for those. '
			. 'Refuses entirely if the installed JCE is below 2.9.99.5 (CVE-2026-48907).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'          => ['type' => 'integer', 'description' => 'The #__wf_profiles id.'],
				'name'        => ['type' => 'string', 'description' => 'New name. Must be unique. Renaming the profile named "Default" is refused.'],
				'description' => ['type' => 'string', 'description' => 'New description. Pass an empty string to clear it.'],
				'ordering'    => ['type' => 'integer', 'description' => 'New evaluation position, ascending. Lower is evaluated first and wins.'],
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

		$updates  = [];
		$warnings = [];

		if (\array_key_exists('name', $arguments)) {
			$name = trim((string) $arguments['name']);

			if ($name === '') {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'name cannot be empty. Omit it to leave the name unchanged.',
				], true);
			}

			if (strcasecmp((string) $row['name'], 'Default') === 0 && strcasecmp($name, 'Default') !== 0) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'REFUSED. This profile is named "Default", which JCE treats as a magic '
						. 'name. JcePluginsHelper::postInstall() registers every newly installed JCE '
						. 'editor plugin into the profile matched by `WHERE name = \'Default\' OR id = 1` '
						. '(helpers/plugins.php:408), and uninstall removes it from the same row. '
						. 'Renaming it makes those hooks silently no-op: new plugins install fine but '
						. 'never get a toolbar button, with nothing logged anywhere. If you want a '
						. 'differently named primary profile, duplicate this one, rename the copy, and '
						. 'leave "Default" in place.',
					'profile_id' => $id,
				], true);
			}

			if ($name !== (string) $row['name'] && ($clash = $this->nameTakenBy($name, $id)) > 0) {
				return ToolResult::json([
					'ok'    => false,
					'error' => sprintf(
						'A profile named "%s" already exists (id %d). There is no unique constraint on '
							. 'the column — JCE enforces uniqueness in PHP by incrementing the name '
							. '(models/profile.php:843-847). This tool refuses rather than silently '
							. 'renaming to something you did not ask for.',
						$name,
						$clash
					),
				], true);
			}

			$updates['name'] = $name;
		}

		if (\array_key_exists('description', $arguments)) {
			$updates['description'] = (string) $arguments['description'];
		}

		if (\array_key_exists('ordering', $arguments)) {
			$updates['ordering'] = (int) $arguments['ordering'];

			if ($updates['ordering'] < 0) {
				$warnings[] = 'A negative `ordering` pins this profile above every normally ordered '
					. 'profile. That is legal, and it is also the signature of the rogue profiles left '
					. 'behind by the CVE-2026-48907 campaign, which commonly used -99999. If this is '
					. 'intentional, fine; if you are reading this in an audit, it is not.';
			}
		}

		if ($updates === []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Nothing to update. Supply at least one of name, description or ordering.',
			], true);
		}

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

		$response = [
			'ok'          => true,
			'profile_id'  => $id,
			'updated'     => array_keys($updates),
			'before'      => [
				'name'        => (string) $row['name'],
				'description' => (string) $row['description'],
				'ordering'    => (int) $row['ordering'],
			],
			'after'       => [
				'name'        => $updates['name'] ?? (string) $row['name'],
				'description' => $updates['description'] ?? (string) $row['description'],
				'ordering'    => $updates['ordering'] ?? (int) $row['ordering'],
			],
			'component'   => $this->jceEditionNotice(),
		];

		if (\array_key_exists('ordering', $updates)) {
			$response['evaluation_order'] = $this->neighbourhood($id, $updates['ordering']);
			$response['ordering_note'] = 'JCE returns the first published profile that matches the '
				. 'request context, iterating in `ordering` ascending order. Anything listed under '
				. '"below" is now only reachable by requests this profile does not match.';
		}

		if ($warnings !== []) {
			$response['warnings'] = $warnings;
		}

		return ToolResult::json($response);
	}

	private function nameTakenBy(string $name, int $excludeId): int
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName('id'))
			->from($this->db->quoteName($this->jceTable()))
			->where($this->db->quoteName('name') . ' = ' . $this->db->quote($name))
			->where($this->db->quoteName('id') . ' <> ' . $excludeId);

		return (int) $this->db->setQuery($query, 0, 1)->loadResult();
	}

	/** @return array<string,mixed> */
	private function neighbourhood(int $id, int $ordering): array
	{
		$query = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('id'),
				$this->db->quoteName('name'),
				$this->db->quoteName('ordering'),
			])
			->from($this->db->quoteName($this->jceTable()))
			->where($this->db->quoteName('published') . ' = 1')
			->where($this->db->quoteName('id') . ' <> ' . $id)
			->order($this->db->quoteName('ordering') . ' ASC');

		$rows  = $this->db->setQuery($query)->loadAssocList() ?: [];
		$above = [];
		$below = [];

		foreach ($rows as $r) {
			$entry = [
				'id'       => (int) $r['id'],
				'name'     => (string) $r['name'],
				'ordering' => (int) $r['ordering'],
			];

			if ($entry['ordering'] < $ordering) {
				$above[] = $entry;
			} else {
				$below[] = $entry;
			}
		}

		return [
			'this_profile_ordering' => $ordering,
			'evaluated_before_this' => $above,
			'evaluated_after_this'  => $below,
		];
	}
}
