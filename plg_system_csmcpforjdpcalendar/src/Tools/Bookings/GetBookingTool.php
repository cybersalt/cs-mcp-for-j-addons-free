<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Bookings;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class GetBookingTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'get_dpcalendar_booking'; }

	public function getDescription(): string
	{
		return 'Get one DPCalendar booking with its tickets (each with the resolved '
			. 'event title + attendee name), coupon info, and totals breakdown. '
			. 'Required: id.';
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

		$p  = $this->db->getPrefix();
		$tB = $p . 'dpcalendar_bookings';
		$tT = $p . 'dpcalendar_tickets';
		$tE = $p . 'dpcalendar_events';
		$tCp = $p . 'dpcalendar_coupons';

		$booking = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('b.*')
				->select([$this->db->quoteName('c.title', 'coupon_title'), $this->db->quoteName('c.code', 'coupon_code')])
				->from($this->db->quoteName($tB, 'b'))
				->join('LEFT', $this->db->quoteName($tCp, 'c') . ' ON ' . $this->db->quoteName('c.id') . ' = ' . $this->db->quoteName('b.coupon_id'))
				->where($this->db->quoteName('b.id') . ' = ' . $id)
		)->loadAssoc();
		if (!$booking) return ToolResult::error('Booking ' . $id . ' not found.');

		foreach (['id', 'user_id', 'state', 'invoice', 'coupon_id'] as $k) {
			if (array_key_exists($k, $booking)) $booking[$k] = $booking[$k] === null ? null : (int) $booking[$k];
		}
		foreach (['price', 'tax', 'coupon_rate', 'events_discount', 'tickets_discount', 'user_group_discount',
			'earlybird_discount', 'payment_provider_fee', 'net_amount', 'tax_amount', 'gross_amount', 'payment_fee'] as $k) {
			if (array_key_exists($k, $booking)) $booking[$k] = $booking[$k] === null ? null : (float) $booking[$k];
		}
		$booking['state_label'] = $this->bookingStateLabel((int) $booking['state']);
		// Don't return raw_data by default — often bulky payment gateway payload.
		if (isset($booking['raw_data']) && strlen((string) $booking['raw_data']) > 500) {
			$booking['raw_data'] = '[' . strlen((string) $booking['raw_data']) . ' bytes — omitted from response]';
		}

		$tickets = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('t.id'),
					$this->db->quoteName('t.uid'),
					$this->db->quoteName('t.event_id'),
					$this->db->quoteName('e.title', 'event_title'),
					$this->db->quoteName('e.start_date', 'event_start_date'),
					$this->db->quoteName('t.first_name'),
					$this->db->quoteName('t.name'),
					$this->db->quoteName('t.email'),
					$this->db->quoteName('t.price'),
					$this->db->quoteName('t.state'),
					$this->db->quoteName('t.type'),
					$this->db->quoteName('t.public'),
				])
				->from($this->db->quoteName($tT, 't'))
				->join('LEFT', $this->db->quoteName($tE, 'e') . ' ON ' . $this->db->quoteName('e.id') . ' = ' . $this->db->quoteName('t.event_id'))
				->where($this->db->quoteName('t.booking_id') . ' = ' . $id)
		)->loadAssocList() ?: [];
		foreach ($tickets as &$t) {
			foreach (['id', 'event_id', 'state', 'type', 'public'] as $k) {
				if (array_key_exists($k, $t)) $t[$k] = $t[$k] === null ? null : (int) $t[$k];
			}
			if (array_key_exists('price', $t)) $t['price'] = (float) $t['price'];
			if (array_key_exists('state', $t)) $t['state_label'] = $this->ticketStateLabel((int) $t['state']);
		}
		unset($t);
		$booking['tickets'] = $tickets;
		$booking['ticket_count'] = count($tickets);

		return ToolResult::json(['ok' => true, 'booking' => $booking]);
	}
}
