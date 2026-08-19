<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools;

\defined('_JEXEC') or die;

/**
 * Shared logic for turning agent-friendly field definitions into the exact
 * shape Convert Forms stores inside `#__convertforms.params.fields`.
 *
 * The stored shape is an ordered map:
 *
 *     "fields": {
 *       "fields0": { "key": "0", "type": "email", "name": "email", "label": "Email address" },
 *       "fields2": { "key": "2", "type": "submit", "text": "Submit" }
 *     }
 *
 * Two invariants the vendor code depends on, both easy to violate by hand:
 *
 *   1. the map key is literally "fields" . $entry['key'], and
 *   2. every scalar property is stored as a STRING, including numeric ones.
 *
 * The React form builder writes "0" not 0, and several vendor field classes do
 * loose string comparisons (`$field['required'] == '1'`). Writing real ints or
 * bools produces a form that saves cleanly and then behaves subtly wrong, so
 * cfNormaliseFieldProps() stringifies scalars on the way in.
 *
 * Tools accept fields as a LIST (natural for a tool call, order is explicit)
 * and convert to the map here.
 */
trait FormFieldTrait
{
	/**
	 * Properties every field type understands, from ConvertForms/xml/field.xml.
	 * Anything outside this set is still accepted — per-type options live in
	 * ConvertForms/xml/field/<type>.xml and there are dozens — but these are the
	 * ones worth advertising in a tool schema.
	 */
	protected const COMMON_FIELD_PROPS = [
		'name', 'label', 'description', 'required', 'size', 'value', 'placeholder',
		'cssclass', 'inputcssclass', 'hidelabel', 'browserautocomplete',
	];

	/**
	 * Build a single stored field entry from a caller-supplied definition.
	 *
	 * @param array<string,mixed> $definition
	 * @param int                 $key        The field key to assign.
	 * @return array<string,mixed>
	 *
	 * @throws \InvalidArgumentException when type is missing or unusable.
	 */
	protected function cfBuildField(array $definition, int $key): array
	{
		$type = strtolower(trim((string) ($definition['type'] ?? '')));
		if ($type === '') {
			throw new \InvalidArgumentException('Each field needs a "type". Call list_convertforms_field_types to see the usable set.');
		}

		$usable = $this->cfAvailableFieldTypes();
		if ($usable !== [] && !in_array($type, $usable, true)) {
			throw new \InvalidArgumentException(
				'Field type "' . $type . '" is not usable on this install'
				. ($this->cfIsPro() ? '' : ' (this is the free edition; several types are Pro-only)')
				. '. Usable types: ' . implode(', ', $usable)
			);
		}

		$field = ['key' => (string) $key, 'type' => $type];

		foreach ($definition as $prop => $value) {
			if ($prop === 'type' || $prop === 'key') {
				continue;
			}
			$field[(string) $prop] = $this->cfNormaliseFieldValue($value);
		}

		// Input-bearing fields need a name — it is the key submitted values are
		// stored under. The vendor's Field::onBeforeFormSave() would auto-fill
		// "<type>_<key>", but that produces opaque column names, so default to
		// something derived from the label instead and let the caller override.
		if (!in_array($type, $this->cfNoInputFieldTypes(), true) && empty($field['name'])) {
			$field['name'] = $this->cfDeriveFieldName($field['label'] ?? '', $type, $key);
		}

		// Choice fields: coerce whatever shape the caller sent into the stored
		// one, then verify at least one label survives. A choice field with no
		// non-empty label makes ConvertForms\FieldChoice::onBeforeFormSave()
		// fail the ENTIRE form save with COM_CONVERTFORMS_NO_CHOICES, which
		// surfaces only as save() === false — catch it here instead.
		if (in_array($type, ['dropdown', 'radio', 'checkbox'], true)) {
			if (isset($field['choices'])) {
				$field['choices'] = $this->cfNormaliseChoices($field['choices']);
			}
			$this->cfAssertHasChoices($field, $type);
		}

		return $field;
	}

	/**
	 * Convert a list of field definitions into the stored ordered map,
	 * allocating keys that don't collide with $existing.
	 *
	 * @param array<int, array<string,mixed>> $definitions
	 * @param array<string, mixed>            $existing    Current fields map.
	 * @return array<string, array<string,mixed>>
	 */
	protected function cfBuildFieldMap(array $definitions, array $existing = []): array
	{
		$map     = [];
		$scratch = $existing;

		foreach ($definitions as $definition) {
			if (!is_array($definition)) {
				throw new \InvalidArgumentException('Each entry in "fields" must be an object describing one field.');
			}

			$key   = $this->cfNextFieldKey($scratch);
			$built = $this->cfBuildField($definition, $key);

			$map['fields' . $key]     = $built;
			$scratch['fields' . $key] = $built;
		}

		return $map;
	}

