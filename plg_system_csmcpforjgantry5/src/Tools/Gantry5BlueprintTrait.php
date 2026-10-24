<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjgantry5\Tools;

\defined('_JEXEC') or die;

/**
 * Particle and style blueprints: finding them, flattening their form fields
 * into setting keys, their defaults, and validating values written to them.
 * Used through Gantry5BootTrait.
 *
 * A blueprint's `form.fields` is turned into keys exactly as
 * RocketTheme\Toolbox\Blueprints\BlueprintSchema::parseFormField() and
 * getFieldKey() do it:
 *   - a name with dots ("css.class", "date.start") is a nested key;
 *   - a child whose name starts with "." is relative to its parent
 *     ("jquery" + ".enabled" = "jquery.enabled");
 *   - container.set, container.tabs, separator.* and `key` fields hold no data
 *     (Gantry\Component\Config\BlueprintSchema::$types, input@ false), and their
 *     children without a leading dot are top-level keys;
 *   - collection.list is one value (a list of items), whose ".x" children
 *     describe the item fields.
 * Names starting with "_" (_info, _inherit) are notes and widgets, never data.
 */
trait Gantry5BlueprintTrait
{
	/** Field types that store booleans as '1'/'0' (Gantry posts and saves them as strings). */
	private const G5_BOOL_TYPES = ['input.checkbox', 'enable.enable', 'checkbox', 'input.toggle'];

	/** Field types whose stored value must be one of the blueprint's options. */
	private const G5_STRICT_OPTION_TYPES = ['select.select', 'input.radios'];

	// -----------------------------------------------------------------------
	// Finding blueprints
	// -----------------------------------------------------------------------

	/**
	 * Every particle type a theme can use: gantry-particles:// is
	 * gantry-theme://particles then gantry-engine://particles
	 * (Framework/Base/Platform.php:100-104), and gantry-engine:// is
	 * gantry-theme://engine then media/gantry5/engines/<engine>. The first file
	 * found for a name wins.
	 *
	 * @return array<string,array<string,mixed>> keyed by particle type
	 */
	protected function g5ParticleTypes(array $theme): array
	{
		$dirs = [];

		foreach ($this->g5ThemePaths($theme) as $path) {
			$dirs[] = $path . '/particles';
		}

		foreach ($this->g5ThemePaths($theme) as $path) {
			$dirs[] = $path . '/engine/particles';
		}

		$dirs[] = $this->g5EnginePath($theme) . '/particles';

		$out = [];

		foreach ($dirs as $dir) {
			if (!is_dir($dir)) {
				continue;
			}

			foreach ((array) glob($dir . '/*.yaml') as $file) {
				$name = basename((string) $file, '.yaml');

				if (isset($out[$name]) || !$this->g5SafeName($name)) {
					continue;
				}

				$out[$name] = ['type_name' => $name, 'file' => (string) $file];
			}
		}

		ksort($out);

		return $out;
	}

	/**
	 * A particle type's blueprint, flattened.
	 *
	 * @return array{name:string,file:string,label:string,description:string,kind:string,hidden:bool,fields:array<string,array<string,mixed>>,has_directives:bool,configuration:array}
	 */
	protected function g5ParticleBlueprint(array $theme, string $type): array
	{
		$types = $this->g5ParticleTypes($theme);

		if (!isset($types[$type])) {
			throw new \InvalidArgumentException('Particle type "' . $type . '" has no blueprint in ' . $theme['element'] . ' or its engine. Known types: ' . implode(', ', array_keys($types)) . '.');
		}

		$data = $this->g5ParseYamlFile($types[$type]['file']);

		return [
			'name'           => $type,
			'file'           => $this->g5Rel($types[$type]['file']),
			'label'          => (string) ($data['name'] ?? $type),
			'description'    => (string) ($data['description'] ?? ''),
			'kind'           => (string) ($data['type'] ?? 'particle'),
			'hidden'         => !empty($data['hidden']),
			'fields'         => $this->g5FlattenFields((array) ($data['form']['fields'] ?? [])),
			'has_directives' => $this->g5HasDirectives($data),
			'configuration'  => \is_array($data['configuration'] ?? null) ? $data['configuration'] : [],
		];
	}

