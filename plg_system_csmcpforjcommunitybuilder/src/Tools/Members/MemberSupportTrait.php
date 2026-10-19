<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Members;

\defined('_JEXEC') or die;

/**
 * Reading and validating member profile values.
 */
trait MemberSupportTrait
{
	/** Options of a field as fieldtitle => label (label falls back to the title, as CB does). */
	protected function cbOptionMap(int $fieldId): array
	{
		$map = [];

		foreach ($this->cbFieldValueRows($fieldId) as $r) {
			if ((int) ($r['fieldgroup'] ?? 0) === 1) {
				continue; // optgroup header, not a choosable value
			}

			$title       = (string) $r['fieldtitle'];
			$label       = trim((string) $r['fieldlabel']);
			$map[$title] = $label !== '' ? $label : $title;
		}

		return $map;
	}

	/**
	 * A field's value(s) for one member row, with option labels resolved.
	 *
	 * @return array{value:mixed, display?:mixed}
	 */
	protected function cbFieldValue(array $field, array $row, array $secretColumns): array
	{
		$cols = $this->cbFieldColumns($field);
		$type = (string) $field['type'];

		if ($cols === []) {
			return ['value' => null, 'note' => 'This field type stores nothing (computed or display-only).'];
		}

		$values = [];

		foreach ($cols as $c) {
			if (\in_array($c, $secretColumns, true) || $type === 'password') {
				$values[$c] = ($row[$c] ?? null) !== null && ($row[$c] ?? '') !== '' ? '[redacted]' : null;
				continue;
			}

			$values[$c] = $row[$c] ?? null;
		}

		$main = $values[$cols[0]] ?? null;
		$out  = ['value' => \count($cols) === 1 ? $main : $values];

		if (\in_array($type, self::CB_OPTION_TYPES, true) && $main !== null && $main !== '') {
			$map   = $this->cbOptionMap((int) $field['fieldid']);
			$parts = $type === 'select' || $type === 'radio' ? [(string) $main] : explode(self::CB_MULTI_SEPARATOR, (string) $main);

			$out['value']   = $type === 'select' || $type === 'radio' ? (string) $main : $parts;
			$out['display'] = array_map(static fn (string $p): string => $map[$p] ?? $p, $parts);

			if ($type === 'select' || $type === 'radio') {
				$out['display'] = $out['display'][0];
			}

			$unknown = array_values(array_filter($parts, static fn (string $p): bool => $p !== '' && !isset($map[$p]) ));

			if ($unknown !== [] && $type !== 'tag') {
				$out['not_an_option'] = $unknown;
			}
		}

		if ($type === 'checkbox' && $main !== null) {
			$out['display'] = (int) $main === 1 ? 'checked' : 'unchecked';
		}

		return $out;
	}

