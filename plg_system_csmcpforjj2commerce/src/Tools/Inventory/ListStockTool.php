<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Inventory;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

final class ListStockTool extends AbstractTool
{
	use J2commerceBootTrait;

	public function getName(): string { return 'list_j2commerce_stock'; }

	public function getDescription(): string
	{
		return 'List stock levels per J2Commerce variant, with product name, SKU, quantity on hand, quantity '
			. 'on hold, lifetime sold, the low-stock threshold that actually applies (the variant\'s own '
			. 'notify_qty, or the store\'s store_notify_qty when the variant inherits it), availability and '
			. 'backorder rule. filter: low (at or below threshold), out (zero or less), negative (oversold — '
			. 'J2Commerce stores the signed truth and never clamps), no_stock_row (stock managed but no '
			. 'productquantities row, which J2Commerce treats as zero), or all. By default only variants with '
			. 'manage_stock on are listed, because those are the only ones orders ever move; pass '
			. 'include_unmanaged: true for the rest. For variable products both the master and the child '
			. 'variants appear; the children are what sells. Stock lives per variant in '
			. '#__j2commerce_productquantities, never on the product. Read-only; set_j2commerce_stock changes it.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'filter'            => ['type' => 'string', 'enum' => ['all', 'low', 'out', 'negative', 'no_stock_row'], 'description' => 'Default all.'],
				'product_id'        => ['type' => 'integer'],
				'include_unmanaged' => ['type' => 'boolean'],
				'limit'             => ['type' => 'integer', 'description' => 'Default 100, max 200.'],
				'offset'            => ['type' => 'integer'],
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

		$db          = $this->db;
		$params      = $this->j2RawParams();
		$storeNotify = (float) ($params['store_notify_qty'] ?? 0);
		$threshold   = '(CASE WHEN v.use_store_config_notify_qty = 1 THEN ' . $db->quote((string) $storeNotify) . ' ELSE v.notify_qty END)';
		$qty         = 'COALESCE(q.quantity, 0)';

		$where = [];

		if (!(bool) ($arguments['include_unmanaged'] ?? false)) {
			$where[] = 'v.manage_stock = 1';
		}

		if (!empty($arguments['product_id'])) {
			$where[] = 'v.product_id = ' . (int) $arguments['product_id'];
		}

		switch ((string) ($arguments['filter'] ?? 'all')) {
			case 'low':
				$where[] = $qty . ' <= ' . $threshold . ' AND ' . $threshold . ' > 0';
				break;
			case 'out':
				$where[] = $qty . ' <= 0';
				break;
			case 'negative':
				$where[] = 'q.quantity < 0';
				break;
			case 'no_stock_row':
				$where[] = 'q.j2commerce_productquantity_id IS NULL';
				break;
		}

		$from = ' FROM ' . $db->quoteName($this->j2Table('variants')) . ' v'
			. ' JOIN ' . $db->quoteName($this->j2Table('products')) . ' p ON p.j2commerce_product_id = v.product_id'
			. ' LEFT JOIN ' . $db->quoteName('#__content') . ' c ON c.id = p.product_source_id AND p.product_source = ' . $db->quote('com_content')
			. ' LEFT JOIN ' . $db->quoteName($this->j2Table('productquantities')) . ' q ON q.variant_id = v.j2commerce_variant_id'
			. ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));

		$limit  = $this->j2Limit($arguments, 100);
		$offset = $this->j2Offset($arguments);

		$total = (int) $db->setQuery('SELECT COUNT(*)' . $from)->loadResult();

		$rows = $db->setQuery(
			'SELECT v.j2commerce_variant_id, v.product_id, v.is_master, v.sku, v.manage_stock, v.availability, v.allow_backorder,'
			. ' p.product_type, p.enabled, c.title, q.j2commerce_productquantity_id, q.quantity, q.on_hold, q.sold,'
			. ' ' . $threshold . ' AS threshold, v.use_store_config_notify_qty'
			. $from
			. ' ORDER BY ' . $qty . ' ASC, v.j2commerce_variant_id ASC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $r) {
			$quantity  = $r['quantity'] !== null ? (int) $r['quantity'] : null;
			$threshold = (float) $r['threshold'];
			$entry     = [
				'variant_id'      => (int) $r['j2commerce_variant_id'],
				'product_id'      => (int) $r['product_id'],
				'name'            => $r['title'],
				'sku'             => (string) $r['sku'],
				'is_master'       => (int) $r['is_master'] === 1,
				'product_type'    => (string) $r['product_type'],
				'product_enabled' => (int) $r['enabled'] === 1,
				'manage_stock'    => (int) $r['manage_stock'] === 1,
				'quantity'        => $quantity,
				'on_hold'         => $r['on_hold'] !== null ? (int) $r['on_hold'] : null,
				'sold'            => $r['sold'] !== null ? (int) $r['sold'] : null,
				'low_stock_threshold' => $threshold,
				'threshold_source'    => (int) $r['use_store_config_notify_qty'] === 1 ? 'store' : 'variant',
				'availability'    => (int) $r['availability'] === 1 ? 'in_stock' : 'out_of_stock',
				'allow_backorder' => [0 => 'no', 1 => 'allow', 2 => 'allow_and_notify'][(int) $r['allow_backorder']] ?? (string) $r['allow_backorder'],
			];

			if ($quantity === null && (int) $r['manage_stock'] === 1) {
				$entry['status'] = 'no_stock_row';
			} elseif ($quantity !== null && $quantity < 0) {
				$entry['status'] = 'oversold by ' . abs($quantity);
			} elseif (($quantity ?? 0) <= 0) {
				$entry['status'] = 'out';
			} elseif ($threshold > 0 && $quantity <= $threshold) {
				$entry['status'] = 'low';
			} else {
				$entry['status'] = 'ok';
			}

			if (\in_array((string) $r['product_type'], ['variable', 'flexivariable'], true) && (int) $r['is_master'] === 1) {
				$entry['note'] = 'Master variant of a variable product; its stock is not what sells.';
			}

			$out[] = $entry;
		}

		return ToolResult::json([
			'ok'       => true,
			'total'    => $total,
			'limit'    => $limit,
			'offset'   => $offset,
			'showing'  => \count($out),
			'store_notify_qty' => $storeNotify,
			'variants' => $out,
		]);
	}
}
