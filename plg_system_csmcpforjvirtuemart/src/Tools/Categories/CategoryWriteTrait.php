<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Categories;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;

/**
 * Shared write machinery for the category tools.
 *
 * Categories carry the same base-row / language-row split as products, plus one
 * extra piece of bookkeeping that is easy to miss: the parent-child
 * relationship is stored TWICE. `#__virtuemart_categories.category_parent_id`
 * is the field the admin form edits, and `#__virtuemart_category_categories` is
 * a separate xref that `install.sql:196` literally annotates as "Obsolete since
 * vm3.6.12" — and which `models/category.php:806-813` nonetheless writes on
 * every single save. Leaving it stale produces a category tree that disagrees
 * with itself depending on which query built the view, so these tools maintain
 * both.
 */
trait CategoryWriteTrait
{
	/**
	 * Base columns a caller may set on `#__virtuemart_categories`.
	 *
	 * `virtuemart_vendor_id` is absent for the same reason as on products: on a
	 * single-vendor shop VirtueMart forces it to 1 anyway
	 * (`helpers/vmtable.php:1526-1603`).
	 *
	 * @return array<int,string>
	 */
	protected function categoryBaseWritable(): array
	{
		return [
			'category_parent_id',
			'category_template',
			'category_layout',
			'category_product_layout',
			'products_per_row',
			'limit_list_step',
			'limit_list_initial',
			'metarobot',
			'metaauthor',
			'ordering',
			'shared',
			'published',
		];
	}

	/**
	 * `tables/categories.php:92` — plus the auto-appended `slug`.
	 *
	 * @return array<int,string>
	 */
	protected function categoryLangWritable(): array
	{
		return [
			'category_name',
			'category_description',
			'metadesc',
			'metakey',
			'customtitle',
			'slug',
		];
	}