	/**
	 * Validate one requested value for update_cb_user_fields; returns
	 * [column => value-to-store].
	 */
	protected function cbValidateFieldWrite(array $field, mixed $value): array
	{
		$name = (string) $field['name'];
		$type = (string) $field['type'];
		$cols = $this->cbFieldColumns($field);

		if ((string) $field['table'] !== '#__comprofiler') {
			throw new \InvalidArgumentException($name . ' is stored on ' . $field['table'] . ', not on #__comprofiler. Username, email, password and the Joomla name belong to the core Joomla user tools (update_user).');
		}

		if (!isset(self::CB_WRITABLE_TYPES[$type])) {
			throw new \InvalidArgumentException($name . ' is a "' . $type . '" field. This tool writes plain value fields only; files, images, passwords, terms, ratings, points and computed fields are managed by CB itself.');
		}

		$isSystemName = \in_array($name, self::CB_WRITABLE_SYSTEM_FIELDS, true);

		if (((int) $field['sys'] === 1 || (int) $field['calculated'] === 1) && !$isSystemName) {
			throw new \InvalidArgumentException($name . ' is a CB system or calculated field; CB computes it.');
		}

		if ($type === 'predefined' && !$isSystemName) {
			throw new \InvalidArgumentException($name . ' is a CB predefined field that is not a plain name field.');
		}

		if (\count($cols) !== 1) {
			throw new \InvalidArgumentException($name . ' owns ' . \count($cols) . ' columns; this tool writes single-column fields only.');
		}

		$col  = $cols[0];
		$kind = self::CB_WRITABLE_TYPES[$type];

		if ($value === null) {
			if (!\in_array($kind, ['string', 'email', 'option', 'options', 'tags'], true)) {
				throw new \InvalidArgumentException($name . ': clearing a ' . $type . ' field is not supported. CB\'s user store skips NULLs (UserTable::store with updateNulls = false), so a NULL would silently not be written.');
			}

			$value = '';
		}

		if ((int) $field['required'] === 1 && ($value === '' || $value === [])) {
			throw new \InvalidArgumentException($name . ' is a required field; refusing to empty it.');
		}

		$max = (int) ($field['maxlength'] ?? 0);

		switch ($kind) {
			case 'string':
				if (!\is_scalar($value)) {
					throw new \InvalidArgumentException($name . ' takes a string.');
				}

				$value = (string) $value;

				if ($max > 0) {
					$this->cbRequireFits($name, $value, $max);
				}

				return [$col => $value];

			case 'email':
				$value = trim((string) $value);

				if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
					throw new \InvalidArgumentException($name . ': "' . $value . '" is not a valid email address.');
				}

				return [$col => $value];

			case 'int':
				if (!is_numeric($value) || (string) (int) $value !== trim((string) $value)) {
					throw new \InvalidArgumentException($name . ' takes a whole number.');
				}

				return [$col => (string) (int) $value];

			case 'float':
				if (!is_numeric($value)) {
					throw new \InvalidArgumentException($name . ' takes a number.');
				}

				return [$col => (string) (float) $value];

			case 'bool':
				if (!\in_array($value, [0, 1, '0', '1', true, false], true)) {
					throw new \InvalidArgumentException($name . ' is a single checkbox: pass true/false or 1/0.');
				}

				return [$col => $value === true || (string) $value === '1' ? '1' : '0'];

			case 'date':
				$value = (string) $value;

				if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
					throw new \InvalidArgumentException($name . ' takes a date as YYYY-MM-DD (CB stores dates in SQL format).');
				}

				return [$col => $value];

			case 'datetime':
				$value = (string) $value;

				if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) !== 1) {
					throw new \InvalidArgumentException($name . ' takes YYYY-MM-DD HH:MM:SS (UTC, as CB stores it).');
				}

				return [$col => $value];

			case 'time':
				$value = (string) $value;

				if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value) !== 1) {
					throw new \InvalidArgumentException($name . ' takes HH:MM or HH:MM:SS.');
				}

				return [$col => \strlen($value) === 5 ? $value . ':00' : $value];

			case 'option':
				$value = (string) $value;
				$map   = $this->cbOptionMap((int) $field['fieldid']);

				if ($value !== '' && !isset($map[$value])) {
					$byLabel = array_search($value, $map, true);

					if ($byLabel === false) {
						throw new \InvalidArgumentException($name . ': "' . $value . '" is not one of its options: ' . implode(', ', array_keys($map)) . '.');
					}

					$value = (string) $byLabel;
				}

				return [$col => $value];

			case 'options':
			case 'tags':
				$list = \is_array($value) ? $value : ($value === '' ? [] : [$value]);
				$list = array_values(array_map('strval', $list));

				if ($kind === 'options') {
					$map = $this->cbOptionMap((int) $field['fieldid']);

					foreach ($list as $i => $v) {
						if (!isset($map[$v])) {
							$byLabel = array_search($v, $map, true);

							if ($byLabel === false) {
								throw new \InvalidArgumentException($name . ': "' . $v . '" is not one of its options: ' . implode(', ', array_keys($map)) . '.');
							}

							$list[$i] = (string) $byLabel;
						}
					}
				}

				foreach ($list as $v) {
					if (str_contains($v, self::CB_MULTI_SEPARATOR)) {
						throw new \InvalidArgumentException($name . ': values may not contain CB\'s separator "|*|".');
					}
				}

				return [$col => implode(self::CB_MULTI_SEPARATOR, array_values(array_unique($list)))];
		}

		throw new \InvalidArgumentException($name . ': unsupported field type ' . $type . '.');
	}
}
