<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Products;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Replace, add to, or remove from a product's category assignments.
 *
 * This is a separate tool from update_virtuemart_product on purpose. Category
 * assignment is the single most dangerous field in VirtueMart's product save,
 * because the guard at `models/product.php:2940` reads:
 *
 *     if (empty($data['categories'])
 *         or (!empty($data['categories'][0]) and $data['categories'][0] != "-2"))
 *
 * — so an EMPTY categories array means "yes, store categories", i.e. store the
 * empty set, i.e. delete every assignment. The only way to leave them alone is
 * the literal sentinel `"-2"`. Making that an explicit, separately named
 * operation removes the possibility of triggering it by omission.
 */
final class SetProductCategoriesTool extends AbstractTool
{
	use VirtuemartBootTrait;
	use ProductWriteTrait;

	public function getName(): string { return 'set_virtuemart_product_categories'; }

	public function getDescription(): string
	{
		return 'Set which categories a VirtueMart product belongs to, writing '
			. '#__virtuemart_product_categories. Requires id, plus one of: category_ids (replace the whole '
			. 'set), add (append), or remove (detach). '
			. 'This is a separate tool rather than a field on update_virtuemart_product because category '
			. 'assignment is where VirtueMart\'s partial-save hazard bites hardest. Its own guard at '
			. 'models/product.php:2940 treats an EMPTY categories array as "store the empty set" — i.e. '
			. 'delete every assignment — and the only way to skip category handling is the literal string '
			. 'sentinel "-2". Here, clearing the set requires passing category_ids: [] and is reported as '
			. 'a removal. '
			. 'Category ids are validated before anything is written: an id that does not exist is '
			. 'refused, and an id belonging to an UNPUBLISHED category is accepted but reported, since the '
			. 'product will not be listed under it. '
			. 'ORDERING is written. #__virtuemart_product_categories carries an ordering column and is '
			. 'declared orderable with auto = false (tables/product_categories.php), so the order of your '
			. 'category_ids array is preserved as the product\'s position within each category listing. '
			. 'The has_categories join hint is recomputed afterwards. That column is not decorative: when '
			. 'it reads 0, VirtueMart skips the category join in its listing queries and the assignments '
			. 'have no effect at all (models/product.php:2765-2776). '
			. 'product_canon_category_id is left alone unless you pass canonical_category_id. If the '
			. 'current canonical category is no longer among the assignments, that is reported.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'           => ['type' => 'integer', 'description' => 'virtuemart_product_id. Required.'],
				'category_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Replace the whole set with these, in this order. An empty array detaches the product from every category.'],
				'add'          => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Append these to the existing set.'],
				'remove'       => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Detach these from the existing set.'],
				'canonical_category_id' => ['type' => 'integer', 'description' => 'Set product_canon_category_id. Must be one of the resulting assignments. Pass 0 to clear.'],
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

		foreach (['products', 'product_categories', 'categories'] as $table) {
			if (!$this->vmTableExists($table)) {
				return $this->vmMissingTableError($table);
			}
		}

		$id = $this->requirePositiveInt($arguments, 'id');