	/**
	 * The style groups of a theme (gantry-blueprints://styles/<group>.yaml), with
	 * every field prefixed by its group ("accent.color-1"), because styles.yaml
	 * stores them as styles.<group>.<field>.
	 *
	 * @return array{groups:array<string,array<string,mixed>>,fields:array<string,array<string,mixed>>}
	 */
	protected function g5StyleBlueprints(array $theme): array
	{
		$dirs = [];

		foreach ($this->g5ThemePaths($theme) as $path) {
			$dirs[] = $path . '/blueprints/styles';
		}

		$dirs[] = $this->g5EnginePath($theme) . '/blueprints/styles';

		$groups = [];
		$fields = [];

		foreach ($dirs as $dir) {
			foreach ((array) glob($dir . '/*.yaml') as $file) {
				$group = basename((string) $file, '.yaml');

				if (isset($groups[$group]) || !$this->g5SafeName($group)) {
					continue;
				}

				$data = $this->g5ParseYamlFile((string) $file);

				$groups[$group] = [
					'group'       => $group,
					'label'       => (string) ($data['name'] ?? $group),
					'description' => (string) ($data['description'] ?? ''),
					'kind'        => (string) ($data['type'] ?? ''),
					'file'        => $this->g5Rel((string) $file),
				];

				foreach ($this->g5FlattenFields((array) ($data['form']['fields'] ?? [])) as $key => $field) {
					$field['path'] = array_merge([$group], $field['path']);
					$fields[$group . '.' . $key] = $field;
				}
			}
		}

		ksort($groups);

		return ['groups' => $groups, 'fields' => $fields];
	}

	/** Gantry blueprints can pull in other files with "@" directives (import@, extends@). */
	protected function g5HasDirectives(array $data): bool
	{
		$found = false;

		$scan = static function (array $a) use (&$scan, &$found): void {
			foreach ($a as $k => $v) {
				if (\is_string($k) && str_contains($k, '@') && $k !== 'input@') {
					$found = true;

					return;
				}

				if (\is_array($v)) {
					$scan($v);
				}
			}
		};

		$scan($data);

		return $found;
	}

	// -----------------------------------------------------------------------
	// Flattening
	// -----------------------------------------------------------------------

	/**
	 * @return array<string,array<string,mixed>> dotted key => field definition
	 */
	protected function g5FlattenFields(array $fields, string $parent = ''): array
	{
		$out = [];

		foreach ($fields as $key => $def) {
			if (!\is_array($def) || !\is_string($key) || $key === '') {
				continue;
			}

			// BlueprintSchema::getFieldKey(): ".x" is relative to the parent, anything else is top-level.
			$name = str_starts_with($key, '.') ? ltrim(($parent !== '' ? $parent : '') . $key, '.') : $key;
			$type = (string) ($def['type'] ?? '');

			$isContainer = str_starts_with($type, 'container.') || str_starts_with($type, 'separator.') || $type === 'key'
				|| (isset($def['input@']) && !$def['input@']);

			if ($isContainer) {
				if (\is_array($def['fields'] ?? null)) {
					$out += $this->g5FlattenFields($def['fields'], $name);
				}

				continue;
			}

			$parts    = explode('.', $name);
			$leafName = (string) end($parts);

			if (str_starts_with($name, '_') || str_starts_with($leafName, '_')) {
				continue;
			}

			$field = [
				'key'         => $name,
				'path'        => explode('.', $name),
				'type'        => $type,
				'label'       => isset($def['label']) ? (string) $def['label'] : null,
				'description' => isset($def['description']) ? (string) $def['description'] : null,
				'default'     => $def['default'] ?? null,
				'options'     => \is_array($def['options'] ?? null) ? $def['options'] : null,
				'pattern'     => isset($def['pattern']) && \is_string($def['pattern']) ? $def['pattern'] : null,
				'min'         => isset($def['min']) && is_numeric($def['min']) ? $def['min'] + 0 : null,
				'max'         => isset($def['max']) && is_numeric($def['max']) ? $def['max'] + 0 : null,
				'overridable' => !isset($def['overridable']) || (bool) $def['overridable'],
				'kind'        => str_starts_with($type, 'collection.') ? ($type === 'collection.keyvalue' ? 'keyvalue' : 'collection') : 'scalar',
			];

			if (\is_array($def['fields'] ?? null)) {
				$items = [];

				foreach ($def['fields'] as $sub => $subDef) {
					if (\is_string($sub) && \is_array($subDef) && str_starts_with($sub, '.')) {
						$items[substr($sub, 1)] = (string) ($subDef['type'] ?? '');
					}
				}

				$field['item_fields'] = $items;
			}

			$out[$name] = $field;
		}

		return $out;
	}

