<?php
/**
 * @package     MCP for Community Builder
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Fields;

\defined('_JEXEC') or die;

/**
 * Field metadata shared by the field tools.
 *
 * The defaults and allowed values below are CB's own, from the field edit form
 * (`xmlcb/views/view.com_comprofiler.editfield.xml:173-201`) and its types
 * (`xmlcb/models/model.fields.xml:4-14`).
 */
trait FieldSupportTrait
{
	/** `field_display` — used by profile, registration and edit. */
	private const CB_DISPLAY_VALUES = [0, 1, 2, 3, 4];

	/** Writable field columns, with the type of value they take. */
	private const CB_FIELD_PROPS = [
		'title'        => 'string',
		'description'  => 'string',
		'tab_id'       => 'tab',
		'required'     => 'bool',
		'published'    => 'bool',
		'registration' => 'display',
		'edit'         => 'display',
		'profile'      => 'display',
		'readonly'     => 'bool',
		'searchable'   => 'bool',
		'maxlength'    => 'int',
		'size'         => 'int',
		'cols'         => 'int',
		'rows'         => 'int',
		'default'      => 'string',
		'cssclass'     => 'string',
	];

	/** One field row, summarised for output. */
	protected function cbFieldSummary(array $f, bool $translate): array
	{
		return [
			'fieldid'      => (int) $f['fieldid'],
			'name'         => $f['name'],
			'title'        => $translate ? $this->cbText((string) $f['title']) : $f['title'],
			'title_raw'    => $f['title'],
			'type'         => $f['type'],
			'tab_id'       => (int) $f['tabid'],
			'table'        => $f['table'],
			'columns'      => $this->cbFieldColumns($f),
			'published'    => (int) $f['published'] === 1,
			'required'     => (int) $f['required'] === 1,
			'registration' => (int) $f['registration'],
			'edit'         => (int) $f['edit'],
			'profile'      => (int) $f['profile'],
			'readonly'     => (int) $f['readonly'] === 1,
			'searchable'   => (int) $f['searchable'] === 1,
			'system'       => (int) $f['sys'] === 1,
			'calculated'   => (int) $f['calculated'] === 1,
			'ordering'     => (int) $f['ordering'],
		];
	}

	/** Validate field property arguments into FieldTable column => value. */
	protected function cbFieldProps(array $arguments): array
	{
		$out = [];

		foreach (self::CB_FIELD_PROPS as $prop => $kind) {
			if (!\array_key_exists($prop, $arguments)) {
				continue;
			}

			$v      = $arguments[$prop];
			$column = $prop === 'tab_id' ? 'tabid' : $prop;

			switch ($kind) {
				case 'string':
					if (!\is_scalar($v) && $v !== null) {
						throw new \InvalidArgumentException($prop . ' must be a string.');
					}

					$out[$column] = (string) $v;

					if ($prop === 'title') {
						if (trim((string) $v) === '') {
							throw new \InvalidArgumentException('title cannot be empty.');
						}

						$this->cbRequireFits('title', (string) $v, 255);
					}

					if ($prop === 'cssclass') {
						$this->cbRequireFits('cssclass', (string) $v, 255);
					}
					break;

				case 'bool':
					$out[$column] = $this->cbBool($arguments, $prop) ? 1 : 0;
					break;

				case 'display':
					if (\is_bool($v)) {
						$v = $v ? 1 : 0;
					}

					if (!\in_array((int) $v, self::CB_DISPLAY_VALUES, true) || !is_numeric($v)) {
						throw new \InvalidArgumentException($prop . ' takes 0 (not displayed), 1 (1 line with title), 2 (2 lines with title), 3 (1 line, empty title) or 4 (1 line without title).');
					}

					$out[$column] = (int) $v;
					break;

				case 'int':
					if ($v === null || $v === '') {
						$out[$column] = null;
						break;
					}

					if (!is_numeric($v) || (int) $v < 0) {
						throw new \InvalidArgumentException($prop . ' must be a non-negative whole number.');
					}

					$out[$column] = (int) $v;
					break;

				case 'tab':
					$tabId  = (int) $v;
					$exists = (int) $this->db->setQuery(
						'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__comprofiler_tabs') . ' WHERE tabid = ' . $tabId
					)->loadResult();

					if ($exists === 0) {
						throw new \InvalidArgumentException('tab_id ' . $tabId . ' does not exist (list_cb_tabs). CB would silently move the field to tab 11.');
					}

					$out[$column] = $tabId;
					break;
			}
		}

