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

/**
 * Move an order to another status through `OrderModel::updateOrderStatus()`
 * (`src/Model/OrderModel.php:1112-1228`) — the exact method J2Commerce's own
 * REST endpoint `POST /v1/j2commerce/orders/:id/status` calls
 * (`api/.../Controller/OrderfulfilmentController.php:90-114`).
 *
 * It does, in order: a compare-and-swap on the old status, upload reclaim when
 * leaving New, the stock transition, a history row, a download grant when the
 * new status is a download status, `onJ2CommerceOrderStatusChange` to every
 * j2commerce plugin, and customer + admin email when `$notify`.
 *
 * From 6.6.6 (advisory 621) it also settles coupon and voucher redemptions
 * through `RedemptionHelper::onStatusChange()`: entering cancelled or failed
 * credits the order's voucher value back, leaving them takes it again, and an
 * order that starts counting is re-checked against coupon and voucher limits
 * under a lock. We pass `$refuseShortfall = true`, as J2Commerce's own admin
 * screens do for a merchant's change (`src/Controller/OrderController.php`),
 * so a move that would overrun a limit is refused instead of noted. The REST
 * endpoint passes false. 6.6.5 takes four arguments and ignores the fifth.
 *
 * What we add around it, because the vendor's REST endpoint does not:
 *
 *   - a dry run that predicts each side effect before anything happens;
 *   - the admin language loaded first, so the history row says
 *     "Status changed from Pending to Confirmed" rather than storing the raw
 *     COM_J2COMMERCE_ORDER_HISTORY_STATUS_CHANGED key forever;
 *   - detection of a lost compare-and-swap. `updateOrderStatus()` returns true
 *     when another writer moved the order first (`:1179-1181`), so "true" does
 *     not mean "now in the status you asked for". We re-read and say which.
 */
final class SetOrderStatusTool extends AbstractTool
{
	use J2commerceBootTrait;
	use OrderSupportTrait;

	public function getName(): string { return 'set_j2commerce_order_status'; }

	public function getDescription(): string
	{
		return 'Move a J2Commerce order to another status, through J2Commerce\'s own '
			. 'OrderModel::updateOrderStatus() — the same method its admin and REST API use — so every side effect '
			. 'happens exactly as in the admin: STOCK is debited when the order enters a stock-holding status '
			. 'and restored when it enters one that releases stock (up to J2Commerce 6.6.5 the ids 3 Failed, '
			. '5 New and 6 Cancelled; from 6.6.6 the types new, failed and cancelled), once each, guarded by the '
			. 'order\'s stock_committed flag. From 6.6.6, VOUCHER value the order used is credited back when it '
			. 'enters a cancelled or failed status and taken again when it leaves one, and a move that makes the '
			. 'order count against a coupon or voucher limit it no longer fits is REFUSED, as in the admin. '
			. 'A HISTORY row is written; DOWNLOAD access is granted if the new status is '
			. 'one of the store\'s download statuses (and is never revoked later); the '
			. 'onJ2CommerceOrderStatusChange event reaches every J2Commerce app; and, only if notify is true, '
			. 'the customer AND the store admin are emailed. '
			. 'notify defaults to FALSE. It is all-or-nothing in J2Commerce — there is no way to email only the '
			. 'customer — and the history row records "notified" even when no email template matched. '
			. 'comment is CUSTOMER-VISIBLE: J2Commerce shows the shopper every status-history comment. For a '
			. 'private remark use add_j2commerce_order_note. With no comment, J2Commerce writes "Status changed '
			. 'from X to Y". '
			. 'dry_run: true predicts every side effect (stock movement per line, download grant, emails) '
			. 'without changing anything — use it first on anything but a routine move. '
			. 'There is no state machine: any status can go to any status. Moving to a DISABLED status is '
			. 'refused unless allow_disabled: true. Refunds are not a status: J2Commerce records them in the '
			. 'payment ledger, and a "Refunded" status HOLDS stock and keeps voucher value spent.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => $this->orderSchemaProps() + [
				'status_id'      => ['type' => 'integer', 'description' => 'Target status. Required. list_j2commerce_order_statuses.'],
				'notify'         => ['type' => 'boolean', 'description' => 'Email the customer AND the store admin. Default false.'],
				'comment'        => ['type' => 'string', 'description' => 'CUSTOMER-VISIBLE history comment.'],
				'dry_run'        => ['type' => 'boolean', 'description' => 'Predict side effects only.'],
				'allow_disabled' => ['type' => 'boolean', 'description' => 'Permit moving to a disabled status.'],
			],
			'required' => ['status_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->j2Installed()) {
			return $this->j2NotInstalledError();
		}

