<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Orders;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

final class ListOrdersTool extends AbstractTool
{
	use J2commerceBootTrait;
	use OrderSupportTrait;

	public function getName(): string { return 'list_j2commerce_orders'; }

	public function getDescription(): string
	{
		return 'List J2Commerce orders, newest first, with order number, integer id, status (and whether that '
			. 'status holds stock and counts as paid), customer email, billing name, total and currency, '
			. 'payment method and dates. Filters: status_id (one or a list), email, search (order number, '
			. 'email or billing name), user_id, created_from / created_to (YYYY-MM-DD, UTC), min_total, '
			. 'payment_method (the payment plugin element, e.g. payment_paypal), and include_new. '
			. 'Orders in status 5 "New" are checkouts the shopper never completed — J2Commerce creates the '
			. 'order row before payment — so they are EXCLUDED by default; pass include_new: true, or '
			. 'status_id: 5, to see them. Each order has two ids: order_pk (integer) and order_number (what '
			. 'the customer sees); every order tool accepts either. The guest order-view token and raw '
			. 'payment-gateway data are never returned. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'status_id'      => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Status ids; see list_j2commerce_order_statuses.'],
				'include_new'    => ['type' => 'boolean', 'description' => 'Include abandoned status-5 "New" checkouts. Default false.'],
				'email'          => ['type' => 'string', 'description' => 'Exact customer email.'],
				'search'         => ['type' => 'string'],
				'user_id'        => ['type' => 'integer'],
				'created_from'   => ['type' => 'string', 'description' => 'YYYY-MM-DD, UTC, inclusive.'],
				'created_to'     => ['type' => 'string', 'description' => 'YYYY-MM-DD, UTC, inclusive.'],
				'min_total'      => ['type' => 'number'],
				'payment_method' => ['type' => 'string'],
				'limit'          => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'         => ['type' => 'integer'],
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

		$db    = $this->db;
		$where = [];

		$statuses = array_values(array_filter(array_map('intval', (array) ($arguments['status_id'] ?? []))));

		if ($statuses !== []) {
			$where[] = 'o.order_state_id IN (' . implode(',', $statuses) . ')';
		} elseif (!(bool) ($arguments['include_new'] ?? false)) {
			$where[] = 'o.order_state_id <> 5';
		}

		if (($email = trim((string) ($arguments['email'] ?? ''))) !== '') {
			$where[] = 'o.user_email = ' . $db->quote($email);
		}

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$like    = $this->j2Like($search);
			$where[] = '(o.order_id LIKE ' . $like . ' OR o.user_email LIKE ' . $like
				. ' OR CONCAT_WS(' . $db->quote(' ') . ', i.billing_first_name, i.billing_last_name) LIKE ' . $like . ')';
		}

		if (!empty($arguments['user_id'])) {
			$where[] = 'o.user_id = ' . (int) $arguments['user_id'];
		}

		foreach (['created_from' => '>=', 'created_to' => '<='] as $key => $op) {
			if (($d = trim((string) ($arguments[$key] ?? ''))) !== '') {
				if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1) {
					throw new \InvalidArgumentException($key . ' must be YYYY-MM-DD.');
				}

				$where[] = 'o.created_on ' . $op . ' ' . $db->quote($d . ($op === '>=' ? ' 00:00:00' : ' 23:59:59'));
			}
		}

		if (\array_key_exists('min_total', $arguments)) {
			$where[] = 'o.order_total >= ' . $db->quote((string) (float) $arguments['min_total']);
		}

		if (($pm = trim((string) ($arguments['payment_method'] ?? ''))) !== '') {
			$where[] = 'o.orderpayment_type = ' . $db->quote($pm);
		}

		$from = ' FROM ' . $db->quoteName($this->j2Table('orders')) . ' o'
			. ' LEFT JOIN ' . $db->quoteName($this->j2Table('orderinfos')) . ' i ON i.j2commerce_orderinfo_id = ('
			. 'SELECT MIN(i2.j2commerce_orderinfo_id) FROM ' . $db->quoteName($this->j2Table('orderinfos')) . ' i2 WHERE i2.order_id = o.order_id)'
			. ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));

		$limit  = $this->j2Limit($arguments);
		$offset = $this->j2Offset($arguments);
		$total  = (int) $db->setQuery('SELECT COUNT(*)' . $from)->loadResult();

		$rows = $db->setQuery(
			'SELECT o.*, i.billing_first_name, i.billing_last_name, i.billing_country_name,'
			. ' (SELECT COUNT(*) FROM ' . $db->quoteName($this->j2Table('orderitems')) . ' oi WHERE oi.order_id = o.order_id) AS item_count'
			. $from . ' ORDER BY o.created_on DESC, o.j2commerce_order_id DESC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $r) {
			$entry = $this->orderSummary($r);
			$entry['billing_name']    = trim((string) $r['billing_first_name'] . ' ' . (string) $r['billing_last_name']);
			$entry['billing_country'] = (string) $r['billing_country_name'];
			$entry['item_count']      = (int) $r['item_count'];
			$out[] = $entry;
		}

		$response = [
			'ok'      => true,
			'total'   => $total,
			'limit'   => $limit,
			'offset'  => $offset,
			'showing' => \count($out),
			'orders'  => $out,
		];

		if ($statuses === [] && !(bool) ($arguments['include_new'] ?? false)) {
			$response['excluded'] = 'Status 5 "New" (checkouts never completed) is excluded. Pass include_new: true to include it.';
		}

		return ToolResult::json($response);
	}
}
