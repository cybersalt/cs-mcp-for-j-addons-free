<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Versions;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\ReleaseManagerTrait;
use Joomla\CMS\User\User;

final class UpdateReleaseManagerPackageVersionTool extends AbstractTool
{
	use ReleaseManagerTrait;

	private const UPDATABLE = [
		'version', 'filename', 'sha256',
		'release_notes', 'release_date',
		'is_latest', 'is_stable',
		'min_joomla_version', 'max_joomla_version', 'min_php_version',
		'state',
	];

	public function getName(): string { return 'update_release_manager_package_version'; }

	public function getDescription(): string
	{
		return 'Update an existing cs-release-manager package version. Required: id. Only '
			. 'fields you supply are changed. Pass is_latest:1 to promote this row — '
			. 'cs-release-manager auto-unsets is_latest on the other versions of the same '
			. 'package.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'                 => ['type' => 'integer'],
				'version'            => ['type' => 'string'],
				'filename'           => ['type' => 'string'],
				'sha256'             => ['type' => 'string'],
				'release_notes'      => ['type' => 'string'],
				'release_date'       => ['type' => 'string'],
				'is_latest'          => ['type' => 'integer', 'enum' => [0, 1]],
				'is_stable'          => ['type' => 'integer', 'enum' => [0, 1]],
				'min_joomla_version' => ['type' => 'string'],
				'max_joomla_version' => ['type' => 'string'],
				'min_php_version'    => ['type' => 'string'],
				'state'              => ['type' => 'integer', 'enum' => [0, 1, -2]],
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
		$model = $this->csrmModel('Packageversion');

		$existing = $model->getItem($id);
		if (!$existing || empty($existing->id)) {
			return ToolResult::error('Package version ' . $id . ' not found.');
		}

		$data = ['id' => $id];
		foreach (self::UPDATABLE as $key) {
			if (array_key_exists($key, $arguments)) {
				$data[$key] = $arguments[$key];
			}
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
