<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Versions;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\ReleaseManagerTrait;
use Joomla\CMS\User\User;

final class ListReleaseManagerPackageVersionsTool extends AbstractTool
{
	use ReleaseManagerTrait;

	public function getName(): string { return 'list_release_manager_package_versions'; }

	public function getDescription(): string
	{
		return 'List cs-release-manager package versions, optionally filtered by package_id. '
			. 'Each row is one release uploaded under a Package. Returns id, package_id, version, '
			. 'filename, sha256, release_date, is_latest, is_stable, min_joomla_version, '
			. 'min_php_version, download_count, state. Latest-first order.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'package_id' => ['type' => 'integer'],
				'state'      => ['type' => 'integer', 'enum' => [1, 0, -2]],
				'limit'      => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
				'offset'     => ['type' => 'integer', 'minimum' => 0],
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

		$limit  = max(1, min(200, (int) ($arguments['limit'] ?? 50)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));

		$q = $this->db->getQuery(true)
			->select($this->db->quoteName([
				'id', 'package_id', 'version', 'filename', 'sha256',
				'release_date', 'is_latest', 'is_stable',
				'min_joomla_version', 'max_joomla_version', 'min_php_version',
				'download_count', 'state', 'created', 'modified',
			]))
			->from($this->db->quoteName('#__csrm_package_versions'));

		if (!empty($arguments['package_id'])) {
			$q->where($this->db->quoteName('package_id') . ' = ' . (int) $arguments['package_id']);
		}
		if (isset($arguments['state'])) {
			$q->where($this->db->quoteName('state') . ' = ' . (int) $arguments['state']);
		}
		$q->order($this->db->quoteName('release_date') . ' DESC');

		$countQ = clone $q;
		$countQ->clear('select')->clear('order')->select('COUNT(*)');
		$total = (int) $this->db->setQuery($countQ)->loadResult();

		$rows = $this->db->setQuery($q, $offset, $limit)->loadAssocList() ?: [];

		return ToolResult::json([
			'total'    => $total,
			'count'    => count($rows),
			'limit'    => $limit,
			'offset'   => $offset,
			'versions' => $rows,
		]);
	}
}
