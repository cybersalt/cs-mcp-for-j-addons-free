<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;

/**
 * Bootstrap helpers for talking to Digital Peak's DPCalendar Core
 * (com_dpcalendar) from inside an MCP tool.
 *
 * DPCalendar is a modern namespaced Joomla component
 * (`DigitalPeak\Component\DPCalendar\...`) with a full MVC surface —
 * one AdminModel per entity (Event, Location, Booking, Ticket, Coupon,
 * Extcalendar, Taxrate, Country). Writes therefore go through
 * $this->getModel('com_dpcalendar', 'Event')->save($data) style calls
 * so DPCalendar's own side effects fire correctly: notification mails,
 * fields plugin, tags, cache invalidation, capacity_used bookkeeping.
 *
 * Reads for lists are direct SQL over the `#__dpcalendar_*` tables —
 * cheaper than instantiating the ListModel and driving its
 * UserState-based filter API from outside the request context.
 *
 * Column-name / semantic landmines this trait calls out because they
 * bit me during discovery:
 *
 *   - **Calendars ARE Joomla categories** with `extension = 'com_dpcalendar'`.
 *     There is no `#__dpcalendar_calendars` table. Bookings + tickets
 *     don't reference a calendar directly; they're linked via the event
 *     (`ticket.event_id -> event.id -> event.catid = categories.id`).
 *
 *   - **`event.catid` is `varchar(191)`, NOT `int`.** DPCalendar stores
 *     external-calendar events (Google, iCloud) with string catids like
 *     `google:calendar-id`. The Joomla-native events use the numeric
 *     category id as a string. Always quote when filtering.
 *
 *   - **Bookings don't have an `event_id`.** A booking is a multi-event
 *     checkout container. Each `#__dpcalendar_tickets` row has both a
 *     `booking_id` AND an `event_id` — that's how you go booking → events.
 *
 *   - **`event.state` follows the standard Joomla content enum**:
 *     0=unpublished, 1=published, 2=archived, -2=trashed.
 *
 *   - **`booking.state`** uses DPCalendar-specific codes:
 *     0=pending, 1=confirmed, 2=cancelled, 3=refunded, 4=denied,
 *     5=cancelled by user (per Digital Peak's Booking helper constants).
 *     Different from ticket.state (0=pending, 1=confirmed, 2=cancelled).
 *
 *   - **`coupon.type`** is `'percentage'` or `'value'` (not the more
 *     common `'fixed'`). `area` is 1=events, 2=tickets scope.
 */
trait DPCalendarBootTrait
{
	protected function dpcalendarAdminBase(): ?string
	{
		$path = JPATH_ADMINISTRATOR . '/components/com_dpcalendar';
		return is_dir($path) ? $path : null;
	}

	protected function notInstalledError(): ToolResult
	{
		return ToolResult::error(
			'DPCalendar (com_dpcalendar) is not installed on this site, or the install is incomplete.'
		);
	}

	/**
	 * Standard Joomla content-item state enum.
	 */
	protected function contentStateLabel(int $state): string
	{
		return match ($state) {
			1  => 'published',
			0  => 'unpublished',
			2  => 'archived',
			-2 => 'trashed',
			default => 'unknown(' . $state . ')',
		};
	}

	/**
	 * DPCalendar booking state — from Digital Peak's Booking helper.
	 */
	protected function bookingStateLabel(int $state): string
	{
		return match ($state) {
			0 => 'pending',
			1 => 'confirmed',
			2 => 'cancelled',
			3 => 'refunded',
			4 => 'denied',
			5 => 'cancelled_by_user',
			default => 'unknown(' . $state . ')',
		};
	}

	/**
	 * DPCalendar ticket state.
	 */
	protected function ticketStateLabel(int $state): string
	{
		return match ($state) {
			0  => 'pending',
			1  => 'confirmed',
			2  => 'cancelled',
			3  => 'checked_in',
			-2 => 'trashed',
			default => 'unknown(' . $state . ')',
		};
	}

	/**
	 * Normalise a user-friendly state string into a Joomla content-state
	 * integer for filtering. Returns null when the input is not a
	 * recognisable state.
	 */
	protected function normaliseContentStateFilter(mixed $raw): ?int
	{
		if ($raw === null) {
			return null;
		}
		if (is_int($raw) || (is_string($raw) && ctype_digit(ltrim((string) $raw, '-')))) {
			return (int) $raw;
		}
		return match (strtolower(trim((string) $raw))) {
			'published', 'pub'   => 1,
			'unpublished', 'unpub' => 0,
			'archived'           => 2,
			'trashed', 'trash'   => -2,
			default              => null,
		};
	}
}