	/**
	 * Nested defaults from the blueprint's `default` values. Like
	 * BlueprintSchema::buildDefaults(), a null default is no default.
	 */
	protected function g5BlueprintDefaults(array $fields): array
	{
		$out = [];

		foreach ($fields as $field) {
			if ($field['default'] !== null) {
				$this->g5SetPath($out, $field['path'], $field['default']);
			}
		}

		return $out;
	}

	/**
	 * Dotted keys that the blueprint nests (the "css" of "css.class"): only these
	 * merge key by key between config layers; anything else is replaced whole,
	 * as BlueprintSchema::mergeArrays() does (it recurses only where the
	 * blueprint defines a nested, non-collection structure).
	 *
	 * @return array<string,true>
	 */
	protected function g5NestedPaths(array $fields): array
	{
		$out = [];

		foreach ($fields as $field) {
			$path = $field['path'];
			array_pop($path);
			$prefix = [];

			foreach ($path as $part) {
				$prefix[]                   = $part;
				$out[implode('.', $prefix)] = true;
			}
		}

		foreach ($fields as $key => $field) {
			unset($out[$key]);
		}

		return $out;
	}

	// -----------------------------------------------------------------------
	// Validation
	// -----------------------------------------------------------------------

	/**
	 * Turn a `set` argument into dotted key => stored value, refusing any key
	 * the blueprint does not define. Nested objects are accepted as well as
	 * dotted keys: {"css": {"class": "x"}} is "css.class".
	 *
	 * @return array<string,mixed>
	 */
	protected function g5ValidateSet(array $fields, array $set, string $what): array
	{
		$flat = [];
		$this->g5FlattenInput($fields, $set, '', $flat, $what);

		$out = [];

		foreach ($flat as $key => $value) {
			$out[$key] = $this->g5NormalizeValue($fields[$key], $value, $what);
		}

		return $out;
	}

	protected function g5FlattenInput(array $fields, array $input, string $prefix, array &$flat, string $what): void
	{
		foreach ($input as $key => $value) {
			$key  = (string) $key;
			$full = $prefix === '' ? $key : $prefix . '.' . $key;

			if (isset($fields[$full])) {
				$flat[$full] = $value;
				continue;
			}

			if (\is_array($value) && $value !== [] && !array_is_list($value)) {
				$this->g5FlattenInput($fields, $value, $full, $flat, $what);
				continue;
			}

			throw new \InvalidArgumentException(
				'"' . $full . '" is not a setting of ' . $what . '. Its blueprint defines: ' . implode(', ', array_keys($fields))
				. '. Nothing was written.'
			);
		}
	}

	/**
	 * Values are stored the way Gantry's admin stores them: scalars as strings,
	 * checkboxes as '1'/'0', collections as lists of string maps.
	 */
	protected function g5NormalizeValue(array $field, mixed $value, string $what): mixed
	{
		$key  = $field['key'];
		$type = $field['type'];

		if ($value === null) {
			throw new \InvalidArgumentException($key . ': null is not a value; put the key in `unset` to remove it.');
		}

		if ($field['kind'] === 'collection') {
			if (!\is_array($value) || !array_is_list($value)) {
				throw new \InvalidArgumentException($key . ' is a ' . $type . ' and needs a JSON array of items.');
			}

			$items = [];

			foreach ($value as $i => $item) {
				if (!\is_array($item) || ($item !== [] && array_is_list($item))) {
					throw new \InvalidArgumentException($key . '[' . $i . '] must be an object.');
				}

				$known = $field['item_fields'] ?? [];
				$norm  = [];

				foreach ($item as $k => $v) {
					if ($known !== [] && !\array_key_exists((string) $k, $known)) {
						throw new \InvalidArgumentException($key . '[' . $i . '].' . $k . ' is not an item field. Item fields: ' . implode(', ', array_keys($known)) . '.');
					}

					$norm[(string) $k] = \is_bool($v) ? ($v ? '1' : '0') : (\is_scalar($v) ? (string) $v : $this->g5RequireScalarTree($v, $key));
				}

				$items[] = $norm;
			}

			return $items;
		}

		if ($field['kind'] === 'keyvalue') {
			if (!\is_array($value)) {
				throw new \InvalidArgumentException($key . ' is a collection.keyvalue and needs an object or a list of one-pair objects.');
			}

			return $this->g5RequireScalarTree($value, $key);
		}

		if (\is_array($value) || \is_object($value)) {
			throw new \InvalidArgumentException($key . ' (' . $type . ') takes a single value, not an object or array.');
		}

		if (\in_array($type, self::G5_BOOL_TYPES, true)) {
			$v = \is_string($value) ? strtolower(trim($value)) : $value;

			if (\in_array($v, [true, 1, '1', 'true', 'yes', 'on'], true)) {
				return '1';
			}

			if (\in_array($v, [false, 0, '0', 'false', 'no', 'off', ''], true)) {
				return '0';
			}

			throw new \InvalidArgumentException($key . ' is a checkbox: use true/false or 1/0.');
		}

		$string = \is_bool($value) ? ($value ? '1' : '0') : (string) $value;

		if (\in_array($type, self::G5_STRICT_OPTION_TYPES, true) && \is_array($field['options']) && $field['options'] !== []) {
			$allowed = array_map('strval', array_keys($field['options']));

			if (!\in_array($string, $allowed, true)) {
				throw new \InvalidArgumentException($key . ' must be one of: ' . implode(', ', array_map(static fn ($o) => $o === '' ? '"" (empty)' : $o, $allowed)) . '. Got "' . $string . '".');
			}
		}

		if ($type === 'input.number' && $string !== '') {
			if (!is_numeric($string)) {
				throw new \InvalidArgumentException($key . ' must be a number.');
			}

			if ($field['min'] !== null && $string + 0 < $field['min']) {
				throw new \InvalidArgumentException($key . ' must be at least ' . $field['min'] . '.');
			}

			if ($field['max'] !== null && $string + 0 > $field['max']) {
				throw new \InvalidArgumentException($key . ' must be at most ' . $field['max'] . '.');
			}
		}

		// The blueprint `pattern` is an HTML5 pattern attribute: it must match the whole value.
		if ($field['pattern'] !== null && $string !== '') {
			$regex = '/^(?:' . str_replace('/', '\/', $field['pattern']) . ')$/u';

			if (@preg_match($regex, '') !== false && preg_match($regex, $string) !== 1) {
				throw new \InvalidArgumentException($key . ' must match the pattern ' . $field['pattern'] . ' (from ' . $what . '\'s blueprint). Got "' . $string . '".');
			}
		}

		return $string;
	}

