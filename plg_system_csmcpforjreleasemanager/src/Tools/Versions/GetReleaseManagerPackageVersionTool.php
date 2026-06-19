<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Versions;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\ReleaseManagerTrait;
use Joomla\CMS\User\User;

final class GetReleaseManagerPackageVersionTool extends AbstractTool
{
	use ReleaseManagerTrait;

	public function getName(): string { return 'get_release_manager_package_version'; }

	public function getDescription(): string
	{
		return 'Fetch a single cs-release-manager package version by id, or by '
			. '(package_id + version). Returns every column including release_notes.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'         => ['type' => 'integer'],
				'package_id' => ['type' => 'integer'],
				'version'    => ['type' => 'string'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($refusal = $this->requireReleaseManager()) {
			return $refusal;
		}

		$q = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName('#__csrm_package_versions'));

		if (!empty($arguments['id'])) {
			$q->where($this->db->quoteName('id') . ' = ' . (int) $arguments['id']);
		} elseif (!empty($arguments['package_id']) && !empty($arguments['version'])) {
			$q->where($this->db->quoteName('package_id') . ' = ' . (int) $arguments['package_id'])
				->where($this->db->quoteName('version') . ' = ' . $this->db->quote((string) $arguments['version']));
		} else {
			return ToolResult::error('Provide id, or both package_id and version.');
		}

		$row = $this->db->setQuery($q)->loadAssoc();
		if (!$row) {
			return ToolResult::error('Package version not found.');
		}
		return ToolResult::json($row);
	}
}
