<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Categories;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Delete a category, refusing by default when doing so would strand products or
 * children.
 *
 * Deleting a category is quietly destructive in a way deleting a product is
 * not: the products survive, but a product whose only category was the deleted
 * one becomes unlistable — reachable by direct URL and nowhere else — with
 * nothing anywhere reporting it.
 */
final class DeleteCategoryTool extends AbstractTool
{
	use VirtuemartBootTrait;
	use CategoryWriteTrait;

	public function getName(): string { return 'delete_virtuemart_category'; }

	public function getDescription(): string
	{
		return 'Delete a VirtueMart category, its row in every language satellite table, its media links '
			. 'and its parent xref. Requires id. '
			. 'REFUSES by default when the category has child categories or assigned products, because '
			. 'both outcomes are silently damaging. Orphaned children keep a category_parent_id pointing '
			. 'at nothing and disappear from the tree; and a product whose ONLY category was this one '
			. 'becomes unlistable — still reachable by direct URL, absent from every category page and '
			. 'listing, with no error anywhere. The refusal names how many products would be stranded '
			. 'that way. '
			. 'Pass reassign_products_to to move the assignments to another category instead of deleting '
			. 'them, or force: true to delete the assignments and accept the consequence. Pass '
			. 'reparent_children_to to move child categories, or force: true to leave them orphaned. '
			. 'THIS IS IRREVERSIBLE. VirtueMart has no trash state for categories. Unpublishing the '
			. 'category instead keeps everything intact and is invisible to shoppers either way. '
			. 'The products themselves are never deleted by this tool — only their assignment rows in '
			. '#__virtuemart_product_categories.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'                    => ['type' => 'integer', 'description' => 'virtuemart_category_id. Required.'],
				'reassign_products_to'  => ['type' => 'integer', 'description' => 'Move this category\'s product assignments to this category id instead of deleting them.'],
				'reparent_children_to'  => ['type' => 'integer', 'description' => 'Move child categories to this parent id. 0 makes them top-level.'],
				'force'                 => ['type' => 'boolean', 'description' => 'Delete even though products or children are attached. Assignments are removed and children orphaned.'],
			],
			'required' => ['id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->vmAdminBase() === null) {
			return $this->vmNotInstalledError();
		}

		if (!$this->vmTableExists('categories')) {
			return $this->vmMissingTableError('categories');
		}

		$id    = $this->requirePositiveInt($arguments, 'id');
		$force = (bool) ($arguments['force'] ?? false);

