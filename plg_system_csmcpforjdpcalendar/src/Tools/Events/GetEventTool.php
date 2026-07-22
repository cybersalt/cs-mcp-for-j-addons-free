<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Events;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

/**
 * Full single-event read. Returns the full row + joined calendar / creator +
 * attached locations + host users + ticket counts by state.
 */
final class GetEventTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'get_dpcalendar_event'; }

	public function getDescription(): string
	{
		return 'Get one DPCalendar event with full details + attached locations, host '
			. 'users, and per-state ticket counts. Required: id.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => ['id' => ['type' => 'integer']],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$p = $this->db->getPrefix();
		$tE = $p . 'dpcalendar_events';
		$tC = $p . 'categories';
		$tU = $p . 'users';
		$tEL = $p . 'dpcalendar_events_location';
		$tEH = $p . 'dpcalendar_events_hosts';
		$tL = $p . 'dpcalendar_locations';
		$tT = $p . 'dpcalendar_tickets';

		$ev = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('e.*')
				->select([
					$this->db->quoteName('c.title', 'calendar_title'),
					$this->db->quoteName('u.name', 'created_by_name'),
					$this->db->quoteName('u.email', 'created_by_email'),
				])
				->from($this->db->quoteName($tE, 'e'))
				->join('LEFT', $this->db->quoteName($tC, 'c') . ' ON CAST(' . $this->db->quoteName('c.id') . ' AS CHAR) = ' . $this->db->quoteName('e.catid'))
				->join('LEFT', $this->db->quoteName($tU, 'u') . ' ON ' . $this->db->quoteName('u.id') . ' = ' . $this->db->quoteName('e.created_by'))
				->where($this->db->quoteName('e.id') . ' = ' . $id)
		)->loadAssoc();
		if (!$ev) return ToolResult::error('Event ' . $id . ' not found.');

		foreach (['id', 'original_id', 'show_end_time', 'all_day', 'hits', 'capacity', 'capacity_used', 'max_tickets',
			'booking_waiting_list', 'booking_series', 'state', 'checked_out', 'access', 'access_content',
			'created_by', 'modified_by', 'featured'] as $k) {
			if (array_key_exists($k, $ev)) $ev[$k] = $ev[$k] === null ? null : (int) $ev[$k];
		}
		$ev['state_label'] = $this->contentStateLabel((int) $ev['state']);
		$ev['is_recurring'] = !empty($ev['rrule']);

		// Locations.
		$ev['locations'] = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([$this->db->quoteName('l.id'), $this->db->quoteName('l.title'), $this->db->quoteName('l.city'), $this->db->quoteName('l.country')])
				->from($this->db->quoteName($tEL, 'el'))
				->join('LEFT', $this->db->quoteName($tL, 'l') . ' ON ' . $this->db->quoteName('l.id') . ' = ' . $this->db->quoteName('el.location_id'))
				->where($this->db->quoteName('el.event_id') . ' = ' . $id)
		)->loadAssocList() ?: [];
		foreach ($ev['locations'] as &$loc) {
			foreach (['id', 'country'] as $k) if (array_key_exists($k, $loc)) $loc[$k] = (int) $loc[$k];
		}
		unset($loc);

		// Hosts.
		$ev['hosts'] = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([$this->db->quoteName('h.user_id'), $this->db->quoteName('u.name'), $this->db->quoteName('u.email')])
				->from($this->db->quoteName($tEH, 'h'))
				->join('LEFT', $this->db->quoteName($tU, 'u') . ' ON ' . $this->db->quoteName('u.id') . ' = ' . $this->db->quoteName('h.user_id'))
				->where($this->db->quoteName('h.event_id') . ' = ' . $id)
		)->loadAssocList() ?: [];
		foreach ($ev['hosts'] as &$h) if (isset($h['user_id'])) $h['user_id'] = (int) $h['user_id'];
		unset($h);

		// Ticket counts by state.
		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([$this->db->quoteName('state'), 'COUNT(*) AS n'])
				->from($this->db->quoteName($tT))
				->where($this->db->quoteName('event_id') . ' = ' . $id)
				->group($this->db->quoteName('state'))
		)->loadAssocList('state') ?: [];
		$ticketCounts = ['total' => 0];
		foreach ($rows as $s => $r) {
			$ticketCounts['total'] += (int) $r['n'];
			$ticketCounts[$this->ticketStateLabel((int) $s)] = (int) $r['n'];
		}
		$ev['ticket_counts'] = $ticketCounts;

		return ToolResult::json(['ok' => true, 'event' => $ev]);
	}
}
