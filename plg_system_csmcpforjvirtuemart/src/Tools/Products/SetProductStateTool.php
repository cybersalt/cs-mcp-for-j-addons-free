<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Products;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Publish or unpublish products with a targeted UPDATE on one column.
 *
 * This is the textbook case for direct SQL over the model. `published` is a
 * single tinyint with no satellites, no slug involvement and no derived state,
 * whereas routing it through `ProductModel::store()` would drag the entire
 * destructive replace-set machinery along for a one-bit change.
 *
 * Two vendor behaviours are worth the caller knowing about, and both are
 * reported rather than left to be discovered:
 *
 *   - The SQL default (`0`, `install.sql:842`) and the PHP table class default
 *     (`1`, `tables/products.php:93`) disagree, so nothing about a product's
 *     published state can be inferred from how it was created.
 *   - A user without `vm.product.edit.state` has their published value silently
 *     overwritten when saving through the component
 *     (`models/product.php:2734-2740`), so the admin UI can report a state it
 *     did not store. This tool has no such behaviour.
 */
final class SetProductStateTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'set_virtuemart_product_state'; }

	public function getDescription(): string
	{
		return 'Publish or unpublish one or more VirtueMart products. Pass id for a single product or ids '
			. 'for several, plus published (true/false). '
			. 'This does a targeted UPDATE on the published column only. Nothing else about the product is '
			. 'read or written: no price row, category assignment, manufacturer link, shopper group, media '
			. 'link or custom field value is touched. That is exactly why this tool exists separately '
			. 'from update_virtuemart_product — routing a one-bit change through VirtueMart\'s own '
			. 'ProductModel::store() would drag its destructive replace-set behaviour along with it '
			. '(models/product.php:2895-2960). '
			. 'Unpublishing is the recommended alternative to delete_virtuemart_product: an unpublished '
			. 'product is invisible to shoppers, keeps all its data, and can be brought straight back. '
			. 'VirtueMart products have no trash state at all. '
			. 'Publishing a product does NOT make it visible on its own. It also needs a row in the '
			. 'language satellite table for the shopper\'s language (the join is INNER, '
			. 'helpers/vmtable.php:1065-1068), at least one published category, and a price row. Any '
			. 'product published here that still fails one of those is reported with a still_invisible '
			. 'note; get_virtuemart_product gives the full checklist. '
			. 'Variants (child products) are NOT cascaded. Publishing a parent does not publish its '
			. 'children and vice versa; pass their ids explicitly if that is what you want.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'        => ['type' => 'integer', 'description' => 'A single virtuemart_product_id.'],
				'ids'       => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Several product ids. Use instead of id for a batch.'],
				'published' => ['type' => 'boolean', 'description' => 'Required. true publishes, false unpublishes.'],
			],
			'required' => ['published'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->vmAdminBase() === null) {
			return $this->vmNotInstalledError();
		}

		if (!$this->vmTableExists('products')) {
			return $this->vmMissingTableError('products');
		}

		$published = $this->vmNormalisePublished($arguments['published'] ?? null);

		if ($published === null) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'published is required and must be true or false.',
			], true);
		}

		$ids = [];

		foreach (array_merge([$arguments['id'] ?? null], (array) ($arguments['ids'] ?? [])) as $raw) {
			$candidate = (int) $raw;

			if ($candidate > 0 && !\in_array($candidate, $ids, true)) {
				$ids[] = $candidate;
			}
		}

		if ($ids === []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Supply id or a non-empty ids array.',
			], true);
		}

		$existing = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('virtuemart_product_id'),
					$this->db->quoteName('published'),
				])
				->from($this->db->quoteName($this->vmTable('products')))
				->whereIn($this->db->quoteName('virtuemart_product_id'), $ids)
		)->loadAssocList('virtuemart_product_id') ?: [];

		$missing = array_values(array_diff($ids, array_map('intval', array_keys($existing))));

		if ($missing !== []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'These product ids do not exist: ' . implode(', ', $missing)
					. '. Nothing was written — a batch that silently skipped part of its input would be '
					. 'worse than a refusal.',
			], true);
		}

		$toChange = [];

		foreach ($existing as $productId => $row) {
			if ((int) $row['published'] !== $published) {
				$toChange[] = (int) $productId;
			}
		}

		if ($toChange === []) {
			return ToolResult::json([
				'ok'        => true,
				'changed'   => [],
				'unchanged' => $ids,
				'note'      => 'Every one of these products is already ' . ($published === 1 ? 'published' : 'unpublished')
					. '. No row was written and modified_on was not stamped.',
			]);
		}

		$now = $this->vmNow();

		$query = $this->db->getQuery(true)
			->update($this->db->quoteName($this->vmTable('products')))
			->set($this->db->quoteName('published') . ' = ' . $published)
			->set($this->db->quoteName('modified_on') . ' = ' . $this->db->quote($now))
			->set($this->db->quoteName('modified_by') . ' = ' . (int) $actor->id)
			->whereIn($this->db->quoteName('virtuemart_product_id'), $toChange);

		$this->db->setQuery($query)->execute();

		$response = [
			'ok'          => true,
			'published'   => $published === 1,
			'changed'     => $toChange,
			'unchanged'   => array_values(array_diff($ids, $toChange)),
			'modified_on' => $now,
		];

		if ($published === 1) {
			$blocked = $this->stillInvisible($toChange);

			if ($blocked !== []) {
				$response['still_invisible']      = $blocked;
				$response['still_invisible_note'] = 'These products are now published but will still not '
					. 'appear in the shop for the reason given. Publishing is only one of five conditions; '
					. 'get_virtuemart_product returns the full checklist.';
			}
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/**
	 * Which of these newly published products still cannot be seen, and why.
	 *
	 * @param  array<int,int> $ids
	 * @return array<int,array<string,mixed>>
	 */
	private function stillInvisible(array $ids): array
	{
		$defaultTag = $this->vmDefaultLangTag();
		$out        = [];

		if (!$this->vmLangTableExists('products', $defaultTag)) {
			return $out;
		}

		$withLang = $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('virtuemart_product_id'))
				->from($this->db->quoteName($this->vmLangTable('products', $defaultTag)))
				->whereIn($this->db->quoteName('virtuemart_product_id'), $ids)
		)->loadColumn() ?: [];

		$withLang = array_map('intval', $withLang);

		$withCategory = [];

		if ($this->vmTableExists('product_categories')) {
			$withCategory = array_map('intval', $this->db->setQuery(
				$this->db->getQuery(true)
					->select('DISTINCT ' . $this->db->quoteName('virtuemart_product_id'))
					->from($this->db->quoteName($this->vmTable('product_categories')))
					->whereIn($this->db->quoteName('virtuemart_product_id'), $ids)
			)->loadColumn() ?: []);
		}

		foreach ($ids as $id) {
			$reasons = [];

			if (!\in_array($id, $withLang, true)) {
				$reasons[] = 'No row in ' . $this->vmLangTable('products', $defaultTag)
					. '. The read join is INNER, so this product does not exist for shoppers.';
			}

			if (!\in_array($id, $withCategory, true)) {
				$reasons[] = 'No category assignment, so it appears in no listing.';
			}

			if ($reasons !== []) {
				$out[] = ['virtuemart_product_id' => $id, 'reasons' => $reasons];
			}
		}

		return $out;
	}
}
