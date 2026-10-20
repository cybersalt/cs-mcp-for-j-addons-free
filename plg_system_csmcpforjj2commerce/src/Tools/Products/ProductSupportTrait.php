<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Products;

\defined('_JEXEC') or die;

/**
 * Assembling a product, and the few writes every product tool shares.
 *
 * A J2Commerce product is spread over at least five tables, and none of them
 * holds what a person would call "the product":
 *
 *   #__content                       name (title), page, category, publish state
 *   #__j2commerce_products           type, visibility, tax profile, manufacturer
 *   #__j2commerce_variants           price, SKU, weight, stock rules — the
 *                                    is_master = 1 row for every product type
 *   #__j2commerce_productquantities  stock on hand, keyed by variant
 *   #__j2commerce_product_prices     tier / group / date prices per variant
 *
 * plus options, option values, images, files, filters and the price index.
 */
trait ProductSupportTrait
{
	/** Product types the core registers a behaviour for (`src/Service/ProductService.php:91-98`). */
	private const CORE_PRODUCT_TYPES = ['simple', 'configurable', 'downloadable', 'variable', 'flexivariable'];

	/** Types whose price and stock live on CHILD variants (`src/Helper/ProductTypeHelper.php:51-60`). */
	private const VARIANT_TYPES = ['variable', 'flexivariable'];

	/** `allow_backorder`: 0 no, 1 yes, 2 yes and tell the shopper. */
	private const BACKORDER_LABELS = [0 => 'no', 1 => 'allow', 2 => 'allow_and_notify'];

