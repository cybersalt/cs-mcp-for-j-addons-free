<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Reports;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

/**
 * Per-event booking / ticket counts, either for one event or the top-N
 * events by ticket count. Answers "which events are selling well" or
 * "how full is this event".
 */
final class GetEventBookingsSummaryTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'get_dpcalendar_event_bookings_summary'; }

	public function getDescription(): string
	{
		return 'Per-event booking rollup — ticket counts, capacity utilisation, unique '
			. 'attendees. Pass event_id for one event; omit to get top-N events by '
			. 'ticket count (top-selling). Optional: limit (top-N mode, default 20), '
			. 'date_from / date_to (filter events by start_date window in top-N mode).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'event_id' => ['type' => 'integer'],
				'limit'    => ['type' => 'integer'],
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

		$p  = $this->db->getPrefix();
		$tE = $p . 'dpcalendar_events';
		$tT = $p . 'dpcalendar_tickets';

		if (array_key_exists('event_id', $arguments)) {
			$eid = (int) $arguments['event_id'];
			$ev = $this->db->setQuery(
				$this->db->getQuery(true)
					->select(['id', 'title', 'catid', 'start_date', 'end_date', 'capacity', 'capacity_used', 'max_tickets'])
					->from($this->db->quoteName($tE))
					->where($this->db->quoteName('id') . ' = ' . $eid)
			)->loadAssoc();
			if (!$ev) return ToolResult::error('Event ' . $eid . ' not found.');

			$rows = $this->db->setQuery(
				$this->db->getQuery(true)
					->select([$this->db->quoteName('state'), 'COUNT(*) AS n'])
					->from($this->db->quoteName($tT))
					->where($this->db->quoteName('event_id') . ' = ' . $eid)
					->group($this->db->quoteName('state'))
			)->loadAssocList('state') ?: [];
			$tickets = ['total' => 0, 'by_state' => []];
			foreach ($rows as $s => $r) {
				$tickets['total'] += (int) $r['n'];
				$tickets['by_state'][$this->ticketStateLabel((int) $s)] = (int) $r['n'];
			}
			$uniqueAttendees = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select('COUNT(DISTINCT COALESCE(NULLIF(' . $this->db->quoteName('email') . ", ''), " . $this->db->quoteName('user_id') . '))')
					->from($this->db->quoteName($tT))
					->where($this->db->quoteName('event_id') . ' = ' . $eid)
			)->loadResult();
			$uniqueBookings = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select('COUNT(DISTINCT ' . $this->db->quoteName('booking_id') . ')')
					->from($this->db->quoteName($tT))
					->where($this->db->quoteName('event_id') . ' = ' . $eid)
			)->loadResult();

			$cap = $ev['capacity'] !== null ? (int) $ev['capacity'] : null;
			$capUsed = (int) ($ev['capacity_used'] ?? 0);
			return ToolResult::json([
				'ok' => true,
				'event' => [
					'id' => (int) $ev['id'],
					'title' => $ev['title'],
					'start_date' => $ev['start_date'],
					'end_date'   => $ev['end_date'],
					'capacity'   => $cap,
					'capacity_used'    => $capUsed,
					'capacity_remaining' => $cap !== null ? max(0, $cap - $capUsed) : null,
					'capacity_percent' => $cap !== null && $cap > 0 ? round(($capUsed / $cap) * 100, 1) : null,
					'max_tickets_per_booking' => (int) ($ev['max_tickets'] ?? 0),
				],
				'tickets'           => $tickets,
				'unique_attendees'  => $uniqueAttendees,
				'distinct_bookings' => $uniqueBookings,
			]);
		}

		// Top-N mode.
		$limit = max(1, min(200, (int) ($arguments['limit'] ?? 20)));
		$q = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('e.id'),
				$this->db->quoteName('e.title'),
				$this->db->quoteName('e.start_date'),
				$this->db->quoteName('e.capacity'),
				$this->db->quoteName('e.capacity_used'),
				'COUNT(' . $this->db->quoteName('t.id') . ') AS ticket_count',
			])
			->from($this->db->quoteName($tE, 'e'))
			->join('LEFT', $this->db->quoteName($tT, 't') . ' ON ' . $this->db->quoteName('t.event_id') . ' = ' . $this->db->quoteName('e.id'))
			->group($this->db->quoteName('e.id'))
			->having('COUNT(' . $this->db->quoteName('t.id') . ') > 0')
			->order('ticket_count DESC');
		if (!empty($arguments['date_from'])) {
			$q->where($this->db->quoteName('e.start_date') . ' >= ' . $this->db->quote((string) $arguments['date_from']));
		}
		if (!empty($arguments['date_to'])) {
			$q->where($this->db->quoteName('e.start_date') . ' <= ' . $this->db->quote((string) $arguments['date_to']));
		}
		$this->db->setQuery($q, 0, $limit);
		$rows = $this->db->loadAssocList() ?: [];
		foreach ($rows as &$r) {
			foreach (['id', 'capacity', 'capacity_used', 'ticket_count'] as $k) {
				if (array_key_exists($k, $r)) $r[$k] = $r[$k] === null ? null : (int) $r[$k];
			}
		}
		unset($r);

		return ToolResult::json([
			'ok'    => true,
			'limit' => $limit,
			'top'   => $rows,
		]);
	}
}
