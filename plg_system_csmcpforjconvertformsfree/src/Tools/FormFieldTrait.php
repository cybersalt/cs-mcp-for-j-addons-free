<?php
/**
 * @package     MCP for Convert Forms
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

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
 *
 * Every new field starts from Convert Forms' own defaults for its type (see
 * cfFieldDefaults()). The layouts depend on them: controlgroup.php prints a
 * label only when `hidelabel` is SET and false, heading.php opens a tag named
 * by `heading_type`, submit.php sizes the button from `fontsize` / `vpadding` /
 * `hpadding`, and html.php prints `text`. A field stored with only the keys a
 * caller happened to send renders with no labels, a zero-size button and a
 * broken heading tag (GitHub issue #14).
 */
trait FormFieldTrait
{
	/** Per-request cache of cfFieldDefaults(), keyed by field type. */
	private static array $cfFieldDefaultsCache = [];

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

		// Start from what Convert Forms' own field editor would store for a new
		// field of this type, then lay the caller's settings over it.
		$defaults = $this->cfFieldDefaults($type);
		foreach ($defaults as $prop => $value) {
			if ($prop !== 'type' && $prop !== 'key') {
				$field[(string) $prop] = $value;
			}
		}

		$definition = $this->cfApplyFieldAliases($type, $definition);

		foreach ($definition as $prop => $value) {
			if ($prop === 'type' || $prop === 'key') {
				continue;
			}
			$field[(string) $prop] = $this->cfNormaliseFieldValue($value);
		}

		// The editor's "Required" toggle starts switched on, and a field with no
		// `required` key at all is validated as required by ConvertForms\Field
		// (its constructor falls back to $required = true) while rendering as
		// optional. Store an explicit "0" unless the caller asked for required.
		$takesRequired = array_key_exists('required', $defaults)
			|| ($defaults === [] && !in_array($type, $this->cfNoInputFieldTypes(), true));
		if ($takesRequired && !array_key_exists('required', $definition)) {
			$field['required'] = '0';
		}

		// Without the vendor definitions (an unusual install), still store the
		// key the label markup depends on.
		if ($defaults === [] && !array_key_exists('hidelabel', $field) && !in_array($type, $this->cfNoInputFieldTypes(), true)) {
			$field['hidelabel'] = '0';
		}