		return $out;
	}

	/**
	 * Normalise a field name the way `FieldTable::store()` does for non-system
	 * fields (`libraries/CBLib/CB/Database/Table/FieldTable.php:238-251`), so we
	 * can report the real name and catch collisions before CB does.
	 */
	protected function cbNormaliseFieldName(string $name): string
	{
		$name = strtolower($name);
		$name = preg_replace('/^cb_/', '', $name);
		$name = preg_replace('/[^a-zA-Z0-9_]+/', '', $name);
		$name = preg_replace('/(_{2,})+/', '', $name);
		$name = preg_replace('/^_/', '', $name);

		return 'cb_' . $name;
	}

	/**
	 * Registered field types (from the CB plugins loaded for this site), with
	 * whether each is "unique" (a one-per-site system type you cannot create).
	 * Must run inside cbRun().
	 *
	 * @return array<string,array{label:?string,unique:bool,plugin_id:int}>
	 */
	protected function cbFieldTypes(): array
	{
		global $_PLUGINS;

		$out = [];

		foreach ((array) $_PLUGINS->getUserFieldTypes() as $type) {
			$tmp       = new \CB\Database\Table\FieldTable();
			$tmp->type = $type;
			$handler   = new \cbFieldHandler();
			$xml       = $handler->_loadFieldXML($tmp);

			$out[(string) $type] = [
				'label'     => $xml ? $this->cbText((string) $xml->attributes('label')) : null,
				'unique'    => $xml ? $xml->attributes('unique') === 'true' : false,
				'plugin_id' => (int) $tmp->pluginid,
			];
		}

		ksort($out);

		return $out;
	}

	/**
	 * Option rows to hand `FieldValueTable::updateFieldValues()`. Existing rows
	 * are matched by value (fieldtitle) and keep their id, so they are updated in
	 * place instead of being deleted and re-inserted.
	 *
	 * @param  array<int,mixed> $options  [{value, label?, group?}] or plain strings
	 * @return array<int,array<string,mixed>>
	 */
	protected function cbBuildOptionRows(array $field, array $options): array
	{
		$existing = [];

		foreach ($this->cbFieldValueRows((int) $field['fieldid']) as $r) {
			$existing[(int) $r['fieldgroup'] . '|' . (string) $r['fieldtitle']] = (int) $r['fieldvalueid'];
		}

		$isSelect = preg_match('/select|multiselect|tag/', (string) $field['type']) === 1;
		$rows     = [];
		$seen     = [];

		foreach ($options as $i => $opt) {
			if (\is_string($opt) || \is_numeric($opt)) {
				$opt = ['value' => (string) $opt];
			}

			if (!\is_array($opt)) {
				throw new \InvalidArgumentException('Option #' . $i . ' must be a string or {value, label?, group?}.');
			}

			$value = trim((string) ($opt['value'] ?? ''));
			$label = trim((string) ($opt['label'] ?? ''));
			$group = !empty($opt['group']);

			if ($value === '') {
				throw new \InvalidArgumentException('Option #' . $i . ' has an empty value. CB drops options with an empty value (FieldValueTable::updateFieldValues).');
			}

			if ($group && !$isSelect) {
				throw new \InvalidArgumentException('Option groups exist only on drop-down and tag fields.');
			}

			if (str_contains($value, self::CB_MULTI_SEPARATOR)) {
				throw new \InvalidArgumentException('Option "' . $value . '" contains CB\'s separator "|*|".');
			}

			$this->cbRequireFits('Option value', $value, 246);
			$this->cbRequireFits('Option label', $label, 246);

			$key = ($group ? 1 : 0) . '|' . $value;

			if (isset($seen[$key])) {
				throw new \InvalidArgumentException('Option "' . $value . '" is listed twice.');
			}

			$seen[$key] = true;

			$rows[] = [
				'fieldvalueid' => $existing[$key] ?? 0,
				'fieldtitle'   => $value,
				'fieldlabel'   => $group ? '' : $label,
				'fieldgroup'   => $group ? 1 : 0,
			];
		}

		return $rows;
	}

	/** Count members whose stored value includes $value for this field. */
	protected function cbCountMembersWithValue(array $field, string $value): int
	{
		$cols = $this->cbFieldColumns($field);

		if ((string) $field['table'] !== '#__comprofiler' || $cols === [] || !\in_array($cols[0], $this->cbProfileColumns(), true)) {
			return 0;
		}

		$c   = $this->db->quoteName($cols[0]);
		$q   = $this->db->quote($value);
		$sep = self::CB_MULTI_SEPARATOR;

		return (int) $this->db->setQuery(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__comprofiler') . ' WHERE ' . $c . ' = ' . $q
			. ' OR ' . $c . ' LIKE ' . $this->db->quote($this->db->escape($value . $sep, true) . '%', false)
			. ' OR ' . $c . ' LIKE ' . $this->db->quote('%' . $this->db->escape($sep . $value, true), false)
			. ' OR ' . $c . ' LIKE ' . $this->db->quote('%' . $this->db->escape($sep . $value . $sep, true) . '%', false)
		)->loadResult();
	}
}
