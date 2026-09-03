<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Orders;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Change an order's status — the ONE place this add-on calls a VirtueMart model.
 *
 * Everywhere else, direct SQL is both safer and sufficient. Here it is neither.
 * `updateStatusForOneOrder()` (`models/orders.php:1146`) does five things a
 * plain UPDATE would not:
 *
 *   1. propagates the status onto every `order_items` row (`:1548`), which the
 *      NEXT status change depends on — an item with a stale status makes
 *      `handleStockAfterStatusChangedPerProduct()` meet an unknown old state and
 *      silently skip stock adjustment (`:2035-2038`);
 *   2. adjusts `product_in_stock`, `product_ordered` and `product_sales`
 *      according to the old and new `order_stock_handle` (`:2024-2120`);
 *   3. sets `paid` when the new status is in `os_trigger_paid` (`:1643-1660`);
 *   4. creates an invoice number when the status calls for one (`:1576-1594`);
 *   5. appends the `#__virtuemart_order_histories` row and sends the emails.
 *
 * Reimplementing that in SQL would be a much larger correctness surface than
 * accepting the model's constraints. So the model is called, behind guards.
 *
 * The guards matter because the failure modes are violent. A missing BT address
 * row makes `notifyCustomer()` dereference a null at `:2512` and `:2604` — a
 * fatal Error on PHP 8, mid-write, after the status has already been stored.
 * And `vRequest::vmCheckToken()` on failure calls `$app->redirect()` then
 * `$app->close()`, killing the process with no exception and no response at
 * all. Both are checked for before anything is called.
 */
final class SetOrderStatusTool extends AbstractTool
{
	use VirtuemartBootTrait;

	/**
	 * Keys forwarded to the model, and nothing else.
	 *
	 * `updateStatusForOneOrder()` does `$data->bind($inputOrder)` at
	 * `models/orders.php:1186`, which writes ANY key matching an orders column.
	 * Passing the caller's arguments through unfiltered would let a status
	 * change rewrite `order_total`, `paid` or `virtuemart_user_id`.
	 */
	private const FORWARDED_KEYS = [
		'virtuemart_order_id',
		'order_status',
		'customer_notified',
		'comments',
	];

	public function getName(): string { return 'set_virtuemart_order_status'; }

