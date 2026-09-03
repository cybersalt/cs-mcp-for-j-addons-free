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
 * Delete a profile, permanently.
 *
 * `#__wf_profiles` has no trash state and no `state` column beyond the binary
 * `published` flag, so there is nothing to restore from. The row is gone.
 *
 * Two refusals guard the two ways this bites:
 *
 *   1. Deleting the profile named `Default` breaks JCE plugin install and
 *      uninstall, which target it by name (`helpers/plugins.php:408`), and on a
 *      typical site removes the only published profile.
 *   2. Deleting the LAST published profile leaves the site with no editor for
 *      anyone. `WFApplication::getProfiles()` returns null when nothing matches
 *      (`classes/application.php:244`), and a null profile means JCE renders no
 *      editor at all — an outcome that looks like a broken site rather than a
 *      configuration change.
 *
 * Both refusals can be worked around deliberately: unpublish rather than
 * delete, or delete after another published profile covers the same users.
 */
final class DeleteProfileTool extends AbstractTool
{
	use JceBootTrait;
	use JceSafetyTrait;

	public function getName(): string { return 'delete_jce_profile'; }

	public function getDescription(): string
	{
		return 'Permanently delete a JCE editor profile from #__wf_profiles. '
			. 'THERE IS NO UNDO. The table has no trash state — only a binary `published` flag — so the '
			. 'row is gone, including its entire params configuration. If the goal is to switch a '
			. 'profile off, use set_jce_profile_state instead, which is reversible. '
			. 'Requires confirm=true. Without it the tool returns a full preview of what would be '
			. 'destroyed — name, assignment, toolbar size, params size — and deletes nothing. '
			. 'REFUSES to delete the profile named "Default": JCE registers every newly installed editor '
			. 'plugin into it by name (helpers/plugins.php:408 matches `name = \'Default\' OR id = 1`), '
			. 'so deleting it silently breaks all future JCE plugin installs. '
			. 'REFUSES to delete the last published profile. JCE returns null when no profile matches '
			. '(classes/application.php:244) and then renders no editor at all — for everyone, in both '
			. 'the site and the administrator, with no error message anywhere. That reads as a broken '
			. 'site, not a setting. '
			. 'Refuses entirely if the installed JCE is below 2.9.99.5 (CVE-2026-48907). If you are '
			. 'deleting a rogue profile found by audit_jce_profiles on an unpatched site, update JCE '
			. 'first — the attacker can otherwise simply recreate it.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'      => ['type' => 'integer', 'description' => 'The #__wf_profiles id.'],
				'confirm' => ['type' => 'boolean', 'description' => 'Must be true to actually delete. Omit or false for a preview of what would be destroyed.'],
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

		$summary = [
			'id'           => $id,
			'name'         => (string) $row['name'],
			'description'  => (string) $row['description'],
			'published'    => (int) $row['published'] === 1,
			'ordering'     => (int) $row['ordering'],
			'area_label'   => $this->jceAreaLabel((int) $row['area']),
			'user_groups'  => $this->jceDescribeGroups($this->jceSplitIntList($row['types'] ?? '')),
			'users'        => $this->jceSplitIntList($row['users'] ?? ''),
			'components'   => $this->jceSplitList($row['components'] ?? ''),
			'devices'      => $this->jceEffectiveDevices($row['device'] ?? ''),
			'toolbar_rows' => \count($this->jceParseRows($row['rows'] ?? '')),
			'plugins'      => $this->jceSplitList($row['plugins'] ?? ''),
			'params_bytes' => \strlen((string) ($row['params'] ?? '')),
		];

		if (strcasecmp((string) $row['name'], 'Default') === 0) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'REFUSED. This is the profile named "Default", which JCE treats as magic. '
					. 'JcePluginsHelper::postInstall() registers every newly installed JCE editor plugin '
					. 'into `WHERE name = \'Default\' OR id = 1` (helpers/plugins.php:408) and '
					. 'removeFromProfile() unregisters from the same row. Delete it and every subsequent '
					. 'JCE plugin install completes successfully while adding no toolbar button, with '
					. 'nothing logged. On most sites it is also the only published profile. Unpublish it '
					. 'with set_jce_profile_state if you want it out of the way.',
				'profile' => $summary,
			], true);
		}

		if ((int) $row['published'] === 1 && $this->publishedCount() <= 1) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'REFUSED. This is the only published profile on the site. JCE selects a '
					. 'profile by iterating published rows and returns null when none matches '
					. '(classes/application.php:244); with a null profile it renders no editor at all, '
					. 'for every user, in both the site and the administrator, with no error message. '
					. 'Publish a replacement profile first, then delete this one.',
				'profile' => $summary,
			], true);
		}

		if (!(bool) ($arguments['confirm'] ?? false)) {
			return ToolResult::json([
				'ok'        => true,
				'deleted'   => false,
				'preview'   => true,
				'message'   => 'Nothing was deleted. Call again with confirm=true to permanently remove '
					. 'this profile. There is no trash state on this table and no undo.',
				'would_delete' => $summary,
				'component' => $this->jceEditionNotice(),
			]);
		}

		$query = $this->db->getQuery(true)
			->delete($this->db->quoteName($this->jceTable()))
			->where($this->db->quoteName('id') . ' = ' . $id);

		$this->db->setQuery($query)->execute();

		return ToolResult::json([
			'ok'         => true,
			'deleted'    => true,
			'profile_id' => $id,
			'was'        => $summary,
			'note'       => 'The row is gone. `ordering` is not compacted by JCE, so there is now a gap '
				. 'in the sequence. That is harmless — matching only cares about relative order — but '
				. 'reorder_jce_profiles will renumber cleanly if you want it tidy.',
			'reassignment_note' => 'Users who were served by this profile now fall through to the next '
				. 'published profile that matches them, or to no editor at all if none does. Run '
				. 'resolve_jce_profile_for on an affected user to confirm which.',
			'component'  => $this->jceEditionNotice(),
		]);
	}

	private function publishedCount(): int
	{
		return (int) $this->db->setQuery(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName($this->jceTable())
			. ' WHERE ' . $this->db->quoteName('published') . ' = 1'
		)->loadResult();
	}
}