	/** Keyvalue and collection item values: nested arrays of strings only. */
	protected function g5RequireScalarTree(mixed $value, string $key): mixed
	{
		if (\is_bool($value)) {
			return $value ? '1' : '0';
		}

		if (\is_scalar($value)) {
			return (string) $value;
		}

		if (\is_array($value)) {
			$out = [];

			foreach ($value as $k => $v) {
				$out[$k] = $this->g5RequireScalarTree($v, $key);
			}

			return $out;
		}

		throw new \InvalidArgumentException($key . ' contains a value that is not text.');
	}

	// -----------------------------------------------------------------------
	// Nested array helpers
	// -----------------------------------------------------------------------

	protected function g5SetPath(array &$data, array $path, mixed $value): void
	{
		$ref = &$data;

		foreach ($path as $i => $part) {
			if ($i === \count($path) - 1) {
				$ref[$part] = $value;
				break;
			}

			if (!isset($ref[$part]) || !\is_array($ref[$part])) {
				$ref[$part] = [];
			}

			$ref = &$ref[$part];
		}
	}

	/** Remove a nested key and any parent it leaves empty. Returns whether it existed. */
	protected function g5UnsetPath(array &$data, array $path): bool
	{
		$part = array_shift($path);

		if ($part === null || !\array_key_exists($part, $data)) {
			return false;
		}

		if ($path === []) {
			unset($data[$part]);

			return true;
		}

		if (!\is_array($data[$part])) {
			return false;
		}

		$found = $this->g5UnsetPath($data[$part], $path);

		if ($found && $data[$part] === []) {
			unset($data[$part]);
		}

		return $found;
	}

	protected function g5GetPath(array $data, array $path, ?bool &$found = null): mixed
	{
		$found = false;

		foreach ($path as $part) {
			if (!\is_array($data) || !\array_key_exists($part, $data)) {
				return null;
			}

			$data = $data[$part];
		}

		$found = true;

		return $data;
	}

	/**
	 * Every scalar or list leaf of a nested array as dotted key => value (maps
	 * are walked; lists are leaves).
	 *
	 * @return array<string,mixed>
	 */
	protected function g5FlattenData(array $data, string $prefix = ''): array
	{
		$out = [];

		foreach ($data as $k => $v) {
			$key = $prefix === '' ? (string) $k : $prefix . '.' . $k;

			if (\is_array($v) && $v !== [] && !array_is_list($v)) {
				$out += $this->g5FlattenData($v, $key);
			} else {
				$out[$key] = $v;
			}
		}

		return $out;
	}
}
