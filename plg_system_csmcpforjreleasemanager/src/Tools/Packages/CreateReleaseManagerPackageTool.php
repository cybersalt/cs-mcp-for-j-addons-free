<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Packages;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\ReleaseManagerTrait;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\User\User;

final class CreateReleaseManagerPackageTool extends AbstractTool
{
	use ReleaseManagerTrait;

	public function getName(): string { return 'create_release_manager_package'; }

	public function getDescription(): string
	{
		return 'Create a new cs-release-manager Package record. Required: title, '
			. 'extension_element (the Joomla element name, e.g. "pkg_csmcpforj4seo" for a '
			. 'package or "csmcpforj4seo" for a plugin). Optional: alias (auto-generated from '
			. 'title), description, extension_type (default "package" — also "component", '
			. '"plugin", "module", "library", "template", "file", "language"), '
			. 'extension_folder (only for plugins, e.g. "system"), is_public (1 = free download, '
			. 'no auth gate; 0 = membership-gated), user_groups (array of Joomla user-group ids '
			. 'allowed to download when is_public=0), state (1 published, 0 unpublished). '
			. 'Goes through cs-release-manager\'s PackageModel so all side effects fire.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['title', 'extension_element'],
			'properties' => [
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
				'state'             => ['type' => 'integer', 'enum' => [0, 1]],
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

		$title   = $this->requireString($arguments, 'title');
		$element = $this->requireString($arguments, 'extension_element');

		$alias = (string) ($arguments['alias'] ?? '');
		if ($alias === '') {
			$alias = OutputFilter::stringURLSafe($title);
		}

		$data = [
			'id'                => 0,
			'title'             => $title,
			'alias'             => $alias,
			'description'       => (string) ($arguments['description'] ?? ''),
			'extension_type'    => (string) ($arguments['extension_type'] ?? 'package'),
			'extension_element' => $element,
			'extension_folder'  => (string) ($arguments['extension_folder'] ?? ''),
			'extension_client'  => isset($arguments['extension_client']) ? (int) $arguments['extension_client'] : 0,
			'is_public'         => isset($arguments['is_public']) ? (int) $arguments['is_public'] : 0,
			'user_groups'       => isset($arguments['user_groups']) && is_array($arguments['user_groups'])
				? json_encode(array_map('intval', $arguments['user_groups']))
				: '[]',
			'install_limit'     => isset($arguments['install_limit']) ? (int) $arguments['install_limit'] : 0,
			'denial_message'    => (string) ($arguments['denial_message'] ?? ''),
			'renewal_url'       => (string) ($arguments['renewal_url'] ?? ''),
			'state'             => isset($arguments['state']) ? (int) $arguments['state'] : 1,
		];

		$model  = $this->csrmModel('Package');
		$result = $this->saveAdminModel($model, $data);

		if ($result['id'] <= 0) {
			return ToolResult::error('cs-release-manager rejected the package: ' . ($result['error'] ?: 'unknown error'));
		}

		$response = [
			'ok'                => true,
			'id'                => $result['id'],
			'title'             => $title,
			'alias'             => $alias,
			'extension_element' => $element,
			'is_public'         => $data['is_public'],
			'edit_url'          => 'index.php?option=com_csreleasemanager&task=package.edit&id=' . $result['id'],
		];
		if (!$result['ok'] && $result['error'] !== '') {
			$response['post_save_warning'] = $result['error'];
		}
		return ToolResult::json($response);
	}
}
