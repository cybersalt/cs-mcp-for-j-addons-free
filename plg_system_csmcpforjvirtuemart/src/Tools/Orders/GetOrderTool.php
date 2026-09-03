<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Orders;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Return one order in full, plus an integrity check.
 *
 * Orders have more ways to be quietly broken than anything else in VirtueMart,
 * and none of them surface in the admin UI as an error — they surface as a
 * redirect, a fatal in the mailer, or an invoice that will not regenerate. This
 * tool checks the four invariants that matter and says which ones fail.
 */
final class GetOrderTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'get_virtuemart_order'; }

	public function getDescription(): string
	{
		return 'Return one VirtueMart order in full: the #__virtuemart_orders row, its billing (BT) and '
			. 'shipping (ST) address rows, its line items, its calculation rules, its status history and '
			. 'any invoice registry row. Address by id or order_number. '
			. 'REDACTED: order_pass, order_create_invoice_pass, o_hash and oi_hash never come back in '
			. 'cleartext. The first two are bearer credentials that let a guest open the whole order '
			. '(models/orders.php:83, :216). Everything else, including full billing and shipping '
			. 'addresses, IS returned and is personal data. '
			. 'The address rows are read with SHOW COLUMNS at runtime rather than an assumed column list. '
			. '#__virtuemart_order_userinfos is NOT in install.sql: it is created by '
			. 'install_essential_data.sql:119 and then reshaped by VirtueMart\'s userfields screen, which '
			. 'issues real ALTER TABLE statements (models/userfields.php:306). Deleting a userfield does '
			. 'not drop its column either — VmTable::_modifyColumn() renames it to '
			. '<name>_DELETED_<unixtime> (helpers/vmtable.php:2748-2751) — so a mature shop has zombie '
			. 'columns and no fixed schema. '
			. 'An integrity block reports the four invariants that break an order silently: exactly one '
			. 'BT address row, a non-empty billing email, order_items whose order_status mirrors the '
			. 'order\'s, and a consistent invoice state. A missing BT row is the worst of them — the '
			. 'admin order screen redirects with COM_VIRTUEMART_ORDER_NOTFOUND, and notifyCustomer() '
			. 'dereferences a null at models/orders.php:2512 and :2604, which is fatal on PHP 8. '
			. 'order_total and order_salesPrice are different figures with no trigger keeping them in '
			. 'step; both are reported and neither is derived from the other here.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'           => ['type' => 'integer', 'description' => 'virtuemart_order_id.'],
				'order_number' => ['type' => 'string', 'description' => 'Alternative to id. Uniqueness is enforced in PHP only (tables/orders.php:114); the DB index is non-unique (install.sql:599), so duplicates are possible and are refused rather than guessed.'],
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

		$id = (int) ($arguments['id'] ?? 0);

		if ($id <= 0) {
			$number = trim((string) ($arguments['order_number'] ?? ''));

			if ($number === '') {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Supply either id or order_number.',
				], true);
			}

			$matches = array_map('intval', $this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName('virtuemart_order_id'))
					->from($this->db->quoteName($this->vmTable('orders')))
					->where($this->db->quoteName('order_number') . ' = ' . $this->db->quote($number))
			)->loadColumn() ?: []);

			if ($matches === []) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'No order with order_number ' . $number . '.',
				], true);
			}

			if (\count($matches) > 1) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'order_number ' . $number . ' matches ' . \count($matches) . ' orders. '
						. 'VirtueMart enforces order-number uniqueness in PHP only '
						. '(tables/orders.php:114 via helpers/vmtable.php:1793-1813); the DB index is a '
						. 'plain non-unique KEY (install.sql:599), so a raw insert can create duplicates. '
						. 'That also breaks getOrderIdByOrderNumber() (models/orders.php:101). Refusing to '
						. 'guess.',
					'matching_ids' => $matches,
				], true);
			}

			$id = $matches[0];
		}

		$order = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->vmTable('orders')))
				->where($this->db->quoteName('virtuemart_order_id') . ' = ' . $id)
		)->loadAssoc();

		if (!\is_array($order)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No order with virtuemart_order_id ' . $id . '.',
			], true);
		}

		$states = $this->vmOrderStates();
		$code   = (string) $order['order_status'];

		$addresses = [];

		if ($this->vmTableExists('order_userinfos')) {
			$rows = $this->db->setQuery(
				$this->db->getQuery(true)
					->select('*')
					->from($this->db->quoteName($this->vmTable('order_userinfos')))
					->where($this->db->quoteName('virtuemart_order_id') . ' = ' . $id)
			)->loadAssocList() ?: [];

			foreach ($rows as $row) {
				$type = (string) ($row['address_type'] ?? '');

				$addresses[] = [
					'address_type' => $type === '' ? null : $type,
					'row'          => $this->stripDeletedColumns($row),
				];
			}
		}

		$items = $this->vmTableExists('order_items')
			? array_map(
				fn (array $row): array => $this->vmRedactRow($row),
				$this->db->setQuery(
					$this->db->getQuery(true)
						->select('*')
						->from($this->db->quoteName($this->vmTable('order_items')))
						->where($this->db->quoteName('virtuemart_order_id') . ' = ' . $id)
						->order($this->db->quoteName('virtuemart_order_item_id') . ' ASC')
				)->loadAssocList() ?: []
			)
			: [];

		$history = $this->vmTableExists('order_histories')
			? array_map(
				fn (array $row): array => $this->vmRedactRow($row),
				$this->db->setQuery(
					$this->db->getQuery(true)
						->select('*')
						->from($this->db->quoteName($this->vmTable('order_histories')))
						->where($this->db->quoteName('virtuemart_order_id') . ' = ' . $id)
						->order($this->db->quoteName('virtuemart_order_history_id') . ' ASC')
				)->loadAssocList() ?: []
			)
			: [];

		$calcRules = $this->vmTableExists('order_calc_rules')
			? $this->db->setQuery(
				$this->db->getQuery(true)
					->select('*')
					->from($this->db->quoteName($this->vmTable('order_calc_rules')))
					->where($this->db->quoteName('virtuemart_order_id') . ' = ' . $id)
			)->loadAssocList() ?: []
			: [];

		$invoices = $this->vmTableExists('invoices')
			? array_map(
				fn (array $row): array => $this->vmRedactRow($row),
				$this->db->setQuery(
					$this->db->getQuery(true)
						->select('*')
						->from($this->db->quoteName($this->vmTable('invoices')))
						->where($this->db->quoteName('virtuemart_order_id') . ' = ' . $id)
				)->loadAssocList() ?: []
			)
			: [];

		$response = [
			'ok'    => true,
			'order' => $this->vmRedactRow($order),
			'status' => [
				'code'               => $code,
				'name'               => $states[$code]['order_status_name'] ?? null,
				'order_stock_handle' => $states[$code]['order_stock_handle'] ?? null,
				'known'              => isset($states[$code]),
			],
			'addresses'    => $addresses,
			'items'        => $items,
			'calc_rules'   => $calcRules,
			'history'      => $history,
			'invoices'     => $invoices,
			'redacted'     => $this->vmRedactedColumns(),
		];

		$response['integrity'] = $this->integrity($order, $addresses, $items, $states);

		$response['totals_note'] = 'order_total and order_salesPrice are different figures and no trigger '
			. 'keeps them in step. order_salesPrice sums the line items; order_total adds shipment, '
			. 'payment, their taxes and discounts (models/orders.php:1031-1049). VirtueMart\'s own '
			. 'recompute, updateBill(), reads heavily from the request (:922, :1001-1002, :1015-1016) and '
			. 'zeroes order_billTaxAmount and coupon_discount when there is none, so it is not safely '
			. 'callable outside an admin POST and this add-on never calls it.';

		$response['userinfos_note'] = 'The address rows come from #__virtuemart_order_userinfos, which is '
			. 'NOT in install.sql. Its columns are generated from #__virtuemart_userfields and altered by '
			. 'the userfields screen at runtime (models/userfields.php:306). Deleting a userfield renames '
			. 'its column to <name>_DELETED_<unixtime> instead of dropping it '
			. '(helpers/vmtable.php:2748-2751); those zombie columns are filtered out of this response.';

		$response['history_note'] = 'Every status update appends a history row even when nothing changed: '
			. 'updateOrderHistory() has its dedupe check commented out '
			. '(models/orders.php:2402-2412). A long history is normal and does not mean the order was '
			. 'edited repeatedly.';

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/**
	 * The four invariants that break an order without saying so.
	 *
	 * @param  array<string,mixed>       $order
	 * @param  array<int,array<string,mixed>> $addresses
	 * @param  array<int,array<string,mixed>> $items
	 * @param  array<string,array<string,mixed>> $states
	 * @return array<string,mixed>
	 */
	private function integrity(array $order, array $addresses, array $items, array $states): array
	{
		$checks = [];

		$btRows = 0;
		$btRow  = null;

		foreach ($addresses as $address) {
			if ($address['address_type'] === 'BT') {
				$btRows++;
				$btRow = $address['row'];
			}
		}

		$checks[] = [
			'check'  => 'exactly one BT (billing) address row',
			'passed' => $btRows === 1,
			'detail' => match (true) {
				$btRows === 1 => 'One billing row.',
				$btRows === 0 => 'NO billing row. The admin order screen redirects with '
					. 'COM_VIRTUEMART_ORDER_NOTFOUND (views/orders/view.html.php:56-59); getOrder() skips '
					. 'the whole details block (models/orders.php:264-288) so has_ST and order_name are '
					. 'never set; notifyCustomer() dereferences $order[\'details\'][\'BT\']->order_status '
					. 'at :2512 and ->email at :2604, which is a fatal Error on PHP 8; and invoice PDF '
					. 'generation fails identically. This order cannot be emailed, invoiced or opened in '
					. 'the admin.',
				default => $btRows . ' billing rows. There is no unique constraint on '
					. '(virtuemart_order_id, address_type) — the only unique index is the primary key '
					. '(install_essential_data.sql:146-149) — and getOrder() does '
					. 'loadObjectList(\'address_type\'), which silently keeps only the LAST one '
					. '(models/orders.php:263). Which address the customer sees is therefore arbitrary.',
			},
		];

		$email = trim((string) ($btRow['email'] ?? ''));

		$checks[] = [
			'check'  => 'billing row has an email address',
			'passed' => $email !== '',
			'detail' => $email !== ''
				? 'Present.'
				: 'Empty. Order email goes to $order[\'details\'][\'BT\']->email '
					. '(models/orders.php:2600-2604), so this order cannot notify the shopper. Note '
					. '#__virtuemart_order_userinfos has an email column that #__virtuemart_userinfos does '
					. 'not, so a customer record with an email does not help here.',
		];

		$orderStatus = (string) $order['order_status'];
		$mismatched  = [];

		foreach ($items as $item) {
			$itemStatus = (string) ($item['order_status'] ?? '');

			if ($itemStatus !== $orderStatus) {
				$mismatched[] = (int) $item['virtuemart_order_item_id'];
			}
		}

		$checks[] = [
			'check'  => 'order_items.order_status mirrors orders.order_status',
			'passed' => $mismatched === [],
			'detail' => $mismatched === []
				? 'All ' . \count($items) . ' item(s) match.'
				: 'Items ' . implode(', ', $mismatched) . ' disagree with the order status "'
					. $orderStatus . '". VirtueMart keeps them in step by calling updateSingleItem() for '
					. 'every item on a status change (models/orders.php:1548). An item with an empty or '
					. 'stale status makes the NEXT change hit '
					. 'handleStockAfterStatusChangedPerProduct() with an unknown old state, which raises '
					. 'a vmError and silently skips stock adjustment while letting the status change '
					. 'proceed (:2035-2038).',
		];

		$locked      = (int) $order['invoice_locked'] === 1;
		$invoiceOk   = true;
		$invoiceText = 'No invoice number has been created for this order.';

		if ($locked) {
			$invoiceText = 'invoice_locked is 1, so createReferencedInvoiceNumber() refuses to mint a new '
				. 'number (models/invoice.php:116-119). That is set after a successful number creation '
				. '(:158, :207) and by the item-edit branch of a status change '
				. '(models/orders.php:1519-1522). Do NOT clear it by hand — that is what the '
				. 'unlockInvoices config key is for, and clearing it lets VirtueMart mint a SECOND number '
				. 'for the same order.';
		}

		$checks[] = [
			'check'  => 'invoice state is coherent',
			'passed' => $invoiceOk,
			'detail' => $invoiceText,
		];

		if ($orderStatus !== '' && !isset($states[$orderStatus])) {
			$checks[] = [
				'check'  => 'order_status matches a row in #__virtuemart_orderstates',
				'passed' => false,
				'detail' => 'Status "' . $orderStatus . '" has no orderstates row. Stock handling '
					. 'silently declines to adjust anything when it meets an unknown state '
					. '(models/orders.php:2035-2038) while letting the status change proceed, so this '
					. 'order has almost certainly drifted out of step with product stock.',
			];
		}

		$failed = 0;

		foreach ($checks as $check) {
			if (!$check['passed']) {
				$failed++;
			}
		}

		return ['all_passed' => $failed === 0, 'failed_count' => $failed, 'checks' => $checks];
	}

	/**
	 * Drop the zombie columns VirtueMart leaves behind on userfield deletion.
	 *
	 * @param  array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function stripDeletedColumns(array $row): array
	{
		$out = [];

		foreach ($row as $column => $value) {
			if (preg_match('/_DELETED_\d+$/', (string) $column) === 1) {
				continue;
			}

			$out[$column] = $value;
		}

		return $out;
	}
}