		$product = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('virtuemart_product_id'),
					$this->db->quoteName('product_canon_category_id'),
				])
				->from($this->db->quoteName($this->vmTable('products')))
				->where($this->db->quoteName('virtuemart_product_id') . ' = ' . $id)
		)->loadAssoc();

		if (!\is_array($product)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No product with virtuemart_product_id ' . $id . '. Nothing was written.',
			], true);
		}

		$current = $this->vmXrefIds('product_categories', 'virtuemart_product_id', $id, 'virtuemart_category_id');

		$hasReplace = \array_key_exists('category_ids', $arguments);
		$hasAdd     = \array_key_exists('add', $arguments);
		$hasRemove  = \array_key_exists('remove', $arguments);

		if (!$hasReplace && !$hasAdd && !$hasRemove) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Supply one of category_ids, add or remove. Calling with none would be '
					. 'ambiguous, and in VirtueMart the ambiguous reading of "no categories supplied" is '
					. '"delete them all" (models/product.php:2940). This tool will not guess.',
				'current_category_ids' => $current,
			], true);
		}

		if ($hasReplace && ($hasAdd || $hasRemove)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'category_ids replaces the whole set and cannot be combined with add or '
					. 'remove. Nothing was written.',
			], true);
		}

		$target = $hasReplace ? $this->cleanIds((array) $arguments['category_ids']) : $current;

		if ($hasAdd) {
			foreach ($this->cleanIds((array) $arguments['add']) as $add) {
				if (!\in_array($add, $target, true)) {
					$target[] = $add;
				}
			}
		}

		if ($hasRemove) {
			$target = array_values(array_diff($target, $this->cleanIds((array) $arguments['remove'])));
		}

		// --- validate every target id BEFORE writing --------------------------
		$known = [];

		if ($target !== []) {
			$known = $this->db->setQuery(
				$this->db->getQuery(true)
					->select([
						$this->db->quoteName('virtuemart_category_id'),
						$this->db->quoteName('published'),
					])
					->from($this->db->quoteName($this->vmTable('categories')))
					->whereIn($this->db->quoteName('virtuemart_category_id'), $target)
			)->loadAssocList('virtuemart_category_id') ?: [];

			$unknown = array_values(array_diff($target, array_map('intval', array_keys($known))));

			if ($unknown !== []) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'These category ids do not exist: ' . implode(', ', $unknown)
						. '. Nothing was written. VirtueMart has no foreign keys, so an invalid id would '
						. 'have inserted cleanly and simply never matched anything.',
				], true);
			}
		}

		if ($target === $current) {
			return ToolResult::json([
				'ok'           => true,
				'changed'      => false,
				'category_ids' => $current,
				'note'         => 'The resulting set is identical to the current one. Nothing was written.',
			]);
		}

		$count = $this->vmReplaceXref('product_categories', 'virtuemart_product_id', $id, 'virtuemart_category_id', $target, true);
		$flags = $this->refreshHasFlags($id);

		$response = [
			'ok'                   => true,
			'virtuemart_product_id' => $id,
			'previous_category_ids' => $current,
			'category_ids'          => $target,
			'rows_written'          => $count,
			'has_categories'        => $flags['has_categories'],
		];

		$unpublished = [];

		foreach ($known as $categoryId => $row) {
			if ((int) $row['published'] !== 1) {
				$unpublished[] = (int) $categoryId;
			}
		}

		if ($unpublished !== []) {
			$response['unpublished_categories'] = $unpublished;
			$response['unpublished_note'] = 'These categories are unpublished, so the product will not be '
				. 'listed under them even though the assignment was written.';
		}

		if ($target === []) {
			$response['warning'] = 'The product is now in NO category. It remains reachable by direct URL '
				. 'but appears in no listing anywhere in the shop.';
		}

		// --- canonical category ----------------------------------------------
		$canonical = (int) $product['product_canon_category_id'];

		if (\array_key_exists('canonical_category_id', $arguments)) {
			$requested = (int) $arguments['canonical_category_id'];

			if ($requested !== 0 && !\in_array($requested, $target, true)) {
				$response['canonical_refused'] = 'canonical_category_id ' . $requested . ' was NOT set: '
					. 'it is not among the product\'s resulting category assignments. The category '
					. 'assignments above were still written.';
			} else {
				$row                            = new \stdClass();
				$row->virtuemart_product_id     = $id;
				$row->product_canon_category_id = $requested;
				$row->modified_on               = $this->vmNow();
				$row->modified_by               = (int) $actor->id;

				$this->db->updateObject($this->vmTable('products'), $row, 'virtuemart_product_id');

				$response['product_canon_category_id'] = $requested;
				$canonical                             = $requested;
			}
		}

		if ($canonical > 0 && !\in_array($canonical, $target, true)) {
			$response['canonical_orphaned'] = 'product_canon_category_id is ' . $canonical . ', which is '
				. 'no longer one of this product\'s categories. VirtueMart uses it to pick the canonical '
				. 'URL when a product is in several categories, so leaving it dangling produces a '
				. 'canonical link into a category the product is not in. Pass canonical_category_id to '
				. 'fix or clear it.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/** @return array<int,int> */
	private function cleanIds(array $raw): array
	{
		$out = [];

		foreach ($raw as $value) {
			$id = (int) $value;

			if ($id > 0 && !\in_array($id, $out, true)) {
				$out[] = $id;
			}
		}

		return $out;
	}
}
