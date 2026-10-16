<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Products;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

final class GetProductTool extends AbstractTool
{
	use J2commerceBootTrait;
	use ProductSupportTrait;

	public function getName(): string { return 'get_j2commerce_product'; }

	public function getDescription(): string
	{
		return 'Get one J2Commerce product fully assembled. Pass product_id, or article_id to look a product '
			. 'up by the Joomla article it belongs to. Returns: the article (title = product name, state, '
			. 'category, access, language); the product row (type, enabled, visibility, tax profile, '
			. 'manufacturer and its name, up-sells, cross-sells); EVERY variant, master first, each with '
			. 'price, SKU, UPC, weight, dimensions, stock on hand / on hold / sold, availability, backorder '
			. 'rule and sale limits (or "store_default"); tier, customer-group and date prices from '
			. '#__j2commerce_product_prices; options and their values with price and weight modifiers; '
			. 'images; downloadable files; filters; and the stored price index for variable types, flagged '
			. 'when it disagrees with the variants. '
			. 'Read this before any product write: it is the only way to see what update_j2commerce_variant '
			. 'and set_j2commerce_stock will act on. For variable and flexivariable products the master '
			. 'variant is a template, not a sellable item — price and stock that matter are on the child '
			. 'variants. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'product_id' => ['type' => 'integer'],
				'article_id' => ['type' => 'integer', 'description' => 'Alternative: the Joomla article id the product hangs off.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->j2Installed()) {
			return $this->j2NotInstalledError();
		}

		$this->j2RequireAccess($actor, 'j2commerce.viewproducts');

		$db = $this->db;

		if (!empty($arguments['product_id'])) {
			$productId = (int) $arguments['product_id'];
		} elseif (!empty($arguments['article_id'])) {
			$productId = (int) $db->setQuery(
				$db->getQuery(true)
					->select($db->quoteName('j2commerce_product_id'))
					->from($db->quoteName($this->j2Table('products')))
					->where($db->quoteName('product_source') . ' = ' . $db->quote('com_content'))
					->where($db->quoteName('product_source_id') . ' = ' . (int) $arguments['article_id'])
			)->loadResult();

			if ($productId === 0) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Article ' . (int) $arguments['article_id'] . ' has no J2Commerce product. '
						. 'create_j2commerce_product attaches one.',
				], true);
			}
		} else {
			throw new \InvalidArgumentException('Pass product_id or article_id.');
		}

		$product = $this->productRow($productId);

		if ($product === null) {
			return ToolResult::json(['ok' => false, 'error' => 'No J2Commerce product with id ' . $productId . '.'], true);
		}

		$type     = (string) $product['product_type'];
		$article  = $this->articleFor($product);
		$variants = $this->variantsFor($productId);
		$variantIds = array_map(static fn (array $v): int => $v['variant_id'], $variants);

		$response = [
			'ok'           => true,
			'product_id'   => $productId,
			'name'         => $article['title'] ?? null,
			'product_type' => $type,
			'enabled'      => (int) $product['enabled'] === 1,
			'visibility'   => (int) $product['visibility'] === 1,
			'article'      => $article !== null ? [
				'id'       => (int) $article['id'],
				'title'    => (string) $article['title'],
				'alias'    => (string) $article['alias'],
				'state'    => (int) $article['state'],
				'catid'    => (int) $article['catid'],
				'access'   => (int) $article['access'],
				'language' => (string) $article['language'],
			] : null,
			'taxprofile_id'   => (int) $product['taxprofile_id'],
			'manufacturer'    => $this->manufacturer((int) $product['manufacturer_id']),
			'vendor_id'       => (int) $product['vendor_id'],
			'addtocart_text'  => (string) $product['addtocart_text'],
			'up_sells'        => $this->ids((string) $product['up_sells']),
			'cross_sells'     => $this->ids((string) $product['cross_sells']),
			'hits'            => (int) $product['hits'],
			'created_on'      => (string) $product['created_on'],
			'modified_on'     => (string) $product['modified_on'],
			'currency'        => $this->j2StoreCurrency(),
			'variants'        => $variants,
		];

		if (!\in_array($type, self::CORE_PRODUCT_TYPES, true)) {
			$response['type_note'] = 'product_type "' . $type . '" is not a core J2Commerce type. An app supplies '
				. 'its behaviour through onJ2CommerceGetProductBehavior; with that app missing J2Commerce falls '
				. 'back to treating it as simple (src/Service/ProductService.php:320-332).';
		}

		if (\in_array($type, self::VARIANT_TYPES, true)) {
			$response['variant_note'] = 'Shoppers buy a child variant. Edit price, SKU and stock on the child '
				. 'variants, not the master.';
		}

		$masters = array_values(array_filter($variants, static fn (array $v): bool => $v['is_master']));

		if (\count($masters) !== 1) {
			$response['warning'] = 'This product has ' . \count($masters) . ' master variant(s); it should have '
				. 'exactly one. J2Commerce\'s Database Health screen has a fix for the missing case.';
		}

		if ($article === null) {
			$response['orphaned'] = 'The article this product belonged to no longer exists.';
		}

		if ($variantIds !== [] && $this->j2TableExists('product_prices')) {
			$prices = $db->setQuery(
				'SELECT * FROM ' . $db->quoteName($this->j2Table('product_prices'))
				. ' WHERE variant_id IN (' . implode(',', $variantIds) . ') ORDER BY variant_id, quantity_from'
			)->loadAssocList() ?: [];

			$response['advanced_prices'] = array_map(fn (array $p): array => [
				'price_id'          => (int) $p['j2commerce_productprice_id'],
				'variant_id'        => (int) $p['variant_id'],
				'price'             => $this->j2Money($p['price']),
				'quantity_from'     => (float) $p['quantity_from'],
				'quantity_to'       => (float) $p['quantity_to'],
				'date_from'         => $p['date_from'],
				'date_to'           => $p['date_to'],
				'customer_group_id' => (int) $p['customer_group_id'],
			], $prices);
		}

		$response['options'] = $this->options($productId);

		$images = $db->setQuery(
			'SELECT * FROM ' . $db->quoteName($this->j2Table('productimages')) . ' WHERE product_id = ' . $productId
		)->loadAssoc();

		if (\is_array($images)) {
			$response['images'] = [
				'main_image'        => (string) $images['main_image'],
				'main_image_alt'    => (string) $images['main_image_alt'],
				'thumb_image'       => (string) $images['thumb_image'],
				'additional_images' => json_decode((string) $images['additional_images'], true) ?: (string) $images['additional_images'],
			];
		}

		$files = $db->setQuery(
			'SELECT j2commerce_productfile_id, product_file_display_name, product_file_save_name, download_total FROM '
			. $db->quoteName($this->j2Table('productfiles')) . ' WHERE product_id = ' . $productId
		)->loadAssocList() ?: [];

		if ($files !== []) {
			$response['files'] = $files;
		}

		$filters = $db->setQuery(
			'SELECT f.j2commerce_filter_id, f.filter_name, g.group_name FROM ' . $db->quoteName($this->j2Table('product_filters')) . ' pf'
			. ' JOIN ' . $db->quoteName($this->j2Table('filters')) . ' f ON f.j2commerce_filter_id = pf.filter_id'
			. ' LEFT JOIN ' . $db->quoteName($this->j2Table('filtergroups')) . ' g ON g.j2commerce_filtergroup_id = f.group_id'
			. ' WHERE pf.product_id = ' . $productId
		)->loadAssocList() ?: [];

		if ($filters !== []) {
			$response['filters'] = $filters;
		}

		if (\in_array($type, self::VARIANT_TYPES, true) && $this->j2TableExists('productprice_index')) {
			$index = $db->setQuery(
				'SELECT min_price, max_price FROM ' . $db->quoteName($this->j2Table('productprice_index')) . ' WHERE product_id = ' . $productId
			)->loadAssoc();

			$children = array_filter($variants, static fn (array $v): bool => !$v['is_master'] && (float) $v['price'] !== 0.0);
			$prices   = array_map(static fn (array $v): float => (float) $v['price'], $children);
			$expected = $prices === [] ? [0.0, 0.0] : [min($prices), max($prices)];

			$response['price_index'] = \is_array($index)
				? ['min_price' => (float) $index['min_price'], 'max_price' => (float) $index['max_price']]
				: null;

			if (!\is_array($index) || abs((float) $index['min_price'] - $expected[0]) > 0.00001 || abs((float) $index['max_price'] - $expected[1]) > 0.00001) {
				$response['price_index_stale'] = 'The stored price index does not match the child variants '
					. '(expected ' . $expected[0] . '-' . $expected[1] . '). Only the price sort in '
					. 'mod_j2commerce_products reads it. update_j2commerce_variant rebuilds it on any price change.';
			}
		}

		return ToolResult::json($response);
	}

	private function manufacturer(int $id): ?array
	{
		if ($id === 0) {
			return null;
		}

		// A manufacturer has no name column: its name is the COMPANY on its address
		// row (src/Model/ManufacturersModel.php:123-132).
		$row = $this->db->setQuery(
			'SELECT m.j2commerce_manufacturer_id, m.enabled, a.company FROM ' . $this->db->quoteName($this->j2Table('manufacturers')) . ' m'
			. ' LEFT JOIN ' . $this->db->quoteName($this->j2Table('addresses')) . ' a ON a.j2commerce_address_id = m.address_id'
			. ' WHERE m.j2commerce_manufacturer_id = ' . $id
		)->loadAssoc();

		return \is_array($row)
			? ['manufacturer_id' => $id, 'name' => (string) $row['company'], 'enabled' => (int) $row['enabled'] === 1]
			: ['manufacturer_id' => $id, 'missing' => true];
	}

	private function options(int $productId): array
	{
		$rows = $this->db->setQuery(
			'SELECT po.j2commerce_productoption_id, po.option_id, po.required, po.is_variant, po.ordering, o.option_name, o.type'
			. ' FROM ' . $this->db->quoteName($this->j2Table('product_options')) . ' po'
			. ' LEFT JOIN ' . $this->db->quoteName($this->j2Table('options')) . ' o ON o.j2commerce_option_id = po.option_id'
			. ' WHERE po.product_id = ' . $productId . ' ORDER BY po.ordering'
		)->loadAssocList() ?: [];

		if ($rows === []) {
			return [];
		}

		$poIds  = array_map(static fn (array $r): int => (int) $r['j2commerce_productoption_id'], $rows);
		$values = $this->db->setQuery(
			'SELECT pov.*, ov.optionvalue_name FROM ' . $this->db->quoteName($this->j2Table('product_optionvalues')) . ' pov'
			. ' LEFT JOIN ' . $this->db->quoteName($this->j2Table('optionvalues')) . ' ov ON ov.j2commerce_optionvalue_id = pov.optionvalue_id'
			. ' WHERE pov.productoption_id IN (' . implode(',', $poIds) . ') ORDER BY pov.ordering'
		)->loadAssocList() ?: [];

		$byOption = [];

		foreach ($values as $v) {
			$byOption[(int) $v['productoption_id']][] = [
				'product_optionvalue_id' => (int) $v['j2commerce_product_optionvalue_id'],
				'name'                   => (string) $v['optionvalue_name'],
				'price'                  => (string) $v['product_optionvalue_prefix'] . (float) $v['product_optionvalue_price'],
				'weight'                 => (string) $v['product_optionvalue_weight_prefix'] . (float) $v['product_optionvalue_weight'],
				'sku'                    => (string) $v['product_optionvalue_sku'],
				'default'                => (int) $v['product_optionvalue_default'] === 1,
			];
		}

		return array_map(static fn (array $r): array => [
			'product_option_id' => (int) $r['j2commerce_productoption_id'],
			'option_id'         => (int) $r['option_id'],
			'name'              => (string) $r['option_name'],
			'type'              => (string) $r['type'],
			'required'          => (int) $r['required'] === 1,
			'is_variant'        => (int) $r['is_variant'] === 1,
			'values'            => $byOption[(int) $r['j2commerce_productoption_id']] ?? [],
		], $rows);
	}

	private function ids(string $csv): array
	{
		return array_values(array_filter(array_map('intval', explode(',', $csv))));
	}
}
