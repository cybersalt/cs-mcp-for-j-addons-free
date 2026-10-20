<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Customers;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

/**
 * J2Commerce has no customers table. `CustomerTable` maps onto
 * `#__j2commerce_addresses` (`src/Table/CustomerTable.php:39`); a customer is a
 * set of address rows sharing an email, optionally tied to a Joomla user, and
 * guests are `user_id = 0`. The vendor's own list (`src/Model/CustomersModel.php:150-205`)
 * starts from addresses, so a buyer who never left a saved address is not a
 * "customer" there. This list starts from BOTH addresses and orders.
 */
final class ListCustomersTool extends AbstractTool
{
	use J2commerceBootTrait;

	public function getName(): string { return 'list_j2commerce_customers'; }

	public function getDescription(): string
	{
		return 'List J2Commerce customers. J2Commerce has NO customers table: a customer is the saved address '
			. 'rows sharing an email (#__j2commerce_addresses), optionally linked to a Joomla user, plus the '
			. 'orders placed under that email; guests have user_id 0. J2Commerce\'s own customer screen starts '
			. 'from saved addresses only, so a buyer who never saved an address does not appear there — this '
			. 'list includes anyone with an address OR a placed order (abandoned status-5 "New" checkouts are '
			. 'ignored). Per customer: email, name and country from their first address, Joomla user id '
			. '(0 = guest), number of orders, number of paid orders (status type complete, approved, shipped '
			. 'or delivered), paid lifetime value, first and last order dates. Mixed-currency stores: the '
			. 'value is a plain sum of order totals in whatever currency each order used. Filters: search '
			. '(email or name), guests_only, registered_only, min_orders. Sort by last_order, lifetime_value, '
			. 'orders or email. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'search'          => ['type' => 'string'],
				'guests_only'     => ['type' => 'boolean'],
				'registered_only' => ['type' => 'boolean'],
				'min_orders'      => ['type' => 'integer'],
				'order_by'        => ['type' => 'string', 'enum' => ['last_order', 'lifetime_value', 'orders', 'email']],
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

		// The vendor's REST customers route requires exportorders (api/.../CustomersController.php:33).
		$this->j2RequireAccess($actor, 'j2commerce.exportorders');

		$db     = $this->db;
		$orders = $db->quoteName($this->j2Table('orders'));
		$addr   = $db->quoteName($this->j2Table('addresses'));

		$paidIds = array_keys(array_filter($this->j2StatusMap(), static fn (array $s): bool => \in_array((string) $s['type'], self::J2_FINALIZED_TYPES, true)));
		$paidIn  = $paidIds === [] ? '0' : implode(',', $paidIds);

		$emails = 'SELECT LOWER(TRIM(email)) AS em FROM ' . $addr . ' WHERE email <> ' . $db->quote('')
			. ' UNION SELECT LOWER(TRIM(user_email)) FROM ' . $orders . ' WHERE user_email <> ' . $db->quote('') . ' AND order_state_id <> 5';

		$stats = 'SELECT LOWER(TRIM(user_email)) AS em, COUNT(*) AS orders,'
			. ' SUM(CASE WHEN order_state_id IN (' . $paidIn . ') THEN 1 ELSE 0 END) AS paid_orders,'
			. ' SUM(CASE WHEN order_state_id IN (' . $paidIn . ') THEN order_total ELSE 0 END) AS lifetime_value,'
			. ' MIN(created_on) AS first_order, MAX(created_on) AS last_order, MAX(user_id) AS order_user'
			. ' FROM ' . $orders . ' WHERE order_state_id <> 5 GROUP BY LOWER(TRIM(user_email))';

		$firstAddr = 'SELECT LOWER(TRIM(email)) AS em, MIN(j2commerce_address_id) AS aid, MAX(user_id) AS addr_user FROM ' . $addr
			. ' WHERE email <> ' . $db->quote('') . ' GROUP BY LOWER(TRIM(email))';

		$from = ' FROM (' . $emails . ') e'
			. ' LEFT JOIN (' . $stats . ') s ON s.em = e.em'
			. ' LEFT JOIN (' . $firstAddr . ') fa ON fa.em = e.em'
			. ' LEFT JOIN ' . $addr . ' a ON a.j2commerce_address_id = fa.aid'
			. ' LEFT JOIN ' . $db->quoteName($this->j2Table('countries')) . ' c ON c.j2commerce_country_id = a.country_id';

		$where = [];

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$like    = $this->j2Like($search);
			$where[] = '(e.em LIKE ' . $like . ' OR CONCAT_WS(' . $db->quote(' ') . ', a.first_name, a.last_name) LIKE ' . $like . ')';
		}

