<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Inventory;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Stock report across the catalogue.
 *
 * VirtueMart keeps three interlocking counters and they are easy to
 * misinterpret in isolation: `product_in_stock` is physical stock,
 * `product_ordered` is stock reserved by orders in an `R` state, and
 * `product_sales` is a lifetime counter that moves in the OPPOSITE direction to
 * `product_in_stock` (`models/product.php:3533-3539`). Sellable stock is
 * `product_in_stock` minus `product_ordered`, which is not a stored column.
 */
final class ListStockTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'list_virtuemart_stock'; }

	public function getDescription(): string
	{
		return 'Stock report across the VirtueMart catalogue, from #__virtuemart_products. '
			. 'Reports product_in_stock (physical), product_ordered (reserved by orders sitting in a '
			. 'status whose order_stock_handle is R), product_sales (lifetime), low_stock_notification '
			. '(the threshold VirtueMart emails on) and a computed available figure. '
			. 'AVAILABLE = product_in_stock - product_ordered. That is not a stored column and VirtueMart '
			. 'never shows it as one, but it is what is genuinely sellable: stock reserved by pending '
			. 'orders is still counted in product_in_stock until those orders reach a status whose '
			. 'order_stock_handle is O (models/orders.php:2049-2074). A negative available figure means '
			. 'the shop is oversold and is flagged. '
			. 'product_sales moves in the OPPOSITE direction to product_in_stock '
			. '(models/product.php:3533-3539), so a large product_sales with a large product_in_stock is '
			. 'normal and does not mean the numbers disagree. '
			. 'Filters: low_stock_only (at or below the product\'s own low_stock_notification threshold, '
			. 'or below min_stock if you give one), out_of_stock_only, oversold_only, category_id, '
			. 'search on name or SKU. '
			. 'Stock is per PRODUCT ROW, and a variant is its own row. A parent with variants usually '
			. 'holds no stock itself unless the shop uses shared_stock, in which case VirtueMart writes '
			. 'the parent instead (models/product.php:3518-3523). Parent rows with variants are flagged '
			. 'so a zero there is not read as a problem.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'search'            => ['type' => 'string', 'description' => 'Substring match on product_name (shop language) or product_sku.'],
				'category_id'       => ['type' => 'integer'],
				'low_stock_only'    => ['type' => 'boolean', 'description' => 'Only products at or below their low_stock_notification threshold (or below min_stock).'],
				'out_of_stock_only' => ['type' => 'boolean', 'description' => 'Only products with product_in_stock <= 0.'],
				'oversold_only'     => ['type' => 'boolean', 'description' => 'Only products where product_ordered exceeds product_in_stock.'],
				'min_stock'         => ['type' => 'integer', 'description' => 'Treat anything at or below this as low stock, instead of each product\'s own threshold.'],
				'published_only'    => ['type' => 'boolean', 'description' => 'Only published products. Default false.'],
				'limit'             => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'            => ['type' => 'integer'],
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

		if (!$this->vmTableExists('products')) {
			return $this->vmMissingTableError('products');
		}

		$tag       = $this->vmDefaultLangTag();
		$hasLang   = $this->vmLangTableExists('products', $tag);
		$limit     = $this->vmLimit($arguments);
		$offset    = $this->vmOffset($arguments);

		$from = ' FROM ' . $this->db->quoteName($this->vmTable('products'), 'p');

		if ($hasLang) {
			$from .= ' LEFT JOIN ' . $this->db->quoteName($this->vmLangTable('products', $tag), 'pl')
				. ' ON ' . $this->db->quoteName('p.virtuemart_product_id')
				. ' = ' . $this->db->quoteName('pl.virtuemart_product_id');
		}

		$where = [];

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$like  = $this->db->quote('%' . $this->db->escape($search, true) . '%', false);
			$parts = [$this->db->quoteName('p.product_sku') . ' LIKE ' . $like];

			if ($hasLang) {
				$parts[] = $this->db->quoteName('pl.product_name') . ' LIKE ' . $like;
			}

			$where[] = '(' . implode(' OR ', $parts) . ')';
		}

		if (\array_key_exists('category_id', $arguments)) {
			$where[] = $this->db->quoteName('p.virtuemart_product_id') . ' IN (SELECT '
				. $this->db->quoteName('virtuemart_product_id') . ' FROM '
				. $this->db->quoteName($this->vmTable('product_categories')) . ' WHERE '
				. $this->db->quoteName('virtuemart_category_id') . ' = ' . (int) $arguments['category_id'] . ')';
		}

		if ((bool) ($arguments['published_only'] ?? false)) {
			$where[] = $this->db->quoteName('p.published') . ' = 1';
		}

		if ((bool) ($arguments['out_of_stock_only'] ?? false)) {
			$where[] = $this->db->quoteName('p.product_in_stock') . ' <= 0';
		}

		if ((bool) ($arguments['oversold_only'] ?? false)) {
			$where[] = $this->db->quoteName('p.product_ordered') . ' > '
				. $this->db->quoteName('p.product_in_stock');
		}

		if ((bool) ($arguments['low_stock_only'] ?? false)) {
			if (\array_key_exists('min_stock', $arguments)) {
				$where[] = $this->db->quoteName('p.product_in_stock') . ' <= ' . (int) $arguments['min_stock'];
			} else {
				// low_stock_notification is 0 on most rows, which VirtueMart reads
				// as "no threshold set" rather than "warn at zero".
				$where[] = '(' . $this->db->quoteName('p.low_stock_notification') . ' > 0 AND '
					. $this->db->quoteName('p.product_in_stock') . ' <= '
					. $this->db->quoteName('p.low_stock_notification') . ')';
			}
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		$total = (int) $this->db->setQuery('SELECT COUNT(*)' . $from . $whereSql)->loadResult();

		$select = [
			$this->db->quoteName('p.virtuemart_product_id'),
			$this->db->quoteName('p.product_sku'),
			$this->db->quoteName('p.product_parent_id'),
			$this->db->quoteName('p.product_in_stock'),
			$this->db->quoteName('p.product_ordered'),
			$this->db->quoteName('p.product_sales'),
			$this->db->quoteName('p.low_stock_notification'),
			$this->db->quoteName('p.product_stockhandle'),
			$this->db->quoteName('p.product_availability'),
			$this->db->quoteName('p.product_available_date'),
			$this->db->quoteName('p.published'),
		];

		if ($hasLang) {
			$select[] = $this->db->quoteName('pl.product_name');
		}

		$rows = $this->db->setQuery(
			'SELECT ' . implode(', ', $select) . $from . $whereSql
			. ' ORDER BY ' . $this->db->quoteName('p.product_in_stock') . ' ASC, '
			. $this->db->quoteName('p.virtuemart_product_id') . ' ASC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$parentIds = [];

		foreach ($rows as $row) {
			$parentIds[] = (int) $row['virtuemart_product_id'];
		}

		$variantCounts = $this->variantCounts($parentIds);

		$out      = [];
		$oversold = 0;
		$low      = 0;

		foreach ($rows as $row) {
			$id        = (int) $row['virtuemart_product_id'];
			$inStock   = (int) $row['product_in_stock'];
			$ordered   = (int) $row['product_ordered'];
			$threshold = (int) $row['low_stock_notification'];
			$available = $inStock - $ordered;

			$entry = [
				'virtuemart_product_id'   => $id,
				'product_name'            => $hasLang ? ($row['product_name'] ?? null) : null,
				'product_sku'             => (string) $row['product_sku'],
				'published'               => (int) $row['published'] === 1,
				'product_in_stock'        => $inStock,
				'product_ordered'         => $ordered,
				'available'               => $available,
				'product_sales'           => (int) $row['product_sales'],
				'low_stock_notification'  => $threshold,
				'product_stockhandle'     => (string) $row['product_stockhandle'],
				'product_availability'    => (string) $row['product_availability'],
				'product_available_date'  => $row['product_available_date'],
				'is_variant'              => (int) $row['product_parent_id'] > 0,
			];

			$notes = [];

			if ($available < 0) {
				$oversold++;
				$notes[] = 'OVERSOLD: product_ordered (' . $ordered . ') exceeds product_in_stock ('
					. $inStock . '). Either the shop has taken more orders than it can fill, or stock '
					. 'handling was skipped on a status change — handleStockAfterStatusChangedPerProduct '
					. 'silently declines to adjust stock when the old or new status code is not a '
					. 'published orderstates row (models/orders.php:2035-2038) while letting the status '
					. 'change proceed.';
			}

			if ($threshold > 0 && $inStock <= $threshold) {
				$low++;
				$notes[] = 'At or below its low_stock_notification threshold of ' . $threshold . '.';
			}

			if ($threshold === 0) {
				$notes[] = 'low_stock_notification is 0, which VirtueMart reads as "no threshold set" '
					. 'rather than "warn at zero", so no low-stock email will ever fire for this product.';
			}

			$variants = $variantCounts[$id] ?? 0;

			if ($variants > 0) {
				$entry['variant_count'] = $variants;
				$notes[] = 'This is a parent with ' . $variants . ' variant(s). Stock normally lives on '
					. 'the child rows, so a zero here is usually correct — unless the shop uses '
					. 'shared_stock, in which case VirtueMart writes the parent instead '
					. '(models/product.php:3518-3523).';
			}

			if ($notes !== []) {
				$entry['notes'] = $notes;
			}

			$out[] = $entry;
		}

		return ToolResult::json([
			'ok'            => true,
			'total'         => $total,
			'limit'         => $limit,
			'offset'        => $offset,
			'showing'       => \count($out),
			'oversold_rows' => $oversold,
			'low_stock_rows' => $low,
			'products'      => $out,
			'counter_note'  => 'product_in_stock is physical stock. product_ordered is stock reserved by '
				. 'orders in a status whose order_stock_handle is R. available = in_stock - ordered and '
				. 'is computed here, not stored. product_sales is a lifetime counter that moves in the '
				. 'OPPOSITE direction to product_in_stock (models/product.php:3533-3539).',
			'language'      => $hasLang ? $tag : 'unavailable — the default language satellite table is missing',
			'component'     => $this->vmComponentNotice(),
		]);
	}

	/**
	 * @param  array<int,int> $ids
	 * @return array<int,int>
	 */
	private function variantCounts(array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('product_parent_id'),
					'COUNT(*) AS ' . $this->db->quoteName('total'),
				])
				->from($this->db->quoteName($this->vmTable('products')))
				->whereIn($this->db->quoteName('product_parent_id'), $ids)
				->group($this->db->quoteName('product_parent_id'))
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$out[(int) $row['product_parent_id']] = (int) $row['total'];
		}

		return $out;
	}
}
