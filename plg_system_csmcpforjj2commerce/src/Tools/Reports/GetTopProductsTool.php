<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Reports;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

final class GetTopProductsTool extends AbstractTool
{
	use J2commerceBootTrait;

	public function getName(): string { return 'get_j2commerce_top_products'; }

	public function getDescription(): string
	{
		return 'Best-selling J2Commerce products for a date range (YYYY-MM-DD, UTC, default the last 30 days), '
			. 'ranked by quantity or by revenue, from the order lines of PAID orders (status types complete, '
			. 'approved, shipped, delivered; scope: placed adds open orders). Per product: units sold, line '
			. 'revenue (final line price including tax), number of orders, and the current product name, '
			. 'enabled state and stock. by_variant: true splits variable products into their variants. '
			. 'Order lines store their own name and SKU at the time of sale, so products deleted since still '
			. 'appear, marked deleted. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'from'       => ['type' => 'string'],
				'to'         => ['type' => 'string'],
				'rank_by'    => ['type' => 'string', 'enum' => ['quantity', 'revenue'], 'description' => 'Default revenue.'],
				'scope'      => ['type' => 'string', 'enum' => ['paid', 'placed']],
				'by_variant' => ['type' => 'boolean'],
				'limit'      => ['type' => 'integer', 'description' => 'Default 20, max 200.'],
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

		$this->j2RequireAccess($actor, 'j2commerce.viewreports');

		$db   = $this->db;
		$to   = trim((string) ($arguments['to'] ?? '')) ?: gmdate('Y-m-d');
		$from = trim((string) ($arguments['from'] ?? '')) ?: gmdate('Y-m-d', strtotime($to . ' -30 days'));

		foreach (['from' => $from, 'to' => $to] as $k => $v) {
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
				throw new \InvalidArgumentException($k . ' must be YYYY-MM-DD.');
			}
		}

		$types = (string) ($arguments['scope'] ?? 'paid') === 'placed' ? [...self::J2_FINALIZED_TYPES, 'open'] : self::J2_FINALIZED_TYPES;
		$ids   = array_keys(array_filter($this->j2StatusMap(), static fn (array $s): bool => \in_array((string) $s['type'], $types, true)));

		if ($ids === []) {
			return ToolResult::json(['ok' => true, 'products' => [], 'note' => 'No order status carries a paid lifecycle type.']);
		}

		$byVariant = (bool) ($arguments['by_variant'] ?? false);
		$key       = $byVariant ? 'oi.variant_id' : 'oi.product_id';
		$rank      = (string) ($arguments['rank_by'] ?? 'revenue') === 'quantity' ? 'units' : 'revenue';
		$limit     = $this->j2Limit($arguments, 20);

		$rows = $db->setQuery(
			'SELECT ' . $key . ' AS k, MAX(oi.product_id) AS product_id, MAX(oi.variant_id) AS variant_id, MAX(oi.orderitem_name) AS name,'
			. ' MAX(oi.orderitem_sku) AS sku, SUM(oi.orderitem_quantity) AS units,'
			. ' SUM(oi.orderitem_finalprice_with_tax) AS revenue, COUNT(DISTINCT oi.order_id) AS orders'
			. ' FROM ' . $db->quoteName($this->j2Table('orderitems')) . ' oi'
			. ' JOIN ' . $db->quoteName($this->j2Table('orders')) . ' o ON o.order_id = oi.order_id'
			. ' WHERE o.order_state_id IN (' . implode(',', $ids) . ')'
			. ' AND o.created_on >= ' . $db->quote($from . ' 00:00:00') . ' AND o.created_on <= ' . $db->quote($to . ' 23:59:59')
			. ' GROUP BY ' . $key . ' ORDER BY ' . $rank . ' DESC',
			0,
			$limit
		)->loadAssocList() ?: [];

		$productIds = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int) $r['product_id'], $rows))));
		$current    = [];

		if ($productIds !== []) {
			$current = $db->setQuery(
				'SELECT p.j2commerce_product_id, p.enabled, c.title FROM ' . $db->quoteName($this->j2Table('products')) . ' p'
				. ' LEFT JOIN ' . $db->quoteName('#__content') . ' c ON c.id = p.product_source_id'
				. ' WHERE p.j2commerce_product_id IN (' . implode(',', $productIds) . ')'
			)->loadAssocList('j2commerce_product_id') ?: [];
		}

		$rankNo = 0;

		$out = array_map(function (array $r) use ($current, &$rankNo, $byVariant): array {
			$pid   = (int) $r['product_id'];
			$entry = [
				'rank'         => ++$rankNo,
				'product_id'   => $pid,
				'name_at_sale' => (string) $r['name'],
				'sku'          => (string) $r['sku'],
				'units'        => (float) $r['units'],
				'revenue'      => round((float) $r['revenue'], 2),
				'orders'       => (int) $r['orders'],
			];

			if ($byVariant) {
				$entry['variant_id'] = (int) $r['variant_id'];
			}

			if (isset($current[$pid])) {
				$entry['current_name'] = $current[$pid]['title'];
				$entry['enabled']      = (int) $current[$pid]['enabled'] === 1;
			} else {
				$entry['deleted'] = true;
			}

			return $entry;
		}, $rows);

		return ToolResult::json([
			'ok'       => true,
			'from'     => $from,
			'to'       => $to,
			'rank_by'  => $rank === 'units' ? 'quantity' : 'revenue',
			'products' => $out,
			'note'     => 'revenue is the line total including tax as stored on each order line, in the order\'s currency, '
				. 'BEFORE cart-level discounts: a cart coupon is recorded on the order, not spread across its lines, so '
				. 'these figures can add up to more than get_j2commerce_sales_summary\'s revenue.',
		]);
	}
}