	/**
	 * Stringify scalars the way the form builder does, recursing into the
	 * nested structures used by choice lists and calculation settings.
	 * Booleans become "1" / "0" because that is what the vendor compares against.
	 */
	protected function cfNormaliseFieldValue(mixed $value): mixed
	{
		if (is_bool($value)) {
			return $value ? '1' : '0';
		}
		if (is_int($value) || is_float($value)) {
			return (string) $value;
		}
		if (is_array($value)) {
			$out = [];
			foreach ($value as $k => $v) {
				$out[$k] = $this->cfNormaliseFieldValue($v);
			}
			return $out;
		}

		return $value;
	}

	/**
	 * Derive a submitted-value key from a label: "Full Name" -> "fullName".
	 * Falls back to "<type>_<key>", which is what the vendor would have used.
	 */
	protected function cfDeriveFieldName(mixed $label, string $type, int $key): string
	{
		$label = trim((string) $label);
		if ($label === '') {
			return $type . '_' . $key;
		}

		$clean = preg_replace('/[^a-zA-Z0-9 ]+/', ' ', $label) ?? '';
		$parts = preg_split('/\s+/', trim($clean)) ?: [];
		$parts = array_values(array_filter($parts, static fn($p) => $p !== ''));

		if ($parts === []) {
			return $type . '_' . $key;
		}

		$name = strtolower(array_shift($parts));
		foreach ($parts as $part) {
			$name .= ucfirst(strtolower($part));
		}

		// A leading digit would be a poor identifier in downstream exports.
		if (ctype_digit($name[0])) {
			$name = $type . '_' . $name;
		}

		return $name;
	}

	/**
	 * Reject a choice field whose choices are all blank before the vendor does,
	 * because its failure surfaces only as save() === false with a language-key
	 * error that is hard to act on.
	 *
	 * Accepts both the stored shape (`choices.choices[]`) and the flatter
	 * `choices[]` an agent is likely to send.
	 *
	 * @param array<string,mixed> $field
	 */
	protected function cfAssertHasChoices(array $field, string $type): void
	{
		$choices = $field['choices'] ?? null;

		if (is_array($choices) && isset($choices['choices']) && is_array($choices['choices'])) {
			$choices = $choices['choices'];
		}

		if (!is_array($choices) || $choices === []) {
			throw new \InvalidArgumentException(
				'A "' . $type . '" field needs at least one choice. Supply '
				. 'choices as a list of {label, value} objects — Convert Forms '
				. 'rejects the whole form save when a choice field has none.'
			);
		}

		foreach ($choices as $choice) {
			$label = is_array($choice) ? ($choice['label'] ?? '') : $choice;
			if (trim((string) $label) !== '') {
				return;
			}
		}

		throw new \InvalidArgumentException(
			'Every choice on this "' . $type . '" field has an empty label. '
			. 'Convert Forms fails the entire form save in that case (COM_CONVERTFORMS_NO_CHOICES).'
		);
	}

	/**
	 * Normalise a caller-supplied choices value into the shape the vendor's
	 * FieldChoice::getOptions() reads: `choices.choices[] = {label, value, calc-value, default}`.
	 *
	 * @param mixed $raw
	 * @return array<string, mixed>
	 */
	protected function cfNormaliseChoices(mixed $raw): array
	{
		if (is_array($raw) && isset($raw['choices']) && is_array($raw['choices'])) {
			$raw = $raw['choices'];
		}

		if (!is_array($raw)) {
			return ['choices' => []];
		}

		$out = [];
		foreach ($raw as $choice) {
			if (is_string($choice)) {
				$out[] = ['label' => $choice, 'value' => $choice, 'calc-value' => '', 'default' => ''];
				continue;
			}
			if (!is_array($choice)) {
				continue;
			}

			$label = (string) ($choice['label'] ?? '');
			$out[] = [
				'label'      => $label,
				// The vendor falls back to the label when value is empty; make
				// that explicit so exports and task conditions are predictable.
				'value'      => (string) ($choice['value'] ?? $label),
				'calc-value' => (string) ($choice['calc-value'] ?? $choice['calc_value'] ?? ''),
				'default'    => !empty($choice['default']) ? '1' : '',
			];
		}

		return ['choices' => $out];
	}
}
