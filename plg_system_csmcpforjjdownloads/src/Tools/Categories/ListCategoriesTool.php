<?php
/**
 * @package     MCP for jDownloads
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Categories;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\JdownloadsBootTrait;
use Joomla\CMS\User\User;

final class ListCategoriesTool extends AbstractTool
{
	use JdownloadsBootTrait;
	use CategorySupportTrait;

	public function getName(): string { return 'list_jdownloads_categories'; }

	public function getDescription(): string
	{
		return 'List jDownloads categories in tree order (jDownloads keeps its own nested set, not Joomla\'s '
			. '#__categories). Each row has its parent, level, state, access level, language, the folder it owns '
			. 'under the jDownloads upload folder (and whether that folder exists on disk) and how many downloads '
			. 'sit directly in it. Filter by parent_id (direct children only, or the whole branch with '
			. 'include_descendants), published, or a search on title/alias. The ROOT row is never listed.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'parent_id'           => ['type' => 'integer', 'description' => 'Only children of this category (0 = top level).'],
				'include_descendants' => ['type' => 'boolean', 'description' => 'With parent_id: the whole branch, not just direct children.'],
				'published'           => ['type' => 'integer', 'enum' => [0, 1]],
				'search'              => ['type' => 'string', 'description' => 'Substring of title or alias; "id:5" for one id.'],
				'limit'               => ['type' => 'integer', 'description' => 'Default 100, max 500.'],
				'offset'              => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->jdInstalled()) {
			return $this->jdNotInstalledError();
		}

		$this->jdRequireAccess($actor);

		$db     = $this->db;
		$rootId = $this->jdRootId();
		$where  = ['c.id <> ' . $rootId];

		if (\array_key_exists('parent_id', $arguments)) {
			$parent = $this->catResolveParent($arguments['parent_id'], $rootId);

			if (!empty($arguments['include_descendants'])) {
				$where[] = 'c.lft > ' . (int) $parent['lft'] . ' AND c.rgt < ' . (int) $parent['rgt'];
			} else {
				$where[] = 'c.parent_id = ' . (int) $parent['id'];
			}
		}

		if (\array_key_exists('published', $arguments)) {
			$where[] = 'c.published = ' . $this->jdParsePublished($arguments['published']);
		}

		$search = trim((string) ($arguments['search'] ?? ''));

		if ($search !== '') {
			$where[] = preg_match('/^id:(\d+)$/i', $search, $m)
				? 'c.id = ' . (int) $m[1]
				: '(c.title LIKE ' . $this->jdLike($search) . ' OR c.alias LIKE ' . $this->jdLike($search) . ')';
		}

		$whereSql = ' WHERE ' . implode(' AND ', $where);
		$limit    = $this->jdLimit($arguments, 100, 500);
		$offset   = $this->jdOffset($arguments);

		$total = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__jdownloads_categories', 'c') . $whereSql)->loadResult();
		$rows  = $db->setQuery(
			'SELECT c.* FROM ' . $db->quoteName('#__jdownloads_categories', 'c') . $whereSql . ' ORDER BY c.lft',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$counts = $this->catDownloadCounts(array_column($rows, 'id'));
		$items  = [];

		foreach ($rows as $row) {
			$items[] = $this->catShape($row) + ['downloads_in_category' => $counts[(int) $row['id']] ?? 0];
		}

		return ToolResult::json([
			'ok'          => true,
			'total'       => $total,
			'limit'       => $limit,
			'offset'      => $offset,
			'upload_root' => $this->jdUploadRoot(),
			'categories'  => $items,
		]);
	}
}
