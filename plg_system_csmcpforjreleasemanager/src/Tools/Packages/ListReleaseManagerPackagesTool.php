<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Packages;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\ReleaseManagerTrait;
use Joomla\CMS\User\User;

final class ListReleaseManagerPackagesTool extends AbstractTool
{
	use ReleaseManagerTrait;

	public function getName(): string { return 'list_release_manager_packages'; }

	public function getDescription(): string
	{
		return 'List cs-release-manager Package records. Each Package is one distribution unit '
			. '(typically one Joomla extension you distribute). Optional: search (title or alias '
			. 'substring), state (1=published, 0=unpublished, -2=trashed), limit, offset. '
			. 'Returns id, title, alias, extension_type, extension_element, is_public, state, '
			. 'created, modified.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'search' => ['type' => 'string'],
				'state'  => ['type' => 'integer', 'enum' => [1, 0, -2]],
				'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
				'offset' => ['type' => 'integer', 'minimum' => 0],
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
				'id', 'title', 'alias', 'extension_type', 'extension_element',
				'extension_folder', 'is_public', 'state', 'created', 'modified',
			]))
			->from($this->db->quoteName('#__csrm_packages'));

		if (isset($arguments['state'])) {
			$q->where($this->db->quoteName('state') . ' = ' . (int) $arguments['state']);
		}
		if (!empty($arguments['search'])) {
			$like = '%' . $this->db->escape((string) $arguments['search'], true) . '%';
			$q->where(
				'(' . $this->db->quoteName('title') . ' LIKE ' . $this->db->quote($like)
				. ' OR ' . $this->db->quoteName('alias') . ' LIKE ' . $this->db->quote($like)
				. ' OR ' . $this->db->quoteName('extension_element') . ' LIKE ' . $this->db->quote($like)
				. ')'
			);
		}
		$q->order($this->db->quoteName('title') . ' ASC');

		// Total before limiting
		$countQ = clone $q;
		$countQ->clear('select')->clear('order')->select('COUNT(*)');
		$total = (int) $this->db->setQuery($countQ)->loadResult();

		$rows = $this->db->setQuery($q, $offset, $limit)->loadAssocList() ?: [];

		return ToolResult::json([
			'total'    => $total,
			'count'    => count($rows),
			'limit'    => $limit,
			'offset'   => $offset,
			'packages' => $rows,
		]);
	}
}
