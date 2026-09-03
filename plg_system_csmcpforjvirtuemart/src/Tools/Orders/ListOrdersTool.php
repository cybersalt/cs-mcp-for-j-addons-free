<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Orders;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * List orders, with the bearer credentials redacted.
 *
 * `order_pass` and `order_create_invoice_pass` are not identifiers, they are
 * credentials: `getOrderIdByOrderPass()` (`models/orders.php:83`) lets a guest
 * view an order with nothing else, and `getMyOrderDetails()` uses it at `:216`.
 * They never leave this tool in cleartext.
 *
 * The BT-row join is the other thing worth being careful about. VirtueMart's
 * own listing query joins with an explicit `AND u.address_type = "BT"`
 * (`models/orders.php:521`), so an order whose billing row is missing or has a
 * null `address_type` silently loses its name and email columns. That is not
 * cosmetic — the same missing row makes the admin order screen redirect with
 * COM_VIRTUEMART_ORDER_NOTFOUND and makes `notifyCustomer()` fatal on PHP 8.
 * A LEFT JOIN plus an explicit flag is the only honest way to show it.
 */
final class ListOrdersTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'list_virtuemart_orders'; }

	public function getDescription(): string
	{
		return 'List VirtueMart orders from #__virtuemart_orders, with the billing name and email joined '
			. 'from #__virtuemart_order_userinfos. '
			. 'REDACTION: order_pass, order_create_invoice_pass and o_hash are never returned in '
			. 'cleartext. The first two are bearer credentials — VirtueMart lets a guest view an entire '
			. 'order given nothing but the pass (models/orders.php:83, :216) — so they are as sensitive '
			. 'as a password. The billing name, email and address ARE returned and are personal data; '
			. 'treat the response accordingly. '
			. 'Filters: status (an order_status_code such as P, C, S, X — these are user-editable rows in '
			. '#__virtuemart_orderstates, not a fixed enum), user_id, order_number, search (on order '
			. 'number, billing name or billing email), created_from / created_to, unpaid_only. Supports '
			. 'limit (default 50, max 200) and offset. '
			. 'The billing row join is a LEFT JOIN on purpose. VirtueMart\'s own list query hardcodes AND '
			. 'u.address_type = "BT" (models/orders.php:521), so an order whose billing row is missing or '
			. 'has a null address_type quietly loses its name and email. That is a serious fault, not a '
			. 'cosmetic one: the same missing row makes the admin order screen redirect with '
			. 'COM_VIRTUEMART_ORDER_NOTFOUND (views/orders/view.html.php:56-59) and makes notifyCustomer() '
			. 'dereference a null on PHP 8 (models/orders.php:2512, :2604). Such orders are returned here '
			. 'with billing_row_missing: true. '
			. 'TOTALS: order_total and order_salesPrice are different numbers and neither is derived from '
			. 'the other by any trigger. order_salesPrice is the sum of the line items; order_total adds '
			. 'shipment, payment, their taxes, and discounts (models/orders.php:1031-1049). Both are '
			. 'reported, labelled, and never reconciled here.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'status'       => ['type' => 'string', 'description' => 'order_status_code, e.g. "P", "C", "S". Use list_virtuemart_order_statuses to see this shop\'s codes.'],
				'user_id'      => ['type' => 'integer', 'description' => 'Joomla user id (virtuemart_user_id is the same value).'],
				'order_number' => ['type' => 'string', 'description' => 'Exact order_number match.'],
				'search'       => ['type' => 'string', 'description' => 'Substring match on order_number, billing name or billing email.'],
				'created_from' => ['type' => 'string', 'description' => 'SQL date/datetime lower bound on created_on.'],
				'created_to'   => ['type' => 'string', 'description' => 'SQL date/datetime upper bound on created_on.'],
				'unpaid_only'  => ['type' => 'boolean', 'description' => 'Only orders where paid is less than order_total.'],
				'limit'        => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'       => ['type' => 'integer'],
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

		if (!$this->vmTableExists('orders')) {
			return $this->vmMissingTableError('orders');
		}

		$hasUserinfos = $this->vmTableExists('order_userinfos');
		$userColumns  = $hasUserinfos ? $this->vmColumns('order_userinfos') : [];

		$limit  = $this->vmLimit($arguments);
		$offset = $this->vmOffset($arguments);

		$from = ' FROM ' . $this->db->quoteName($this->vmTable('orders'), 'o');

		if ($hasUserinfos) {
			$from .= ' LEFT JOIN ' . $this->db->quoteName($this->vmTable('order_userinfos'), 'u')
				. ' ON ' . $this->db->quoteName('o.virtuemart_order_id')
				. ' = ' . $this->db->quoteName('u.virtuemart_order_id')
				. ' AND ' . $this->db->quoteName('u.address_type') . ' = ' . $this->db->quote('BT');
		}

		$where = [];

		if (($status = trim((string) ($arguments['status'] ?? ''))) !== '') {
			$where[] = $this->db->quoteName('o.order_status') . ' = ' . $this->db->quote($status);
		}

		if (\array_key_exists('user_id', $arguments)) {
			$where[] = $this->db->quoteName('o.virtuemart_user_id') . ' = ' . (int) $arguments['user_id'];
		}

		if (($number = trim((string) ($arguments['order_number'] ?? ''))) !== '') {
			$where[] = $this->db->quoteName('o.order_number') . ' = ' . $this->db->quote($number);
		}

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$like  = $this->db->quote('%' . $this->db->escape($search, true) . '%', false);
			$parts = [$this->db->quoteName('o.order_number') . ' LIKE ' . $like];

			if ($hasUserinfos) {
				foreach (['first_name', 'last_name', 'email'] as $column) {
					if (\in_array($column, $userColumns, true)) {
						$parts[] = $this->db->quoteName('u.' . $column) . ' LIKE ' . $like;
					}
				}
			}

			$where[] = '(' . implode(' OR ', $parts) . ')';
		}

		if (($fromDate = trim((string) ($arguments['created_from'] ?? ''))) !== '') {
			$where[] = $this->db->quoteName('o.created_on') . ' >= ' . $this->db->quote($fromDate);
		}

		if (($toDate = trim((string) ($arguments['created_to'] ?? ''))) !== '') {
			$where[] = $this->db->quoteName('o.created_on') . ' <= ' . $this->db->quote($toDate);
		}

		if ((bool) ($arguments['unpaid_only'] ?? false)) {
			$where[] = $this->db->quoteName('o.paid') . ' < ' . $this->db->quoteName('o.order_total');
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		$total = (int) $this->db->setQuery('SELECT COUNT(*)' . $from . $whereSql)->loadResult();

		$select = [
			$this->db->quoteName('o.virtuemart_order_id'),
			$this->db->quoteName('o.order_number'),
			$this->db->quoteName('o.virtuemart_user_id'),
			$this->db->quoteName('o.virtuemart_vendor_id'),
			$this->db->quoteName('o.order_status'),
			$this->db->quoteName('o.order_total'),
			$this->db->quoteName('o.order_salesPrice'),
			$this->db->quoteName('o.order_subtotal'),
			$this->db->quoteName('o.order_tax'),
			$this->db->quoteName('o.order_shipment'),
			$this->db->quoteName('o.order_payment'),
			$this->db->quoteName('o.coupon_code'),
			$this->db->quoteName('o.coupon_discount'),
			$this->db->quoteName('o.order_currency'),
			$this->db->quoteName('o.paid'),
			$this->db->quoteName('o.paid_on'),
			$this->db->quoteName('o.invoice_locked'),
			$this->db->quoteName('o.order_language'),
			$this->db->quoteName('o.created_on'),
			$this->db->quoteName('o.modified_on'),
		];

		if ($hasUserinfos) {
			foreach (['first_name', 'last_name', 'email', 'company'] as $column) {
				if (\in_array($column, $userColumns, true)) {
					$select[] = $this->db->quoteName('u.' . $column);
				}
			}

			$select[] = $this->db->quoteName('u.virtuemart_order_userinfo_id');
		}

		$rows = $this->db->setQuery(
			'SELECT ' . implode(', ', $select) . $from . $whereSql
			. ' ORDER BY ' . $this->db->quoteName('o.virtuemart_order_id') . ' DESC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$states      = $this->vmOrderStates();
		$out         = [];
		$missingBt   = 0;
		$unknownCode = [];

		foreach ($rows as $row) {
			$row  = $this->vmRedactRow($row);
			$code = (string) $row['order_status'];

			$entry = [
				'virtuemart_order_id'   => (int) $row['virtuemart_order_id'],
				'order_number'          => (string) $row['order_number'],
				'virtuemart_user_id'    => (int) $row['virtuemart_user_id'],
				'order_status'          => $code,
				'order_status_name'     => $states[$code]['order_status_name'] ?? null,
				'order_stock_handle'    => $states[$code]['order_stock_handle'] ?? null,
				'order_total'           => $row['order_total'],
				'order_salesPrice'      => $row['order_salesPrice'],
				'order_subtotal'        => $row['order_subtotal'],
				'order_tax'             => $row['order_tax'],
				'order_shipment'        => $row['order_shipment'],
				'order_payment'         => $row['order_payment'],
				'coupon_code'           => $row['coupon_code'],
				'coupon_discount'       => $row['coupon_discount'],
				'paid'                  => $row['paid'],
				'paid_on'               => $row['paid_on'],
				'invoice_locked'        => (int) $row['invoice_locked'] === 1,
				'order_currency'        => (int) $row['order_currency'],
				'order_language'        => $row['order_language'],
				'created_on'            => $row['created_on'],
				'modified_on'           => $row['modified_on'],
			];

			if ($hasUserinfos) {
				$entry['billing'] = [
					'first_name' => $row['first_name'] ?? null,
					'last_name'  => $row['last_name'] ?? null,
					'company'    => $row['company'] ?? null,
					'email'      => $row['email'] ?? null,
				];

				if (($row['virtuemart_order_userinfo_id'] ?? null) === null) {
					$missingBt++;
					$entry['billing_row_missing'] = true;
					$entry['billing_row_note'] = 'This order has no #__virtuemart_order_userinfos row with '
						. 'address_type = "BT". That is a serious fault, not a display quirk: the admin '
						. 'order screen redirects with COM_VIRTUEMART_ORDER_NOTFOUND '
						. '(views/orders/view.html.php:56-59), notifyCustomer() dereferences a null at '
						. 'models/orders.php:2512 and :2604 which is fatal on PHP 8, and invoice PDF '
						. 'generation fails the same way. There is no unique constraint on '
						. '(virtuemart_order_id, address_type), so a duplicate BT row is also possible and '
						. 'loadObjectList(\'address_type\') silently keeps only the last one.';
				}
			}

			if ($code !== '' && !isset($states[$code])) {
				$unknownCode[] = $code;
				$entry['unknown_status_note'] = 'order_status "' . $code . '" does not match any row in '
					. '#__virtuemart_orderstates. Stock handling silently declines to adjust anything when '
					. 'it meets an unknown state (models/orders.php:2035-2038) while letting the status '
					. 'change proceed, so orders in this state drift out of step with stock.';
			}

			if ((float) $row['paid'] < (float) $row['order_total']) {
				$entry['outstanding'] = (string) ((float) $row['order_total'] - (float) $row['paid']);
			}

			$out[] = $entry;
		}

		$response = [
			'ok'       => true,
			'total'    => $total,
			'limit'    => $limit,
			'offset'   => $offset,
			'showing'  => \count($out),
			'orders'   => $out,
			'redacted' => $this->vmRedactedColumns(),
			'redaction_note' => 'order_pass and order_create_invoice_pass are bearer credentials that let '
				. 'a guest open the order (models/orders.php:83, :216). They are never returned. Billing '
				. 'names, emails and addresses ARE returned and are personal data.',
			'totals_note' => 'order_total and order_salesPrice are different figures and no trigger keeps '
				. 'them in step. order_salesPrice sums the line items; order_total adds shipment, payment, '
				. 'their taxes and discounts (models/orders.php:1031-1049). Do not compute one from the '
				. 'other.',
		];

		if ($missingBt > 0) {
			$response['orders_missing_billing_row'] = $missingBt;
		}

		if ($unknownCode !== []) {
			$response['unknown_status_codes'] = array_values(array_unique($unknownCode));
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}
}
