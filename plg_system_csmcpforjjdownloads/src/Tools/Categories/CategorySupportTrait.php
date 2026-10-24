<?php
/**
 * @package     MCP for jDownloads
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Categories;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\String\StringHelper;

/**
 * Category helpers shared by the category tools. Requires JdownloadsBootTrait.
 */
trait CategorySupportTrait
{
	/** The fields the category tools accept, beyond title/parent. */
	private const CAT_TEXT_FIELDS = [
		'description' => ['col' => 'description', 'max' => 65535],
		'notes'       => ['col' => 'notes', 'max' => 65535],
		'metadesc'    => ['col' => 'metadesc', 'max' => 65535],
		'metakey'     => ['col' => 'metakey', 'max' => 65535],
	];

	protected function catShape(array $row, bool $full = false): array
	{
		$abs = $this->jdCategoryAbsPath($row);

		$out = [
			'id'            => (int) $row['id'],
			'title'         => (string) $row['title'],
			'alias'         => (string) $row['alias'],
			'parent_id'     => (int) $row['parent_id'],
			'level'         => (int) $row['level'],
			'published'     => (int) $row['published'],
			'access'        => (int) $row['access'],
			'language'      => (string) $row['language'],
			'folder'        => $this->jdCategoryRelPath($row),
			'folder_exists' => $abs !== null && is_dir($abs),
			'views'         => (int) $row['views'],
			'has_password'  => (string) ($row['password'] ?? '') !== '',
			'checked_out'   => (int) $row['checked_out'],
		];

		if ($full) {
			$out += [
				'description'      => (string) $row['description'],
				'notes'            => (string) $row['notes'],
				'metadesc'         => (string) $row['metadesc'],
				'metakey'          => (string) $row['metakey'],
				'robots'           => (string) $row['robots'],
				'pic'              => (string) $row['pic'],
				'lft'              => (int) $row['lft'],
				'rgt'              => (int) $row['rgt'],
				'cat_dir'          => (string) $row['cat_dir'],
				'cat_dir_parent'   => (string) $row['cat_dir_parent'],
				'created_time'     => $row['created_time'],
				'created_user_id'  => (int) $row['created_user_id'],
				'modified_time'    => $row['modified_time'],
				'modified_user_id' => (int) $row['modified_user_id'],
				'asset_id'         => (int) $row['asset_id'],
				'user_access'      => (int) ($row['user_access'] ?? 0),
				'params'           => json_decode((string) $row['params'], true) ?: new \stdClass(),
			];
		}

		return $out;
	}

	/** @return array<int,int> category id => number of downloads directly in it */
	protected function catDownloadCounts(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

		if ($ids === []) {
			return [];
		}

		$rows = $this->db->setQuery(
			'SELECT catid, COUNT(*) AS n FROM ' . $this->db->quoteName('#__jdownloads_files')
			. ' WHERE catid IN (' . implode(',', $ids) . ') GROUP BY catid'
		)->loadAssocList('catid') ?: [];

		$out = [];

		foreach ($ids as $id) {
			$out[$id] = (int) ($rows[$id]['n'] ?? 0);
		}

		return $out;
	}

	/** The parent row a category argument names; 0 or the ROOT id both mean "top level". */
	protected function catResolveParent(mixed $value, int $rootId): array
	{
		$parentId = (int) $value;

		if ($parentId <= 0) {
			$parentId = $rootId;
		}

		$parent = $this->jdCategoryRow($parentId);

		if ($parent === null) {
			throw new \InvalidArgumentException('parent_id ' . $parentId . ' is not a jDownloads category. Nothing was written.');
		}

		return $parent;
	}

	/**
	 * The folder base name jDownloads would derive: from the title when
	 * "create_auto_cat_dir" is on (the default), otherwise from the manual
	 * folder name (`CategoryModel::save()` lines 469-486).
	 */
	protected function catFolderBase(string $title, ?string $folderName): string
	{
		$auto = (int) $this->jdParams()->get('create_auto_cat_dir', 1) === 1;

		if ($auto && $folderName !== null && trim($folderName) !== '') {
			throw new \InvalidArgumentException(
				'This site has jDownloads\' "create category folders automatically" option on, so the folder name always '
				. 'follows the title. Leave folder_name out, or turn that option off first. Nothing was written.'
			);
		}

		$source = (!$auto && $folderName !== null && trim($folderName) !== '') ? $folderName : $title;

		return $this->jdCleanName($source);
	}

	/**
	 * `CategoryModel::generateNewFolderName()`: when a SIBLING row already uses
	 * the name, increment it ("Name (2)", or "name-2" when "fix blanks" is on)
	 * until it is free. Siblings only, and the database only — the disk check
	 * is ours (catRequireFolderFree()).
	 */
	protected function catUniqueFolderName(int $parentId, string $name, int $exceptId): string
	{
		$style = (int) $this->jdParams()->get('fix_upload_filename_blanks', 0) === 1 ? 'dash' : 'default';
		$taken = function (string $candidate) use ($parentId, $exceptId): bool {
			return (int) $this->db->setQuery(
				'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__jdownloads_categories')
				. ' WHERE parent_id = ' . $parentId . ' AND cat_dir = ' . $this->db->quote($candidate) . ' AND id <> ' . $exceptId
			)->loadResult() > 0;
		};

		$guard = 0;

		while ($taken($name)) {
			$name = StringHelper::increment($name, $style);

			if (++$guard > 500) {
				throw new \RuntimeException('Could not find a free folder name. Nothing was written.');
			}
		}

