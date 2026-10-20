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
 * Create a CB field through CB's own `FieldTable::store()`
 * (`libraries/CBLib/CB/Database/Table/FieldTable.php:224`), which:
 *   - normalises the name to `cb_<lowercase>` (non-system fields),
 *   - refuses a name already in use,
 *   - asks the field type's XML which table and columns it needs and ADDS THE
 *     COLUMN(S) to `#__comprofiler` (`cbFieldHandler::adaptSQL()`),
 *   - stores the option rows when given.
 * A hand-written INSERT into `#__comprofiler_fields` would create a field
 * with no column behind it, which then fails on every profile save.
 */
final class CreateFieldTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use FieldSupportTrait;

	public function getName(): string { return 'create_cb_field'; }

	public function getDescription(): string
	{
		return 'Create a Community Builder profile field through CB\'s own FieldTable::store(), which adds the '
			. 'field\'s database column(s) to #__comprofiler (a field is a column; a hand-made row would have none). '
			. 'CB renames it to cb_<lowercase name>. Give name, title and type (list_cb_field_types; "unique" '
			. 'system types cannot be created) and optionally tab_id (default 11, Contact Info), description, '
			. 'required, published (default true), registration / edit / profile display (0 hidden, 1 one line '
			. 'with title, 2 two lines, 3 empty title, 4 no title), readonly, searchable, maxlength, size, cols, '
			. 'rows, default, cssclass, params (type-specific settings, stored as given), and options for '
			. 'select / radio / multi-select / multi-checkbox / tag fields ([{value, label?}] or plain strings). '
			. 'Unlike CB\'s own form, registration defaults to 0 here, so a new field never appears on the live '
			. 'sign-up form by surprise. Afterwards the field row, the new column and the options are re-read.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'name'         => ['type' => 'string', 'description' => 'e.g. "company" — becomes cb_company.'],
				'title'        => ['type' => 'string'],
				'type'         => ['type' => 'string', 'description' => 'e.g. text, textarea, select, multicheckbox, date, integer.'],
				'tab_id'       => ['type' => 'integer', 'description' => 'Default 11 (Contact Info).'],
				'description'  => ['type' => 'string'],
				'required'     => ['type' => 'boolean'],
				'published'    => ['type' => 'boolean', 'description' => 'Default true.'],
				'registration' => ['type' => 'integer', 'description' => 'Display on registration, 0-4. Default 0 here.'],
				'edit'         => ['type' => 'integer', 'description' => 'Display on profile edit, 0-4. Default 1.'],
				'profile'      => ['type' => 'integer', 'description' => 'Display on profile, 0-4. Default 1.'],
				'readonly'     => ['type' => 'boolean'],
				'searchable'   => ['type' => 'boolean'],
				'maxlength'    => ['type' => 'integer'],
				'size'         => ['type' => 'integer'],
				'cols'         => ['type' => 'integer'],
				'rows'         => ['type' => 'integer'],
				'default'      => ['type' => 'string'],
				'cssclass'     => ['type' => 'string'],
				'params'       => ['type' => 'object', 'description' => 'Type-specific params (see get_cb_field on a similar field).'],
				'options'      => ['type' => 'array', 'items' => new \stdClass(),'description' => 'For option fields: ["Red","Blue"] or [{"value":"red","label":"Red"}].'],
			],
			'required'             => ['name', 'title', 'type'],
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
		$this->cbRequireAccess($actor, 'core.create', 'com_comprofiler.fields');

		$rawName = $this->requireString($arguments, 'name');
		$type    = $this->requireString($arguments, 'type');
		$name    = $this->cbNormaliseFieldName($rawName);

		if ($name === 'cb_') {
			throw new \InvalidArgumentException('name has no usable characters (letters, digits, underscore).');
		}

		$this->cbRequireFits('name', $name, 50);

		if ($this->cbFindField($name) !== null) {
			return ToolResult::json(['ok' => false, 'error' => 'A field named ' . $name . ' already exists.'], true);
		}

		if (\in_array($name, $this->cbProfileColumns(), true)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => '#__comprofiler already has a column ' . $name . ' that no field owns (check_cb_health lists these). '
					. 'CB would adopt it with whatever data it holds. Choose another name, or recreate it in CB\'s admin if adopting that data is intended.',
			], true);
		}

		$props = $this->cbFieldProps($arguments + ['title' => '']);

		$data = array_merge([
			'tabid'        => 11,
			'published'    => 1,
			'required'     => 0,
			'registration' => 0,
			'edit'         => 1,
			'profile'      => 1,
			'readonly'     => 0,
			'searchable'   => 0,
		], $props, [
			'name'       => $name,
			'type'       => $type,
			'sys'        => 0,
			'calculated' => 0,
		]);

		if (isset($arguments['params'])) {
			if (!\is_array($arguments['params'])) {
				throw new \InvalidArgumentException('params must be an object.');
			}

			$data['params'] = json_encode($arguments['params'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		$options = $arguments['options'] ?? null;

		if ($options !== null) {
			if (!\is_array($options)) {
				throw new \InvalidArgumentException('options must be an array.');
			}

			if (!\in_array($type, self::CB_OPTION_TYPES, true)) {
				throw new \InvalidArgumentException('options apply only to ' . implode(', ', self::CB_OPTION_TYPES) . ' fields.');
			}

			$data['_fieldvalues'] = $this->cbBuildOptionRows(['fieldid' => 0, 'type' => $type], $options);
		}

		$run = $this->cbRun($actor, function () use ($data, $type): array {
			$types = $this->cbFieldTypes();

			if (!isset($types[$type])) {
				return ['ok' => false, 'error' => 'Unknown field type "' . $type . '". Registered types: ' . implode(', ', array_keys($types)) . '.'];
			}

			if ($types[$type]['unique']) {
				return ['ok' => false, 'error' => '"' . $type . '" is a unique system type; CB allows only the one it ships.'];
			}

			$field = new \CB\Database\Table\FieldTable();

			if (!$field->bind($data)) {
				return ['ok' => false, 'error' => 'bind failed: ' . $field->getError()];
			}

			if (!$field->check()) {
				return ['ok' => false, 'error' => (string) $field->getError()];
			}

			if (!$field->store()) {
				return ['ok' => false, 'error' => (string) $field->getError()];
			}

			return ['ok' => true, 'error' => null, 'fieldid' => (int) $field->fieldid];
		});

		if (!$run['result']['ok']) {
			$leftover = $this->cbFindField($name);

			return ToolResult::json([
				'ok'            => false,
				'error'         => $run['result']['error'],
				'field_row_left_behind' => $leftover !== null ? (int) $leftover['fieldid'] : null,
				'cb_messages'   => $run['messages'],
				'plugin_errors' => $run['plugin_errors'],
			], true);
		}

		$row      = $this->cbFindField((int) $run['result']['fieldid']);
		$columns  = $this->cbProfileColumns();
		$colState = [];

		foreach ($this->cbFieldColumns($row ?? []) as $c) {
			$colState[$c] = \in_array($c, $columns, true);
		}

		$ok = $row !== null && !\in_array(false, $colState, true);

		return ToolResult::json([
			'ok'            => $ok,
			'field'         => $row ? $this->cbFieldSummary($row, true) : null,
			'name_note'     => $rawName !== $name ? 'CB stores custom field names as cb_<lowercase>: "' . $rawName . '" became "' . $name . '".' : null,
			'columns_created' => $colState,
			'options'       => $row ? array_map(static fn (array $r): array => ['fieldvalueid' => (int) $r['fieldvalueid'], 'value' => $r['fieldtitle'], 'label' => $r['fieldlabel'], 'group' => (int) $r['fieldgroup'] === 1], $this->cbFieldValueRows((int) $row['fieldid'])) : [],
			'cb_messages'   => $run['messages'],
			'plugin_errors' => $run['plugin_errors'],
		] + $this->cbContextNote(), !$ok);
	}
}