		$category = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('virtuemart_category_id'),
					$this->db->quoteName('category_parent_id'),
				])
				->from($this->db->quoteName($this->vmTable('categories')))
				->where($this->db->quoteName('virtuemart_category_id') . ' = ' . $id)
		)->loadAssoc();

		if (!\is_array($category)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No category with virtuemart_category_id ' . $id . '. Nothing was deleted.',
			], true);
		}

		$oldParent = (int) $category['category_parent_id'];

		$children = array_map('intval', $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('virtuemart_category_id'))
				->from($this->db->quoteName($this->vmTable('categories')))
				->where($this->db->quoteName('category_parent_id') . ' = ' . $id)
		)->loadColumn() ?: []);

		$assigned = $this->vmTableExists('product_categories')
			? array_map('intval', $this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName('virtuemart_product_id'))
					->from($this->db->quoteName($this->vmTable('product_categories')))
					->where($this->db->quoteName('virtuemart_category_id') . ' = ' . $id)
			)->loadColumn() ?: [])
			: [];

		$reassignTo = \array_key_exists('reassign_products_to', $arguments)
			? (int) $arguments['reassign_products_to']
			: null;

		$reparentTo = \array_key_exists('reparent_children_to', $arguments)
			? (int) $arguments['reparent_children_to']
			: null;

		$stranded = $this->strandedProducts($id, $assigned);

		if (!$force && $reassignTo === null && $assigned !== []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Category ' . $id . ' has ' . \count($assigned) . ' product(s) assigned to it, '
					. 'and ' . \count($stranded) . ' of them have NO other category. Deleting it would '
					. 'leave those products reachable only by direct URL — absent from every listing and '
					. 'category page, with no error anywhere in the shop. Refusing; nothing was deleted.',
				'assigned_product_count'  => \count($assigned),
				'would_be_stranded'       => $stranded,
				'resolution'              => 'Pass reassign_products_to with another category id to move '
					. 'them, or force: true to delete the assignments and accept the consequence.',
			], true);
		}

		if (!$force && $reparentTo === null && $children !== []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Category ' . $id . ' has ' . \count($children) . ' child categor(y/ies). '
					. 'Deleting it would leave them with a category_parent_id pointing at nothing, which '
					. 'removes them from the tree entirely. Refusing; nothing was deleted.',
				'child_ids'  => $children,
				'resolution' => 'Pass reparent_children_to (0 makes them top-level) or force: true.',
			], true);
		}

		// --- children ---------------------------------------------------------
		if ($children !== []) {
			$newParent = $reparentTo ?? 0;

			if ($newParent > 0) {
				$exists = (int) $this->db->setQuery(
					$this->db->getQuery(true)
						->select('COUNT(*)')
						->from($this->db->quoteName($this->vmTable('categories')))
						->where($this->db->quoteName('virtuemart_category_id') . ' = ' . $newParent)
				)->loadResult();

				if ($exists === 0) {
					return ToolResult::json([
						'ok'    => false,
						'error' => 'reparent_children_to ' . $newParent . ' does not exist. Nothing was '
							. 'deleted.',
					], true);
				}
			}

			$this->db->setQuery(
				$this->db->getQuery(true)
					->update($this->db->quoteName($this->vmTable('categories')))
					->set($this->db->quoteName('category_parent_id') . ' = ' . $newParent)
					->where($this->db->quoteName('category_parent_id') . ' = ' . $id)
			)->execute();

			foreach ($children as $child) {
				$this->syncCategoryParentXref($child, $newParent, 0);
			}
		}

		// --- product assignments ---------------------------------------------
		$reassigned = 0;

		if ($assigned !== [] && $reassignTo !== null) {
			$exists = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select('COUNT(*)')
					->from($this->db->quoteName($this->vmTable('categories')))
					->where($this->db->quoteName('virtuemart_category_id') . ' = ' . $reassignTo)
			)->loadResult();

			if ($exists === 0) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'reassign_products_to ' . $reassignTo . ' does not exist. Nothing was '
						. 'deleted.',
				], true);
			}

			// The xref carries a UNIQUE composite key, so a product already in
			// the target category must not be inserted twice.
			$alreadyThere = array_map('intval', $this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName('virtuemart_product_id'))
					->from($this->db->quoteName($this->vmTable('product_categories')))
					->where($this->db->quoteName('virtuemart_category_id') . ' = ' . $reassignTo)
			)->loadColumn() ?: []);

			foreach (array_diff($assigned, $alreadyThere) as $productId) {
				$row                         = new \stdClass();
				$row->virtuemart_product_id  = (int) $productId;
				$row->virtuemart_category_id = $reassignTo;
				$row->ordering               = 0;

				$this->db->insertObject($this->vmTable('product_categories'), $row);
				$reassigned++;
			}
		}

		if ($this->vmTableExists('product_categories')) {
			$this->db->setQuery(
				$this->db->getQuery(true)
					->delete($this->db->quoteName($this->vmTable('product_categories')))
					->where($this->db->quoteName('virtuemart_category_id') . ' = ' . $id)
			)->execute();
		}

		// --- language rows, media, xref, base row -----------------------------
		$deletedLangRows = [];

		foreach ($this->vmActiveLangTags() as $tag) {
			if (!$this->vmLangTableExists('categories', $tag)) {
				continue;
			}

			$this->db->setQuery(
				$this->db->getQuery(true)
					->delete($this->db->quoteName($this->vmLangTable('categories', $tag)))
					->where($this->db->quoteName('virtuemart_category_id') . ' = ' . $id)
			)->execute();

			$deletedLangRows[$tag] = $this->db->getAffectedRows();
		}

		foreach (['category_medias', 'calc_categories'] as $table) {
			if ($this->vmTableExists($table) && \in_array('virtuemart_category_id', $this->vmColumns($table), true)) {
				$this->db->setQuery(
					$this->db->getQuery(true)
						->delete($this->db->quoteName($this->vmTable($table)))
						->where($this->db->quoteName('virtuemart_category_id') . ' = ' . $id)
				)->execute();
			}
		}

		if ($this->vmTableExists('category_categories')) {
			$this->db->setQuery(
				'DELETE FROM ' . $this->db->quoteName($this->vmTable('category_categories'))
				. ' WHERE ' . $this->db->quoteName('category_child_id') . ' = ' . $id
				. ' OR ' . $this->db->quoteName('category_parent_id') . ' = ' . $id
			)->execute();
		}

		$this->db->setQuery(
			$this->db->getQuery(true)
				->delete($this->db->quoteName($this->vmTable('categories')))
				->where($this->db->quoteName('virtuemart_category_id') . ' = ' . $id)
		)->execute();

		if ($oldParent > 0) {
			$this->refreshCategoryFlags($oldParent);
		}

		$response = [
			'ok'                     => true,
			'deleted_category_id'    => $id,
			'deleted_language_rows'  => $deletedLangRows,
			'children_handled'       => $children === []
				? 'none'
				: ($reparentTo === null
					? 'orphaned (force): ' . implode(', ', $children)
					: 'reparented to ' . $reparentTo . ': ' . implode(', ', $children)),
			'product_assignments_removed' => \count($assigned),
			'products_reassigned'         => $reassigned,
			'irreversible'                => 'VirtueMart has no trash state for categories.',
		];

		if ($reassignTo === null && $stranded !== []) {
			$response['stranded_products'] = $stranded;
			$response['stranded_note'] = 'These products now belong to NO category. They remain reachable '
				. 'by direct URL but appear in no listing anywhere in the shop, and nothing in VirtueMart '
				. 'reports this. Use set_virtuemart_product_categories to give them a home.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/**
	 * Products whose ONLY category is the one being deleted.
	 *
	 * @param  array<int,int> $assigned
	 * @return array<int,int>
	 */
	private function strandedProducts(int $categoryId, array $assigned): array
	{
		if ($assigned === [] || !$this->vmTableExists('product_categories')) {
			return [];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('virtuemart_product_id'),
					'COUNT(*) AS ' . $this->db->quoteName('total'),
				])
				->from($this->db->quoteName($this->vmTable('product_categories')))
				->whereIn($this->db->quoteName('virtuemart_product_id'), $assigned)
				->group($this->db->quoteName('virtuemart_product_id'))
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			if ((int) $row['total'] <= 1) {
				$out[] = (int) $row['virtuemart_product_id'];
			}
		}

		return $out;
	}
}
