<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * DPCalendar MCP add-on plugin. Registers ~43 tools wrapping Digital Peak's
 * DPCalendar Core (com_dpcalendar).
 *
 * Reads: direct SQL over #__dpcalendar_* tables with JOINs to #__categories
 * (calendar names), #__users (host/attendee names), and #__dpcalendar_events
 * (booking → events resolution via the tickets junction).
 *
 * Writes: through DPCalendar's own AdminModel classes via cs-mcp-for-j's
 * getModel() helper so DPCalendar's side effects (notification emails,
 * capacity bookkeeping, tags, fields) fire correctly.
 */
final class Csmcpforjdpcalendar extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Calendars (Joomla categories where extension = com_dpcalendar)
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Calendars\ListCalendarsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Calendars\GetCalendarTool::class,

		// Events (#__dpcalendar_events)
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Events\ListEventsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Events\GetEventTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Events\CreateEventTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Events\UpdateEventTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Events\DeleteEventTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Events\SetEventStateTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Events\SetEventFeaturedTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Events\DuplicateEventTool::class,

		// Bookings (#__dpcalendar_bookings)
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Bookings\ListBookingsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Bookings\GetBookingTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Bookings\UpdateBookingTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Bookings\DeleteBookingTool::class,

		// Tickets (#__dpcalendar_tickets)
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Tickets\ListTicketsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Tickets\GetTicketTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Tickets\CheckInTicketTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Tickets\CheckOutTicketTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Tickets\DeleteTicketTool::class,

		// Locations (#__dpcalendar_locations)
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Locations\ListLocationsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Locations\GetLocationTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Locations\CreateLocationTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Locations\UpdateLocationTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Locations\DeleteLocationTool::class,

		// Coupons (#__dpcalendar_coupons)
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Coupons\ListCouponsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Coupons\GetCouponTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Coupons\CreateCouponTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Coupons\UpdateCouponTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Coupons\DeleteCouponTool::class,

		// External calendars (#__dpcalendar_extcalendars) — Google/iCloud/CalDAV feeds
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Extcalendars\ListExtcalendarsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Extcalendars\GetExtcalendarTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Extcalendars\CreateExtcalendarTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Extcalendars\UpdateExtcalendarTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Extcalendars\DeleteExtcalendarTool::class,

		// Tax rates (#__dpcalendar_taxrates)
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Taxrates\ListTaxratesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Taxrates\GetTaxrateTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Taxrates\CreateTaxrateTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Taxrates\UpdateTaxrateTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Taxrates\DeleteTaxrateTool::class,

		// Countries (#__dpcalendar_countries) — reference lookup
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Countries\ListCountriesTool::class,

		// Reports (multi-table roll-ups)
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Reports\GetDashboardSummaryTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Reports\GetEventBookingsSummaryTool::class,
		\Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Reports\GetRevenueSummaryTool::class,
	];

	public static function getSubscribedEvents(): array
	{
		return [RegisterToolsEvent::EVENT_NAME => 'onRegisterTools'];
	}

	public function onRegisterTools(RegisterToolsEvent $event): void
	{
		$registry = $event->getRegistry();
		$db       = $this->getDatabase();

		foreach (self::TOOLS as $toolClass) {
			$registry->register(new $toolClass($db));
		}
	}

	public static function getToolClasses(): array
	{
		return self::TOOLS;
	}
}
