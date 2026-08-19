<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\FormFieldTrait;
use Joomla\CMS\User\User;

final class UpdateFormFieldTool extends AbstractTool
{
	use ConvertFormsBootTrait;
	use FormFieldTrait;

	public function getName(): string { return 'update_convertforms_form_field'; }

	public function getDescription(): string
	{
		return 'Change properties of one field on a Convert Forms form. Identify the '
			. 'field by map key ("fields2"), key ("2") or name ("email"). Only the '
			. 'properties you send are changed; send null to remove one. Renaming a '
			. 'field is guarded — submitted values are keyed by the old name, so the '
			. 'tool refuses unless you confirm, and tells you how many submissions '
			. 'would be affected.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['form_id', 'field'],
			'properties' => [
				'form_id' => ['type' => 'integer'],
				'field'   => ['type' => 'string', 'description' => 'Map key, key, or name of the field to update.'],
				'settings' => [
					'type'        => 'object',
					'description' => 'Properties to change, e.g. {"label":"Your email","required":true,"placeholder":"you@example.com"}. null removes a property. For choice fields pass "choices": [{label, value}].',
				],
				'confirm_rename' => [
					'type'        => 'boolean',
					'description' => 'Required to change "name" on a field that already has submitted data.',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ensureCfLoaded()) {
			return $this->notInstalledError();
		}

		$formId = $this->requirePositiveInt($arguments, 'form_id');
		$form   = $this->cfFetchForm($formId);

		if ($form === null) {
			return ToolResult::error('Convert Forms form ' . $formId . ' not found.');
		}

		$fields   = $this->cfFields($form['params']);
		$selector = $this->requireString($arguments, 'field');
		$mapKey   = $this->cfFindFieldKey($fields, $selector);

		if ($mapKey === null) {
			return ToolResult::error(
				'No field matching "' . $selector . '" on form ' . $formId
				. '. Use list_convertforms_form_fields to see the available map keys, keys and names.'
			);
		}

		$settings = $arguments['settings'] ?? null;
		if (!is_array($settings) || $settings === []) {
			return ToolResult::error('"settings" must be a non-empty object of properties to change.');
		}

		$field = $fields[$mapKey];
		$type  = strtolower((string) ($field['type'] ?? ''));

		// key and type are structural. Changing key would desynchronise it from
		// the map key; changing type would leave type-specific properties from
		// the old type behind. Both are better expressed as delete + add.
		foreach (['key', 'type'] as $structural) {
			if (array_key_exists($structural, $settings)) {
				return ToolResult::error(
					'Cannot change "' . $structural . '" with this tool — it is structural. '
					. 'Delete the field and add a replacement instead '
					. '(delete_convertforms_form_field then add_convertforms_form_field with before=).'
				);
			}
		}

		$oldName = isset($field['name']) ? (string) $field['name'] : '';

		if (array_key_exists('name', $settings)) {
			$newName = trim((string) $settings['name']);

			if ($newName === '' && !in_array($type, $this->cfNoInputFieldTypes(), true)) {
				return ToolResult::error('An input field cannot have an empty name — submitted values are stored under it.');
			}

			if ($newName !== '' && strtolower($newName) !== strtolower($oldName)) {
				foreach ($fields as $otherKey => $other) {
					if ((string) $otherKey === $mapKey) {
						continue;
					}
					if (isset($other['name']) && strtolower((string) $other['name']) === strtolower($newName)) {
						return ToolResult::error(
							'Form ' . $formId . ' already has a field named "' . $newName
							. '". Two fields cannot share a name — submissions are keyed by it.'
						);
					}
				}

				$affected = $this->submissionsHolding($formId, $oldName);

				if ($affected > 0 && empty($arguments['confirm_rename'])) {
					return ToolResult::error(
						'Renaming "' . $oldName . '" to "' . $newName . '" would orphan the data in '
						. $affected . ' existing submission(s): Convert Forms stores submitted values '
						. 'keyed by field name, and this tool does not rewrite historical rows. The old '
						. 'values remain in the database under "' . $oldName . '" but stop lining up with '
						. 'the form. Re-run with confirm_rename=true to proceed anyway.'
					);
				}
			}
		}

		// Apply the patch.
		foreach ($settings as $prop => $value) {
			$prop = (string) $prop;

			if ($value === null) {
				unset($field[$prop]);
				continue;
			}

			if ($prop === 'choices') {
				$field['choices'] = $this->cfNormaliseChoices($value);
				continue;
			}

			$field[$prop] = $this->cfNormaliseFieldValue($value);
		}

		if (in_array($type, ['dropdown', 'radio', 'checkbox'], true)) {
			try {
				$this->cfAssertHasChoices($field, $type);
			} catch (\InvalidArgumentException $e) {
				return ToolResult::error($e->getMessage());
			}
		}

		$fields[$mapKey]  = $field;
		$params           = $form['params'];
		$params['fields'] = $fields;

		$out = $this->cfSaveForm(
			['id' => $formId, 'name' => $form['name'], 'state' => $form['state'], 'ordering' => $form['ordering']],
			$params
		);

		if ($out['id'] <= 0) {
			return ToolResult::error('Field update failed: ' . ($out['error'] ?: 'unknown error'));
		}

		$renamed = isset($settings['name']) && strtolower(trim((string) $settings['name'])) !== strtolower($oldName);

		return ToolResult::json([
			'ok'      => true,
			'form_id' => $formId,
			'map_key' => $mapKey,
			'field'   => $field,
			'changed' => array_keys($settings),
			'renamed_from' => $renamed ? $oldName : null,
			'orphaned_submission_values' => $renamed ? $this->submissionsHolding($formId, $oldName) : 0,
			'save_warnings' => $out['error'] ?: null,
		]);
	}

	/**
	 * How many of this form's submissions carry a value under $name.
	 *
	 * Uses a LIKE against the JSON blob rather than JSON_EXTRACT so it works on
	 * MySQL 5.6 / older MariaDB too. It can over-count if a *value* happens to
	 * contain the same quoted key text, which is acceptable for a warning that
	 * only ever errs toward caution.
	 */
	private function submissionsHolding(int $formId, string $name): int
	{
		if ($name === '' || !$this->cfTableExists('conversions')) {
			return 0;
		}

		$needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], '"' . $name . '":') . '%';

		$q = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName($this->cfTableName('conversions')))
			->where($this->db->quoteName('form_id') . ' = ' . $formId)
			->where($this->db->quoteName('params') . ' LIKE ' . $this->db->quote($needle));

		return (int) $this->db->setQuery($q)->loadResult();
	}
}
