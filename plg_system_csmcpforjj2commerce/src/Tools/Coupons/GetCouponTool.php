<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Coupons;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

final class GetCouponTool extends AbstractTool
{
	use J2commerceBootTrait;
	use CouponSupportTrait;

	public function getName(): string { return 'get_j2commerce_coupon'; }

	public function getDescription(): string
	{
		return 'Get one J2Commerce coupon by coupon_id or code (case-insensitive — J2Commerce stores codes '
			. 'uppercased): its settings, a usable_now verdict with reasons, the restriction lists resolved to '
			. 'names (products, categories, manufacturers, users), and its redemption history — every order '
			. 'that used it, with status, customer and discount amount, marking which redemptions COUNT '
			. 'toward its usage limit (only orders in a placed status do; abandoned and failed checkouts do '
			. 'not). Total discount given on counted orders is summed. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'coupon_id' => ['type' => 'integer'],
				'code'      => ['type' => 'string'],
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

		$this->j2RequireAccess($actor, 'j2commerce.vieworders');

		$db = $this->db;

		if (!empty($arguments['coupon_id'])) {
			$where = 'j2commerce_coupon_id = ' . (int) $arguments['coupon_id'];
		} elseif (trim((string) ($arguments['code'] ?? '')) !== '') {
			$where = 'UPPER(coupon_code) = ' . $db->quote(strtoupper(trim((string) $arguments['code'])));
		} else {
			throw new \InvalidArgumentException('Pass coupon_id or code.');
		}

		$row = $db->setQuery('SELECT * FROM ' . $db->quoteName($this->j2Table('coupons')) . ' WHERE ' . $where)->loadAssoc();

		if (!\is_array($row)) {
			return ToolResult::json(['ok' => false, 'error' => 'No coupon matches.'], true);
		}

		$id     = (int) $row['j2commerce_coupon_id'];
		$usage  = $this->couponUsage([$id])[$id] ?? 0;
		$coupon = $this->shapeCoupon($row, $usage);
		$placed = $this->couponPlacedStatusIds();
		$map    = $this->j2StatusMap();

		$coupon['restrictions_named'] = [
			'products'      => $this->names($coupon['restrictions']['product_ids'],
				'SELECT p.j2commerce_product_id AS id, c.title AS name FROM ' . $db->quoteName($this->j2Table('products')) . ' p LEFT JOIN '
				. $db->quoteName('#__content') . ' c ON c.id = p.product_source_id WHERE p.j2commerce_product_id IN (%s)'),
			'categories'    => $this->names($coupon['restrictions']['category_ids'],
				'SELECT id, title AS name FROM ' . $db->quoteName('#__categories') . ' WHERE id IN (%s)'),
			'manufacturers' => $this->names($coupon['restrictions']['manufacturer_ids'],
				'SELECT m.j2commerce_manufacturer_id AS id, a.company AS name FROM ' . $db->quoteName($this->j2Table('manufacturers')) . ' m LEFT JOIN '
				. $db->quoteName($this->j2Table('addresses')) . ' a ON a.j2commerce_address_id = m.address_id WHERE m.j2commerce_manufacturer_id IN (%s)'),
			'users'         => $this->names($coupon['restrictions']['user_ids'],
				'SELECT id, CONCAT(name, ' . $db->quote(' <') . ', email, ' . $db->quote('>') . ') AS name FROM ' . $db->quoteName('#__users') . ' WHERE id IN (%s)'),
		];

		$redemptions = $db->setQuery(
			'SELECT od.order_id, od.discount_amount, od.discount_customer_email, od.user_id, o.j2commerce_order_id, o.order_state_id, o.created_on'
			. ' FROM ' . $db->quoteName($this->j2Table('orderdiscounts')) . ' od'
			. ' LEFT JOIN ' . $db->quoteName($this->j2Table('orders')) . ' o ON o.order_id = od.order_id'
			. ' WHERE od.discount_type = ' . $db->quote('coupon') . ' AND od.discount_entity_id = ' . $id
			. ' ORDER BY o.created_on DESC'
		)->loadAssocList() ?: [];

		$given = 0.0;

		$coupon['redemptions'] = array_map(function (array $r) use ($placed, $map, &$given): array {
			$sid     = (int) $r['order_state_id'];
			$counted = $placed === [] || \in_array($sid, $placed, true);

			if ($counted) {
				$given += (float) $r['discount_amount'];
			}

			return [
				'order_pk'       => $r['j2commerce_order_id'] !== null ? (int) $r['j2commerce_order_id'] : null,
				'order_number'   => (string) $r['order_id'],
				'status'         => $map[$sid]['label'] ?? ('#' . $sid),
				'customer_email' => (string) $r['discount_customer_email'],
				'user_id'        => (int) $r['user_id'],
				'discount'       => $this->j2Money($r['discount_amount']),
				'created_on'     => $r['created_on'],
				'counts_toward_limit' => $counted,
			];
		}, $redemptions);

		$coupon['total_discount_given'] = round($given, 5);

		return ToolResult::json(['ok' => true] + $coupon);
	}

	private function names(array $ids, string $sqlTemplate): array
	{
		if ($ids === []) {
			return [];
		}

		$rows  = $this->db->setQuery(\sprintf($sqlTemplate, implode(',', array_map('intval', $ids))))->loadAssocList('id') ?: [];
		$out   = [];

		foreach ($ids as $id) {
			$out[] = ['id' => $id, 'name' => isset($rows[$id]) ? (string) $rows[$id]['name'] : null, 'exists' => isset($rows[$id])];
		}

		return $out;
	}
}
