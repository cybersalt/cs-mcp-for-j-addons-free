<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Versions;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\ReleaseManagerTrait;
use Joomla\CMS\User\User;

final class CreateReleaseManagerPackageVersionTool extends AbstractTool
{
	use ReleaseManagerTrait;

	public function getName(): string { return 'create_release_manager_package_version'; }

	public function getDescription(): string
	{
		return 'Create a cs-release-manager package version record. Required: package_id, '
			. 'version (semver string), filename (the zip filename ALREADY uploaded to '
			. '/media/com_csreleasemanager/downloads/{package.extension_element}/). Optional: '
			. 'sha256 (auto-computed from the file if omitted), release_notes (HTML), '
			. 'release_date (YYYY-MM-DD HH:MM:SS, default now), is_latest (1 to mark this as '
			. 'the served-update version — UNSETS is_latest on other versions of the same '
			. 'package), is_stable, min_joomla_version (default "5.0"), '
			. 'max_joomla_version (set to "6" for J5+J6 support; LEAVE EMPTY for J5-only — cs-release-manager '
			. 'parses the (int) of this value to decide the regex range, so "6" or "6.99" both produce '
			. '"(5|6)\\.[0-9]+", but "6" is cleaner), '
			. 'min_php_version (default "8.1" — use "8.3" for new releases since older PHP is unsupported), '
			. 'state (1 published, 0 draft). '
			. 'IMPORTANT: this tool does NOT upload the zip itself — the file must already exist '
			. 'on disk under the package\'s downloads folder before you call this.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['package_id', 'version', 'filename'],
			'properties' => [
				'package_id'         => ['type' => 'integer'],
				'version'            => ['type' => 'string'],
				'filename'           => ['type' => 'string'],
				'sha256'             => ['type' => 'string', 'description' => 'Hex SHA-256. Auto-computed from the file if omitted.'],
				'release_notes'      => ['type' => 'string'],
				'release_date'       => ['type' => 'string', 'description' => 'MySQL datetime. Default now.'],
				'is_latest'          => ['type' => 'integer', 'enum' => [0, 1]],
				'is_stable'          => ['type' => 'integer', 'enum' => [0, 1]],
				'min_joomla_version' => ['type' => 'string'],
				'max_joomla_version' => ['type' => 'string'],
				'min_php_version'    => ['type' => 'string'],
				'state'              => ['type' => 'integer', 'enum' => [0, 1]],
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

		$packageId = $this->requirePositiveInt($arguments, 'package_id');
		$version   = $this->requireString($arguments, 'version');
		$filename  = $this->requireString($arguments, 'filename');

		$data = [
			'id'                 => 0,
			'package_id'         => $packageId,
			'version'            => $version,
			'filename'           => $filename,
			'sha256'             => (string) ($arguments['sha256'] ?? ''),
			'release_notes'      => (string) ($arguments['release_notes'] ?? ''),
			'release_date'       => (string) ($arguments['release_date'] ?? gmdate('Y-m-d H:i:s')),
			'is_latest'          => isset($arguments['is_latest']) ? (int) $arguments['is_latest'] : 1,
			'is_stable'          => isset($arguments['is_stable']) ? (int) $arguments['is_stable'] : 1,
			'min_joomla_version' => (string) ($arguments['min_joomla_version'] ?? '5.0'),
			'max_joomla_version' => (string) ($arguments['max_joomla_version'] ?? ''),
			'min_php_version'    => (string) ($arguments['min_php_version'] ?? '8.1'),
			'state'              => isset($arguments['state']) ? (int) $arguments['state'] : 1,
		];

		$model  = $this->csrmModel('Packageversion');
		$result = $this->saveAdminModel($model, $data);

		if ($result['id'] <= 0) {
			return ToolResult::error('cs-release-manager rejected the package version: ' . ($result['error'] ?: 'unknown error'));
		}

		$response = [
			'ok'         => true,
			'id'         => $result['id'],
			'package_id' => $packageId,
			'version'    => $version,
			'filename'   => $filename,
			'is_latest'  => $data['is_latest'],
			'edit_url'   => 'index.php?option=com_csreleasemanager&task=packageversion.edit&id=' . $result['id'],
		];
		if (!$result['ok'] && $result['error'] !== '') {
			$response['post_save_warning'] = $result['error'];
		}
		return ToolResult::json($response);
	}
}
