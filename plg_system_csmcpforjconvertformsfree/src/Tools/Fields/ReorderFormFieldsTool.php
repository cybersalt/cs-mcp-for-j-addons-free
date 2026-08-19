<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\FormFieldTrait;
use Joomla\CMS\User\User;

final class ReorderFormFieldsTool extends AbstractTool
{
	use ConvertFormsBootTrait;
	use FormFieldTrait;

	public function getName(): string { return 'reorder_convertforms_form_fields'; }

	public function getDescription(): string
	{
		return 'Set the display order of a form\'s fields. Convert Forms has no '
			. '"ordering" property on a field — render order IS the order of the '
			. 'stored fields map — so reordering means rewriting that map. Pass the '
			. 'complete desired order; any field you omit is appended in its current '
			. 'relative position rather than being dropped.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['form_id', 'order'],
			'properties' => [
				'form_id' => ['type' => 'integer'],
				'order'   => [
					'type'        => 'array',
					'description' => 'Field identifiers (map key, key or name) in the desired top-to-bottom order.',
					'items'       => ['type' => 'string'],
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	/**
	 * "reorder_" is not an auto-classified prefix. This is a write, but it is
	 * genuinely idempotent — applying the same order twice leaves the same
	 * result — and it never destroys a field.
	 */
	public function getMcpAnnotations(): array
	{
		return [
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => true,
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

		$fields = $this->cfFields($form['params']);
		if ($fields === []) {
			return ToolResult::error('Form ' . $formId . ' has no fields to reorder.');
		}

		$order = $arguments['order'] ?? null;
		if (!is_array($order) || $order === []) {
			return ToolResult::error('"order" must be a non-empty list of field identifiers.');
		}

		$reordered = [];
		$seen      = [];

		foreach ($order as $selector) {
			$selector = trim((string) $selector);
			$mapKey   = $this->cfFindFieldKey($fields, $selector);

			if ($mapKey === null) {
				return ToolResult::error(
					'No field matching "' . $selector . '" on form ' . $formId
					. '. Use list_convertforms_form_fields to see the available identifiers.'
				);
			}

			if (isset($seen[$mapKey])) {
				return ToolResult::error(
					'Field "' . $selector . '" appears more than once in "order" '
					. '(it resolves to ' . $mapKey . ', already placed).'
				);
			}

			$seen[$mapKey]      = true;
			$reordered[$mapKey] = $fields[$mapKey];
		}

		// Anything the caller left out keeps its existing relative order, appended
		// after the explicitly ordered fields. Dropping them would silently delete
		// fields on a partial call.
		$appended = [];
		foreach ($fields as $mapKey => $field) {
			if (!isset($seen[$mapKey])) {
				$reordered[$mapKey] = $field;
				$appended[]         = (string) $mapKey;
			}
		}

		$params           = $form['params'];
		$params['fields'] = $reordered;

		$out = $this->cfSaveForm(
			['id' => $formId, 'name' => $form['name'], 'state' => $form['state'], 'ordering' => $form['ordering']],
			$params
		);

		if ($out['id'] <= 0) {
			return ToolResult::error('Field reorder failed: ' . ($out['error'] ?: 'unknown error'));
		}

		$final = [];
		foreach ($reordered as $mapKey => $field) {
			$final[] = [
				'map_key' => (string) $mapKey,
				'type'    => $field['type'] ?? null,
				'name'    => $field['name'] ?? null,
				'label'   => $field['label'] ?? null,
			];
		}

		return ToolResult::json([
			'ok'              => true,
			'form_id'         => $formId,
			'order'           => $final,
			'appended_unlisted' => $appended,
			'save_warnings'   => $out['error'] ?: null,
		]);
	}
}