		return $name;
	}

	/** Refuse names that collide with jDownloads' reserved top-level folders. */
	protected function catRequireNotReserved(string $folder, int $parentId, int $rootId): void
	{
		if ($parentId !== $rootId) {
			return;
		}

		foreach ($this->jdReservedFolders() as $reserved) {
			if (strcasecmp($folder, $reserved) === 0) {
				throw new \InvalidArgumentException(
					'"' . $folder . '" is the folder jDownloads uses for its preview / temporary zip files. A top-level '
					. 'category cannot use it. Choose another title. Nothing was written.'
				);
			}
		}
	}

	/** The relative folder of a parent row, '' for ROOT. */
	protected function catParentRelPath(array $parent, int $rootId): string
	{
		return (int) $parent['id'] === $rootId ? '' : $this->jdCategoryRelPath($parent);
	}

	/**
	 * An alias as `CategoryModel::prepareTable()` makes it, unique among the
	 * siblings (`JDCategoryTable::checkData()` refuses a duplicate). A caller's
	 * explicit alias that is taken is refused; a derived one is suffixed.
	 */
	protected function catAlias(?string $requested, string $title, int $parentId, int $exceptId): string
	{
		$explicit = $requested !== null && trim($requested) !== '';
		$alias    = ApplicationHelper::stringURLSafe($explicit ? (string) $requested : $title);

		if (trim(str_replace('-', '', $alias)) === '') {
			$alias = gmdate('Y-m-d-H-i-s');
		}

		$this->jdRequireFits('alias', $alias, 191);

		$taken = fn (string $a): bool => (int) $this->db->setQuery(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__jdownloads_categories')
			. ' WHERE parent_id = ' . $parentId . ' AND alias = ' . $this->db->quote($a) . ' AND id <> ' . $exceptId
		)->loadResult() > 0;

		if (!$taken($alias)) {
			return $alias;
		}

		if ($explicit) {
			throw new \InvalidArgumentException(
				'alias "' . $alias . '" is already used by another category with the same parent (jDownloads requires '
				. 'it to be unique there). Nothing was written.'
			);
		}

		$n = 2;

		while ($taken($alias . '-' . $n)) {
			$n++;
		}

		return $alias . '-' . $n;
	}

	/** Ids of a category and everything below it. */
	protected function catSubtreeIds(array $row): array
	{
		return array_map('intval', $this->db->setQuery(
			'SELECT id FROM ' . $this->db->quoteName('#__jdownloads_categories')
			. ' WHERE lft BETWEEN ' . (int) $row['lft'] . ' AND ' . (int) $row['rgt']
		)->loadColumn() ?: []);
	}

	/**
	 * Nested-set and folder-path integrity of the whole tree. Empty = sound.
	 *
	 * @return string[]
	 */
	protected function catTreeProblems(int $cap = 50): array
	{
		$rows = $this->db->setQuery(
			'SELECT id, parent_id, lft, rgt, level, cat_dir, cat_dir_parent, title FROM '
			. $this->db->quoteName('#__jdownloads_categories') . ' ORDER BY lft'
		)->loadAssocList('id') ?: [];

		$problems = [];
		$roots    = array_filter($rows, static fn (array $r): bool => (int) $r['parent_id'] === 0);

		if (\count($roots) !== 1) {
			$problems[] = \count($roots) . ' rows have parent_id 0; there must be exactly one ROOT row';
		}

		$seen = [];

		foreach ($rows as $id => $r) {
			$lft = (int) $r['lft'];
			$rgt = (int) $r['rgt'];

			if ($lft >= $rgt) {
				$problems[] = 'category ' . $id . ': lft ' . $lft . ' is not below rgt ' . $rgt;
			}

			foreach ([$lft, $rgt] as $v) {
				if (isset($seen[$v])) {
					$problems[] = 'category ' . $id . ' shares the nested-set value ' . $v . ' with category ' . $seen[$v];
				}

				$seen[$v] = $id;
			}

			$pid = (int) $r['parent_id'];

			if ($pid === 0) {
				continue;
			}

			$p = $rows[$pid] ?? null;

			if ($p === null) {
				$problems[] = 'category ' . $id . ' has parent_id ' . $pid . ', which does not exist';
				continue;
			}

			if (!($lft > (int) $p['lft'] && $rgt < (int) $p['rgt'])) {
				$problems[] = 'category ' . $id . ' (lft ' . $lft . ', rgt ' . $rgt . ') is not inside its parent ' . $pid
					. ' (lft ' . $p['lft'] . ', rgt ' . $p['rgt'] . ')';
			}

			if ((int) $r['level'] !== (int) $p['level'] + 1) {
				$problems[] = 'category ' . $id . ' has level ' . $r['level'] . ' but its parent is level ' . $p['level'];
			}

			$expected = (int) $p['parent_id'] === 0 ? '' : $this->jdCategoryRelPath($p);

			if ((string) $r['cat_dir_parent'] !== $expected) {
				$problems[] = 'category ' . $id . ' has cat_dir_parent "' . $r['cat_dir_parent'] . '" but its parent\'s folder is "'
					. $expected . '" (jDownloads looks for its files in the wrong place)';
			}

			if (\count($problems) >= $cap) {
				$problems[] = '(stopped after ' . $cap . ' problems)';
				break;
			}
		}

		return $problems;
	}
}
