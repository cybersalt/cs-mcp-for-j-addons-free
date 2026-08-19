<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\FormFieldTrait;
use Joomla\CMS\User\User;

final class AddFormFieldTool extends AbstractTool
{
	use ConvertFormsBootTrait;
	use FormFieldTrait;

	public function getName(): string { return 'add_convertforms_form_field'; }

	public function getDescription(): string
	{
		return 'Add one or more fields to an existing Convert Forms form. New fields '
			. 'go at the end by default; use before to insert ahead of an existing '
			. 'field (by map key, key or name). Field keys are allocated so they '
			. 'never collide with keys already in use, including keys freed by '
			. 'earlier deletions. Choice types need a "choices" list or the save '
			. 'is rejected.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['form_id', 'fields'],
			'properties' => [
				'form_id' => ['type' => 'integer'],
				'fields'  => [
					'type'        => 'array',
					'description' => 'Ordered list of new fields. Each needs "type"; input types also take name, label, description, required, placeholder, value, size, cssclass. dropdown/radio/checkbox need "choices": [{label, value}].',
					'items'       => ['type' => 'object'],
				],
				'before' => [
					'type'        => 'string',
					'description' => 'Insert before this existing field (map key "fields2", key "2", or name "email"). Default: append at the end.',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	/**
	 * "add_" is not one of the prefixes AbstractTool auto-classifies, and its
	 * fallback marks a tool non-idempotent-and-unknown. Be explicit: this
	 * creates something new (so not idempotent — calling twice adds two fields)
	 * but destroys nothing.
	 */
	public function getMcpAnnotations(): array
	{
		return [
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		];
	}

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

		$definitions = $arguments['fields'] ?? null;
		if (!is_array($definitions) || $definitions === []) {
			return ToolResult::error('"fields" must be a non-empty list of field definitions.');
		}

		$existing = $this->cfFields($form['params']);

		// Refuse a duplicate submitted-value name up front: Convert Forms stores
		// submissions in a flat object keyed by name, so two fields sharing one
		// name means the second silently overwrites the first on every submit.
		$taken = [];
		foreach ($existing as $field) {
			if (!empty($field['name'])) {
				$taken[strtolower((string) $field['name'])] = true;
			}
		}
		foreach ($definitions as $definition) {
			if (!is_array($definition) || empty($definition['name'])) {
				continue;
			}
			$candidate = strtolower(trim((string) $definition['name']));
			if (isset($taken[$candidate])) {
				return ToolResult::error(
					'Form ' . $formId . ' already has a field named "' . $definition['name']
					. '". Submitted values are keyed by name, so a duplicate would '
					. 'overwrite the existing field\'s data on every submission. '
					. 'Choose a different name.'
				);
			}
			$taken[$candidate] = true;
		}

		try {
			$new = $this->cfBuildFieldMap($definitions, $existing);
		} catch (\InvalidArgumentException $e) {
			return ToolResult::error($e->getMessage());
		}

		$before = array_key_exists('before', $arguments) ? trim((string) $arguments['before']) : '';

		if ($before !== '') {
			$anchor = $this->cfFindFieldKey($existing, $before);
			if ($anchor === null) {
				return ToolResult::error(
					'No field matching "' . $before . '" on form ' . $formId
					. '. Use list_convertforms_form_fields to see the map keys, keys and names.'
				);
			}

			$merged = [];
			foreach ($existing as $mapKey => $field) {
				if ((string) $mapKey === $anchor) {
					foreach ($new as $k => $v) {
						$merged[$k] = $v;
					}
				}
				$merged[$mapKey] = $field;
			}
			$fields = $merged;
		} else {
			$fields = $existing;
			foreach ($new as $k => $v) {
				$fields[$k] = $v;
			}
		}

		$params           = $form['params'];
		$params['fields'] = $fields;

		$out = $this->cfSaveForm(
			['id' => $formId, 'name' => $form['name'], 'state' => $form['state'], 'ordering' => $form['ordering']],
			$params
		);

		if ($out['id'] <= 0) {
			return ToolResult::error('Adding fields failed: ' . ($out['error'] ?: 'unknown error'));
		}

		$saved = $this->cfFetchForm($formId);

		return ToolResult::json([
			'ok'            => true,
			'form_id'       => $formId,
			'added'         => array_values(array_map(
				static fn($k, $f) => ['map_key' => $k, 'key' => $f['key'] ?? null, 'type' => $f['type'] ?? null, 'name' => $f['name'] ?? null],
				array_keys($new),
				$new
			)),
			'added_count'   => count($new),
			'inserted_before' => $before !== '' ? $before : null,
			'field_count'   => $saved === null ? null : count($this->cfFields($saved['params'])),
			'save_warnings' => $out['error'] ?: null,
		]);
	}
}
