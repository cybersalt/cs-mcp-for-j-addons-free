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
 * Renumber the whole profile set from an explicit, complete ordering.
 *
 * This takes the full list of ids rather than a move-up/move-down operation on
 * one row, because `ordering` on this table is neither compacted nor unique.
 * Gaps, duplicates and negative values are all legal, and a partial reorder
 * against that background produces a result nobody can predict. Supplying the
 * complete list and rewriting it as 1..N makes the outcome exact.
 *
 * The safety consequence is real: `ordering` is the JCE profile precedence
 * chain, and the CVE-2026-48907 rogue profiles used absurd negative values such
 * as `-99999` specifically to pin themselves above every legitimate profile.
 * Renumbering to a clean 1..N sequence removes that possibility by
 * construction, which is why this tool exists rather than an incremental one.
 */
final class ReorderProfilesTool extends AbstractTool
{
	use JceBootTrait;
	use JceSafetyTrait;

	public function getName(): string { return 'reorder_jce_profiles'; }

	public function getDescription(): string
	{
		return 'Renumber every JCE profile\'s `ordering` from an explicit, complete list of ids, '
			. 'rewriting them as 1, 2, 3 … N in the order given. '
			. 'WHY THE FULL LIST: `ordering` on #__wf_profiles is not compacted by JCE, not unique and '
			. 'not constrained — gaps, duplicate values and negative numbers are all legal, and ties are '
			. 'broken by whatever order the database happens to return. A move-one-row-up operation '
			. 'against that background has an unpredictable result, so this tool insists on the complete '
			. 'set and produces an exact one. '
			. 'The order you give IS the evaluation order. JCE iterates published profiles by `ordering` '
			. 'ascending and returns the FIRST whose user-group/user, component, device and area tests '
			. 'pass (classes/application.php:367, :461-464). Put the most specific profiles first and '
			. 'the catch-all last, or the catch-all will swallow everything. '
			. 'The ids you supply must be exactly the ids that exist — no more, no fewer. A missing or '
			. 'unknown id is refused with the difference reported rather than partially applied. '
			. 'Set preview=true to see the before/after mapping without writing. '
			. 'This is also the clean remedy for an audit finding of a profile with an absurd negative '
			. 'ordering, which is the signature the CVE-2026-48907 campaign used to pin a rogue profile '
			. 'above every legitimate one. '
			. 'Refuses entirely if the installed JCE is below 2.9.99.5.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'ids' => [
					'type'  => 'array',
					'items' => ['type' => 'integer'],
					'description' => 'Every #__wf_profiles id, in the desired evaluation order. First = evaluated first = wins. Must be the complete set.',
				],
				'preview' => ['type' => 'boolean', 'description' => 'true to return the before/after mapping without writing. Default false.'],
			],
			'required' => ['ids'],
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

		$requested = [];

		foreach ((array) ($arguments['ids'] ?? []) as $id) {
			$id = (int) $id;

			if ($id > 0 && !\in_array($id, $requested, true)) {
				$requested[] = $id;
			}
		}

		if ($requested === []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'ids is required and must contain at least one profile id.',
			], true);
		}

		$existing = $this->existingProfiles();
		$existingIds = array_keys($existing);

		$missing = array_values(array_diff($existingIds, $requested));
		$unknown = array_values(array_diff($requested, $existingIds));

		if ($missing !== [] || $unknown !== []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'REFUSED. The supplied list is not the complete set of profiles. A partial '
					. 'reorder against a non-compacted, non-unique `ordering` column produces an '
					. 'unpredictable evaluation order, so this tool applies nothing rather than half of '
					. 'what was asked.',
				'missing_from_your_list' => array_map(static fn ($id) => [
					'id'   => $id,
					'name' => $existing[$id]['name'],
				], $missing),
				'unknown_ids' => $unknown,
				'expected_count' => \count($existingIds),
				'supplied_count' => \count($requested),
				'hint' => 'Call list_jce_profiles first and reorder the ids it returns.',
			], true);
		}

		$mapping = [];
		$position = 0;

		foreach ($requested as $id) {
			$position++;

			$mapping[] = [
				'position' => $position,
				'id'       => $id,
				'name'     => $existing[$id]['name'],
				'published' => $existing[$id]['published'],
				'ordering_before' => $existing[$id]['ordering'],
				'ordering_after'  => $position,
				'changed'  => $existing[$id]['ordering'] !== $position,
			];
		}

		if ((bool) ($arguments['preview'] ?? false)) {
			return ToolResult::json([
				'ok'      => true,
				'preview' => true,
				'written' => false,
				'mapping' => $mapping,
				'message' => 'Nothing was written. Call again without preview to apply this ordering.',
				'component' => $this->jceEditionNotice(),
			]);
		}

		$modified = $this->jceModifiedColumns((int) $actor->id);

		foreach ($mapping as $entry) {
			if (!$entry['changed']) {
				continue;
			}

			$set = [$this->db->quoteName('ordering') . ' = ' . (int) $entry['ordering_after']];

			foreach ($modified as $column => $value) {
				$set[] = $this->db->quoteName($column) . ' = '
					. (\is_int($value) ? (string) $value : $this->db->quote((string) $value));
			}

			$query = $this->db->getQuery(true)
				->update($this->db->quoteName($this->jceTable()))
				->set($set)
				->where($this->db->quoteName('id') . ' = ' . (int) $entry['id']);

			$this->db->setQuery($query)->execute();
		}

		$changed = array_values(array_filter($mapping, static fn ($e) => $e['changed']));

		return ToolResult::json([
			'ok'            => true,
			'written'       => true,
			'profiles_total' => \count($mapping),
			'rows_changed'  => \count($changed),
			'mapping'       => $mapping,
			'note'          => 'Every profile now has a distinct, positive `ordering` from 1 to '
				. \count($mapping) . '. Unpublished profiles are numbered too — they are simply skipped '
				. 'by the runtime query, and numbering them keeps the sequence stable if they are '
				. 'published later.',
			'effect_note'   => 'Only the relative order of PUBLISHED profiles affects behaviour. Use '
				. 'resolve_jce_profile_for to verify that a given user still gets the profile you '
				. 'expect.',
			'component'     => $this->jceEditionNotice(),
		]);
	}

	/** @return array<int,array<string,mixed>> Keyed by id. */
	private function existingProfiles(): array
	{
		$query = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('id'),
				$this->db->quoteName('name'),
				$this->db->quoteName('ordering'),
				$this->db->quoteName('published'),
			])
			->from($this->db->quoteName($this->jceTable()))
			->order($this->db->quoteName('ordering') . ' ASC');

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];
		$out  = [];

		foreach ($rows as $row) {
			$out[(int) $row['id']] = [
				'name'      => (string) $row['name'],
				'ordering'  => (int) $row['ordering'],
				'published' => (int) $row['published'] === 1,
			];
		}

		return $out;
	}
}
