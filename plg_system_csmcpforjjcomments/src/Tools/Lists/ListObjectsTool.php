<?php
/**
 * @package     MCP for JComments
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Lists;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\JcommentsBootTrait;
use Joomla\CMS\User\User;

/**
 * Commented items with live counts. Counts are computed, as JComments
 * computes them; there is no stored counter.
 */
final class ListObjectsTool extends AbstractTool
{
	use JcommentsBootTrait;

	public function getName(): string { return 'list_jcomments_objects'; }

	public function getDescription(): string
	{
		return 'List the items (usually articles) that have JComments comments, with live counts of published, '
			. 'waiting-for-approval and deleted comments, the latest comment date, and the title JComments has cached '
			. 'for each. Sort by pending (most waiting first, the default), latest or total. Shows which articles '
			. 'need moderation.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'object_group' => ['type' => 'string', 'description' => 'e.g. com_content. Default: any.'],
				'sort'         => ['type' => 'string', 'enum' => ['pending', 'latest', 'total']],
				'limit'        => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'       => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->jcInstalled()) {
			return $this->jcNotInstalledError();
		}

		$this->jcRequireAccess($actor);

		$db    = $this->db;
		$where = '';

		if (($group = trim((string) ($arguments['object_group'] ?? ''))) !== '') {
			$where = ' WHERE object_group = ' . $db->quote($group);
		}

		$order = match ((string) ($arguments['sort'] ?? 'pending')) {
			'latest' => 'last_comment DESC',
			'total'  => 'total DESC, last_comment DESC',
			default  => 'pending DESC, last_comment DESC',
		};

		$limit  = $this->jcLimit($arguments);
		$offset = $this->jcOffset($arguments);
		$table  = $db->quoteName($this->jcTable(''));

		$total = (int) $db->setQuery('SELECT COUNT(*) FROM (SELECT 1 FROM ' . $table . $where . ' GROUP BY object_group, object_id) x')->loadResult();
		$rows  = $db->setQuery(
			'SELECT object_group, object_id, COUNT(*) AS total, '
			. 'SUM(published = 1 AND deleted = 0) AS published, SUM(published = 0 AND deleted = 0) AS pending, '
			. 'SUM(deleted = 1) AS deleted, MAX(date) AS last_comment '
			. 'FROM ' . $table . $where . ' GROUP BY object_group, object_id ORDER BY ' . $order,
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$info  = $this->jcObjectInfo($rows);
		$items = [];

		foreach ($rows as $r) {
			$key     = $r['object_group'] . ':' . (int) $r['object_id'];
			$items[] = [
				'object_id'    => (int) $r['object_id'],
				'object_group' => (string) $r['object_group'],
				'title'        => $info[$key]['title'] ?? null,
				'link'         => $info[$key]['link'] ?? null,
				'objects_row_exists' => (bool) ($info[$key]['cached'] ?? false),
				'total'        => (int) $r['total'],
				'published'    => (int) $r['published'],
				'pending'      => (int) $r['pending'],
				'deleted'      => (int) $r['deleted'],
				'last_comment' => $r['last_comment'],
			];
		}

		return ToolResult::json(['ok' => true, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'objects' => $items]);
	}
}
