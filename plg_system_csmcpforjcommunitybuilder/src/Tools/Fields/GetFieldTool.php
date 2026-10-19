<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

final class GetFieldTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use FieldSupportTrait;

	public function getName(): string { return 'get_cb_field'; }

	public function getDescription(): string
	{
		return 'One Community Builder field in full: every column of #__comprofiler_fields, its type-specific '
			. 'params decoded, its options (value, label, group) for select / radio / checkbox / tag fields with '
			. 'how many members currently hold each value, whether each of its database columns actually exists, '
			. 'and how many members have a non-empty value. Find it by field_id or name. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'field_id' => ['type' => 'integer'],
				'name'     => ['type' => 'string', 'description' => 'Field name, e.g. "cb_company".'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cbInstalled()) {
			return $this->cbNotInstalledError();
		}

		$this->cbRequireAccess($actor, 'core.manage', 'com_comprofiler.fields');

		$key   = $arguments['field_id'] ?? ($arguments['name'] ?? null);
		$field = $key === null ? null : $this->cbFindField($key);

		if ($field === null) {
			throw new \InvalidArgumentException('Give field_id or name of an existing CB field (list_cb_fields).');
		}

		$translate = true;

		try {
			$this->cbRun($actor, static fn () => null);
		} catch (\Throwable $e) {
			$translate = false;
		}

		$columns  = (string) $field['table'] === '#__comprofiler' ? $this->cbProfileColumns() : array_keys($this->db->getTableColumns('#__users', false) ?: []);
		$colState = [];

		foreach ($this->cbFieldColumns($field) as $c) {
			$colState[$c] = \in_array($c, $columns, true);
		}

		$options = [];

		foreach ($this->cbFieldValueRows((int) $field['fieldid']) as $r) {
			$o = [
				'fieldvalueid' => (int) $r['fieldvalueid'],
				'value'        => $r['fieldtitle'],
				'label'        => $r['fieldlabel'] !== '' ? ($translate ? $this->cbText((string) $r['fieldlabel']) : $r['fieldlabel']) : null,
				'group'        => (int) $r['fieldgroup'] === 1,
			];

			if ((int) $r['fieldgroup'] !== 1) {
				$o['members_with_value'] = $this->cbCountMembersWithValue($field, (string) $r['fieldtitle']);
			}

			$options[] = $o;
		}

		$filled = null;
		$cols   = $this->cbFieldColumns($field);

		if ((string) $field['table'] === '#__comprofiler' && $cols !== [] && ($colState[$cols[0]] ?? false)) {
			$c      = $this->db->quoteName($cols[0]);
			$filled = (int) $this->db->setQuery(
				'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__comprofiler') . ' WHERE ' . $c . ' IS NOT NULL AND ' . $c . ' <> \'\''
			)->loadResult();
		}

		$params = json_decode((string) ($field['params'] ?? ''), true);

		return ToolResult::json([
			'ok'                => true,
			'field'             => $this->cbFieldSummary($field, $translate) + [
				'description'   => $field['description'],
				'default'       => $field['default'],
				'maxlength'     => $field['maxlength'] !== null ? (int) $field['maxlength'] : null,
				'size'          => $field['size'] !== null ? (int) $field['size'] : null,
				'cols'          => $field['cols'] !== null ? (int) $field['cols'] : null,
				'rows'          => $field['rows'] !== null ? (int) $field['rows'] : null,
				'cssclass'      => $field['cssclass'],
				'plugin_id'     => (int) $field['pluginid'],
			],
			'params'            => \is_array($params) ? $params : ($field['params'] === null ? new \stdClass() : ['raw' => $field['params']]),
			'column_exists'     => $colState,
			'members_with_a_value' => $filled,
			'options'           => $options,
		]);
	}
}
