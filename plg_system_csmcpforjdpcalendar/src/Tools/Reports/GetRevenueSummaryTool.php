<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Reports;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

/**
 * Revenue rollup from #__dpcalendar_bookings. Only counts confirmed bookings
 * (state=1). Groups by currency because DPCalendar stores per-booking currency
 * strings and totals across currencies would be meaningless.
 */
final class GetRevenueSummaryTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'get_dpcalendar_revenue_summary'; }

	public function getDescription(): string
	{
		return 'Revenue rollup from confirmed DPCalendar bookings (state=1). '
			. 'Groups by currency because DPCalendar stores per-booking currency '
			. 'strings and cross-currency totals aren\'t meaningful. Returns this '
			. 'month, last month, this year, lifetime. Optional: date_from / date_to '
			. '(YYYY-MM-DD) to override the defaults.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'date_from' => ['type' => 'string'],
				'date_to'   => ['type' => 'string'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$t = $this->db->getPrefix() . 'dpcalendar_bookings';

		$rev = function (?string $from, ?string $to) use ($t) {
			$q = $this->db->getQuery(true)
				->select([
					$this->db->quoteName('currency'),
					'SUM(' . $this->db->quoteName('gross_amount') . ') AS revenue',
					'COUNT(*) AS bookings',
				])
				->from($this->db->quoteName($t))
				->where($this->db->quoteName('state') . ' = 1')
				->group($this->db->quoteName('currency'));
			if ($from) $q->where($this->db->quoteName('book_date') . ' >= ' . $this->db->quote($from));
			if ($to)   $q->where($this->db->quoteName('book_date') . ' <= ' . $this->db->quote($to));
			$rows = $this->db->setQuery($q)->loadAssocList() ?: [];
			foreach ($rows as &$r) {
				$r['currency'] = (string) ($r['currency'] ?? '');
				$r['revenue']  = (float) $r['revenue'];
				$r['bookings'] = (int) $r['bookings'];
			}
			return $rows;
		};

		if (!empty($arguments['date_from']) || !empty($arguments['date_to'])) {
			return ToolResult::json([
				'ok'          => true,
				'date_from'   => $arguments['date_from'] ?? null,
				'date_to'     => $arguments['date_to'] ?? null,
				'by_currency' => $rev($arguments['date_from'] ?? null, $arguments['date_to'] ?? null),
			]);
		}

		$now      = date('Y-m-d');
		$monthStart = date('Y-m-01');
		$lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
		$lastMonthEnd   = date('Y-m-t',  strtotime('last day of last month'));
		$yearStart      = date('Y-01-01');

		return ToolResult::json([
			'ok' => true,
			'this_month' => [
				'from' => $monthStart, 'to' => $now,
				'by_currency' => $rev($monthStart . ' 00:00:00', $now . ' 23:59:59'),
			],
			'last_month' => [
				'from' => $lastMonthStart, 'to' => $lastMonthEnd,
				'by_currency' => $rev($lastMonthStart . ' 00:00:00', $lastMonthEnd . ' 23:59:59'),
			],
			'this_year' => [
				'from' => $yearStart, 'to' => $now,
				'by_currency' => $rev($yearStart . ' 00:00:00', $now . ' 23:59:59'),
			],
			'lifetime' => [
				'by_currency' => $rev(null, null),
			],
		]);
	}
}
