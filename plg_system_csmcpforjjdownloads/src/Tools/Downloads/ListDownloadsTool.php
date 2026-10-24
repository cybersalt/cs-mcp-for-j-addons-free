<?php
/**
 * @package     MCP for jDownloads
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Downloads;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\JdownloadsBootTrait;
use Joomla\CMS\User\User;

final class ListDownloadsTool extends AbstractTool
{
	use JdownloadsBootTrait;
	use DownloadSupportTrait;

	private const ORDER = [
		'id'        => 'f.id',
		'title'     => 'f.title',
		'created'   => 'f.created',
		'modified'  => 'f.modified',
		'downloads' => 'f.downloads',
		'file_date' => 'f.file_date',
	];

	public function getName(): string { return 'list_jdownloads_downloads'; }

	public function getDescription(): string
	{
		return 'List jDownloads downloads with their file metadata: category, state, version, file name and whether '
			. 'that file is on disk, size, file date, licence, author, download count. Filter by category_id (add '
			. 'include_subcategories for the whole branch), published, featured, licence, or a search on title, alias '
			. 'and file name ("id:12" for one id). Order by id, title, created, modified, downloads or file_date.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'category_id'           => ['type' => 'integer'],
				'include_subcategories' => ['type' => 'boolean'],
				'published'             => ['type' => 'integer', 'enum' => [0, 1]],
				'featured'              => ['type' => 'integer', 'enum' => [0, 1]],
				'license_id'            => ['type' => 'integer'],
				'search'                => ['type' => 'string'],
				'order_by'              => ['type' => 'string', 'enum' => array_keys(self::ORDER)],
				'order_dir'             => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
				'limit'                 => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'                => ['type' => 'integer'],
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

		$db    = $this->db;
		$where = [];
		$join  = '';

		if (\array_key_exists('category_id', $arguments)) {
			$cat = $this->dlRequireCategory((int) $arguments['category_id']);

			if (!empty($arguments['include_subcategories'])) {
				$join    = ' INNER JOIN ' . $db->quoteName('#__jdownloads_categories', 'c') . ' ON c.id = f.catid';
				$where[] = 'c.lft BETWEEN ' . (int) $cat['lft'] . ' AND ' . (int) $cat['rgt'];
			} else {
				$where[] = 'f.catid = ' . (int) $cat['id'];
			}
		}

		if (\array_key_exists('published', $arguments)) {
			$where[] = 'f.published = ' . $this->jdParsePublished($arguments['published']);
		}

		if (\array_key_exists('featured', $arguments)) {
			$where[] = 'f.featured = ' . ((int) (bool) $arguments['featured']);
		}

		if (\array_key_exists('license_id', $arguments)) {
			$where[] = 'f.license = ' . $db->quote((string) (int) $arguments['license_id']);
		}

		$search = trim((string) ($arguments['search'] ?? ''));

		if ($search !== '') {
			$where[] = preg_match('/^id:(\d+)$/i', $search, $m)
				? 'f.id = ' . (int) $m[1]
				: '(f.title LIKE ' . $this->jdLike($search) . ' OR f.alias LIKE ' . $this->jdLike($search)
					. ' OR f.url_download LIKE ' . $this->jdLike($search) . ')';
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
		$order    = self::ORDER[(string) ($arguments['order_by'] ?? 'id')] ?? 'f.id';
		$dir      = strtoupper((string) ($arguments['order_dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
		$limit    = $this->jdLimit($arguments);
		$offset   = $this->jdOffset($arguments);
		$from     = ' FROM ' . $db->quoteName('#__jdownloads_files', 'f') . $join;

		$total = (int) $db->setQuery('SELECT COUNT(*)' . $from . $whereSql)->loadResult();
		$rows  = $db->setQuery('SELECT f.*' . $from . $whereSql . ' ORDER BY ' . $order . ' ' . $dir, $offset, $limit)->loadAssocList() ?: [];

		return ToolResult::json([
			'ok'        => true,
			'total'     => $total,
			'limit'     => $limit,
			'offset'    => $offset,
			'downloads' => array_map(fn (array $r): array => $this->dlShape($r), $rows),
		]);
	}
}
