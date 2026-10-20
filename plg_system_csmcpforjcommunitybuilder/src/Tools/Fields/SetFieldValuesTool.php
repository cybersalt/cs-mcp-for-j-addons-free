<?php
/**
 * @package     MCP for Community Builder
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

/**
 * Replace a field's option list through CB's own
 * `FieldValueTable::updateFieldValues()`
 * (`libraries/CBLib/CB/Database/Table/FieldValueTable.php:61`).
 *
 * That method is a REPLACE-SET: every existing option not resubmitted with its
 * id is DELETED. So this tool always takes the complete list, matches existing
 * options by value so they keep their ids, and — because members store the
 * option's TEXT, not its id — counts how many members hold each option that
 * would disappear and refuses unless the caller accepts orphaning them.
 */
final class SetFieldValuesTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use FieldSupportTrait;

	public function getName(): string { return 'set_cb_field_values'; }

	public function getDescription(): string
	{
		return 'Set the COMPLETE option list of a Community Builder select / radio / multi-select / multi-checkbox / '
			. 'tag field, in order. CB\'s own option save deletes every option not resubmitted, so this tool always '
			. 'takes the whole list: existing options are matched by value and kept (with their ids), new ones '
			. 'are added, missing ones are removed. Options: ["Red","Blue"] or [{"value":"red","label":"Red"}] or '
			. '{"value":"Europe","group":true} for an option group on drop-downs. Members store the option\'s '
			. 'VALUE TEXT, so renaming or removing a value orphans every member who chose it; the tool counts '
			. 'them and refuses unless allow_orphaned_values is true. dry_run shows added / kept / removed.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'field_id'              => ['type' => 'integer'],
				'name'                  => ['type' => 'string'],
				'options'               => ['type' => 'array', 'items' => new \stdClass(), 'description' => 'The complete list, in order.'],
				'allow_orphaned_values' => ['type' => 'boolean', 'description' => 'Proceed even though members hold a value being removed. Default false.'],
				'dry_run'               => ['type' => 'boolean'],
			],
			'required'             => ['options'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cbInstalled()) {
			return $this->cbNotInstalledError();
		}

		$this->cbRequireAccess($actor, 'core.manage', 'com_comprofiler.fields');
		$this->cbRequireAccess($actor, 'core.edit', 'com_comprofiler.fields');

		$key   = $arguments['field_id'] ?? ($arguments['name'] ?? null);
		$field = $key === null ? null : $this->cbFindField($key);

		if ($field === null) {
			throw new \InvalidArgumentException('Give field_id or name of an existing CB field.');
		}

		if (!\in_array((string) $field['type'], self::CB_OPTION_TYPES, true)) {
			throw new \InvalidArgumentException($field['name'] . ' is a "' . $field['type'] . '" field; options exist only on ' . implode(', ', self::CB_OPTION_TYPES) . '.');
		}

		$options = $arguments['options'] ?? null;

		if (!\is_array($options) || $options === []) {
			throw new \InvalidArgumentException('options must be the complete, non-empty list. (To remove every option, delete the field or do it in CB\'s admin.)');
		}

		$rows     = $this->cbBuildOptionRows($field, $options);
		$existing = $this->cbFieldValueRows((int) $field['fieldid']);

		$keptIds = array_filter(array_map(static fn (array $r): int => (int) $r['fieldvalueid'], $rows));
		$added   = array_values(array_map(static fn (array $r): string => $r['fieldtitle'], array_filter($rows, static fn (array $r): bool => (int) $r['fieldvalueid'] === 0)));
		$removed = [];

		foreach ($existing as $e) {
			if (!\in_array((int) $e['fieldvalueid'], $keptIds, true)) {
				$entry = ['fieldvalueid' => (int) $e['fieldvalueid'], 'value' => $e['fieldtitle'], 'group' => (int) $e['fieldgroup'] === 1];

				if ((int) $e['fieldgroup'] !== 1) {
					$entry['members_holding_it'] = $this->cbCountMembersWithValue($field, (string) $e['fieldtitle']);
				}

				$removed[] = $entry;
			}
		}

		$orphaning = array_sum(array_map(static fn (array $r): int => (int) ($r['members_holding_it'] ?? 0), $removed));

		$plan = ['kept' => \count($keptIds), 'added' => $added, 'removed' => $removed];

		if ($this->cbBool($arguments, 'dry_run')) {
			return ToolResult::json([
				'ok'                        => true,
				'dry_run'                   => true,
				'plan'                      => $plan,
				'new_order'                 => array_map(static fn (array $r): string => $r['fieldtitle'], $rows),
				'member_answers_orphaned'   => $orphaning,
				'needs_allow_orphaned_values' => $orphaning > 0 && !$this->cbBool($arguments, 'allow_orphaned_values'),
			]);
		}

		if ($orphaning > 0 && !$this->cbBool($arguments, 'allow_orphaned_values')) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Refused: ' . $orphaning . ' member answer(s) use a value this list removes. Members store the '
					. 'option text, so they would keep a value that is no longer an option (CB shows it, but they cannot '
					. 're-select it). Keep those values, update the members first (update_cb_user_fields), or pass '
					. 'allow_orphaned_values: true.',
				'plan'  => $plan,
			], true);
		}

		$fieldId = (int) $field['fieldid'];

		$run = $this->cbRun($actor, static function () use ($fieldId, $rows): array {
			$f = new \CB\Database\Table\FieldTable();

			if (!$f->load($fieldId)) {
				return ['ok' => false, 'error' => 'CB could not load field ' . $fieldId . '.'];
			}

			$values = new \CB\Database\Table\FieldValueTable();
			$ok     = (bool) $values->updateFieldValues($f, $rows);

			return ['ok' => $ok, 'error' => $ok ? null : 'FieldValueTable::updateFieldValues() returned false: ' . $values->getError()];
		});

		$after    = $this->cbFieldValueRows($fieldId);
		$actual   = array_map(static fn (array $r): string => (string) $r['fieldtitle'], $after);
		$expected = array_map(static fn (array $r): string => (string) $r['fieldtitle'], $rows);
		$ok       = $run['result']['ok'] && $actual === $expected;

		return ToolResult::json([
			'ok'               => $ok,
			'field'            => $field['name'],
			'plan'             => $plan,
			'options_after'    => array_map(static fn (array $r): array => ['fieldvalueid' => (int) $r['fieldvalueid'], 'value' => $r['fieldtitle'], 'label' => $r['fieldlabel'], 'group' => (int) $r['fieldgroup'] === 1, 'ordering' => (int) $r['ordering']], $after),
			'order_verified'   => $actual === $expected,
			'error'            => $run['result']['error'],
			'cb_messages'      => $run['messages'],
			'plugin_errors'    => $run['plugin_errors'],
		] + $this->cbContextNote(), !$ok);
	}
}