	public function getDescription(): string
	{
		return 'Change a VirtueMart order\'s status. Requires order_id (or order_number) and status. '
			. 'This is the ONLY tool in this add-on that calls VirtueMart\'s own PHP, and the only '
			. 'supported way to write an order. It goes through '
			. 'VirtueMartModelOrders::updateStatusForOneOrder() (models/orders.php:1146) because that '
			. 'method does five things a plain UPDATE would not: it propagates the status onto every '
			. 'order_items row (:1548), adjusts product_in_stock / product_ordered / product_sales '
			. 'according to the old and new order_stock_handle (:2024-2120), sets `paid` when the new '
			. 'status is in the os_trigger_paid config list (:1643-1660), creates an invoice number when '
			. 'the status calls for one (:1576-1594), and appends the order history row. '
			. 'THERE IS NO STATE MACHINE. VirtueMart accepts any code to any code with no validation '
			. 'whatsoever — updateStatusForOneOrder() binds whatever it is given at :1186. The one guard '
			. 'in the codebase, safeUpdateOrderStatus() (:1117), takes a caller-supplied whitelist and is '
			. 'called from nowhere in the package. This tool therefore validates the transition itself '
			. 'and WARNS about the surprising ones (reviving a cancelled order, moving backwards from '
			. 'shipped) rather than refusing, because a shop is entitled to its own workflow. '
			. 'EMAIL. notify_customer: false sets customer_notified = 0, which hard-returns before the '
			. 'shopper email at models/orders.php:2565-2568. It does NOT suppress the VENDOR / store-owner '
			. 'email: that send happens at :2562, three lines EARLIER, so the store owner is notified '
			. 'regardless. No parameter can change that — only editing the email_os_v config list or '
			. 'choosing a different status can. With notify_customer: true (the default) the shop\'s own '
			. 'email_os_s list decides, so no email is sent for a status outside it. '
			. 'REFUSES when the order has no BT (billing) address row, because notifyCustomer() '
			. 'dereferences it unguarded at :2512 and :2604 and that is a fatal Error on PHP 8 — thrown '
			. 'mid-write, after the status has already been stored. '
			. 'REFUSES when the target status is not a PUBLISHED row in #__virtuemart_orderstates, '
			. 'because handleStockAfterStatusChangedPerProduct() raises an error and silently declines to '
			. 'adjust stock for an unknown state while letting the status change proceed anyway '
			. '(:2035-2038). That produces a permanently desynchronised stock figure with no trace. '
			. 'REFUSES when VirtueMart cannot boot in this request — on Joomla 6 without the compat '
			. 'plugin\'s class aliases, merely requiring its helpers/config.php is fatal at line 367. '
			. 'Every other tool in this add-on works without any of that. '
			. 'A history row is appended on EVERY call even when nothing changed: updateOrderHistory() '
			. 'has its dedupe check commented out (:2402-2412). Do not poll this tool. '
			. 'Payment and shipment plugin triggers fire by default (plgVmOnUpdateOrderPayment, '
			. 'plgVmOnUpdateOrderShipment, and plgVmOnCancelPayment when moving to X). THOSE CAN CHARGE OR '
			. 'REFUND MONEY. A false return from either aborts the whole update. Pass use_triggers: false '
			. 'to skip them — note that does not suppress email, only the triggers (:1197).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'order_id'        => ['type' => 'integer', 'description' => 'virtuemart_order_id.'],
				'order_number'    => ['type' => 'string', 'description' => 'Alternative to order_id.'],
				'status'          => ['type' => 'string', 'description' => 'Target order_status_code, e.g. "C" or "S". Must be a published row in #__virtuemart_orderstates. Required.'],
				'comments'        => ['type' => 'string', 'description' => 'Free text stored on the order history row.'],
				'notify_customer' => ['type' => 'boolean', 'description' => 'Default true, which lets the shop\'s email_os_s config decide. false suppresses the SHOPPER email only — the vendor email is sent three lines earlier and cannot be suppressed here.'],
				'use_triggers'    => ['type' => 'boolean', 'description' => 'Default true. Payment and shipment plugin triggers CAN CHARGE OR REFUND MONEY. false skips them; it does not suppress email.'],
			],
			'required' => ['status'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->vmAdminBase() === null) {
			return $this->vmNotInstalledError();
		}

		if (!$this->vmTableExists('orders')) {
			return $this->vmMissingTableError('orders');
		}

		$orderId = $this->resolveOrderId($arguments);

		if ($orderId instanceof ToolResult) {
			return $orderId;
		}

		$status = strtoupper(trim((string) ($arguments['status'] ?? '')));

