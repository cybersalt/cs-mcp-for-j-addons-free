<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Packages;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\ReleaseManagerTrait;
use Joomla\CMS\User\User;

final class GetReleaseManagerPackageTool extends AbstractTool
{
	use ReleaseManagerTrait;

	public function getName(): string { return 'get_release_manager_package'; }

	public function getDescription(): string
	{
		return 'Fetch a single cs-release-manager Package record by id or by alias. Returns '
			. 'every column including the JSON-parsed user_groups array and the configured '
			. 'update_xml_url / download_url / renewal_url. Use list_release_manager_packages to find the id.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'    => ['type' => 'integer'],
				'alias' => ['type' => 'string'],
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
			->from($this->db->quoteName('#__csrm_packages'));

		if (!empty($arguments['id'])) {
			$q->where($this->db->quoteName('id') . ' = ' . (int) $arguments['id']);
		} elseif (!empty($arguments['alias'])) {
			$q->where($this->db->quoteName('alias') . ' = ' . $this->db->quote((string) $arguments['alias']));
		} else {
			return ToolResult::error('Provide id or alias.');
		}

		$row = $this->db->setQuery($q)->loadAssoc();
		if (!$row) {
			return ToolResult::error('Package not found.');
		}

		// Decode the user_groups JSON for convenience
		if (!empty($row['user_groups'])) {
			$decoded = json_decode((string) $row['user_groups'], true);
			$row['user_groups__parsed'] = is_array($decoded) ? $decoded : null;
		} else {
			$row['user_groups__parsed'] = [];
		}

		return ToolResult::json($row);
	}
}
