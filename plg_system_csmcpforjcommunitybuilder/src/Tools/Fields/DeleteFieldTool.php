<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

/**
 * Delete a CB field through `FieldTable::canDelete()` + `FieldTable::delete()`
 * (`libraries/CBLib/CB/Database/Table/FieldTable.php:450-494`): CB refuses
 * system fields, runs `adaptSQL($field, 'drop')` on the column, then deletes
 * the option rows and the field row.
 *
 * THE COLUMN AND EVERY MEMBER'S ANSWER ARE DROPPED. `FieldTable::delete()`
 * carries a comment saying the field is deleted "without deleting the user
 * data, preserving it for reinstall", and the core field types' XML says
 * `drop="never"` on the table — but on CB 2.12.0 `adaptSQL(..., 'drop')`
 * dropped the column for an integer field (`<database>` XML) in live testing.
 * So this tool demands a second, explicit confirmation whenever members hold a
 * value, and re-reads the schema afterwards to report what really happened.
 */
final class DeleteFieldTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use FieldSupportTrait;

	public function getName(): string { return 'delete_cb_field'; }

	public function getDescription(): string
	{
		return 'Delete a custom Community Builder field through CB\'s own FieldTable::delete(). CB DROPS the '
			. 'field\'s database column, so every member\'s answer is lost, along with its options and the field '
			. 'row (despite a comment in CB\'s code saying the data is preserved — verified live on CB 2.12.0). The '
			. 'response re-reads the schema and reports what actually happened. System fields are refused (CB\'s '
			. 'rule). Requires confirm: true, and additionally confirm_data_loss: true when any member has a '
			. 'value; without them the tool returns the blast radius (members with a value, options).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'field_id'          => ['type' => 'integer'],
				'name'              => ['type' => 'string'],
				'confirm'           => ['type' => 'boolean', 'description' => 'Must be true to delete.'],
				'confirm_data_loss' => ['type' => 'boolean', 'description' => 'Must also be true when members hold a value in this field.'],
			],
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
		$this->cbRequireAccess($actor, 'core.delete', 'com_comprofiler.fields');

		$key   = $arguments['field_id'] ?? ($arguments['name'] ?? null);
		$field = $key === null ? null : $this->cbFindField($key);

		if ($field === null) {
			throw new \InvalidArgumentException('Give field_id or name of an existing CB field.');
		}

		if ((int) $field['sys'] === 1) {
			return ToolResult::json(['ok' => false, 'error' => $field['name'] . ' is a CB system field; CB does not allow deleting it.'], true);
		}

		$cols     = $this->cbFieldColumns($field);
		$columns  = $this->cbProfileColumns();
		$withData = null;

		if ((string) $field['table'] === '#__comprofiler' && $cols !== [] && \in_array($cols[0], $columns, true)) {
			$c        = $this->db->quoteName($cols[0]);
			$withData = (int) $this->db->setQuery(
				'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__comprofiler') . ' WHERE ' . $c . ' IS NOT NULL AND ' . $c . ' <> \'\''
			)->loadResult();
		}

		$optionCount = \count($this->cbFieldValueRows((int) $field['fieldid']));

		$blast = [
			'field'                 => ['fieldid' => (int) $field['fieldid'], 'name' => $field['name'], 'type' => $field['type']],
			'columns'               => $cols,
			'members_with_a_value'  => $withData,
			'options'               => $optionCount,
		];

		if (!$this->cbBool($arguments, 'confirm')) {
			return ToolResult::json([
				'ok'      => false,
				'error'   => 'Not deleted: pass confirm: true. This is what would go.',
				'would_delete' => $blast,
			], true);
		}

		if ((int) $withData > 0 && !$this->cbBool($arguments, 'confirm_data_loss')) {
			return ToolResult::json([
				'ok'      => false,
				'error'   => 'Not deleted: ' . $withData . ' member(s) have a value in this field, and CB drops the column '
					. 'with their answers. Pass confirm_data_loss: true as well if that is intended (export first '
					. 'with query_cb_table if the answers matter).',
				'would_delete' => $blast,
			], true);
		}

		$fieldId = (int) $field['fieldid'];

		$run = $this->cbRun($actor, static function () use ($fieldId): array {
			$f = new \CB\Database\Table\FieldTable();

			if (!$f->load($fieldId)) {
				return ['ok' => false, 'error' => 'CB could not load field ' . $fieldId . '.'];
			}

			if (!$f->canDelete()) {
				return ['ok' => false, 'error' => (string) $f->getError()];
			}

			if (!$f->delete()) {
				return ['ok' => false, 'error' => (string) $f->getError()];
			}

			return ['ok' => true, 'error' => null];
		});

		$rowGone     = $this->cbFindField($fieldId) === null;
		$optionsLeft = \count($this->cbFieldValueRows($fieldId));
		$after       = $this->cbProfileColumns();
		$colState    = [];

		foreach ($cols as $c) {
			$colState[$c] = \in_array($c, $after, true) ? 'kept (data still in #__comprofiler)' : 'dropped (data gone)';
		}

		$ok = $run['result']['ok'] && $rowGone && $optionsLeft === 0;

		return ToolResult::json([
			'ok'               => $ok,
			'deleted'          => $blast,
			'field_row_gone'   => $rowGone,
			'options_left'     => $optionsLeft,
			'columns_after'    => $colState,
			'error'            => $run['result']['error'],
			'cb_messages'      => $run['messages'],
			'plugin_errors'    => $run['plugin_errors'],
		] + $this->cbContextNote(), !$ok);
	}
}