		if ($type === 'heading') {
			$field['heading_type'] = $this->cfHeadingTag($field['heading_type'] ?? 'h2');
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

	/**
	 * What Convert Forms' field editor stores for a new field of this type.
	 *
	 * The editor renders ConvertForms\Field::getOptionsForm() for the new field:
	 * a Joomla Form built from ConvertForms/xml/field.xml minus the class's
	 * $excludeFields, plus ConvertForms/xml/field/<type>.xml, plus whatever the
	 * convertformstools plugins add on onConvertFormsBackendRenderOptionsForm.
	 * Saving posts every input of that form. This builds the same form (without
	 * rendering it) and reads the value each input would post: its default, the
	 * first option of a list with no matching default, and "1" / "0" for the
	 * nrtoggle switches.
	 *
	 * Returns [] when the definitions cannot be found, so callers degrade to the
	 * old behaviour rather than failing.
	 *
	 * @return array<string, mixed>
	 */
	protected function cfFieldDefaults(string $type): array
	{
		$type = strtolower(trim($type));

		if (array_key_exists($type, self::$cfFieldDefaultsCache)) {
			return self::$cfFieldDefaultsCache[$type];
		}

		$defaults = [];
		$base     = $this->cfAdminBase();
		$dir      = $base === null ? '' : $base . '/ConvertForms/xml';

		if ($base !== null && preg_match('/^[a-z0-9_]+$/', $type) === 1 && is_file($dir . '/field.xml')) {
			$xml = $this->cfFieldOptionsXml($type, $dir);

			if ($xml !== null) {
				$defaults = $this->cfXmlDefaults($xml);
			}
		}

		unset($defaults['key'], $defaults['type']);

		return self::$cfFieldDefaultsCache[$type] = $defaults;
	}

	/**
	 * The options-form XML for one field type, built the way
	 * ConvertForms\Field::getOptionsForm() builds it.
	 */
	private function cfFieldOptionsXml(string $type, string $dir): ?\SimpleXMLElement
	{
		$exclude  = $this->cfFieldExcludes($type);
		$typeFile = $dir . '/field/' . $type . '.xml';

		try {
			$form = new \Joomla\CMS\Form\Form('csmcpforj_cf_field_' . $type, ['control' => 'jform']);
			$form->loadFile($dir . '/field.xml');

			if ($exclude !== []) {
				$all = in_array('*', $exclude, true);
				foreach ($form->getXml()->xpath('//field[@name]') ?: [] as $node) {
					$name = (string) $node['name'];
					if ($name !== 'key' && $name !== 'type' && ($all || in_array($name, $exclude, true))) {
						$form->removeField($name);
					}
				}
			}

			if (is_file($typeFile)) {
				$form->loadFile($typeFile);
			}

			// Let the convertformstools plugins add their options, as the editor does.
			$app = \Joomla\CMS\Factory::getApplication();
			if (method_exists($app, 'triggerEvent')) {
				try {
					$app->triggerEvent('onConvertFormsBackendRenderOptionsForm', [&$form, $type]);
				} catch (\Throwable $e) {
					// A plugin failing here only costs its own defaults.
				}
			}

			$xml = $form->getXml();

			return $xml instanceof \SimpleXMLElement ? $xml : null;
		} catch (\Throwable $e) {
			// Fall back to reading the two files directly.
		}

		$xml = @simplexml_load_file($dir . '/field.xml');
		if ($xml === false) {
			return null;
		}

		$all = in_array('*', $exclude, true);
		foreach ($xml->xpath('//field[@name]') ?: [] as $node) {
			$name = (string) $node['name'];
			if ($name !== 'key' && $name !== 'type' && ($all || in_array($name, $exclude, true))) {
				$dom = dom_import_simplexml($node);
				$dom->parentNode?->removeChild($dom);
			}
		}

		if (is_file($typeFile)) {
			$extra = @simplexml_load_file($typeFile);
			if ($extra !== false) {
				// Wrap both so the type's own fields win over same-named common ones.
				$merged = new \SimpleXMLElement('<form/>');
				foreach ([$xml, $extra] as $part) {
					$target = dom_import_simplexml($merged);
					foreach ($part->children() as $child) {
						$target->appendChild($target->ownerDocument->importNode(dom_import_simplexml($child), true));
					}
				}

				return $merged;
			}
		}

		return $xml;
	}

	/**
	 * The vendor field class's $excludeFields: common options its editor hides.
	 *
	 * @return array<int, string>
	 */
	private function cfFieldExcludes(string $type): array
	{
		$class = '\\ConvertForms\\Field\\' . ucfirst($type);

		if (!class_exists($class)) {
			return [];
		}

		try {
			$reflection = new \ReflectionClass($class);
			if (!$reflection->hasProperty('excludeFields')) {
				return [];
			}

			$property = $reflection->getProperty('excludeFields');
			$property->setAccessible(true);
			$value = $property->getValue($reflection->newInstanceWithoutConstructor());

			return is_array($value) ? array_values(array_map('strval', $value)) : [];
		} catch (\Throwable $e) {
			return [];
		}
	}

	/**
	 * What a new form saved from Convert Forms' editor carries for its
	 * submission, behaviour and design settings (models/forms/form.xml and
	 * form_design.xml). Without these, FieldsHelper::prepare() falls back to its
	 * own hard-coded values, which differ from the editor's: a 2px solid black
	 * border, 20px padding and full width instead of none, 0 and 500px.
	 *
	 * Real columns (id, name, state), the fields map and legacy campaigns are
	 * left out.
	 *
	 * @return array<string, mixed>
	 */
	protected function cfFormDefaults(): array
	{
		$base = $this->cfAdminBase();
		if ($base === null) {
			return [];
		}

		$out = [];

		foreach (['form.xml', 'form_design.xml'] as $file) {
			$path = $base . '/models/forms/' . $file;
			$xml  = is_file($path) ? @simplexml_load_file($path) : false;

			if ($xml !== false) {
				$out = array_replace($out, $this->cfXmlDefaults($xml));
			}
		}

		unset($out['id'], $out['name'], $out['state'], $out['fields'], $out['campaign']);

		return $out;
	}

	/**
	 * Walk a Joomla form XML tree and collect the value each input would post
	 * when nobody has touched it. <fields name="x"> groups become nested arrays.
	 *
	 * @return array<string, mixed>
	 */
	private function cfXmlDefaults(\SimpleXMLElement $node): array
	{
		$out = [];

		foreach ($node->children() as $child) {
			$tag = $child->getName();

			if ($tag === 'fieldset' || $tag === 'form') {
				$out = array_replace($out, $this->cfXmlDefaults($child));
				continue;
			}

			if ($tag === 'fields') {
				$group = (string) $child['name'];
				$sub   = $this->cfXmlDefaults($child);

				if ($group === '') {
					$out = array_replace($out, $sub);
				} elseif ($sub !== []) {
					$out[$group] = array_replace(is_array($out[$group] ?? null) ? $out[$group] : [], $sub);
				}
				continue;
			}

			if ($tag !== 'field') {
				continue;
			}

			$value = $this->cfXmlFieldDefault($child);
			if ($value !== null) {
				$out[(string) $child['name']] = $value;
			}
		}

		return $out;
	}

	/**
	 * The value one <field> posts untouched, or null when it posts nothing worth
	 * storing (notes, Pro teasers, editors, pickers, complex custom widgets).
	 */
	private function cfXmlFieldDefault(\SimpleXMLElement $field): string|array|null
	{
		$name = (string) $field['name'];
		if ($name === '' || $name[0] === '@' || in_array($name, ['key', 'type', 'id'], true)) {
			return null;
		}

		$type    = strtolower((string) ($field['type'] ?? '')) ?: 'text';
		$default = isset($field['default']) ? (string) $field['default'] : null;

		// Convert Forms' own input-mask widget posts a pair (models/forms/fields/inputmask.php).
		if ($type === 'inputmask') {
			return ['options' => '', 'custom' => ''];
		}

		if (in_array($type, [
			'note', 'spacer', 'nr_pro', 'nr_well', 'nr_choices', 'tfimagedimensionscontrol',
			'plugins', 'editor', 'media', 'menuitem', 'campaigns', 'formfields', 'subform', 'checkbox', 'checkboxes',
		], true) || (string) ($field['multiple'] ?? '') === 'true') {
			return null;
		}

		if ($type === 'nrtoggle') {
			$checked = strtolower((string) ($field['checked'] ?? ''));
			$on      = in_array($checked, ['true', 'checked', '1'], true)
				|| ($default !== null && $default !== '' && $default !== '0');

			return $on ? '1' : '0';
		}

		if ($type === 'list' || $type === 'radio') {
			$options = [];
			foreach ($field->option as $option) {
				$options[] = (string) ($option['value'] ?? '');
			}

			// A <select> whose value matches no option shows, and posts, its first one.
			if ($default !== null && ($options === [] || in_array($default, $options, true))) {
				return $default;
			}

			return $options !== [] ? $options[0] : ($default ?? '');
		}

		if ($default !== null) {
			return $default;
		}

		return in_array($type, ['text', 'textarea', 'hidden', 'color', 'nrnumber', 'number', 'cssclasses', 'url', 'email', 'tel'], true)
			? ''
			: null;
	}

	/**
	 * Accept the property names an agent is likely to try for the three types
	 * whose content key is not the obvious one, and map them to what Convert
	 * Forms reads:
	 *
	 *   - html:    text (layouts/fields/html.php). Accepts html, content, value.
	 *   - submit:  text (layouts/fields/submit.php). Accepts label.
	 *   - heading: label for the words, heading_type for the tag. Accepts text,
	 *              and headingtype / heading_tag / tag / level for the tag.
	 *
	 * An explicit value under the real key always wins.
	 *
	 * @param array<string, mixed> $definition
	 * @return array<string, mixed>
	 */
	protected function cfApplyFieldAliases(string $type, array $definition): array
	{
		$move = static function (array $def, array $aliases, string $target): array {
			foreach ($aliases as $alias) {
				if (!array_key_exists($alias, $def)) {
					continue;
				}
				if ((!array_key_exists($target, $def) || $def[$target] === null || $def[$target] === '') && $def[$alias] !== null) {
					$def[$target] = $def[$alias];
				}
				unset($def[$alias]);
			}

			return $def;
		};

		return match ($type) {
			'html'    => $move($definition, ['html', 'content', 'value'], 'text'),
			'submit'  => $move($definition, ['label'], 'text'),
			'heading' => $move($move($definition, ['text'], 'label'), ['headingtype', 'heading_tag', 'tag', 'level'], 'heading_type'),
			default   => $definition,
		};
	}

	/**
	 * A heading tag Convert Forms' editor can store: h1 to h6. Accepts 1-6 too.
	 * layouts/fields/heading.php prints the value straight into the tag name.
	 *
	 * @throws \InvalidArgumentException for anything else.
	 */
	protected function cfHeadingTag(mixed $value): string
	{
		$tag = strtolower(trim((string) $value));

		if (preg_match('/^[1-6]$/', $tag) === 1) {
			$tag = 'h' . $tag;
		}

		if (preg_match('/^h[1-6]$/', $tag) !== 1) {
			throw new \InvalidArgumentException('heading_type must be one of h1, h2, h3, h4, h5 or h6 (got "' . (string) $value . '").');
		}

		return $tag;
	}

	/**
	 * Add any of the type's editor defaults that an existing field lacks,
	 * without touching keys it already has. Repairs fields stored bare by
	 * earlier versions of this add-on when they are next edited. `required` is
	 * left alone: a field without it is already treated as required when
	 * submitted, and only the caller should change that.
	 *
	 * @param array<string, mixed> $field
	 * @return array{0: array<string, mixed>, 1: array<int, string>} the field and the keys added
	 */
	protected function cfBackfillFieldDefaults(array $field): array
	{
		$type  = strtolower((string) ($field['type'] ?? ''));
		$added = [];

		foreach ($this->cfFieldDefaults($type) as $prop => $value) {
			if ($prop === 'required' || array_key_exists($prop, $field)) {
				continue;
			}
			$field[$prop] = $value;
			$added[]      = (string) $prop;
		}

		return [$field, $added];
	}
}