		$userExpr = 'GREATEST(COALESCE(s.order_user, 0), COALESCE(fa.addr_user, 0))';

		if ((bool) ($arguments['guests_only'] ?? false)) {
			$where[] = $userExpr . ' = 0';
		}

		if ((bool) ($arguments['registered_only'] ?? false)) {
			$where[] = $userExpr . ' > 0';
		}

		if (!empty($arguments['min_orders'])) {
			$where[] = 'COALESCE(s.orders, 0) >= ' . (int) $arguments['min_orders'];
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
		$orderMap = [
			'last_order'     => 's.last_order DESC',
			'lifetime_value' => 's.lifetime_value DESC',
			'orders'         => 's.orders DESC',
			'email'          => 'e.em ASC',
		];
		$orderSql = ' ORDER BY ' . ($orderMap[(string) ($arguments['order_by'] ?? 'last_order')] ?? $orderMap['last_order']) . ', e.em ASC';

		$limit  = $this->j2Limit($arguments);
		$offset = $this->j2Offset($arguments);
		$total  = (int) $db->setQuery('SELECT COUNT(*)' . $from . $whereSql)->loadResult();

		$rows = $db->setQuery(
			'SELECT e.em, s.orders, s.paid_orders, s.lifetime_value, s.first_order, s.last_order, ' . $userExpr . ' AS user_id,'
			. ' a.first_name, a.last_name, a.company, a.city, c.country_name'
			. $from . $whereSql . $orderSql,
			$offset,
			$limit
		)->loadAssocList() ?: [];

		// Guest checkout saves no address row (verified live on 6.6.5), so for those
		// customers the name and location come from their latest order's billing details.
		$needFallback = array_values(array_map(
			static fn (array $r): string => (string) $r['em'],
			array_filter($rows, static fn (array $r): bool => trim((string) $r['first_name'] . (string) $r['last_name']) === '')
		));

		if ($needFallback !== []) {
			$billing = $db->setQuery(
				'SELECT LOWER(TRIM(o.user_email)) AS em, i.billing_first_name, i.billing_last_name, i.billing_company, i.billing_city, i.billing_country_name'
				. ' FROM ' . $orders . ' o JOIN ' . $db->quoteName($this->j2Table('orderinfos')) . ' i ON i.order_id = o.order_id'
				. ' WHERE LOWER(TRIM(o.user_email)) IN (' . implode(',', array_map([$db, 'quote'], $needFallback)) . ')'
				. ' ORDER BY o.created_on ASC'
			)->loadAssocList('em') ?: [];

			foreach ($rows as &$r) {
				$b = $billing[(string) $r['em']] ?? null;

				if ($b !== null && trim((string) $r['first_name'] . (string) $r['last_name']) === '') {
					$r['first_name']   = $b['billing_first_name'];
					$r['last_name']    = $b['billing_last_name'];
					$r['company']      = $b['billing_company'];
					$r['city']         = $b['billing_city'];
					$r['country_name'] = $b['billing_country_name'];
					$r['from_order']   = true;
				}
			}

			unset($r);
		}

		$out = array_map(fn (array $r): array => [
			'email'          => (string) $r['em'],
			'name'           => trim((string) $r['first_name'] . ' ' . (string) $r['last_name']) ?: null,
			'company'        => $r['company'] !== null && $r['company'] !== '' ? (string) $r['company'] : null,
			'city'           => $r['city'],
			'country'        => $r['country_name'],
			'user_id'        => (int) $r['user_id'],
			'guest'          => (int) $r['user_id'] === 0,
			'orders'         => (int) $r['orders'],
			'paid_orders'    => (int) $r['paid_orders'],
			'lifetime_value' => $this->j2Money($r['lifetime_value'] ?? 0),
			'first_order_on' => $r['first_order'],
			'last_order_on'  => $r['last_order'],
			'saved_address'  => empty($r['from_order']) && trim((string) $r['first_name'] . (string) $r['last_name']) !== '',
		], $rows);

		return ToolResult::json([
			'ok'        => true,
			'total'     => $total,
			'limit'     => $limit,
			'offset'    => $offset,
			'showing'   => \count($out),
			'customers' => $out,
			'note'      => 'Emails are compared case-insensitively. lifetime_value sums PAID orders only (status types '
				. implode(', ', self::J2_FINALIZED_TYPES) . '). saved_address false means the customer has no saved '
				. 'address row — normal for guest checkout, which saves none — and J2Commerce\'s own Customers screen, '
				. 'which starts from saved addresses, does not list them.',
		]);
	}
}
