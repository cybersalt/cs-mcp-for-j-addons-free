<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Orders;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

final class GetOrderTool extends AbstractTool
{
	use J2commerceBootTrait;
	use OrderSupportTrait;

	public function getName(): string { return 'get_j2commerce_order'; }

	public function getDescription(): string
	{
		return 'Get one J2Commerce order in full, by order_pk (integer id) or order_number. Returns the totals '
			. 'breakdown (subtotal, tax, shipping, discount, fees, surcharge, credit, refunded), the status with '
			. 'whether it holds stock and counts as paid, whether stock has been committed, billing and shipping '
			. 'addresses, every line item with its options/attributes, SKU, quantity, unit and final prices and '
			. 'tax, shipping method and tracking number, taxes, discounts (coupon and voucher codes used), fees, '
			. 'download grants, the payment ledger (charges and refunds from #__j2commerce_ordertransactions), '
			. 'and the full status history. '
			. 'History rows are marked customer_visible or not: J2Commerce shows the shopper every history row '
			. 'EXCEPT typed admin and system notes — including the automatic stock-change lines and any comment '
			. 'passed with a status change. '
			. 'Never returned: the guest order-view token (which opens the order without a login and is carried '
			. 'in emailed links), the raw payment-gateway transaction details, order_params, the stored payment '
			. 'block of the address row, and the customer IP. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => $this->orderSchemaProps(),
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

		$order  = $this->resolveOrder($arguments);
		$db     = $this->db;
		$number = $db->quote((string) $order['order_id']);
		$pk     = (int) $order['j2commerce_order_id'];

		$fetch = fn (string $table, string $where, string $order = '') => $this->j2TableExists($table)
			? ($db->setQuery('SELECT * FROM ' . $db->quoteName($this->j2Table($table)) . ' WHERE ' . $where . ($order !== '' ? ' ORDER BY ' . $order : ''))->loadAssocList() ?: [])
			: [];

		$response = $this->orderSummary($order);
		$response = ['ok' => true] + $response + [
			'order_type'      => (string) $order['order_type'],
			'stock_committed' => (int) $order['stock_committed'] === 1,
			'totals'          => [
				'subtotal'        => $this->j2Money($order['order_subtotal']),
				'subtotal_ex_tax' => $this->j2Money($order['order_subtotal_ex_tax']),
				'tax'             => $this->j2Money($order['order_tax']),
				'shipping'        => $this->j2Money($order['order_shipping']),
				'shipping_tax'    => $this->j2Money($order['order_shipping_tax']),
				'discount'        => $this->j2Money($order['order_discount']),
				'discount_tax'    => $this->j2Money($order['order_discount_tax']),
				'fees'            => $this->j2Money($order['order_fees']),
				'surcharge'       => $this->j2Money($order['order_surcharge']),
				'credit'          => $this->j2Money($order['order_credit']),
				'refunded'        => $this->j2Money($order['order_refund']),
				'total'           => $this->j2Money($order['order_total']),
				'prices_included_tax' => (int) $order['is_including_tax'] === 1,
				'currency_rate'   => $this->j2Money($order['currency_value']),
			],
			'is_shippable'      => (int) $order['is_shippable'] === 1,
			'transaction'       => [
				'id'     => (string) $order['transaction_id'],
				'status' => (string) $order['transaction_status'],
			],
			'customer_note'     => (string) $order['customer_note'],
			'customer_language' => (string) $order['customer_language'],
			'customer_group'    => (string) $order['customer_group'],
		];

		$infos = $fetch('orderinfos', 'order_id = ' . $number);

		if ($infos !== []) {
			$i = $infos[0];
			$addr = static function (array $i, string $p): array {
				$out = [];

				foreach (['company', 'first_name', 'middle_name', 'last_name', 'address_1', 'address_2', 'city', 'zip',
					'zone_name', 'country_name', 'zone_id', 'country_id', 'phone_1', 'phone_2', 'tax_number'] as $f) {
					$out[$f] = $i[$p . $f] ?? null;
				}

				return $out;
			};

			$response['billing_address']  = $addr($i, 'billing_');
			$response['shipping_address'] = $addr($i, 'shipping_');

			foreach (['all_billing' => 'billing_custom_fields', 'all_shipping' => 'shipping_custom_fields'] as $col => $key) {
				$decoded = json_decode((string) $i[$col], true);

				if (\is_array($decoded) && $decoded !== []) {
					$response[$key] = $decoded;
				}
			}

			if (\count($infos) > 1) {
				$response['address_warning'] = \count($infos) . ' address rows exist for this order; showing the first.';
			}
		}

		$items = $fetch('orderitems', 'order_id = ' . $number, 'j2commerce_orderitem_id');
		$itemIds = array_map(static fn (array $r): int => (int) $r['j2commerce_orderitem_id'], $items);
		$attrs = $itemIds === [] ? [] : $fetch('orderitemattributes', 'orderitem_id IN (' . implode(',', $itemIds) . ')');
		$attrsByItem = [];

		foreach ($attrs as $a) {
			$attrsByItem[(int) $a['orderitem_id']][] = [
				'name'  => (string) $a['orderitemattribute_name'],
				'value' => (string) $a['orderitemattribute_value'],
				'price' => (string) $a['orderitemattribute_prefix'] . (float) $a['orderitemattribute_price'],
				'type'  => (string) $a['orderitemattribute_type'],
			];
		}

		$response['items'] = array_map(fn (array $r): array => [
			'orderitem_id'   => (int) $r['j2commerce_orderitem_id'],
			'product_id'     => (int) $r['product_id'],
			'variant_id'     => (int) $r['variant_id'],
			'product_type'   => (string) $r['product_type'],
			'name'           => (string) $r['orderitem_name'],
			'sku'            => (string) $r['orderitem_sku'],
			'quantity'       => (float) $r['orderitem_quantity'],
			'unit_price'     => $this->j2Money($r['orderitem_price']),
			'option_price'   => $this->j2Money($r['orderitem_option_price']),
			'final_price'    => $this->j2Money($r['orderitem_finalprice']),
			'final_with_tax' => $this->j2Money($r['orderitem_finalprice_with_tax']),
			'tax'            => $this->j2Money($r['orderitem_tax']),
			'discount'       => $this->j2Money($r['orderitem_discount']),
			'weight_total'   => (string) $r['orderitem_weight_total'],
			'attributes'     => $attrsByItem[(int) $r['j2commerce_orderitem_id']] ?? [],
		], $items);

		$response['shipping'] = array_map(fn (array $s): array => [
			'ordershipping_id' => (int) $s['j2commerce_ordershipping_id'],
			'method'           => (string) $s['ordershipping_name'],
			'type'             => (string) $s['ordershipping_type'],
			'code'             => (string) $s['ordershipping_code'],
			'price'            => $this->j2Money($s['ordershipping_price']),
			'tax'              => $this->j2Money($s['ordershipping_tax']),
			'extra'            => $this->j2Money($s['ordershipping_extra']),
			'tracking_number'  => (string) $s['ordershipping_tracking_id'],
			'label_claim_held' => trim((string) $s['ordershipping_label_claim']) !== '',
		], $fetch('ordershippings', 'order_id = ' . $number));

		$response['taxes'] = array_map(fn (array $t): array => [
			'title'   => (string) $t['ordertax_title'],
			'percent' => (float) $t['ordertax_percent'],
			'amount'  => $this->j2Money($t['ordertax_amount']),
		], $fetch('ordertaxes', 'order_id = ' . $number));

		$response['discounts'] = array_map(fn (array $d): array => [
			'type'       => (string) $d['discount_type'],
			'entity_id'  => (int) $d['discount_entity_id'],
			'title'      => (string) $d['discount_title'],
			'code'       => (string) $d['discount_type'] === 'voucher' ? $this->j2MaskCode((string) $d['discount_code']) : (string) $d['discount_code'],
			'value'      => (string) $d['discount_value'],
			'value_type' => (string) $d['discount_value_type'],
			'amount'     => $this->j2Money($d['discount_amount']),
			'tax'        => $this->j2Money($d['discount_tax']),
		], $fetch('orderdiscounts', 'order_id = ' . $number));

		$response['fees'] = array_map(fn (array $f): array => [
			'name'   => (string) $f['name'],
			'type'   => (string) $f['fee_type'],
			'amount' => $this->j2Money($f['amount']),
			'tax'    => $this->j2Money($f['tax']),
		], $fetch('orderfees', 'order_id = ' . $number));

		$downloads = $fetch('orderdownloads', 'order_id = ' . $number);

		if ($downloads !== []) {
			$response['downloads'] = array_map(static fn (array $d): array => [
				'product_id'     => (int) $d['product_id'],
				'limit_count'    => (int) $d['limit_count'],
				'access_granted' => $d['access_granted'],
				'access_expires' => $d['access_expires'],
			], $downloads);
			$response['downloads_note'] = 'J2Commerce grants downloads when an order enters a download status '
				. 'and never revokes them — moving the order to Cancelled leaves access in place.';
		}

		// The ledger joins on the INTEGER primary key, unlike every other child table.
		$response['payment_ledger'] = array_map(fn (array $t): array => [
			'type'           => (string) $t['type'],
			'state'          => (string) $t['state'],
			'plugin'         => (string) $t['plugin'],
			'gateway_txn_id' => (string) $t['gateway_txn_id'],
			'parent_txn_id'  => (string) $t['parent_txn_id'],
			'amount'         => $this->j2Money($t['amount']),
			'currency'       => (string) $t['currency_code'],
			'created_at'     => (string) $t['created_at'],
		], $fetch('ordertransactions', 'order_id = ' . $pk, 'created_at'));

		$statuses = $this->j2StatusMap();

		$response['history'] = array_map(function (array $h) use ($statuses): array {
			$params = json_decode((string) $h['params'], true);
			$type   = \is_array($params) ? (string) ($params['type'] ?? '') : '';

			return [
				'history_id'       => (int) $h['j2commerce_orderhistory_id'],
				'created_on'       => (string) $h['created_on'],
				'created_by'       => (int) $h['created_by'],
				'status'           => $statuses[(int) $h['order_state_id']]['label'] ?? ('#' . (int) $h['order_state_id']),
				'notified'         => (int) $h['notify_customer'] === 1,
				'note_type'        => $type !== '' ? $type : 'history',
				'customer_visible' => $type === '',
				'comment'          => (string) $h['comment'],
			];
		}, $fetch('orderhistories', 'order_id = ' . $number, 'created_on, j2commerce_orderhistory_id'));

		$response['withheld'] = 'token (guest order-view key), transaction_details (raw gateway data), order_params, '
			. 'the stored payment block and the customer IP are never returned.';

		return ToolResult::json($response);
	}
}
