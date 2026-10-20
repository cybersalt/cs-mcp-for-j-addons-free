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
 * Update a CB field: load the full row through CB's `FieldTable`, change only
 * the named properties, store through `FieldTable::store()` (which re-checks
 * the column against the type's XML, and renames the column when the name
 * changes). Then re-read and diff.
 */
final class UpdateFieldTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use FieldSupportTrait;

	/** Properties a system field may have changed. */
	private const SYSTEM_SAFE = ['title', 'description', 'cssclass', 'registration', 'edit', 'profile', 'readonly', 'searchable', 'required', 'size', 'cols', 'rows', 'maxlength', 'tabid'];

	public function getName(): string { return 'update_cb_field'; }

	public function getDescription(): string
	{
		return 'Change properties of a Community Builder field: title, description, tab_id, required, published, '
			. 'registration / edit / profile display (0-4), readonly, searchable, maxlength, size, cols, rows, '
			. 'default, cssclass, params (merged into the existing params; a null value removes a key) and '
			. 'new_name (renames the field AND its database column(s), through CB). Only named properties change: '
			. 'the field is loaded whole through CB\'s FieldTable and stored through FieldTable::store(). The type '
			. 'cannot be changed here (CB may convert the column and its data — do that in CB\'s admin). System '
			. 'fields allow display and label changes only, not publish state or name. Options are a separate tool: '
			. 'set_cb_field_values. Re-read and diffed afterwards. dry_run shows the change.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'field_id'     => ['type' => 'integer'],
				'name'         => ['type' => 'string', 'description' => 'Identify the field by name instead of field_id.'],
				'new_name'     => ['type' => 'string', 'description' => 'Rename (and rename its column). Custom fields only.'],
				'title'        => ['type' => 'string'],
				'description'  => ['type' => 'string'],
				'tab_id'       => ['type' => 'integer'],
				'required'     => ['type' => 'boolean'],
				'published'    => ['type' => 'boolean'],
				'registration' => ['type' => 'integer'],
				'edit'         => ['type' => 'integer'],
				'profile'      => ['type' => 'integer'],
				'readonly'     => ['type' => 'boolean'],
				'searchable'   => ['type' => 'boolean'],
				'maxlength'    => ['type' => 'integer'],
				'size'         => ['type' => 'integer'],
				'cols'         => ['type' => 'integer'],
				'rows'         => ['type' => 'integer'],
				'default'      => ['type' => 'string'],
				'cssclass'     => ['type' => 'string'],
				'params'       => ['type' => 'object', 'description' => 'Merged into existing params; null removes a key.'],
				'dry_run'      => ['type' => 'boolean'],
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
		$this->cbRequireAccess($actor, 'core.edit', 'com_comprofiler.fields');

		$key   = $arguments['field_id'] ?? ($arguments['name'] ?? null);
		$field = $key === null ? null : $this->cbFindField($key);

		if ($field === null) {
			throw new \InvalidArgumentException('Give field_id or name of an existing CB field.');
		}

		$isSystem = (int) $field['sys'] === 1 || (int) $field['calculated'] === 1;
		$props    = $this->cbFieldProps($arguments);

		if ($isSystem) {
			$blocked = array_values(array_diff(array_keys($props), self::SYSTEM_SAFE));

			if ($blocked !== []) {
				throw new \InvalidArgumentException($field['name'] . ' is a CB system field; these cannot be changed on it here: ' . implode(', ', $blocked) . '. (The name fields are published by the name_style setting.)');
			}
		}

		$newName = null;

		if (isset($arguments['new_name']) && trim((string) $arguments['new_name']) !== '') {
			if ($isSystem) {
				throw new \InvalidArgumentException('System and calculated fields cannot be renamed (FieldTable::check).');
			}

			$newName = $this->cbNormaliseFieldName((string) $arguments['new_name']);
			$this->cbRequireFits('new_name', $newName, 50);

			if ($newName !== $field['name']) {
				if ($this->cbFindField($newName) !== null) {
					throw new \InvalidArgumentException('A field named ' . $newName . ' already exists.');
				}

				if (\in_array($newName, $this->cbProfileColumns(), true)) {
					throw new \InvalidArgumentException('#__comprofiler already has a column ' . $newName . '; the rename would collide with it.');
				}
			} else {
				$newName = null;
			}
		}

		$params = null;

		if (\array_key_exists('params', $arguments)) {
			if (!\is_array($arguments['params'])) {
				throw new \InvalidArgumentException('params must be an object.');
			}

			$existing = json_decode((string) ($field['params'] ?? ''), true);

			if ($field['params'] !== null && $field['params'] !== '' && !\is_array($existing)) {
				throw new \InvalidArgumentException('The field\'s stored params are not JSON; refusing to merge into them.');
			}

			$params = \is_array($existing) ? $existing : [];

			foreach ($arguments['params'] as $k => $v) {
				if ($v === null) {
					unset($params[$k]);
				} else {
					$params[$k] = $v;
				}
			}
		}

		$changes = [];

		foreach ($props as $col => $v) {
			if ((string) $field[$col] !== (string) $v) {
				$changes[$col] = ['before' => $field[$col], 'after' => $v];
			}
		}

		if ($newName !== null) {
			$changes['name'] = ['before' => $field['name'], 'after' => $newName];
		}

		if ($params !== null && json_encode($params) !== json_encode(json_decode((string) ($field['params'] ?? ''), true) ?: [])) {
			$changes['params'] = ['before' => json_decode((string) ($field['params'] ?? ''), true), 'after' => $params];
		}

		if ($changes === []) {
			return ToolResult::json(['ok' => true, 'changed' => false, 'note' => 'Nothing differs from the stored field.']);
		}

		if ($this->cbBool($arguments, 'dry_run')) {
			return ToolResult::json(['ok' => true, 'dry_run' => true, 'would_change' => $changes]);
		}

		$fieldId = (int) $field['fieldid'];

		$run = $this->cbRun($actor, static function () use ($fieldId, $props, $newName, $params): array {
			$f = new \CB\Database\Table\FieldTable();

			if (!$f->load($fieldId)) {
				return ['ok' => false, 'error' => 'CB could not load field ' . $fieldId . '.'];
			}

			foreach ($props as $col => $v) {
				$f->$col = $v;
			}

			if ($newName !== null) {
				$f->name = $newName;
			}

			if ($params !== null) {
				$f->params = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			}

			if (!$f->check()) {
				return ['ok' => false, 'error' => (string) $f->getError()];
			}

			if (!$f->store()) {
				return ['ok' => false, 'error' => (string) $f->getError()];
			}

			return ['ok' => true, 'error' => null];
		});

		$after    = $this->cbFindField($fieldId) ?? [];
		$verified = [];

		foreach ($props as $col => $v) {
			$verified[$col] = (string) ($after[$col] ?? '') === (string) $v;
		}

		if ($newName !== null) {
			$verified['name'] = ($after['name'] ?? null) === $newName;
		}

		$columns  = (string) ($after['table'] ?? '') === '#__comprofiler' ? $this->cbProfileColumns() : null;
		$colState = [];

		if ($columns !== null) {
			foreach ($this->cbFieldColumns($after) as $c) {
				$colState[$c] = \in_array($c, $columns, true);
			}
		}

		$ok = $run['result']['ok'] && !\in_array(false, $verified, true) && !\in_array(false, $colState, true);

		return ToolResult::json([
			'ok'                 => $ok,
			'field_id'           => $fieldId,
			'changes'            => $changes,
			'verified_by_reread' => $verified,
			'column_exists'      => $colState,
			'error'              => $run['result']['error'],
			'cb_messages'        => $run['messages'],
			'plugin_errors'      => $run['plugin_errors'],
		] + $this->cbContextNote(), !$ok);
	}
}
