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
 * Publish or unpublish a profile.
 *
 * `published` is a plain tinyint with only two meaningful values; there is no
 * archived or trashed state on this table. Unpublishing is the reversible way
 * to take a profile out of service — the row, its toolbar and its whole params
 * blob survive intact.
 *
 * The interesting case is unpublishing the last published profile. JCE renders
 * no editor whatsoever when no profile matches, so that is refused rather than
 * performed and reported.
 */
final class SetProfileStateTool extends AbstractTool
{
	use JceBootTrait;
	use JceSafetyTrait;

	public function getName(): string { return 'set_jce_profile_state'; }

	public function getDescription(): string
	{
		return 'Publish or unpublish a JCE editor profile. '
			. 'Only published profiles are considered at runtime — classes/application.php:367 selects '
			. '`WHERE published = 1 ORDER BY ordering ASC` — so unpublishing is the reversible way to '
			. 'take a profile out of service. Nothing is lost: the toolbar, the plugin list and the '
			. 'entire params blob stay exactly as they are. Prefer this over delete_jce_profile. '
			. 'PUBLISHING has consequences beyond the profile itself. Because the first match wins, '
			. 'publishing a profile with a low `ordering` can immediately take users away from a more '
			. 'specific profile below it. The response lists which published profiles this one now sits '
			. 'above. '
			. 'REFUSES to unpublish the last published profile: with no published profile, JCE returns '
			. 'null from profile matching and renders no editor at all, for everyone, in both the site '
			. 'and the administrator, with nothing logged. '
			. 'Also warns when publishing a profile whose `types` and `users` are both empty — JCE skips '
			. 'such a row during matching (application.php:394), so publishing it changes nothing. '
			. 'Refuses entirely if the installed JCE is below 2.9.99.5 (CVE-2026-48907).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'        => ['type' => 'integer', 'description' => 'The #__wf_profiles id.'],
				'published' => ['type' => 'boolean', 'description' => 'true to publish, false to unpublish.'],
			],
			'required' => ['id', 'published'],
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

		if (!\array_key_exists('published', $arguments)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'published is required and must be a boolean.',
			], true);
		}

		$id      = $this->requirePositiveInt($arguments, 'id');
		$publish = (bool) $arguments['published'];
		$row     = $this->jceProfileRow($id);

		if ($row === null) {
			return $this->jceProfileNotFoundError($id);
		}

		$was = (int) $row['published'] === 1;

		if (!$publish && $was) {
			$others = $this->publishedOthers($id);

			if ($others === []) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'REFUSED. This is the only published JCE profile on the site. '
						. 'WFApplication::getProfiles() returns null when no published profile matches '
						. '(classes/application.php:244), and with a null profile JCE renders no editor '
						. 'at all — for every user, in both the site and the administrator, with no '
						. 'error message and nothing in the log. Publish a replacement first.',
					'profile_id' => $id,
					'name'       => (string) $row['name'],
				], true);
			}
		}

		if ($was === $publish) {
			return ToolResult::json([
				'ok'         => true,
				'changed'    => false,
				'profile_id' => $id,
				'published'  => $publish,
				'message'    => sprintf('Profile "%s" is already %s. Nothing was written.',
					(string) $row['name'],
					$publish ? 'published' : 'unpublished'
				),
				'component'  => $this->jceEditionNotice(),
			]);
		}

		$set = [$this->db->quoteName('published') . ' = ' . ($publish ? 1 : 0)];

		foreach ($this->jceModifiedColumns((int) $actor->id) as $column => $value) {
			$set[] = $this->db->quoteName($column) . ' = '
				. (\is_int($value) ? (string) $value : $this->db->quote((string) $value));
		}

		$query = $this->db->getQuery(true)
			->update($this->db->quoteName($this->jceTable()))
			->set($set)
			->where($this->db->quoteName('id') . ' = ' . $id);

		$this->db->setQuery($query)->execute();

		$response = [
			'ok'         => true,
			'changed'    => true,
			'profile_id' => $id,
			'name'       => (string) $row['name'],
			'published'  => $publish,
			'was'        => $was,
			'component'  => $this->jceEditionNotice(),
		];

		$types = $this->jceSplitIntList($row['types'] ?? '');
		$users = $this->jceSplitIntList($row['users'] ?? '');

		if ($publish && $types === [] && $users === []) {
			$response['warning'] = 'This profile has no user groups and no individual users assigned. '
				. 'JCE skips such a row entirely during matching (classes/application.php:394), so '
				. 'publishing it has no effect at all — it will appear published in the admin list and '
				. 'match nobody. Use set_jce_profile_assignment to give it an audience.';
		}

		if ($publish) {
			$below = $this->publishedBelow($id, (int) $row['ordering']);

			if ($below !== []) {
				$response['shadow_warning'] = sprintf(
					'This profile has ordering %d and is now evaluated before %d other published '
						. 'profile(s): %s. First match wins, so any request this profile matches will '
						. 'never reach those. Run resolve_jce_profile_for to confirm the effect on a '
						. 'specific user.',
					(int) $row['ordering'],
					\count($below),
					implode(', ', array_map(
						static fn ($p) => $p['name'] . ' (#' . $p['id'] . ', ordering ' . $p['ordering'] . ')',
						$below
					))
				);
			}
		}

		return ToolResult::json($response);
	}

	/** @return array<int,array<string,mixed>> */
	private function publishedOthers(int $excludeId): array
	{
		$query = $this->db->getQuery(true)
			->select([$this->db->quoteName('id'), $this->db->quoteName('name')])
			->from($this->db->quoteName($this->jceTable()))
			->where($this->db->quoteName('published') . ' = 1')
			->where($this->db->quoteName('id') . ' <> ' . $excludeId);

		return $this->db->setQuery($query)->loadAssocList() ?: [];
	}

	/** @return array<int,array<string,mixed>> */
	private function publishedBelow(int $excludeId, int $ordering): array
	{
		$query = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('id'),
				$this->db->quoteName('name'),
				$this->db->quoteName('ordering'),
			])
			->from($this->db->quoteName($this->jceTable()))
			->where($this->db->quoteName('published') . ' = 1')
			->where($this->db->quoteName('id') . ' <> ' . $excludeId)
			->where($this->db->quoteName('ordering') . ' > ' . $ordering)
			->order($this->db->quoteName('ordering') . ' ASC');

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];

		return array_map(static fn ($r) => [
			'id'       => (int) $r['id'],
			'name'     => (string) $r['name'],
			'ordering' => (int) $r['ordering'],
		], $rows);
	}
}