	protected function productRow(int $productId): ?array
	{
		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->j2Table('products')))
				->where($this->db->quoteName('j2commerce_product_id') . ' = ' . $productId)
		)->loadAssoc();

		return \is_array($row) ? $row : null;
	}

	protected function requireProduct(int $productId): array
	{
		$row = $this->productRow($productId);

		if ($row === null) {
			throw new \InvalidArgumentException('No J2Commerce product with id ' . $productId . '. Nothing was written.');
		}

		return $row;
	}

	protected function articleFor(array $product): ?array
	{
		if ((string) $product['product_source'] !== 'com_content' || (int) $product['product_source_id'] <= 0) {
			return null;
		}

		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select(['id', 'title', 'alias', 'state', 'catid', 'access', 'language', 'publish_up', 'publish_down'])
				->from($this->db->quoteName('#__content'))
				->where($this->db->quoteName('id') . ' = ' . (int) $product['product_source_id'])
		)->loadAssoc();

		return \is_array($row) ? $row : null;
	}

	/** @return array<int,array<string,mixed>> every variant of a product, master first, with stock. */
	protected function variantsFor(int $productId): array
	{
		$rows = $this->db->setQuery(
			'SELECT v.*, q.' . $this->db->quoteName('quantity') . ', q.' . $this->db->quoteName('on_hold')
			. ', q.' . $this->db->quoteName('sold') . ' AS ' . $this->db->quoteName('quantity_sold')
			. ', q.' . $this->db->quoteName('j2commerce_productquantity_id')
			. ', vo.' . $this->db->quoteName('product_optionvalue_ids')
			. ' FROM ' . $this->db->quoteName($this->j2Table('variants')) . ' v'
			. ' LEFT JOIN ' . $this->db->quoteName($this->j2Table('productquantities')) . ' q ON q.variant_id = v.j2commerce_variant_id'
			. ' LEFT JOIN ' . $this->db->quoteName($this->j2Table('product_variant_optionvalues')) . ' vo ON vo.variant_id = v.j2commerce_variant_id'
			. ' WHERE v.product_id = ' . $productId
			. ' ORDER BY v.is_master DESC, v.j2commerce_variant_id ASC'
		)->loadAssocList() ?: [];

		return array_map(fn (array $r): array => $this->shapeVariant($r), $rows);
	}

	protected function shapeVariant(array $r): array
	{
		$managed  = (int) $r['manage_stock'] === 1;
		$quantity = $r['quantity'] !== null ? (int) $r['quantity'] : null;

		$out = [
			'variant_id'        => (int) $r['j2commerce_variant_id'],
			'is_master'         => (int) $r['is_master'] === 1,
			'is_default'        => (int) $r['isdefault_variant'] === 1,
			'sku'               => (string) $r['sku'],
			'upc'               => (string) $r['upc'],
			'price'             => $this->j2Money($r['price']),
			'shipping'          => (int) $r['shipping'] === 1,
			'weight'            => round((float) $r['weight'], 5),
			'weight_class_id'   => (int) $r['weight_class_id'],
			'length'            => round((float) $r['length'], 5),
			'width'             => round((float) $r['width'], 5),
			'height'            => round((float) $r['height'], 5),
			'length_class_id'   => (int) $r['length_class_id'],
			'stock'             => [
				'manage_stock'      => $managed,
				'quantity'          => $quantity,
				'on_hold'           => $r['on_hold'] !== null ? (int) $r['on_hold'] : null,
				'sold'              => round((float) $r['sold'], 4),
				'availability'      => (int) $r['availability'] === 1 ? 'in_stock' : 'out_of_stock',
				'allow_backorder'   => self::BACKORDER_LABELS[(int) $r['allow_backorder']] ?? (string) $r['allow_backorder'],
				'has_stock_row'     => $r['j2commerce_productquantity_id'] !== null,
			],
			'sale_limits'       => [
				'min_sale_qty'  => (int) $r['use_store_config_min_sale_qty'] === 1 ? 'store_default' : round((float) $r['min_sale_qty'], 5),
				'max_sale_qty'  => (int) $r['use_store_config_max_sale_qty'] === 1 ? 'store_default' : round((float) $r['max_sale_qty'], 5),
				'notify_qty'    => (int) $r['use_store_config_notify_qty'] === 1 ? 'store_default' : round((float) $r['notify_qty'], 5),
			],
		];

		if (!empty($r['product_optionvalue_ids'])) {
			$out['option_value_ids'] = array_map('intval', explode(',', (string) $r['product_optionvalue_ids']));
		}

		if ($managed && $quantity !== null && $quantity < 0) {
			$out['stock']['oversold_by'] = abs($quantity);
		}

		if ($managed && $quantity === null) {
			$out['stock']['warning'] = 'Stock is managed but this variant has no productquantities row. '
				. 'J2Commerce treats that as zero on hand.';
		}

		return $out;
	}

	protected function masterVariantId(int $productId): ?int
	{
		$id = $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('j2commerce_variant_id'))
				->from($this->db->quoteName($this->j2Table('variants')))
				->where($this->db->quoteName('product_id') . ' = ' . $productId)
				->where($this->db->quoteName('is_master') . ' = 1')
				->order($this->db->quoteName('j2commerce_variant_id') . ' ASC')
		)->loadResult();

		return $id === null ? null : (int) $id;
	}

	/**
	 * Rebuild `#__j2commerce_productprice_index` for one product.
	 *
	 * The same computation as `Variable::runIndexes()` / `Flexivariable::runIndexes()`
	 * (`src/Model/Behavior/Variable.php:412-455`): min and max `price` over the
	 * product's NON-master variants, skipping a price of 0 or '', storing 0 when
	 * there is none. Done in SQL here rather than by calling the behaviour because
	 * the behaviour goes through a list model's `getItems()`, which we have no
	 * reason to run. Simple-family products have no index row by design
	 * (`src/Model/ProductModel.php:651-662`), so this is a no-op for them.
	 *
	 * Why it matters: J2Commerce rebuilds this only on a full article save, and
	 * its admin AJAX variant actions never do — so a direct price change would
	 * otherwise leave the price sort in mod_j2commerce_products wrong.
	 */
	protected function rebuildPriceIndex(int $productId, string $productType): ?array
	{
		if (!\in_array($productType, self::VARIANT_TYPES, true) || !$this->j2TableExists('productprice_index')) {
			return null;
		}

		$range = $this->db->setQuery(
			'SELECT MIN(' . $this->db->quoteName('price') . ') AS mn, MAX(' . $this->db->quoteName('price') . ') AS mx'
			. ' FROM ' . $this->db->quoteName($this->j2Table('variants'))
			. ' WHERE product_id = ' . $productId . ' AND is_master = 0 AND price <> 0'
		)->loadAssoc() ?: [];

		$min = (float) ($range['mn'] ?? 0);
		$max = (float) ($range['mx'] ?? 0);

		$exists = (int) $this->db->setQuery(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName($this->j2Table('productprice_index')) . ' WHERE product_id = ' . $productId
		)->loadResult();

		$sql = $exists > 0
			? 'UPDATE ' . $this->db->quoteName($this->j2Table('productprice_index'))
				. ' SET min_price = ' . $this->db->quote((string) $min) . ', max_price = ' . $this->db->quote((string) $max)
				. ' WHERE product_id = ' . $productId
			: 'INSERT INTO ' . $this->db->quoteName($this->j2Table('productprice_index'))
				. ' (product_id, min_price, max_price) VALUES (' . $productId . ', '
				. $this->db->quote((string) $min) . ', ' . $this->db->quote((string) $max) . ')';

		$this->db->setQuery($sql)->execute();

		return ['min_price' => $min, 'max_price' => $max];
	}

	protected function requireTaxProfile(int $id): void
	{
		if ($id === 0) {
			return;
		}

		$n = (int) $this->db->setQuery(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName($this->j2Table('taxprofiles')) . ' WHERE j2commerce_taxprofile_id = ' . $id
		)->loadResult();

		if ($n === 0) {
			throw new \InvalidArgumentException('No tax profile with id ' . $id . '. list_j2commerce_tax_profiles shows them. Nothing was written.');
		}
	}

	protected function requireManufacturer(int $id): void
	{
		if ($id === 0) {
			return;
		}

		$n = (int) $this->db->setQuery(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName($this->j2Table('manufacturers')) . ' WHERE j2commerce_manufacturer_id = ' . $id
		)->loadResult();

		if ($n === 0) {
			throw new \InvalidArgumentException('No manufacturer with id ' . $id . '. list_j2commerce_manufacturers shows them. Nothing was written.');
		}
	}

	/** Other products already using a SKU. J2Commerce does not enforce uniqueness, so we only warn. */
	protected function skuCollisions(string $sku, int $exceptVariantId): array
	{
		if (trim($sku) === '') {
			return [];
		}

		return array_map('intval', $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('product_id'))
				->from($this->db->quoteName($this->j2Table('variants')))
				->where($this->db->quoteName('sku') . ' = ' . $this->db->quote($sku))
				->where($this->db->quoteName('j2commerce_variant_id') . ' <> ' . $exceptVariantId)
		)->loadColumn() ?: []);
	}

	/** @param mixed $value list of ids or CSV */
	protected function idCsv(mixed $value): string
	{
		$ids = \is_array($value) ? $value : explode(',', (string) $value);
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));

		return implode(',', $ids);
	}
}
