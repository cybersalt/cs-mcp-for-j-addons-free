<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Packages;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\ReleaseManagerTrait;
use Joomla\CMS\User\User;

final class UpdateReleaseManagerPackageTool extends AbstractTool
{
	use ReleaseManagerTrait;

	private const UPDATABLE = [
		'title', 'alias', 'description',
		'extension_type', 'extension_element', 'extension_folder', 'extension_client',
		'is_public', 'install_limit',
		'denial_message', 'renewal_url',
		'state',
	];

	public function getName(): string { return 'update_release_manager_package'; }

	public function getDescription(): string
	{
		return 'Update an existing cs-release-manager Package. Required: id. Only fields you '
			. 'supply are changed. Pass user_groups[] (array of ids) to replace the membership '
			. 'list. NOTE: renaming extension_element triggers cs-release-manager\'s on-disk '
			. 'downloads-folder rename — Version files at /media/com_csreleasemanager/downloads/'
			. '{old_element}/ move to /{new_element}/ automatically.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'                => ['type' => 'integer'],
				'title'             => ['type' => 'string'],
				'alias'             => ['type' => 'string'],
				'description'       => ['type' => 'string'],
				'extension_type'    => ['type' => 'string', 'enum' => ['package', 'component', 'plugin', 'module', 'library', 'template', 'file', 'language']],
				'extension_element' => ['type' => 'string'],
				'extension_folder'  => ['type' => 'string'],
				'extension_client'  => ['type' => 'integer', 'enum' => [0, 1]],
				'is_public'         => ['type' => 'integer', 'enum' => [0, 1]],
				'user_groups'       => ['type' => 'array', 'items' => ['type' => 'integer']],
				'install_limit'     => ['type' => 'integer'],
				'denial_message'    => ['type' => 'string'],
				'renewal_url'       => ['type' => 'string'],
				'state'             => ['type' => 'integer', 'enum' => [0, 1, -2]],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($refusal = $this->requireReleaseManager()) {
			return $refusal;
		}

		$id    = $this->requirePositiveInt($arguments, 'id');
		$model = $this->csrmModel('Package');

		$existing = $model->getItem($id);
		if (!$existing || empty($existing->id)) {
			return ToolResult::error('Package ' . $id . ' not found.');
		}

		$data = ['id' => $id];
		foreach (self::UPDATABLE as $key) {
			if (array_key_exists($key, $arguments)) {
				$data[$key] = $arguments[$key];
			}
		}
		if (isset($arguments['user_groups']) && is_array($arguments['user_groups'])) {
			$data['user_groups'] = json_encode(array_map('intval', $arguments['user_groups']));
		}

		$result = $this->saveAdminModel($model, $data);
		if (!$result['ok'] && $result['error'] !== '') {
			$check = $model->getItem($id);
			if (!$check || empty($check->id)) {
				return ToolResult::error('cs-release-manager rejected the update: ' . $result['error']);
			}
		}

		$response = [
			'ok'             => true,
			'id'             => $id,
			'fields_changed' => array_values(array_diff(array_keys($data), ['id'])),
		];
		if (!$result['ok'] && $result['error'] !== '') {
			$response['post_save_warning'] = $result['error'];
		}
		return ToolResult::json($response);
	}
}
