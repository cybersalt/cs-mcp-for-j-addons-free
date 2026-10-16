<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Reports;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

final class GetSalesSummaryTool extends AbstractTool
{
	use J2commerceBootTrait;

	public function getName(): string { return 'get_j2commerce_sales_summary'; }

	public function getDescription(): string
	{
		return 'Sales summary for a date range (YYYY-MM-DD, UTC, default the last 30 days): number of orders, '
			. 'revenue (order totals), subtotal, tax, shipping, discounts, fees and refunds, average order value, '
			. 'distinct customers, a breakdown by period (day, week or month), by status, by payment method and '
			. 'by currency. '
			. 'By default only PAID orders count — status types complete, approved, shipped and delivered, the '
			. 'same rule J2Commerce uses for "finalized". scope: placed adds open (pending) orders; scope: all '
			. 'counts every order except abandoned status-5 "New" checkouts. '
			. 'Refunds are the order_refund total J2Commerce keeps in step with its payment ledger; net_revenue '
			. 'subtracts them. Multi-currency stores: amounts are summed as stored per order and also split by '
			. 'currency — the combined figure is only meaningful in a single-currency store. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'from'     => ['type' => 'string', 'description' => 'YYYY-MM-DD, UTC, inclusive. Default 30 days ago.'],
				'to'       => ['type' => 'string', 'description' => 'YYYY-MM-DD, UTC, inclusive. Default today.'],
				'group_by' => ['type' => 'string', 'enum' => ['day', 'week', 'month'], 'description' => 'Default day.'],
				'scope'    => ['type' => 'string', 'enum' => ['paid', 'placed', 'all'], 'description' => 'Default paid.'],
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

		$db = $this->db;
		[$from, $to] = $this->range($arguments);

		$scope    = (string) ($arguments['scope'] ?? 'paid');
		$statuses = $this->j2StatusMap();
		$types    = match ($scope) {
			'placed' => [...self::J2_FINALIZED_TYPES, 'open'],
			'all'    => null,
			default  => self::J2_FINALIZED_TYPES,
		};

		if ($types === null) {
			$statusWhere = 'o.order_state_id <> 5';
		} else {
			$ids = array_keys(array_filter($statuses, static fn (array $s): bool => \in_array((string) $s['type'], $types, true)));
			$statusWhere = $ids === [] ? '1 = 0' : 'o.order_state_id IN (' . implode(',', $ids) . ')';
		}

		$where = ' WHERE ' . $statusWhere . ' AND o.created_on >= ' . $db->quote($from . ' 00:00:00')
			. ' AND o.created_on <= ' . $db->quote($to . ' 23:59:59');
		$orders = $db->quoteName($this->j2Table('orders')) . ' o';

		$sums = 'COUNT(*) AS orders, COALESCE(SUM(o.order_total),0) AS revenue, COALESCE(SUM(o.order_subtotal),0) AS subtotal,'
			. ' COALESCE(SUM(o.order_tax),0) AS tax, COALESCE(SUM(o.order_shipping),0) AS shipping,'
			. ' COALESCE(SUM(o.order_discount),0) AS discount, COALESCE(SUM(o.order_fees),0) AS fees,'
			. ' COALESCE(SUM(o.order_refund),0) AS refunded';

		$totals = $db->setQuery(
			'SELECT ' . $sums . ', COUNT(DISTINCT LOWER(o.user_email)) AS customers FROM ' . $orders . $where
		)->loadAssoc() ?: [];

		$fmt = match ((string) ($arguments['group_by'] ?? 'day')) {
			'month' => '%Y-%m',
			'week'  => '%x-W%v',
			default => '%Y-%m-%d',
		};

		$periods = $db->setQuery(
			'SELECT DATE_FORMAT(o.created_on, ' . $db->quote($fmt) . ') AS period, ' . $sums . ' FROM ' . $orders . $where
			. ' GROUP BY period ORDER BY period'
		)->loadAssocList() ?: [];

		$byStatus = $db->setQuery(
			'SELECT o.order_state_id, ' . $sums . ' FROM ' . $orders . $where . ' GROUP BY o.order_state_id'
		)->loadAssocList() ?: [];

		$byPayment = $db->setQuery(
			'SELECT o.orderpayment_type, ' . $sums . ' FROM ' . $orders . $where . ' GROUP BY o.orderpayment_type ORDER BY revenue DESC'
		)->loadAssocList() ?: [];

		$byCurrency = $db->setQuery(
			'SELECT o.currency_code, ' . $sums . ' FROM ' . $orders . $where . ' GROUP BY o.currency_code'
		)->loadAssocList() ?: [];

		$shape = fn (array $r): array => [
			'orders'      => (int) ($r['orders'] ?? 0),
			'revenue'     => round((float) ($r['revenue'] ?? 0), 2),
			'net_revenue' => round((float) ($r['revenue'] ?? 0) - (float) ($r['refunded'] ?? 0), 2),
			'subtotal'    => round((float) ($r['subtotal'] ?? 0), 2),
			'tax'         => round((float) ($r['tax'] ?? 0), 2),
			'shipping'    => round((float) ($r['shipping'] ?? 0), 2),
			'discount'    => round((float) ($r['discount'] ?? 0), 2),
			'fees'        => round((float) ($r['fees'] ?? 0), 2),
			'refunded'    => round((float) ($r['refunded'] ?? 0), 2),
		];

		$summary = $shape($totals);
		$summary['customers']           = (int) ($totals['customers'] ?? 0);
		$summary['average_order_value'] = $summary['orders'] > 0 ? round($summary['revenue'] / $summary['orders'], 2) : 0.0;

		$response = [
			'ok'          => true,
			'from'        => $from,
			'to'          => $to,
			'scope'       => $scope,
			'summary'     => $summary,
			'by_period'   => array_map(fn (array $r): array => ['period' => (string) $r['period']] + $shape($r), $periods),
			'by_status'   => array_map(fn (array $r): array => [
				'status_id' => (int) $r['order_state_id'],
				'status'    => $statuses[(int) $r['order_state_id']]['label'] ?? null,
			] + $shape($r), $byStatus),
			'by_payment_method' => array_map(fn (array $r): array => ['payment_method' => (string) $r['orderpayment_type']] + $shape($r), $byPayment),
			'by_currency' => array_map(fn (array $r): array => ['currency' => (string) $r['currency_code']] + $shape($r), $byCurrency),
		];

		if (\count($byCurrency) > 1) {
			$response['currency_warning'] = 'Orders in this range use ' . \count($byCurrency) . ' currencies. The summary '
				. 'adds them as stored; use by_currency for meaningful figures.';
		}

		return ToolResult::json($response);
	}

	/** @return array{0:string,1:string} */
	private function range(array $a): array
	{
		$to   = trim((string) ($a['to'] ?? '')) ?: gmdate('Y-m-d');
		$from = trim((string) ($a['from'] ?? '')) ?: gmdate('Y-m-d', strtotime($to . ' -30 days'));

		foreach (['from' => $from, 'to' => $to] as $k => $v) {
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
				throw new \InvalidArgumentException($k . ' must be YYYY-MM-DD.');
			}
		}

		if ($to < $from) {
			throw new \InvalidArgumentException('to is before from.');
		}

		return [$from, $to];
	}
}