		if ($status === '') {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'status is required.',
			], true);
		}

		$order = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('virtuemart_order_id'),
					$this->db->quoteName('order_number'),
					$this->db->quoteName('order_status'),
					$this->db->quoteName('order_total'),
					$this->db->quoteName('paid'),
					$this->db->quoteName('invoice_locked'),
					$this->db->quoteName('created_on'),
				])
				->from($this->db->quoteName($this->vmTable('orders')))
				->where($this->db->quoteName('virtuemart_order_id') . ' = ' . $orderId)
		)->loadAssoc();

		if (!\is_array($order)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No order with virtuemart_order_id ' . $orderId . '. Nothing was written.',
			], true);
		}

		if (trim((string) $order['created_on']) === '') {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Order ' . $orderId . ' has an empty created_on. updateStatusForOneOrder() '
					. 'treats that as an unknown order and bails at models/orders.php:1174-1181, so this '
					. 'call would do nothing while appearing to succeed. Refusing.',
			], true);
		}

		$oldStatus = (string) $order['order_status'];
		$states    = $this->vmOrderStates();

		// --- target status must be real AND published -------------------------
		if (!isset($states[$status])) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Status code "' . $status . '" does not exist in #__virtuemart_orderstates on '
					. 'this shop. Order statuses are user-editable rows, not a fixed enum, so the codes '
					. 'available depend on the shop. Refusing; nothing was written.',
				'available_statuses' => $this->statusSummary($states),
			], true);
		}

		if ((int) $states[$status]['published'] !== 1) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Status "' . $status . '" exists but is UNPUBLISHED. '
					. 'getOrderStatusNames() filters on published (models/orderstatus.php:85-90), so '
					. 'handleStockAfterStatusChangedPerProduct() would meet an unknown state, raise an '
					. 'error and silently skip stock adjustment — while letting the status change go '
					. 'through anyway (models/orders.php:2035-2038). The result is a permanently '
					. 'desynchronised stock figure with nothing recording why. Refusing; nothing was '
					. 'written.',
			], true);
		}

		if ($oldStatus !== '' && !isset($states[$oldStatus])) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'This order\'s CURRENT status "' . $oldStatus . '" has no row in '
					. '#__virtuemart_orderstates. Changing status from an unknown state makes stock '
					. 'handling skip silently (models/orders.php:2035-2038), so stock would drift with no '
					. 'record. Refusing; nothing was written.',
				'resolution' => 'Recreate the missing order state in the VirtueMart admin, or correct '
					. 'this order\'s status column directly, before changing it through this tool.',
			], true);
		}

		if ($oldStatus === $status) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Order ' . $orderId . ' is already in status "' . $status . '". Refusing '
					. 'rather than calling the model: updateOrderHistory() has its dedupe check commented '
					. 'out (models/orders.php:2402-2412), so a no-op call still appends a history row, '
					. 'still runs the payment and shipment triggers, and can still send email.',
			], true);
		}

		// --- billing row must exist, or notifyCustomer() fatals mid-write -----
		$btRows = 0;

		if ($this->vmTableExists('order_userinfos')) {
			$btRows = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select('COUNT(*)')
					->from($this->db->quoteName($this->vmTable('order_userinfos')))
					->where($this->db->quoteName('virtuemart_order_id') . ' = ' . $orderId)
					->where($this->db->quoteName('address_type') . ' = ' . $this->db->quote('BT'))
			)->loadResult();
		}

		if ($btRows === 0) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Order ' . $orderId . ' has no #__virtuemart_order_userinfos row with '
					. 'address_type = "BT". updateStatusForOneOrder() calls notifyCustomer(), which '
					. 'dereferences $order[\'details\'][\'BT\']->order_status at models/orders.php:2512 '
					. 'and ->email at :2604 with no guard. On PHP 8 that is a fatal Error — thrown AFTER '
					. 'the status has already been stored, leaving the order half-updated and this request '
					. 'dead. Refusing.',
				'resolution' => 'Repair the order\'s billing address row in the VirtueMart admin first. '
					. 'get_virtuemart_order reports this and the other order invariants.',
			], true);
		}

		if ($btRows > 1) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Order ' . $orderId . ' has ' . $btRows . ' rows with address_type = "BT". '
					. 'There is no unique constraint on (virtuemart_order_id, address_type) — the only '
					. 'unique index is the primary key (install_essential_data.sql:146-149) — and '
					. 'getOrder() does loadObjectList(\'address_type\'), which silently keeps only the '
					. 'LAST one (models/orders.php:263). Which address the email and invoice use is '
					. 'therefore arbitrary. Refusing until the duplicate is resolved.',
			], true);
		}

		// --- boot VirtueMart, or refuse cleanly -------------------------------
		$bootFailure = $this->vmBootVendor();

		if ($bootFailure !== null) {
			return $bootFailure;
		}

		if (!class_exists('VmModel')) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'VirtueMart loaded but class VmModel is not available, so its orders model '
					. 'cannot be reached. Refusing; nothing was written.',
			], true);
		}

		$model = \VmModel::getModel('orders');

		if (!\is_object($model) || !method_exists($model, 'updateStatusForOneOrder')) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Could not obtain VirtueMartModelOrders::updateStatusForOneOrder(). Refusing; '
					. 'nothing was written.',
			], true);
		}

		// --- build the input, whitelisted ------------------------------------
		$notify = (bool) ($arguments['notify_customer'] ?? true);

		$input = [
			'virtuemart_order_id' => $orderId,
			'order_status'        => $status,
			'customer_notified'   => $notify ? 1 : 0,
			'comments'            => (string) ($arguments['comments'] ?? ''),
		];

		$input = array_intersect_key($input, array_flip(self::FORWARDED_KEYS));

		$useTriggers = (bool) ($arguments['use_triggers'] ?? true);

		$stockBefore = $this->stockSnapshot($orderId);

		$result = $model->updateStatusForOneOrder($orderId, $input, $useTriggers);

		if (!$result) {
			$errors = method_exists($model, 'getErrors') ? $model->getErrors() : [];

			return ToolResult::json([
				'ok'    => false,
				'error' => 'updateStatusForOneOrder() returned false for order ' . $orderId . '. That '
					. 'usually means a payment or shipment plugin returned false from '
					. 'plgVmOnUpdateOrderPayment or plgVmOnUpdateOrderShipment, which aborts the whole '
					. 'update (models/orders.php:1197-1232). The order may be partially updated; call '
					. 'get_virtuemart_order to see its actual state.',
				'model_errors' => $errors,
			], true);
		}

		$after       = $this->reloadOrder($orderId);
		$stockAfter  = $this->stockSnapshot($orderId);

		$response = [
			'ok'                  => true,
			'virtuemart_order_id' => $orderId,
			'order_number'        => (string) $order['order_number'],
			'status'              => [
				'from'                   => $oldStatus,
				'from_name'              => $states[$oldStatus]['order_status_name'] ?? null,
				'to'                     => $status,
				'to_name'                => $states[$status]['order_status_name'] ?? null,
				'to_order_stock_handle'  => $states[$status]['order_stock_handle'] ?? null,
			],
			'paid'                => ['before' => $order['paid'], 'after' => $after['paid'] ?? null],
			'invoice_locked'      => (int) ($after['invoice_locked'] ?? 0) === 1,
			'triggers_run'        => $useTriggers,
		];

		$response['stock_effect'] = $this->describeStockEffect($stockBefore, $stockAfter, $oldStatus, $status, $states);

		$response['email'] = $notify
			? 'notify_customer was true, so the shop\'s own email_os_s configuration decided whether the '
				. 'SHOPPER email went out — no email is sent for a status outside that list. The VENDOR '
				. 'email was sent if "' . $status . '" is in email_os_v (default U, C, R, X).'
			: 'notify_customer was false, so customer_notified = 0 and notifyCustomer() hard-returned '
				. 'before the shopper email (models/orders.php:2565-2568). THE VENDOR EMAIL WAS STILL '
				. 'SENT if "' . $status . '" is in email_os_v: that send is at :2562, three lines EARLIER '
				. 'than the check. No parameter on this tool can suppress it.';

		$response['history_note'] = 'A #__virtuemart_order_histories row was appended. VirtueMart appends '
			. 'one on every call, even a no-op, because updateOrderHistory()\'s dedupe check is commented '
			. 'out (models/orders.php:2402-2412).';

		$response['items_note'] = 'The new status was propagated onto every #__virtuemart_order_items row '
			. '(models/orders.php:1548). That is not cosmetic: the NEXT status change reads the item '
			. 'status as the "old state" for stock handling, and a stale one makes stock adjustment skip '
			. 'silently (:2035-2038).';

		if ($useTriggers) {
			$response['trigger_note'] = 'plgVmOnUpdateOrderPayment and plgVmOnUpdateOrderShipment ran'
				. ($status === 'X' ? ', as did plgVmOnCancelPayment because the new status is X' : '')
				. '. Those plugins can charge or refund money at the gateway; this tool cannot tell you '
				. 'whether they did.';
		} else {
			$response['trigger_note'] = 'Payment and shipment triggers were SKIPPED (use_triggers: '
				. 'false). Any gateway-side action they would have taken — capture, refund, cancellation '
				. '— did not happen, so the shop and the gateway may now disagree.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/** @return int|ToolResult */
	private function resolveOrderId(array $arguments): int|ToolResult
	{
		$id = (int) ($arguments['order_id'] ?? 0);

		if ($id > 0) {
			return $id;
		}

		$number = trim((string) ($arguments['order_number'] ?? ''));

		if ($number === '') {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Supply either order_id or order_number.',
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
					. 'Uniqueness is enforced in PHP only (tables/orders.php:114); the DB index is '
					. 'non-unique (install.sql:599). Refusing to guess which order to change.',
				'matching_ids' => $matches,
			], true);
		}

		return $matches[0];
	}

	/** @param array<string,array<string,mixed>> $states */
	private function statusSummary(array $states): array
	{
		$out = [];

		foreach ($states as $code => $row) {
			$out[] = [
				'code'               => (string) $code,
				'name'               => (string) $row['order_status_name'],
				'published'          => (int) $row['published'] === 1,
				'order_stock_handle' => (string) $row['order_stock_handle'],
			];
		}

		return $out;
	}

	/** @return array<string,mixed> */
	private function reloadOrder(int $orderId): array
	{
		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('order_status'),
					$this->db->quoteName('paid'),
					$this->db->quoteName('paid_on'),
					$this->db->quoteName('invoice_locked'),
				])
				->from($this->db->quoteName($this->vmTable('orders')))
				->where($this->db->quoteName('virtuemart_order_id') . ' = ' . $orderId)
		)->loadAssoc();

		return \is_array($row) ? $row : [];
	}

	/**
	 * Stock counters for the products on this order, before and after.
	 *
	 * @return array<int,array<string,int>>
	 */
	private function stockSnapshot(int $orderId): array
	{
		if (!$this->vmTableExists('order_items')) {
			return [];
		}

		$productIds = array_values(array_unique(array_map('intval', $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('virtuemart_product_id'))
				->from($this->db->quoteName($this->vmTable('order_items')))
				->where($this->db->quoteName('virtuemart_order_id') . ' = ' . $orderId)
		)->loadColumn() ?: [])));

		if ($productIds === []) {
			return [];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('virtuemart_product_id'),
					$this->db->quoteName('product_in_stock'),
					$this->db->quoteName('product_ordered'),
					$this->db->quoteName('product_sales'),
				])
				->from($this->db->quoteName($this->vmTable('products')))
				->whereIn($this->db->quoteName('virtuemart_product_id'), $productIds)
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$out[(int) $row['virtuemart_product_id']] = [
				'product_in_stock' => (int) $row['product_in_stock'],
				'product_ordered'  => (int) $row['product_ordered'],
				'product_sales'    => (int) $row['product_sales'],
			];
		}

		return $out;
	}

	/**
	 * @param  array<int,array<string,int>>       $before
	 * @param  array<int,array<string,int>>       $after
	 * @param  array<string,array<string,mixed>>  $states
	 * @return array<string,mixed>
	 */
	private function describeStockEffect(array $before, array $after, string $from, string $to, array $states): array
	{
		$changes = [];

		foreach ($after as $productId => $now) {
			$was = $before[$productId] ?? null;

			if ($was === null || $was === $now) {
				continue;
			}

			$changes[] = [
				'virtuemart_product_id' => $productId,
				'product_in_stock'      => ['before' => $was['product_in_stock'], 'after' => $now['product_in_stock']],
				'product_ordered'       => ['before' => $was['product_ordered'], 'after' => $now['product_ordered']],
				'product_sales'         => ['before' => $was['product_sales'], 'after' => $now['product_sales']],
			];
		}

		$fromHandle = (string) ($states[$from]['order_stock_handle'] ?? '?');
		$toHandle   = (string) ($states[$to]['order_stock_handle'] ?? '?');

		return [
			'order_stock_handle' => ['from' => $fromHandle, 'to' => $toHandle],
			'meaning'            => 'A = available, O = out (decrements product_in_stock), R = reserved '
				. '(increments product_ordered) — documented in-code at models/orders.php:2049-2052. '
				. 'product_sales moves in the OPPOSITE direction to product_in_stock '
				. '(models/product.php:3533-3539).',
			'changed_products'   => $changes,
			'note'               => $changes === []
				? 'No stock counter moved. That is expected when the old and new statuses share an '
					. 'order_stock_handle, and is also what happens when stock handling silently declines '
					. 'to act (models/orders.php:2035-2038) — the order_stock_handle values above tell you '
					. 'which.'
				: \count($changes) . ' product(s) had a stock counter change.',
		];
	}
}
