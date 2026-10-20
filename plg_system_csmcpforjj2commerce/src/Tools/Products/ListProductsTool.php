<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Products;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

final class ListProductsTool extends AbstractTool
{
	use J2commerceBootTrait;
	use ProductSupportTrait;

	public function getName(): string { return 'list_j2commerce_products'; }

	public function getDescription(): string
	{
		return 'List J2Commerce products with the facts scattered across five tables pulled together: the '
			. 'Joomla article that IS the product (its title is the product name, its state decides whether '
			. 'shoppers can reach it), the product row (type, enabled, visibility, tax profile, manufacturer), '
			. 'and the master variant (price, SKU, stock). For variable and flexivariable products the price '
			. 'range of the child variants is given too, because their master variant carries no real price. '
			. 'Filters: search (article title or any variant SKU), product_type, enabled, category_id (the '
			. 'article category), manufacturer_id, and orphaned: true for products whose article no longer '
			. 'exists — J2Commerce only DISABLES a product when its article is deleted, so these accumulate. '
			. 'Use get_j2commerce_product for every variant, option, image, file and tier price. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'search'          => ['type' => 'string', 'description' => 'Matches article title or any variant SKU.'],
				'product_type'    => ['type' => 'string', 'description' => 'simple, configurable, downloadable, variable, flexivariable (or a type an app adds).'],
				'enabled'         => ['type' => 'boolean'],
				'category_id'     => ['type' => 'integer', 'description' => 'Joomla article category id.'],
				'manufacturer_id' => ['type' => 'integer'],
				'orphaned'        => ['type' => 'boolean', 'description' => 'true: only products whose article is missing. false: only products with an article.'],
				'order_by'        => ['type' => 'string', 'enum' => ['id', 'name', 'price', 'modified']],
				'order_dir'       => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
				'limit'           => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'          => ['type' => 'integer'],
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

		$db    = $this->db;
		$where = [];

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$like    = $this->j2Like($search);
			$where[] = '(c.title LIKE ' . $like . ' OR EXISTS (SELECT 1 FROM ' . $db->quoteName($this->j2Table('variants'))
				. ' sv WHERE sv.product_id = p.j2commerce_product_id AND sv.sku LIKE ' . $like . '))';
		}

		if (($type = trim((string) ($arguments['product_type'] ?? ''))) !== '') {
			$where[] = 'p.product_type = ' . $db->quote($type);
		}

		if (\array_key_exists('enabled', $arguments)) {
			$where[] = 'p.enabled = ' . ((bool) $arguments['enabled'] ? 1 : 0);
		}

		if (!empty($arguments['category_id'])) {
			$where[] = 'c.catid = ' . (int) $arguments['category_id'];
		}

		if (!empty($arguments['manufacturer_id'])) {
			$where[] = 'p.manufacturer_id = ' . (int) $arguments['manufacturer_id'];
		}

		if (\array_key_exists('orphaned', $arguments)) {
			$where[] = (bool) $arguments['orphaned'] ? 'c.id IS NULL' : 'c.id IS NOT NULL';
		}

