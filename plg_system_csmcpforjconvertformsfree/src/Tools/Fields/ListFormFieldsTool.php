<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\FormFieldTrait;
use Joomla\CMS\User\User;

final class ListFormFieldsTool extends AbstractTool
{
	use ConvertFormsBootTrait;
	use FormFieldTrait;

	public function getName(): string { return 'list_convertforms_form_fields'; }

	public function getDescription(): string
	{
		return 'List a form\'s fields in display order, each with its map key, own '
			. 'key, type, name, label and required flag. The "name" is what '
			. 'submitted values are stored under — you need it to read submissions. '
			. 'Pass include_settings=true for every configured property of each field.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['form_id'],
			'properties' => [
				'form_id' => ['type' => 'integer'],
				'include_settings' => [
					'type'        => 'boolean',
					'description' => 'Include all per-field properties, not just the summary. Default false.',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->cfAdminBase() === null) {
			return $this->notInstalledError();
		}

		$formId = $this->requirePositiveInt($arguments, 'form_id');
		$form   = $this->cfFetchForm($formId);

		if ($form === null) {
			return ToolResult::error('Convert Forms form ' . $formId . ' not found.');
		}

		$withSettings = !empty($arguments['include_settings']);
		$noInput      = $this->cfNoInputFieldTypes();
		$usable       = $this->cfAvailableFieldTypes();

		$position = 0;
		$fields   = [];

		foreach ($this->cfFields($form['params']) as $mapKey => $field) {
			$type = strtolower((string) ($field['type'] ?? ''));

			$entry = [
				'position'      => $position++,
				'map_key'       => (string) $mapKey,
				'key'           => (string) ($field['key'] ?? ''),
				'type'          => $type,
				'name'          => $field['name'] ?? null,
				'label'         => $field['label'] ?? null,
				'required'      => isset($field['required']) ? (string) $field['required'] === '1' : null,
				'accepts_input' => !in_array($type, $noInput, true),
			];

			// Surface a field whose type is no longer installed: it will not render
			// and submissions will silently skip it.
			if ($usable !== [] && !in_array($type, $usable, true)) {
				$entry['warning'] = 'Field type "' . $type . '" has no class on this install — '
					. 'it will not render and its value is never captured. '
					. (
						$this->cfIsPro()
							? 'The type may have been removed in this Convert Forms release.'
							: 'This is the free edition and the type is Pro-only.'
					);
			}

			if ($withSettings) {
				$entry['settings'] = $field;
			}

			$fields[] = $entry;
		}

		return ToolResult::json([
			'ok'        => true,
			'form_id'   => $formId,
			'form_name' => $form['name'],
			'count'     => count($fields),
			'fields'    => $fields,
			'notes'     => [
				'Order here is the stored map order, which is the render order.',
				'map_key / key / name are three different identifiers. The field '
					. 'tools accept any of them; submissions are keyed by name.',
			],
		]);
	}
}
