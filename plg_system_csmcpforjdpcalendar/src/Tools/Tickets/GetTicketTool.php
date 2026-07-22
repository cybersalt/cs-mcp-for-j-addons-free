<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class GetTicketTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'get_dpcalendar_ticket'; }

	public function getDescription(): string
	{
		return 'Get one DPCalendar ticket with its event + booking summary. Required: id.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object', 'required' => ['id'],
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
		$t = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('t.*')
				->select([
					$this->db->quoteName('e.title', 'event_title'),
					$this->db->quoteName('e.start_date', 'event_start_date'),
					$this->db->quoteName('e.end_date', 'event_end_date'),
					$this->db->quoteName('b.uid', 'booking_uid'),
					$this->db->quoteName('b.state', 'booking_state'),
					$this->db->quoteName('b.email', 'booking_email'),
				])
				->from($this->db->quoteName($p . 'dpcalendar_tickets', 't'))
				->join('LEFT', $this->db->quoteName($p . 'dpcalendar_events', 'e') . ' ON ' . $this->db->quoteName('e.id') . ' = ' . $this->db->quoteName('t.event_id'))
				->join('LEFT', $this->db->quoteName($p . 'dpcalendar_bookings', 'b') . ' ON ' . $this->db->quoteName('b.id') . ' = ' . $this->db->quoteName('t.booking_id'))
				->where($this->db->quoteName('t.id') . ' = ' . $id)
		)->loadAssoc();
		if (!$t) return ToolResult::error('Ticket ' . $id . ' not found.');

		foreach (['id', 'booking_id', 'event_id', 'user_id', 'state', 'type', 'public', 'booking_state'] as $k) {
			if (array_key_exists($k, $t)) $t[$k] = $t[$k] === null ? null : (int) $t[$k];
		}
		if (array_key_exists('price', $t)) $t['price'] = (float) $t['price'];
		$t['state_label']  = $this->ticketStateLabel((int) $t['state']);
		if (array_key_exists('booking_state', $t) && $t['booking_state'] !== null) {
			$t['booking_state_label'] = $this->bookingStateLabel((int) $t['booking_state']);
		}
		return ToolResult::json(['ok' => true, 'ticket' => $t]);
	}
}
