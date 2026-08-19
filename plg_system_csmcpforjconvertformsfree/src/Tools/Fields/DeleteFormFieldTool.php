<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\FormFieldTrait;
use Joomla\CMS\User\User;

final class DeleteFormFieldTool extends AbstractTool
{
	use ConvertFormsBootTrait;
	use FormFieldTrait;

	public function getName(): string { return 'delete_convertforms_form_field'; }

	public function getDescription(): string
	{
		return 'Remove a field from a Convert Forms form, identified by map key, key '
			. 'or name. Historical submissions keep the values already collected '
			. 'under that field name — they simply stop being displayed — so the '
			. 'tool reports how many rows hold data for it and requires confirmation '
			. 'when that count is above zero.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['form_id', 'field'],
			'properties' => [
				'form_id' => ['type' => 'integer'],
				'field'   => ['type' => 'string', 'description' => 'Map key ("fields2"), key ("2"), or name ("email").'],
				'confirm' => [
					'type'        => 'boolean',
					'description' => 'Required when existing submissions hold data for this field.',
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
				. '. Use list_convertforms_form_fields to see the available identifiers.'
			);
		}

		$field = $fields[$mapKey];
		$name  = isset($field['name']) ? (string) $field['name'] : '';
		$type  = strtolower((string) ($field['type'] ?? ''));

		$holding = $this->submissionsHolding($formId, $name);

		if ($holding > 0 && empty($arguments['confirm'])) {
			return ToolResult::error(
				'Field "' . ($name !== '' ? $name : $mapKey) . '" has data in ' . $holding
				. ' existing submission(s). Deleting the field does not delete that data, but '
				. 'nothing will display it any more because the label and type live on the '
				. 'field definition. Re-run with confirm=true to proceed.'
			);
		}

		// Removing the only submit button leaves a form that renders but cannot
		// be submitted — a failure nobody notices until a visitor tries.
		if ($type === 'submit') {
			$remaining = 0;
			foreach ($fields as $otherKey => $other) {
				if ((string) $otherKey !== $mapKey && strtolower((string) ($other['type'] ?? '')) === 'submit') {
					$remaining++;
				}
			}
			if ($remaining === 0 && empty($arguments['confirm'])) {
				return ToolResult::error(
					'This is the form\'s only submit button. Removing it leaves a form that '
					. 'renders but cannot be submitted. Re-run with confirm=true if that is '
					. 'intended, or add a replacement submit field first.'
				);
			}
		}

		unset($fields[$mapKey]);

		$params           = $form['params'];
		$params['fields'] = $fields;

		$out = $this->cfSaveForm(
			['id' => $formId, 'name' => $form['name'], 'state' => $form['state'], 'ordering' => $form['ordering']],
			$params
		);

		if ($out['id'] <= 0) {
			return ToolResult::error('Field delete failed: ' . ($out['error'] ?: 'unknown error'));
		}

		return ToolResult::json([
			'ok'              => true,
			'form_id'         => $formId,
			'deleted_map_key' => $mapKey,
			'deleted_name'    => $name !== '' ? $name : null,
			'deleted_type'    => $type,
			'field_count'     => count($fields),
			'submissions_still_holding_value' => $holding,
			'save_warnings'   => $out['error'] ?: null,
		]);
	}

	/** @see UpdateFormFieldTool::submissionsHolding() for the LIKE-vs-JSON_EXTRACT rationale. */
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
