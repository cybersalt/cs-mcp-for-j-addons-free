<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Orders;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * The audit trail for an order, or for the shop as a whole.
 *
 * Worth reading with one caveat in mind: VirtueMart appends a history row on
 * every status update whether or not the status changed, because
 * `updateOrderHistory()`'s dedupe check is commented out
 * (`models/orders.php:2402-2412`). Consecutive identical rows are the normal
 * output of a polling integration, not evidence of repeated edits.
 */
final class ListOrderHistoryTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'list_virtuemart_order_history'; }

	public function getDescription(): string
	{
		return 'List rows from #__virtuemart_order_histories — the append-only audit trail of order '
			. 'status changes. Filter by order_id, status code, or a created_on date range. '
			. 'READ THIS BEFORE INTERPRETING THE OUTPUT: VirtueMart appends a history row on EVERY call '
			. 'to updateStatusForOneOrder(), even when nothing changed, because updateOrderHistory()\'s '
			. 'dedupe check is commented out (models/orders.php:2402-2412). Runs of consecutive identical '
			. 'rows are therefore the ordinary output of a polling integration or a repeated save, not '
			. 'evidence that anyone edited the order repeatedly. '
			. 'customer_notified is a record of whether the SHOPPER email was attempted. It says nothing '
			. 'about the vendor / store-owner email, which is sent earlier in the same method '
			. '(models/orders.php:2562) and is not recorded anywhere. A row with customer_notified = 0 '
			. 'very often still resulted in the store owner being emailed. '
			. 'The status column here is order_status_code, normalised from whatever key the caller used '
			. '(:2398-2400). The pseudo-code "-" is written on order deletion (tables/orders.php:200) and '
			. 'is not a real order state. The pseudo-code "N" ("new, coming from cart") exists only in '
			. 'PHP and is never stored (models/orders.php:2034). '
			. 'o_hash is redacted; it is the invoice-staleness detector and leaks nothing useful.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'order_id'     => ['type' => 'integer', 'description' => 'virtuemart_order_id. Omit to list across the shop.'],
				'status'       => ['type' => 'string', 'description' => 'Filter on order_status_code.'],
				'created_from' => ['type' => 'string', 'description' => 'SQL date/datetime lower bound.'],
				'created_to'   => ['type' => 'string', 'description' => 'SQL date/datetime upper bound.'],
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

		if (!$this->vmTableExists('order_histories')) {
			return $this->vmMissingTableError('order_histories');
		}

		$limit  = $this->vmLimit($arguments);
		$offset = $this->vmOffset($arguments);
		$table  = $this->db->quoteName($this->vmTable('order_histories'));

		$where = [];

		if (\array_key_exists('order_id', $arguments)) {
			$where[] = $this->db->quoteName('virtuemart_order_id') . ' = ' . (int) $arguments['order_id'];
		}

		if (($status = trim((string) ($arguments['status'] ?? ''))) !== '') {
			$where[] = $this->db->quoteName('order_status_code') . ' = ' . $this->db->quote($status);
		}

		if (($fromDate = trim((string) ($arguments['created_from'] ?? ''))) !== '') {
			$where[] = $this->db->quoteName('created_on') . ' >= ' . $this->db->quote($fromDate);
		}

		if (($toDate = trim((string) ($arguments['created_to'] ?? ''))) !== '') {
			$where[] = $this->db->quoteName('created_on') . ' <= ' . $this->db->quote($toDate);
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		$total = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $table . $whereSql)->loadResult();

		$rows = $this->db->setQuery(
			'SELECT * FROM ' . $table . $whereSql
			. ' ORDER BY ' . $this->db->quoteName('virtuemart_order_history_id') . ' DESC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$states = $this->vmOrderStates();
		$out    = [];
		$prev   = null;
		$dupes  = 0;

		foreach ($rows as $row) {
			$row  = $this->vmRedactRow($row);
			$code = (string) $row['order_status_code'];

			$entry = [
				'virtuemart_order_history_id' => (int) $row['virtuemart_order_history_id'],
				'virtuemart_order_id'         => (int) $row['virtuemart_order_id'],
				'order_status_code'           => $code,
				'order_status_name'           => $states[$code]['order_status_name'] ?? null,
				'customer_notified'           => (int) $row['customer_notified'] === 1,
				'comments'                    => (string) $row['comments'],
				'paid'                        => $row['paid'],
				'created_on'                  => $row['created_on'],
				'created_by'                  => (int) $row['created_by'],
			];

			if ($code === '-') {
				$entry['note'] = 'The "-" pseudo-code is written when an order is DELETED '
					. '(tables/orders.php:200). It is not a real order state.';
			} elseif ($code !== '' && !isset($states[$code])) {
				$entry['note'] = 'No matching row in #__virtuemart_orderstates. Order states are '
					. 'user-editable data, so this one was probably renamed or deleted after the fact.';
			}

			if ((int) $row['customer_notified'] === 0) {
				$entry['notified_note'] = 'customer_notified is 0, so the SHOPPER email was suppressed. '
					. 'This says nothing about the vendor / store-owner email, which is sent three lines '
					. 'earlier in notifyCustomer() (models/orders.php:2562) and is recorded nowhere.';
			}

			if ($prev !== null
				&& (int) $prev['virtuemart_order_id'] === (int) $row['virtuemart_order_id']
				&& (string) $prev['order_status_code'] === $code) {
				$dupes++;
				$entry['duplicate_of_previous'] = true;
			}

			$prev  = $row;
			$out[] = $entry;
		}

		$response = [
			'ok'      => true,
			'total'   => $total,
			'limit'   => $limit,
			'offset'  => $offset,
			'showing' => \count($out),
			'history' => $out,
			'dedupe_note' => 'VirtueMart appends a history row on every status update call even when the '
				. 'status did not change: updateOrderHistory()\'s dedupe check is commented out '
				. '(models/orders.php:2402-2412).',
		];

		if ($dupes > 0) {
			$response['consecutive_duplicates'] = $dupes;
			$response['duplicates_note'] = $dupes . ' row(s) in this page repeat the status of the row '
				. 'immediately before them for the same order. That is the signature of a polling '
				. 'integration or repeated saves, not of repeated edits.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}
}
