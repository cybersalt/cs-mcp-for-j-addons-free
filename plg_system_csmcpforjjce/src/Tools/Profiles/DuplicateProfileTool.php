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
 * Copy an existing profile, including its toolbar, plugin list and params.
 *
 * This is the one write tool that moves a whole params blob, so it carries the
 * whole params blob's risk. Two refusals contain it:
 *
 *   1. If the source `params` are ENCRYPTED, refuse. We cannot read them, so we
 *      cannot check them, and copying the ciphertext into a new row produces a
 *      profile whose configuration nobody can inspect or repair.
 *   2. If the source `params` contain an executable extension in any
 *      `*.extensions` key, refuse. Duplicating such a profile is duplicating a
 *      webshell-upload permission, and a profile that already carries one is a
 *      likely CVE-2026-48907 artefact rather than something to propagate.
 *
 * The copy is always created UNPUBLISHED, matching what JCE's own copy task
 * does (`models/profile.php:931-959` sets `published = 0`), so the new profile
 * cannot silently start shadowing the original.
 */
final class DuplicateProfileTool extends AbstractTool
{
	use JceBootTrait;
	use JceSafetyTrait;

	public function getName(): string { return 'duplicate_jce_profile'; }

	public function getDescription(): string
	{
		return 'Copy an existing JCE profile into a new one, carrying over its toolbar rows, plugin '
			. 'list, params, assignment, area and devices. '
			. 'The copy is always created UNPUBLISHED and at the end of the evaluation order '
			. '(MAX(ordering)+1), matching JCE\'s own copy behaviour, so it cannot start shadowing the '
			. 'original by accident. Publish it with set_jce_profile_state when it is ready. '
			. 'Supply new_name, or omit it to get "<original> (2)", incrementing until free. '
			. 'REFUSES when the source profile\'s params are encrypted (a ###AES128###, ###CTR128### or '
			. '###DEFUSE### prefix, seen on sites upgraded from an old JCE). The key is a per-site '
			. 'serverkey.php that ships in no package, so those params cannot be read, cannot be '
			. 'checked, and would produce a copy nobody can inspect. Open and save the source profile '
			. 'once in the JCE admin UI to decrypt it in place, then retry. '
			. 'REFUSES when the source profile permits an executable extension in any *.extensions '
			. 'param. Duplicating such a profile duplicates a webshell upload permission, and a profile '
			. 'that already carries one is more likely a CVE-2026-48907 artefact than a template — run '
			. 'audit_jce_profiles. '
			. 'Refuses entirely if the installed JCE is below 2.9.99.5.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'        => ['type' => 'integer', 'description' => 'The #__wf_profiles id to copy.'],
				'new_name'  => ['type' => 'string', 'description' => 'Name for the copy. Defaults to "<original> (2)", incremented until unused.'],
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

		$id     = $this->requirePositiveInt($arguments, 'id');
		$source = $this->jceProfileRow($id);

		if ($source === null) {
			return $this->jceProfileNotFoundError($id);
		}

		$decoded = $this->jceDecodeParams($source['params'] ?? '');

		if (!$decoded['ok']) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'REFUSED — cannot copy this profile. ' . $decoded['error'],
				'source_profile_id' => $id,
				'encrypted' => $decoded['encrypted'],
				'algorithm' => $decoded['algorithm'],
				'component' => $this->jceEditionNotice(),
			], true);
		}

		if (($refusal = $this->scanSourceParams($id, $decoded['params'])) !== null) {
			return $refusal;
		}

		$newName = trim((string) ($arguments['new_name'] ?? ''));

		if ($newName === '') {
			$newName = $this->incrementName((string) $source['name']);
		} elseif ($this->nameExists($newName)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf('A profile named "%s" already exists. Choose another name, or omit '
					. 'new_name to get an automatically incremented one.', $newName),
			], true);
		}

		$ordering = (int) $this->db->setQuery(
			'SELECT MAX(' . $this->db->quoteName('ordering') . ') FROM '
			. $this->db->quoteName($this->jceTable())
		)->loadResult() + 1;

		$values = [
			'name'        => $newName,
			'description' => (string) ($source['description'] ?? ''),
			'users'       => (string) ($source['users'] ?? ''),
			'types'       => (string) ($source['types'] ?? ''),
			'components'  => (string) ($source['components'] ?? ''),
			'area'        => (int) ($source['area'] ?? 0),
			'device'      => (string) ($source['device'] ?? ''),
			'rows'        => (string) ($source['rows'] ?? ''),
			'plugins'     => (string) ($source['plugins'] ?? ''),
			// models/profile.php:931-959 copies with published = 0.
			'published'   => 0,
			'ordering'    => $ordering,
			'params'      => $this->jceEncodeParams($decoded['params']),
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

		$newId = (int) $this->db->insertid();

		$response = [
			'ok'          => true,
			'created'     => true,
			'new_profile_id' => $newId,
			'new_name'    => $newName,
			'copied_from' => ['id' => $id, 'name' => (string) $source['name']],
			'published'   => false,
			'ordering'    => $ordering,
			'copied'      => [
				'toolbar_rows'  => \count($this->jceParseRows($values['rows'])),
				'plugins'       => \count($this->jceSplitList($values['plugins'])),
				'params_keys'   => array_keys($decoded['params']),
				'user_groups'   => $this->jceSplitIntList($values['types']),
				'users'         => $this->jceSplitIntList($values['users']),
				'components'    => $this->jceSplitList($values['components']),
				'devices'       => $this->jceSplitList($values['device']),
				'area_label'    => $this->jceAreaLabel((int) $values['area']),
			],
			'note'        => 'Created unpublished and last in the evaluation order. It applies to the '
				. 'same user groups and users as the original, so publishing it without changing the '
				. 'assignment gives you two profiles competing for the same people — the one with the '
				. 'lower `ordering` wins. Adjust with set_jce_profile_assignment and '
				. 'reorder_jce_profiles before publishing.',
			'params_note' => 'The params blob was decoded, re-encoded and written as plaintext JSON. If '
				. 'the source had been encrypted this call would have been refused, so nothing was '
				. 'silently converted.',
			'component'   => $this->jceEditionNotice(),
		];

		if ($this->jceSplitIntList($values['types']) === [] && $this->jceSplitIntList($values['users']) === []) {
			$response['warning'] = 'The source profile has neither user groups nor users assigned, so '
				. 'the copy does not either. JCE skips such a profile entirely during matching '
				. '(classes/application.php:394).';
		}

		return ToolResult::json($response);
	}

	/**
	 * Refuse to propagate an upload permission that permits executables.
	 *
	 * Every params key ending in `extensions` is checked, not just the two
	 * documented ones, because any JCE plugin manifest may declare a `filetype`
	 * field and third-party plugins do.
	 *
	 * @param array<string,mixed> $params
	 */
	private function scanSourceParams(int $id, array $params): ?ToolResult
	{
		foreach ($this->jceFlattenParams($params) as $path => $value) {
			if (!str_ends_with($path, 'extensions') || !\is_scalar($value)) {
				continue;
			}

			$refusal = $this->jceAssertNoExecutableExtensions($path, $value, [
				'source_profile_id' => $id,
				'context' => 'This value is in the SOURCE profile being copied, not in anything you '
					. 'supplied. Duplicating it would create a second profile permitting the same '
					. 'uploads. A profile that already permits executable uploads is a strong indicator '
					. 'of the CVE-2026-48907 compromise — run audit_jce_profiles before doing anything '
					. 'else with this site.',
			]);

			if ($refusal !== null) {
				return $refusal;
			}
		}

		return null;
	}

	private function incrementName(string $base): string
	{
		$candidate = $base . ' (2)';
		$n         = 2;

		while ($this->nameExists($candidate)) {
			$n++;
			$candidate = $base . ' (' . $n . ')';
		}

		return $candidate;
	}

	private function nameExists(string $name): bool
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName('id'))
			->from($this->db->quoteName($this->jceTable()))
			->where($this->db->quoteName('name') . ' = ' . $this->db->quote($name));

		return (int) $this->db->setQuery($query, 0, 1)->loadResult() > 0;
	}
}
