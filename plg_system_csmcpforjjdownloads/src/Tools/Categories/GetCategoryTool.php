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

final class GetCategoryTool extends AbstractTool
{
	use JdownloadsBootTrait;
	use CategorySupportTrait;

	public function getName(): string { return 'get_jdownloads_category'; }

	public function getDescription(): string
	{
		return 'One jDownloads category in full: every column except the password (has_password says whether one '
			. 'is set), its path of ancestors, direct children, the number of downloads in it and in its whole branch, '
			. 'the folder it owns on disk and whether that exists, its tags, and the explicit permission rules on its '
			. 'asset (list_jdownloads_permissions resolves them per user group).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => ['id' => ['type' => 'integer', 'description' => 'Category id.']],
			'required'             => ['id'],
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

		$id  = $this->requirePositiveInt($arguments, 'id');
		$row = $this->jdCategoryRow($id);

		if ($row === null || (int) $row['parent_id'] === 0) {
			throw new \InvalidArgumentException('No jDownloads category with id ' . $id . '.');
		}

		$db = $this->db;

		$path = $db->setQuery(
			'SELECT id, title FROM ' . $db->quoteName('#__jdownloads_categories')
			. ' WHERE lft < ' . (int) $row['lft'] . ' AND rgt > ' . (int) $row['rgt'] . ' AND parent_id > 0 ORDER BY lft'
		)->loadAssocList() ?: [];

		$children = $db->setQuery(
			'SELECT id, title, published FROM ' . $db->quoteName('#__jdownloads_categories')
			. ' WHERE parent_id = ' . $id . ' ORDER BY lft'
		)->loadAssocList() ?: [];

		$branch = (int) $db->setQuery(
			'SELECT COUNT(*) FROM ' . $db->quoteName('#__jdownloads_files') . ' f INNER JOIN '
			. $db->quoteName('#__jdownloads_categories') . ' c ON c.id = f.catid'
			. ' WHERE c.lft BETWEEN ' . (int) $row['lft'] . ' AND ' . (int) $row['rgt']
		)->loadResult();

		$abs = $this->jdCategoryAbsPath($row);

		return ToolResult::json([
			'ok'                    => true,
			'category'              => $this->catShape($row, true),
			'ancestors'             => array_map(static fn ($r) => ['id' => (int) $r['id'], 'title' => (string) $r['title']], $path),
			'children'              => array_map(static fn ($r) => ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'published' => (int) $r['published']], $children),
			'downloads_in_category' => $this->catDownloadCounts([$id])[$id] ?? 0,
			'downloads_in_branch'   => $branch,
			'folder_absolute'       => $abs,
			'files_in_folder'       => $abs !== null ? \count($this->jdFilesIn($abs)) : 0,
			'tags'                  => $this->jdTags('com_jdownloads.category', $id),
			'asset_rules'           => $this->jdAssetRules('com_jdownloads.category.' . $id),
		]);
	}
}
