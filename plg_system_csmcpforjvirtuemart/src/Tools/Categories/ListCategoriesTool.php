<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Categories;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * List categories, LEFT-joined to their language table for the same reason the
 * product listing is: an INNER JOIN would hide the broken rows.
 */
final class ListCategoriesTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'list_virtuemart_categories'; }

	public function getDescription(): string
	{
		return 'List VirtueMart categories from #__virtuemart_categories, joined to the language satellite '
			. 'table for the requested language. '
			. 'Like products, #__virtuemart_categories has NO category_name column — the name, '
			. 'description, meta fields and slug live in #__virtuemart_categories_<langsuffix>, and '
			. 'VirtueMart joins them with an INNER JOIN (helpers/vmtable.php:1065-1068). This listing uses '
			. 'a LEFT JOIN so a category with no language row shows as category_name null with '
			. 'invisible: true rather than vanishing. '
			. 'Filters: search (on category_name), published, parent_id (0 for top-level categories), '
			. 'language, only_invisible. Supports limit (default 50, max 200) and offset. '
			. 'Each row reports product_count — the number of rows in #__virtuemart_product_categories '
			. 'pointing at it — and child_count. Note that product_count includes unpublished products '
			. 'and products with no language row, because it is a count of assignments rather than of '
			. 'things a shopper can see. '
			. 'The parent relationship is stored TWICE: in category_parent_id on this table, and in the '
			. '#__virtuemart_category_categories xref that install.sql:196 annotates as obsolete but '
			. 'models/category.php:806-813 still writes on every save. Any row where the two disagree is '
			. 'reported with a parent_mismatch note, because different parts of the shop read different '
			. 'ones.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'search'         => ['type' => 'string', 'description' => 'Case-insensitive substring match on category_name in the chosen language.'],
				'published'      => ['type' => 'boolean'],
				'parent_id'      => ['type' => 'integer', 'description' => 'Only children of this category. 0 lists top-level categories.'],
				'language'       => ['type' => 'string', 'description' => 'Language tag whose satellite table to join. Defaults to the shop language.'],
				'only_invisible' => ['type' => 'boolean', 'description' => 'Return ONLY categories with no row in the chosen language table.'],
				'limit'          => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'         => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->vmAdminBase() === null) {
			return $this->vmNotInstalledError();
		}

		if (!$this->vmTableExists('categories')) {
			return $this->vmMissingTableError('categories');
		}

		$tag = $this->vmResolveLangTag($arguments);

		if ($tag instanceof ToolResult) {
			return $tag;
		}

		if (!$this->vmLangTableExists('categories', $tag)) {
			return $this->vmMissingTableError('categories_' . $this->vmLangSuffix($tag));
		}

		$limit  = $this->vmLimit($arguments);
		$offset = $this->vmOffset($arguments);

		$from = ' FROM ' . $this->db->quoteName($this->vmTable('categories'), 'c')
			. ' LEFT JOIN ' . $this->db->quoteName($this->vmLangTable('categories', $tag), 'cl')
			. ' ON ' . $this->db->quoteName('c.virtuemart_category_id')
			. ' = ' . $this->db->quoteName('cl.virtuemart_category_id');

		$where = [];

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$where[] = $this->db->quoteName('cl.category_name') . ' LIKE '
				. $this->db->quote('%' . $this->db->escape($search, true) . '%', false);
		}

		if (\array_key_exists('published', $arguments)) {
			$where[] = $this->db->quoteName('c.published') . ' = ' . ((bool) $arguments['published'] ? 1 : 0);
		}

		if (\array_key_exists('parent_id', $arguments)) {
			$where[] = $this->db->quoteName('c.category_parent_id') . ' = ' . (int) $arguments['parent_id'];
		}

		if ((bool) ($arguments['only_invisible'] ?? false)) {
			$where[] = $this->db->quoteName('cl.virtuemart_category_id') . ' IS NULL';
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		$total = (int) $this->db->setQuery('SELECT COUNT(*)' . $from . $whereSql)->loadResult();

		$select = [
			$this->db->quoteName('c.virtuemart_category_id'),
			$this->db->quoteName('c.category_parent_id'),
			$this->db->quoteName('c.published'),
			$this->db->quoteName('c.ordering'),
			$this->db->quoteName('c.shared'),
			$this->db->quoteName('c.hits'),
			$this->db->quoteName('c.has_children'),
			$this->db->quoteName('c.has_medias'),
			$this->db->quoteName('c.category_template'),
			$this->db->quoteName('c.category_layout'),
			$this->db->quoteName('c.category_product_layout'),
			$this->db->quoteName('c.created_on'),
			$this->db->quoteName('c.modified_on'),
			$this->db->quoteName('cl.category_name'),
			$this->db->quoteName('cl.slug'),
		];

		$rows = $this->db->setQuery(
			'SELECT ' . implode(', ', $select) . $from . $whereSql
			. ' ORDER BY ' . $this->db->quoteName('c.ordering') . ' ASC, '
			. $this->db->quoteName('c.virtuemart_category_id') . ' ASC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$ids = array_map(static fn (array $r): int => (int) $r['virtuemart_category_id'], $rows);

		$productCounts = $this->countBy('product_categories', 'virtuemart_category_id', $ids);
		$childCounts   = $this->countBy('categories', 'category_parent_id', $ids);
		$xrefParents   = $this->xrefParents($ids);

		$out       = [];
		$invisible = 0;

		foreach ($rows as $row) {
			$id      = (int) $row['virtuemart_category_id'];
			$hasLang = $row['category_name'] !== null;

			$entry = [
				'virtuemart_category_id' => $id,
				'category_name'          => $hasLang ? (string) $row['category_name'] : null,
				'slug'                   => $hasLang ? (string) $row['slug'] : null,
				'category_parent_id'     => (int) $row['category_parent_id'],
				'published'              => (int) $row['published'] === 1,
				'ordering'               => (int) $row['ordering'],
				'shared'                 => (int) $row['shared'] === 1,
				'hits'                   => (int) $row['hits'],
				'product_count'          => $productCounts[$id] ?? 0,
				'child_count'            => $childCounts[$id] ?? 0,
				'language'               => $tag,
			];

			if (!$hasLang) {
				$invisible++;
				$entry['invisible'] = true;
				$entry['invisible_reason'] = 'No row in ' . $this->vmLangTable('categories', $tag)
					. '. The read join is INNER, so this category does not appear anywhere in the shop for '
					. $tag . ' shoppers — and neither do the products that only live in it.';
			}

			$xrefParent = $xrefParents[$id] ?? null;

			if ($xrefParent !== null && $xrefParent !== (int) $row['category_parent_id']) {
				$entry['parent_mismatch'] = 'category_parent_id is ' . (int) $row['category_parent_id']
					. ' but #__virtuemart_category_categories says the parent is ' . $xrefParent
					. '. VirtueMart writes both on every save (models/category.php:806-813) even though '
					. 'install.sql:196 calls the xref obsolete, so a disagreement means something wrote '
					. 'one without the other. Re-save through update_virtuemart_category to reconcile.';
			}

			$out[] = $entry;
		}

		$response = [
			'ok'         => true,
			'total'      => $total,
			'limit'      => $limit,
			'offset'     => $offset,
			'showing'    => \count($out),
			'language'   => ['tag' => $tag, 'table' => $this->vmLangTable('categories', $tag)],
			'categories' => $out,
			'count_note' => 'product_count counts rows in #__virtuemart_product_categories, so it '
				. 'includes unpublished products and products with no language row. It is a count of '
				. 'assignments, not of what a shopper can see.',
		];

		if ($invisible > 0) {
			$response['invisible_count'] = $invisible;
			$response['warning'] = $invisible . ' categories in this page have no ' . $tag
				. ' language row and are invisible to shoppers.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/**
	 * @param  array<int,int> $ids
	 * @return array<int,int>
	 */
	private function countBy(string $table, string $column, array $ids): array
	{
		if ($ids === [] || !$this->vmTableExists($table)) {
			return [];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([$this->db->quoteName($column), 'COUNT(*) AS ' . $this->db->quoteName('total')])
				->from($this->db->quoteName($this->vmTable($table)))
				->whereIn($this->db->quoteName($column), $ids)
				->group($this->db->quoteName($column))
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$out[(int) $row[$column]] = (int) $row['total'];
		}

		return $out;
	}

	/**
	 * @param  array<int,int> $ids
	 * @return array<int,int>
	 */
	private function xrefParents(array $ids): array
	{
		if ($ids === [] || !$this->vmTableExists('category_categories')) {
			return [];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('category_child_id'),
					$this->db->quoteName('category_parent_id'),
				])
				->from($this->db->quoteName($this->vmTable('category_categories')))
				->whereIn($this->db->quoteName('category_child_id'), $ids)
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$out[(int) $row['category_child_id']] = (int) $row['category_parent_id'];
		}

		return $out;
	}
}