		// The vendor endpoint requires core.fulfilment or core.edit, plus j2commerce.editorders.
		$this->j2RequireAccess($actor, 'j2commerce.editorders');

		if (!$actor->authorise('core.fulfilment', 'com_j2commerce') && !$actor->authorise('core.edit', 'com_j2commerce')) {
			throw new \RuntimeException('Changing order status requires core.fulfilment or core.edit on com_j2commerce, '
				. 'as J2Commerce\'s own status endpoint does.');
		}

		$order    = $this->resolveOrder($arguments);
		$pk       = (int) $order['j2commerce_order_id'];
		$number   = (string) $order['order_id'];
		$from     = (int) $order['order_state_id'];
		$to       = $this->requirePositiveInt($arguments, 'status_id');
		$notify   = (bool) ($arguments['notify'] ?? false);
		$comment  = trim((string) ($arguments['comment'] ?? ''));
		$this->j2RequirePlainText('comment', $comment);
		$statuses = $this->j2StatusMap();

		if (!isset($statuses[$to])) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No order status with id ' . $to . '. list_j2commerce_order_statuses shows them. Nothing was changed.',
			], true);
		}

		if (!$statuses[$to]['enabled'] && !(bool) ($arguments['allow_disabled'] ?? false)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Status ' . $to . ' "' . $statuses[$to]['label'] . '" is disabled. J2Commerce would accept it '
					. 'anyway — it does not check — but a disabled status is usually disabled for a reason. Pass '
					. 'allow_disabled: true to proceed. Nothing was changed.',
			], true);
		}

		if ($from === $to) {
			return ToolResult::json([
				'ok'      => true,
				'changed' => false,
				'note'    => 'The order is already in status ' . $to . ' "' . $statuses[$to]['label'] . '". J2Commerce '
					. 'treats this as a no-op: no history row, no event, no email.',
				'order'   => $this->orderSummary($order),
			]);
		}

		$preview = $this->predict($order, $from, $to, $notify, $comment);

		if ((bool) ($arguments['dry_run'] ?? false)) {
			return ToolResult::json([
				'ok'      => true,
				'dry_run' => true,
				'order'   => $this->orderSummary($order),
				'from'    => $this->statusInfo($from),
				'to'      => $this->statusInfo($to),
				'will'    => $preview,
				'note'    => 'Nothing was changed.',
			]);
		}

		$model = $this->j2Model('Order');

		$historyTable = $this->db->quoteName($this->j2Table('orderhistories'));
		$lastHistory  = (int) $this->db->setQuery(
			'SELECT COALESCE(MAX(j2commerce_orderhistory_id), 0) FROM ' . $historyTable . ' WHERE order_id = ' . $this->db->quote($number)
		)->loadResult();

		[$ok, $messages, $stray] = $this->j2Run(
			// true = a merchant's own change, as the admin passes it (see the class comment).
			static fn () => $model->updateOrderStatus($pk, $to, $notify, $comment, true)
		);

		$after = $this->resolveOrder(['order_pk' => $pk]);
		$now   = (int) $after['order_state_id'];

		if (!$ok) {
			return ToolResult::json([
				'ok'                  => false,
				'error'               => 'J2Commerce refused the status change: ' . (method_exists($model, 'getError') ? (string) $model->getError() : 'no reason given'),
				'order_status_now'    => $this->statusInfo($now),
				'j2commerce_messages' => $messages,
			], true);
		}

		if ($now !== $to) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'J2Commerce reported success, but the order is in status ' . $now . ', not ' . $to . '. '
					. 'updateOrderStatus() reports success when another writer (a payment webhook, the unpaid-order '
					. 'sweep, another admin) moved the order first — its compare-and-swap lost the race and it did '
					. 'nothing. Read the order and decide again.',
				'order_status_now' => $this->statusInfo($now),
			], true);
		}

		// Every row this change wrote: the status row, plus — when emails went out — the
		// "Customer notified with the subject: …" and "Administrator(s) notified with
		// subject: …" rows J2Commerce adds itself. Those two are the only evidence that
		// mail was actually sent: notify_customer = 1 on the status row is recorded
		// whether or not any template matched (verified live on 6.6.5, status 8).
		$newRows = $this->db->setQuery(
			'SELECT j2commerce_orderhistory_id, comment, notify_customer, params FROM ' . $historyTable
			. ' WHERE order_id = ' . $this->db->quote($number) . ' AND j2commerce_orderhistory_id > ' . $lastHistory
			. ' ORDER BY j2commerce_orderhistory_id ASC'
		)->loadAssocList() ?: [];

		$emails = array_values(array_filter(array_map(
			static fn (array $r): string => (string) $r['comment'],
			$newRows
		), static fn (string $c): bool => stripos($c, 'notified with') !== false));

		$history = $newRows[0] ?? null;

		$response = [
			'ok'              => true,
			'changed'         => true,
			'order'           => $this->orderSummary($after),
			'from'            => $this->statusInfo($from),
			'to'              => $this->statusInfo($to),
			'stock_committed' => ['before' => (int) $order['stock_committed'] === 1, 'after' => (int) $after['stock_committed'] === 1],
			'did'             => $preview,
			'history_row'     => \is_array($history) ? [
				'history_id'       => (int) $history['j2commerce_orderhistory_id'],
				'comment'          => (string) $history['comment'],
				'notified'         => (int) $history['notify_customer'] === 1,
				'customer_visible' => true,
			] : null,
			'history_rows_written' => array_map(static fn (array $r): array => [
				'history_id'       => (int) $r['j2commerce_orderhistory_id'],
				'comment'          => (string) $r['comment'],
				'customer_visible' => trim((string) $r['params']) === '' || trim((string) $r['params']) === '{}',
			], $newRows),
		];

		if ($notify) {
			$response['emails_sent'] = $emails;

			if ($emails === []) {
				$response['email_warning'] = 'notify was true, but J2Commerce recorded no "notified with subject" row, '
					. 'so no email was sent: no enabled email template matches this status. J2Commerce still marks '
					. 'the status row as notified. Add a template for this status in J2Commerce -> Email Templates.';
			} else {
				$response['email_note'] = 'J2Commerce records these send confirmations as ordinary history rows, so '
					. 'the shopper sees them on their order page — including the administrator one.';
			}
		}

		if (\is_array($history) && preg_match('/^COM_J2COMMERCE_[A-Z_]+/', (string) $history['comment']) === 1) {
			$response['language_warning'] = 'The history row stored a raw language key. The J2Commerce admin '
				. 'language did not load in this request.';
		}

		if (($note = $this->j2IdentityNote($actor)) !== null) {
			$response['identity_note'] = $note;
		}

		if ($messages !== []) {
			$response['j2commerce_messages'] = $messages;
		}

		if ($stray !== '') {
			$response['plugin_output'] = mb_substr($stray, 0, 500);
		}

		return ToolResult::json($response);
	}

	/** Predict updateOrderStatus()'s side effects from the same rules it applies. */
	private function predict(array $order, int $from, int $to, bool $notify, string $comment): array
	{
		$db        = $this->db;
		$number    = $db->quote((string) $order['order_id']);
		$committed = (int) $order['stock_committed'] === 1;
		$holds     = $this->j2StatusHoldsStock($to);

		$stock = ['action' => 'none'];

		if ($holds && !$committed) {
			$stock['action'] = 'debit';
		} elseif (!$holds && $committed) {
			$stock['action'] = 'restore';
		}

		if ($stock['action'] !== 'none') {
			$lines = $db->setQuery(
				'SELECT oi.variant_id, oi.orderitem_name, oi.orderitem_sku, oi.orderitem_quantity, v.manage_stock, q.quantity'
				. ' FROM ' . $db->quoteName($this->j2Table('orderitems')) . ' oi'
				. ' LEFT JOIN ' . $db->quoteName($this->j2Table('variants')) . ' v ON v.j2commerce_variant_id = oi.variant_id'
				. ' LEFT JOIN ' . $db->quoteName($this->j2Table('productquantities')) . ' q ON q.variant_id = oi.variant_id'
				. ' WHERE oi.order_id = ' . $number
			)->loadAssocList() ?: [];

			$sign = $stock['action'] === 'debit' ? -1 : 1;

			$stock['lines'] = array_map(static function (array $l) use ($sign): array {
				$managed = (int) $l['manage_stock'] === 1;
				$qty     = (float) $l['orderitem_quantity'];
				$now     = $l['quantity'] !== null ? (int) $l['quantity'] : 0;

				return [
					'variant_id'  => (int) $l['variant_id'],
					'name'        => (string) $l['orderitem_name'],
					'sku'         => (string) $l['orderitem_sku'],
					'quantity'    => $qty,
					'stock_now'   => $managed ? $now : null,
					'stock_after' => $managed ? $now + (int) ($sign * $qty) : null,
					'skipped'     => $managed ? false : 'manage_stock is off for this variant, so J2Commerce does not move its stock.',
				];
			}, $lines);
		} else {
			$stock['reason'] = $committed
				? 'Stock is already committed for this order and the new status also holds stock.'
				: 'Stock is not committed and the new status does not hold stock.';
		}

		$downloadStatuses = $this->downloadStatuses();
		$hasDownloads     = (int) $db->setQuery(
			'SELECT COUNT(*) FROM ' . $db->quoteName($this->j2Table('orderitems')) . ' WHERE order_id = ' . $number
			. ' AND product_type = ' . $db->quote('downloadable')
		)->loadResult() > 0;

		return [
			'stock'     => $stock,
			'history'   => $comment !== ''
				? 'A CUSTOMER-VISIBLE history row with your comment.'
				: 'A customer-visible history row: "Status changed from <old> to <new>".',
			'downloads' => \in_array($to, $downloadStatuses, true)
				? ($hasDownloads ? 'Download access is GRANTED for the order\'s downloadable items (never revoked later).' : 'Download status, but the order has no downloadable items.')
				: 'No download grant (status ' . $to . ' is not a download status).',
			'vouchers'  => $this->predictRedemptions($order, $from, $to),
			'event'     => 'onJ2CommerceOrderStatusChange fires to every enabled J2Commerce app (integrations may act on it).',
			'email'     => $notify ? $this->predictEmail($to, (string) $order['user_email']) : 'No email.',
		];
	}

	/**
	 * What 6.6.6's RedemptionHelper will do to the order's coupons and vouchers,
	 * from its own rules. findShortfall() is read-only, so the dry run can ask it.
	 */
	private function predictRedemptions(array $order, int $from, int $to): string
	{
		$helper = 'J2Commerce\\Component\\J2commerce\\Administrator\\Helper\\RedemptionHelper';

		if (!class_exists($helper)) {
			return 'This J2Commerce (before 6.6.6) does not settle coupons or vouchers on a status change: voucher '
				. 'value an order used stays spent, even when it is cancelled.';
		}

		$hasVouchers = (int) $this->db->setQuery(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName($this->j2Table('orderdiscounts'))
			. ' WHERE order_id = ' . $this->db->quote((string) $order['order_id'])
			. ' AND discount_type = ' . $this->db->quote('voucher')
		)->loadResult() > 0;

		$parts = [];

		if ($helper::entersCounting($from, $to)) {
			$this->j2LoadLanguage();
			$shortfall = $helper::findShortfall((object) $order);
			$parts[]   = $shortfall === null
				? 'The order starts counting against its coupon and voucher limits again; they still cover it.'
				: 'REFUSED: the order would start counting against a limit it no longer fits (' . $shortfall . ').';
		}

		if ($hasVouchers && !$helper::releases($from) && $helper::releases($to)) {
			$parts[] = 'The voucher value this order used is CREDITED back to the voucher (a disabled voucher only gets '
				. 'a note of what it is owed).';
		} elseif ($hasVouchers && $helper::releases($from) && !$helper::releases($to)) {
			$parts[] = 'The voucher value credited back when the order was cancelled or failed is TAKEN again, up to '
				. 'the voucher\'s balance; any shortfall is noted on the order.';
		}

		return $parts !== [] ? implode(' ', $parts) : 'No coupon or voucher change.';
	}

	/**
	 * Which enabled templates could fire for this status. A first-pass match on
	 * status and receiver only: EmailHelper also narrows by customer group,
	 * payment method, shipping method and language (src/Helper/EmailHelper.php:2067-2169),
	 * so a listed template can still be skipped — but an EMPTY list is certain to
	 * send nothing, which is the case worth warning about.
	 */
	private function predictEmail(int $statusId, string $customerEmail): array
	{
		if (!$this->j2TableExists('emailtemplates')) {
			return ['note' => 'No email template table; nothing can be sent.'];
		}

		$rows = $this->db->setQuery(
			'SELECT j2commerce_emailtemplate_id, receiver_type, orderstatus_id, subject FROM '
			. $this->db->quoteName($this->j2Table('emailtemplates'))
			. ' WHERE enabled = 1 AND email_type = ' . $this->db->quote('transactional')
		)->loadAssocList() ?: [];

		$match = ['customer' => [], 'admin' => []];

		foreach ($rows as $r) {
			$statuses = array_map('trim', explode(',', (string) $r['orderstatus_id']));

			if (!\in_array('*', $statuses, true) && !\in_array((string) $statusId, $statuses, true)) {
				continue;
			}

			$receiver = (string) $r['receiver_type'];

			foreach (['customer', 'admin'] as $who) {
				if ($receiver === $who || $receiver === '*' || $receiver === '') {
					$match[$who][] = (int) $r['j2commerce_emailtemplate_id'];
				}
			}
		}

		$admin = (string) ($this->j2RawParams()['admin_email'] ?? '');

		return [
			'customer' => $match['customer'] !== []
				? 'Template(s) ' . implode(', ', $match['customer']) . ' can email the customer (' . $customerEmail . ').'
				: 'NO enabled template for this status: the customer gets nothing.',
			'admin'    => $match['admin'] !== []
				? 'Template(s) ' . implode(', ', $match['admin']) . ' can email the store admin (' . ($admin !== '' ? $admin : 'admin_email is not set') . ').'
				: 'NO enabled template for this status: the admin gets nothing.',
			'note'     => 'J2Commerce marks the history row "notified" either way. Templates are further narrowed by '
				. 'customer group, payment and shipping method and language.',
		];
	}

	/** @return int[] `download_orderstatuses`, or Confirmed by name when unset (`src/Helper/ConfigHelper.php:1142-1160`). */
	private function downloadStatuses(): array
	{
		$value = $this->j2RawParams()['download_orderstatuses'] ?? '';
		$ids   = \is_array($value) ? array_map('intval', $value) : array_map('intval', explode(',', (string) $value));
		$ids   = array_values(array_filter($ids, static fn (int $i): bool => $i > 0));

		if ($ids !== []) {
			return $ids;
		}

		$confirmed = [];

		foreach ($this->j2StatusMap() as $id => $s) {
			if ($s['name'] === 'J2COMMERCE_CONFIRMED') {
				$confirmed[] = $id;
			}
		}

		return $confirmed;
	}
}