	/**
	 * Build a complete category language row, generating and de-duplicating the
	 * slug the way `helpers/vmtable.php:1724-1783` would.
	 *
	 * @param  array<string,mixed> $supplied
	 * @param  array<string,mixed> $existing
	 * @return array{fields:array<string,mixed>,slug_note:?string}|ToolResult
	 */
	protected function buildCategoryLangRow(array $supplied, array $existing, string $langTable, int $categoryId): array|ToolResult
	{
		$fields = [];

		foreach ($this->categoryLangWritable() as $column) {
			if (\array_key_exists($column, $supplied)) {
				$fields[$column] = (string) $supplied[$column];
			} elseif (\array_key_exists($column, $existing)) {
				$fields[$column] = (string) $existing[$column];
			} else {
				$fields[$column] = '';
			}
		}

		if (trim($fields['category_name']) === '') {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'category_name is empty for ' . $langTable . '. VirtueMart derives the slug '
					. 'from it and aborts the entire save when both are empty '
					. '(helpers/vmtable.php:1741-1744). Refusing; nothing was written.',
			], true);
		}

		$slugNote = null;
		$slugIn   = trim($fields['slug']);

		$fields['slug'] = $this->vmSlugify($slugIn === '' ? $fields['category_name'] : $slugIn);

		if ($slugIn === '') {
			$slugNote = 'slug was generated from category_name using VirtueMart\'s own transform '
				. '(helpers/vmtable.php:1754-1776).';
		} elseif ($fields['slug'] !== $slugIn) {
			$slugNote = 'The supplied slug was normalised from "' . $slugIn . '" to "' . $fields['slug']
				. '" by VirtueMart\'s own transform.';
		}

		if ($fields['slug'] === '') {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'The slug reduced to an empty string, so SEF routing for this category would '
					. '404. Supply an explicit slug. Refusing; nothing was written.',
			], true);
		}

		$unique = $this->vmUniqueSlug($fields['slug'], $langTable, 'virtuemart_category_id', $categoryId);

		if (!$unique['unique']) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Could not find a unique slug after 40 attempts, the same limit as '
					. 'checkCreateUnique() (helpers/vmtable.php:1487-1523). The slug column carries a '
					. 'UNIQUE KEY on ' . $langTable . '. Refusing; nothing was written.',
			], true);
		}

		if ($unique['slug'] !== $fields['slug']) {
			$slugNote = ($slugNote === null ? '' : $slugNote . ' ')
				. 'It collided with an existing row and was made unique as "' . $unique['slug'] . '".';
			$fields['slug'] = $unique['slug'];
		}

		foreach ($fields as $column => $value) {
			$limit = $this->columnLimitFor($langTable, (string) $column);

			if ($limit === null) {
				continue;
			}

			$refusal = $this->vmAssertFits((string) $value, $column . ' (in ' . $langTable . ')', $limit);

			if ($refusal !== null) {
				return $refusal;
			}
		}

		return ['fields' => $fields, 'slug_note' => $slugNote];
	}

	/** Maximum byte length of a live column, read from the driver's type string. */
	protected function columnLimitFor(string $table, string $column): ?int
	{
		$columns = $this->db->getTableColumns($table, true) ?: [];

		if (!isset($columns[$column])) {
			return null;
		}

		$type = strtolower((string) ($columns[$column]->Type ?? ''));

		if (preg_match('/^(var)?char\((\d+)\)/', $type, $m) === 1) {
			return (int) $m[2];
		}

		return match (true) {
			str_starts_with($type, 'tinytext')   => 255,
			str_starts_with($type, 'mediumtext') => 16777215,
			str_starts_with($type, 'longtext')   => 4294967295,
			str_starts_with($type, 'text')       => 65535,
			default                              => null,
		};
	}

	/** @param array<string,mixed> $fields */
	protected function writeCategoryLangRow(string $langTable, int $categoryId, array $fields, bool $rowExists): void
	{
		$object                         = new \stdClass();
		$object->virtuemart_category_id = $categoryId;

		foreach ($fields as $column => $value) {
			$object->{$column} = $value;
		}

		if ($rowExists) {
			$this->db->updateObject($langTable, $object, 'virtuemart_category_id');

			return;
		}

		$this->db->insertObject($langTable, $object);
	}

	/**
	 * Keep the obsolete-but-still-written parent xref in step.
	 *
	 * `models/category.php:806-813` writes this on every save even though
	 * `install.sql:196` calls the table obsolete. The table has a UNIQUE KEY on
	 * `category_child_id`, so a child has exactly one row.
	 */
	protected function syncCategoryParentXref(int $categoryId, int $parentId, int $ordering): void
	{
		if (!$this->vmTableExists('category_categories')) {
			return;
		}

		$table = $this->vmTable('category_categories');

		$this->db->setQuery(
			$this->db->getQuery(true)
				->delete($this->db->quoteName($table))
				->where($this->db->quoteName('category_child_id') . ' = ' . $categoryId)
		)->execute();

		$row                     = new \stdClass();
		$row->category_child_id  = $categoryId;
		$row->category_parent_id = $parentId;
		$row->ordering           = $ordering;

		$this->db->insertObject($table, $row);
	}

	/** Recompute `has_children` / `has_medias` from the live rows. */
	protected function refreshCategoryFlags(int $categoryId): array
	{
		$children = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName($this->vmTable('categories')))
				->where($this->db->quoteName('category_parent_id') . ' = ' . $categoryId)
		)->loadResult();

		$medias = 0;

		if ($this->vmTableExists('category_medias')) {
			$medias = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select('COUNT(*)')
					->from($this->db->quoteName($this->vmTable('category_medias')))
					->where($this->db->quoteName('virtuemart_category_id') . ' = ' . $categoryId)
			)->loadResult();
		}

		$flags = [
			'has_children' => $children > 0 ? 1 : 0,
			'has_medias'   => $medias > 0 ? 1 : 0,
		];

		$row                         = new \stdClass();
		$row->virtuemart_category_id = $categoryId;
		$row->has_children           = $flags['has_children'];
		$row->has_medias             = $flags['has_medias'];

		$this->db->updateObject($this->vmTable('categories'), $row, 'virtuemart_category_id');

		return $flags;
	}

	/**
	 * Refuse a parent assignment that would create a cycle.
	 *
	 * VirtueMart has no guard for this and a cycle makes every recursive tree
	 * walk hang.
	 */
	protected function assertNoCategoryCycle(int $categoryId, int $parentId): ?ToolResult
	{
		if ($parentId === 0) {
			return null;
		}

		if ($parentId === $categoryId) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'A category cannot be its own parent. Refusing; nothing was written.',
			], true);
		}

		$seen    = [$categoryId];
		$current = $parentId;

		for ($i = 0; $i < 64 && $current > 0; $i++) {
			if (\in_array($current, $seen, true)) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'That parent assignment would create a cycle in the category tree. '
						. 'VirtueMart has no guard against this and every recursive walk of the tree — '
						. 'breadcrumbs, menus, the admin listing — would loop until PHP gave up. Refusing; '
						. 'nothing was written.',
					'cycle_through' => $seen,
				], true);
			}

			$seen[] = $current;

			$current = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName('category_parent_id'))
					->from($this->db->quoteName($this->vmTable('categories')))
					->where($this->db->quoteName('virtuemart_category_id') . ' = ' . $current)
			)->loadResult();
		}

		return null;
	}
}