		$from = ' FROM ' . $db->quoteName($this->j2Table('products')) . ' p'
			. ' LEFT JOIN ' . $db->quoteName('#__content') . ' c ON c.id = p.product_source_id AND p.product_source = ' . $db->quote('com_content')
			. ' LEFT JOIN ' . $db->quoteName($this->j2Table('variants')) . ' v ON v.j2commerce_variant_id = (SELECT MIN(mv.j2commerce_variant_id) FROM '
			. $db->quoteName($this->j2Table('variants')) . ' mv WHERE mv.product_id = p.j2commerce_product_id AND mv.is_master = 1)'
			. ' LEFT JOIN ' . $db->quoteName($this->j2Table('productquantities')) . ' q ON q.variant_id = v.j2commerce_variant_id';

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		$orderMap = ['id' => 'p.j2commerce_product_id', 'name' => 'c.title', 'price' => 'v.price', 'modified' => 'p.modified_on'];
		$orderBy  = $orderMap[(string) ($arguments['order_by'] ?? 'id')] ?? 'p.j2commerce_product_id';
		$dir      = strtoupper((string) ($arguments['order_dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

		$limit  = $this->j2Limit($arguments);
		$offset = $this->j2Offset($arguments);

		// One master variant per product (the lowest id), so a damaged product with two
		// is_master rows still lists once, without a GROUP BY.
		$total = (int) $db->setQuery('SELECT COUNT(*)' . $from . $whereSql)->loadResult();

		$rows = $db->setQuery(
			'SELECT p.j2commerce_product_id, p.product_type, p.enabled, p.visibility, p.taxprofile_id, p.manufacturer_id,'
			. ' p.product_source, p.product_source_id, p.modified_on,'
			. ' c.id AS article_id, c.title, c.state AS article_state, c.catid,'
			. ' v.j2commerce_variant_id AS master_variant_id, v.sku, v.price, v.manage_stock, v.availability, v.allow_backorder,'
			. ' q.quantity'
			. $from . $whereSql
			. ' ORDER BY ' . $orderBy . ' ' . $dir,
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$ids    = array_map(static fn (array $r): int => (int) $r['j2commerce_product_id'], $rows);
		$ranges = [];
		$counts = [];

		if ($ids !== []) {
			foreach ($db->setQuery(
				'SELECT product_id, COUNT(*) AS n, MIN(NULLIF(price, 0)) AS mn, MAX(price) AS mx, SUM(CASE WHEN q.quantity IS NULL THEN 0 ELSE q.quantity END) AS stock'
				. ' FROM ' . $db->quoteName($this->j2Table('variants')) . ' cv'
				. ' LEFT JOIN ' . $db->quoteName($this->j2Table('productquantities')) . ' q ON q.variant_id = cv.j2commerce_variant_id'
				. ' WHERE cv.is_master = 0 AND cv.product_id IN (' . implode(',', $ids) . ') GROUP BY product_id'
			)->loadAssocList() ?: [] as $r) {
				$counts[(int) $r['product_id']] = (int) $r['n'];
				$ranges[(int) $r['product_id']] = [
					'min' => $this->j2Money($r['mn']),
					'max' => $this->j2Money($r['mx']),
					'total_child_stock' => (int) $r['stock'],
				];
			}
		}

		$out = [];

		foreach ($rows as $r) {
			$id     = (int) $r['j2commerce_product_id'];
			$type   = (string) $r['product_type'];
			$entry  = [
				'product_id'   => $id,
				'name'         => $r['title'] !== null ? (string) $r['title'] : null,
				'product_type' => $type,
				'enabled'      => (int) $r['enabled'] === 1,
				'visibility'   => (int) $r['visibility'] === 1,
				'article'      => $r['article_id'] !== null
					? ['id' => (int) $r['article_id'], 'state' => (int) $r['article_state'], 'catid' => (int) $r['catid']]
					: null,
				'sku'          => $r['sku'] !== null ? (string) $r['sku'] : null,
				'price'        => $this->j2Money($r['price']),
				'stock'        => [
					'manage_stock' => (int) $r['manage_stock'] === 1,
					'quantity'     => $r['quantity'] !== null ? (int) $r['quantity'] : null,
					'availability' => (int) $r['availability'] === 1 ? 'in_stock' : 'out_of_stock',
				],
				'taxprofile_id'   => (int) $r['taxprofile_id'],
				'manufacturer_id' => (int) $r['manufacturer_id'],
				'modified_on'     => (string) $r['modified_on'],
			];

			if (\in_array($type, self::VARIANT_TYPES, true)) {
				$entry['variant_count'] = $counts[$id] ?? 0;
				$entry['price_range']   = $ranges[$id] ?? null;
				$entry['price_note']    = 'Variable-type product: shoppers buy a child variant. The master '
					. 'variant\'s price and stock above are not what is sold.';
			}

			if ($r['article_id'] === null) {
				$entry['orphaned'] = 'The article this product belonged to (' . $r['product_source'] . ' '
					. (int) $r['product_source_id'] . ') does not exist. The product has no name and no page.';
			} elseif ((int) $r['article_state'] !== 1 && (int) $r['enabled'] === 1) {
				$entry['unreachable'] = 'Enabled, but its article is not published, so shoppers cannot reach it.';
			}

			if ($r['master_variant_id'] === null) {
				$entry['damaged'] = 'No master variant. J2Commerce cannot price or sell this product.';
			}

			$out[] = $entry;
		}

		return ToolResult::json([
			'ok'       => true,
			'total'    => $total,
			'limit'    => $limit,
			'offset'   => $offset,
			'showing'  => \count($out),
			'currency' => $this->j2StoreCurrency(),
			'products' => $out,
		]);
	}
}
