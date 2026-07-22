<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Reports;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

/**
 * At-a-glance DPCalendar dashboard — counts across every entity so the agent
 * can answer "how much is there?" in one call.
 */
final class GetDashboardSummaryTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'get_dpcalendar_dashboard_summary'; }

	public function getDescription(): string
	{
		return 'DPCalendar at-a-glance summary. Returns counts for calendars, events '
			. '(by state), bookings (by state), tickets, locations, coupons, external '
			. 'calendars, tax rates. Also returns "next 7 days" and "past 30 days" '
			. 'event counts to gauge activity. No input required.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => new \stdClass(),
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$p = $this->db->getPrefix();

		$scalar = fn(string $sql): int => (int) $this->db->setQuery($sql)->loadResult();
		$countByState = function (string $table) use ($p) {
			$rows = $this->db->setQuery(
				$this->db->getQuery(true)
					->select([$this->db->quoteName('state'), 'COUNT(*) AS n'])
					->from($this->db->quoteName($p . $table))
					->group($this->db->quoteName('state'))
			)->loadAssocList('state') ?: [];
			return $rows;
		};

		// Events counts by state.
		$eventStates = $countByState('dpcalendar_events');
		$events = [
			'total'       => array_sum(array_map(fn($r) => (int) $r['n'], $eventStates)),
			'published'   => (int) ($eventStates['1']['n'] ?? 0),
			'unpublished' => (int) ($eventStates['0']['n'] ?? 0),
			'archived'    => (int) ($eventStates['2']['n'] ?? 0),
			'trashed'     => (int) ($eventStates['-2']['n'] ?? 0),
		];

		$now = gmdate('Y-m-d H:i:s');
		$plus7  = gmdate('Y-m-d H:i:s', strtotime('+7 days'));
		$minus30 = gmdate('Y-m-d H:i:s', strtotime('-30 days'));
		$events['next_7_days_upcoming'] = $scalar(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName($p . 'dpcalendar_events')
			. ' WHERE ' . $this->db->quoteName('state') . ' = 1'
			. ' AND ' . $this->db->quoteName('start_date') . ' BETWEEN ' . $this->db->quote($now) . ' AND ' . $this->db->quote($plus7)
		);
		$events['past_30_days_started'] = $scalar(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName($p . 'dpcalendar_events')
			. ' WHERE ' . $this->db->quoteName('start_date') . ' BETWEEN ' . $this->db->quote($minus30) . ' AND ' . $this->db->quote($now)
		);
		$events['featured'] = $scalar(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName($p . 'dpcalendar_events')
			. ' WHERE ' . $this->db->quoteName('featured') . ' = 1'
		);

		// Booking counts by state.
		$bookingStates = $countByState('dpcalendar_bookings');
		$bookings = [
			'total'     => array_sum(array_map(fn($r) => (int) $r['n'], $bookingStates)),
			'by_state'  => [],
		];
		foreach ($bookingStates as $state => $row) {
			$bookings['by_state'][$this->bookingStateLabel((int) $state)] = (int) $row['n'];
		}

		// Simple totals.
		$summary = [
			'calendars'          => $scalar('SELECT COUNT(*) FROM ' . $this->db->quoteName($p . 'categories') . ' WHERE ' . $this->db->quoteName('extension') . ' = ' . $this->db->quote('com_dpcalendar')),
			'events'             => $events,
			'bookings'           => $bookings,
			'tickets'            => $scalar('SELECT COUNT(*) FROM ' . $this->db->quoteName($p . 'dpcalendar_tickets')),
			'locations'          => $scalar('SELECT COUNT(*) FROM ' . $this->db->quoteName($p . 'dpcalendar_locations')),
			'coupons'            => $scalar('SELECT COUNT(*) FROM ' . $this->db->quoteName($p . 'dpcalendar_coupons')),
			'coupons_published'  => $scalar('SELECT COUNT(*) FROM ' . $this->db->quoteName($p . 'dpcalendar_coupons') . ' WHERE ' . $this->db->quoteName('state') . ' = 1'),
			'external_calendars' => $scalar('SELECT COUNT(*) FROM ' . $this->db->quoteName($p . 'dpcalendar_extcalendars')),
			'tax_rates'          => $scalar('SELECT COUNT(*) FROM ' . $this->db->quoteName($p . 'dpcalendar_taxrates')),
			'countries'          => $scalar('SELECT COUNT(*) FROM ' . $this->db->quoteName($p . 'dpcalendar_countries')),
		];

		return ToolResult::json(['ok' => true, 'summary' => $summary]);
	}
}
