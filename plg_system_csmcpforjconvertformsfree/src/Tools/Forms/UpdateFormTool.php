<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Forms;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Joomla\CMS\User\User;

final class UpdateFormTool extends AbstractTool
{
	use ConvertFormsBootTrait;

	public function getName(): string { return 'update_convertforms_form'; }

	public function getDescription(): string
	{
		return 'Update a Convert Forms form\'s name, state, or params. The params '
			. 'patch is MERGED recursively into the existing blob — send only the '
			. 'keys you want to change and everything else survives. Send null as a '
			. 'value to delete that key. Use the dedicated field tools '
			. '(add/update/delete/reorder _convertforms_form_field) to change '
			. 'fields; this tool refuses a raw params.fields patch because that '
			. 'would silently drop every field you did not resend.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'    => ['type' => 'integer'],
				'name'  => ['type' => 'string'],
				'state' => ['type' => 'integer', 'enum' => [0, 1, 2, -2]],
				'params' => [
					'type'        => 'object',
					'description' => 'Recursive merge patch over params. e.g. {"successmsg":"Thanks!","onsuccess":"msg"}. null deletes a key.',
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

		$id      = $this->requirePositiveInt($arguments, 'id');
		$current = $this->cfFetchForm($id);

		if ($current === null) {
			return ToolResult::error('Convert Forms form ' . $id . ' not found.');
		}

		$patch = $arguments['params'] ?? [];
		if (!is_array($patch)) {
			$patch = [];
		}

		if (array_key_exists('fields', $patch)) {
			return ToolResult::error(
				'Refusing to patch params.fields through update_convertforms_form — '
				. 'fields is an ordered map and a partial patch would drop the fields '
				. 'you did not resend. Use add_convertforms_form_field, '
				. 'update_convertforms_form_field, delete_convertforms_form_field or '
				. 'reorder_convertforms_form_fields instead.'
			);
		}

		$params = $this->cfMergeParams($current['params'], $patch);

		$data = [
			'id'       => $id,
			'name'     => array_key_exists('name', $arguments)
				? $this->requireString($arguments, 'name')
				: $current['name'],
			'state'    => array_key_exists('state', $arguments) ? (int) $arguments['state'] : $current['state'],
			'ordering' => $current['ordering'],
		];

		$out = $this->cfSaveForm($data, $params);

		if ($out['id'] <= 0) {
			return ToolResult::error('Form update failed: ' . ($out['error'] ?: 'unknown error'));
		}

		$saved = $this->cfFetchForm($id);

		return ToolResult::json([
			'ok'            => true,
			'id'            => $id,
			'name'          => $saved['name'] ?? $data['name'],
			'state'         => $saved['state'] ?? $data['state'],
			'state_label'   => $this->contentStateLabel($saved['state'] ?? $data['state']),
			'params_changed' => array_keys($patch),
			'save_warnings' => $out['error'] ?: null,
		]);
	}
}
