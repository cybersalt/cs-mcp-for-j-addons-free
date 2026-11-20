<?php
/**
 * @package     MCP for DPCalendar
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\User\User;

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

	// -----------------------------------------------------------------------
	// DPCalendar's own permissions
	// -----------------------------------------------------------------------

	/** Joomla action => the label on DPCalendar's Permissions tab. */
	private static array $dpActionLabels = [
		'core.manage'           => 'Access Administration Interface',
		'core.admin'            => 'Configure ACL & Options',
		'core.create'           => 'Create',
		'core.edit'             => 'Edit',
		'core.edit.own'         => 'Edit Own',
		'core.edit.state'       => 'Edit State',
		'core.delete'           => 'Delete',
		'dpcalendar.admin.book' => 'Admin Booking',
	];

	/**
	 * Refuse unless the acting Joomla account holds DPCalendar's own
	 * permission for this action. MCP for J's Use / Write grant is not enough
	 * on its own: the AI must never do more than the account could do in
	 * DPCalendar's administrator.
	 *
	 * Every tool needs core.manage on com_dpcalendar (the administrator entry
	 * gate). Events are checked on their calendar's asset
	 * (com_dpcalendar.category.<id>), as EventController / EventModel do, so a
	 * per-calendar Deny holds; Edit Own counts for the event's author.
	 * Bookings and tickets need dpcalendar.admin.book, as BookingModel /
	 * TicketModel require for anyone but the booking's own author. Locations,
	 * coupons, tax rates and external calendars use the component asset.
	 */
	protected function dpRequireAccess(User $actor, ?string $action = null, string $asset = 'com_dpcalendar', ?int $ownerId = null): void
	{
		if ($actor->guest || (int) $actor->id === 0) {
			throw new \RuntimeException('DPCalendar tools need a signed-in Joomla account.');
		}

		if (!$actor->authorise('core.manage', 'com_dpcalendar') && !$actor->authorise('core.admin', 'com_dpcalendar')) {
			$this->dpRefuse('core.manage', 'com_dpcalendar');
		}

		if ($action === null || $action === 'core.manage' || $actor->authorise($action, $asset)) {
			return;
		}

		if ($action === 'core.edit' && $ownerId !== null && $ownerId > 0 && $ownerId === (int) $actor->id
			&& $actor->authorise('core.edit.own', $asset)) {
			return;
		}

		$this->dpRefuse($action, $asset);
	}

	protected function dpRefuse(string $action, string $asset): never
	{
		$target = str_starts_with($asset, 'com_dpcalendar.category.')
			? 'calendar ' . substr($asset, \strlen('com_dpcalendar.category.'))
			: 'DPCalendar';

		throw new \RuntimeException(
			'Refused: the Joomla account this AI app acts for does not have DPCalendar\'s "'
			. (self::$dpActionLabels[$action] ?? $action) . '" permission (' . $action . ') for ' . $target
			. ($action === 'core.edit' ? ', and is not the author of this event with "Edit Own"' : '') . '. '
			. 'MCP for J never lets the AI do more than that account could do in DPCalendar itself. '
			. 'A Super User can grant it in DPCalendar\'s Options on the Permissions tab'
			. ($target !== 'DPCalendar' ? ', or in that calendar\'s own permissions' : '') . '.'
		);
	}

	/** The ACL asset for a calendar id: numeric ids are Joomla categories; external ids use the component. */
	protected function dpCalendarAsset(mixed $catid): string
	{
		$catid = trim((string) $catid);

		return ctype_digit($catid) && (int) $catid > 0 ? 'com_dpcalendar.category.' . (int) $catid : 'com_dpcalendar';
	}

	/**
	 * Check an action on an existing event, on its calendar's asset. A missing
	 * event is left for the tool to report.
	 */
	protected function dpRequireEvent(User $actor, int $eventId, string $action): void
	{
		$this->dpRequireAccess($actor);

		if ($eventId <= 0 || $this->dpcalendarAdminBase() === null) {
			return;
		}

		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName(['catid', 'created_by']))
				->from($this->db->quoteName($this->db->getPrefix() . 'dpcalendar_events'))
				->where($this->db->quoteName('id') . ' = ' . $eventId)
		)->loadAssoc();

		if ($row) {
			$this->dpRequireAccess($actor, $action, $this->dpCalendarAsset($row['catid']), (int) $row['created_by']);
		}
	}

	/**
	 * A ticket's action, checked on its event's calendar the way TicketModel
	 * checks dpcalendar.admin.book.
	 */
	protected function dpRequireTicket(User $actor, int $ticketId, string ...$actions): void
	{
		$this->dpRequireAccess($actor);

		if ($ticketId <= 0 || $this->dpcalendarAdminBase() === null) {
			return;
		}

		$prefix = $this->db->getPrefix();
		$catid  = $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('e.catid'))
				->from($this->db->quoteName($prefix . 'dpcalendar_tickets', 't'))
				->join('LEFT', $this->db->quoteName($prefix . 'dpcalendar_events', 'e') . ' ON '
					. $this->db->quoteName('e.id') . ' = ' . $this->db->quoteName('t.event_id'))
				->where($this->db->quoteName('t.id') . ' = ' . $ticketId)
		)->loadResult();

		$asset = $this->dpCalendarAsset($catid);

		foreach ($actions as $action) {
			// Delete is a component-level action in Joomla's AdminModel.
			$this->dpRequireAccess($actor, $action, $action === 'core.delete' ? 'com_dpcalendar' : $asset);
		}
	}

	/** created_by of a location, for Edit Own (LocationModel checks it the same way). */
	protected function dpLocationOwner(int $id): ?int
	{
		if ($id <= 0 || $this->dpcalendarAdminBase() === null) {
			return null;
		}

		$owner = $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('created_by'))
				->from($this->db->quoteName($this->db->getPrefix() . 'dpcalendar_locations'))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadResult();

		return $owner === null ? null : (int) $owner;
	}

	/** May this account see credentials in full when it asks to? Super Users and core.admin holders only. */
	protected function dpCanReveal(User $actor): bool
	{
		return $actor->authorise('core.admin') || $actor->authorise('core.admin', 'com_dpcalendar');
	}

	/** Does this params key hold a credential? */
	protected function dpIsSecretKey(string $key): bool
	{
		return (bool) preg_match('/api_?key|secret|password|passwd|token|private_?key|access_?key|signature|client_id$|_key$|account_id$/i', $key);
	}

	/** Does this params key hold a URL that can carry a credential (user:pass@ or a secret query)? */
	protected function dpIsUrlKey(string $key): bool
	{
		return (bool) preg_match('/^(uri|url|host|feed|ical|link)$|_ur[il]$/i', $key);
	}

	/** A URL with its user:password and query string masked. */
	protected function dpMaskUrl(string $url): string
	{
		$url = (string) preg_replace('~^([a-z][a-z0-9+.-]*://)[^/@\s]+@~i', '$1••••@', $url);

		return (string) preg_replace('~\?.+$~s', '?••••', $url);
	}

	/** Mask every credential in a decoded or JSON params value: "••••" plus the last 4 characters of long ones. */
	protected function dpMaskSecrets(mixed $params): mixed
	{
		if (\is_string($params)) {
			$decoded = json_decode($params, true);

			return \is_array($decoded) ? json_encode($this->dpMaskSecrets($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $params;
		}

		if (!\is_array($params)) {
			return $params;
		}

		foreach ($params as $key => $value) {
			if (\is_array($value)) {
				$params[$key] = $this->dpMaskSecrets($value);
			} elseif (\is_string($key) && $this->dpIsSecretKey($key) && \is_scalar($value) && (string) $value !== '') {
				$value        = (string) $value;
				$params[$key] = '••••' . (mb_strlen($value) >= 20 ? mb_substr($value, -4) : '');
			} elseif (\is_string($key) && $this->dpIsUrlKey($key) && \is_string($value) && $value !== '') {
				$params[$key] = $this->dpMaskUrl($value);
			}
		}

		return $params;
	}

	/**
	 * An external calendar's settings as DPCalendar itself reads them: through its
	 * table, which un-scrambles the stored password (ExtcalendarTable::load). Null
	 * when there is no such calendar.
	 */
	protected function dpExtcalendarParams(int $id): ?array
	{
		$table = $this->getModel('com_dpcalendar', 'Extcalendar')->getTable();

		if (!$table->load($id)) {
			return null;
		}

		return json_decode((string) ($table->params ?? ''), true) ?: [];
	}

	/**
	 * Put the stored value back wherever a masked one ("••••…") was sent, at any
	 * depth, so a read-then-write round trip never saves the mask. A string error
	 * when a masked value has nothing stored behind it.
	 */
	protected function dpRestoreMasked(array $patch, array $stored, string $path = 'params'): array|string
	{
		foreach ($patch as $key => $value) {
			$here = $path . '.' . $key;

			if (\is_array($value)) {
				$inner = $this->dpRestoreMasked($value, \is_array($stored[$key] ?? null) ? $stored[$key] : [], $here);

				if (\is_string($inner)) {
					return $inner;
				}

				$patch[$key] = $inner;
			} elseif (\is_string($value) && str_contains($value, '••••')) {
				if (!\array_key_exists($key, $stored) || !\is_scalar($stored[$key])) {
					return $here . ' holds a masked value ("••••…"), not a real one. Send the real value, or leave the key out. Nothing was saved.';
				}

				$patch[$key] = (string) $stored[$key];
			}
		}

		return $patch;
	}

	/** An exception message without SQL or server paths. */
	protected function dpSafeMessage(\Throwable $e): string
	{
		if ($e instanceof \Joomla\Database\Exception\ExecutionFailureException
			|| $e instanceof \Joomla\Database\Exception\ConnectionFailureException
			|| $e instanceof \PDOException || $e instanceof \mysqli_sql_exception) {
			return 'a database error occurred (error ' . (int) $e->getCode() . ')';
		}

		$message = $e->getMessage();

		return \defined('JPATH_ROOT') ? str_replace(JPATH_ROOT, '[site root]', $message) : $message;
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
	 * DPCalendar booking state, as in its booking form (forms/booking.xml).
	 */
	protected function bookingStateLabel(int $state): string
	{
		return match ($state) {
			0  => 'inactive',
			1  => 'active',
			2  => 'tickets_reviewed',
			3  => 'confirmation',
			4  => 'on_hold',
			5  => 'invited',
			6  => 'cancelled',
			7  => 'refunded',
			8  => 'waiting',
			10 => 'partially_paid',
			-2 => 'trashed',
			default => 'unknown(' . $state . ')',
		};
	}

	/**
	 * DPCalendar ticket state, as in its ticket form (forms/ticket.xml); 9 is
	 * what TicketModel::checkin() writes.
	 */
	protected function ticketStateLabel(int $state): string
	{
		return $state === 9 ? 'checked_in' : $this->bookingStateLabel($state);
	}

	// -----------------------------------------------------------------------
	// Field cleaning, Edit State, check-out and view levels, as DPCalendar's own forms and lists do
	// -----------------------------------------------------------------------

	/**
	 * Clean incoming string fields the way DPCalendar's admin forms do. Fields in
	 * $html get Joomla's text filter for the acting user (filter="ComponentHelper::filterText"
	 * on title and description); every other string field gets the default input
	 * filter, which strips tags. color must be a hex colour, url must be http(s),
	 * email must be an email address.
	 *
	 * @return array|ToolResult the cleaned data, or a refusal
	 */
	protected function dpClean(array $data, array $html = ['title', 'description']): array|ToolResult
	{
		$filter = InputFilter::getInstance();

		foreach ($data as $key => $value) {
			if (!\is_string($value)) {
				continue;
			}

			if (\in_array($key, $html, true)) {
				$data[$key] = (string) ComponentHelper::filterText($value);
				continue;
			}

			$value = trim((string) $filter->clean($value, 'string'));

			if ($key === 'color' && $value !== '') {
				$value = ltrim($value, '#');

				if (!preg_match('/^[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/', $value)) {
					return ToolResult::error('color must be a hex colour such as 3366CC (DPCalendar stores it without "#"). Nothing was saved.');
				}
			}

			if ($key === 'url' && $value !== '' && !preg_match('~^https?://~i', $value)) {
				return ToolResult::error('url must start with http:// or https://. Nothing was saved.');
			}

			if ($key === 'email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
				return ToolResult::error('email is not a valid email address. Nothing was saved.');
			}

			$data[$key] = $value;
		}

		return $data;
	}

	/**
	 * State and publish dates need Edit State, as DPCalendar's forms unset them
	 * for anyone without it (EventModel / LocationModel / TaxrateModel / ExtcalendarModel::getForm).
	 */
	protected function dpRequireEditStateFor(User $actor, array $arguments, string $asset): void
	{
		foreach (['state', 'publish_up', 'publish_down'] as $key) {
			if (\array_key_exists($key, $arguments)) {
				$this->dpRequireAccess($actor, 'core.edit.state', $asset);

				return;
			}
		}
	}

	/**
	 * Refuse to change a row another user has checked out (open in DPCalendar's
	 * editor); their Save would silently undo this change. Null when the write may go ahead.
	 */
	protected function dpRefuseCheckedOut(string $table, int $id, User $actor, array $arguments): ?ToolResult
	{
		if (!empty($arguments['force_checked_out'])) {
			return null;
		}

		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName(['checked_out']))
				->from($this->db->quoteName($this->db->getPrefix() . $table))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();

		$by = (int) ($row['checked_out'] ?? 0);

		if ($by <= 0 || $by === (int) $actor->id) {
			return null;
		}

		return ToolResult::json([
			'ok'             => false,
			'error'          => 'Refusing to change item ' . $id . ': user ' . $by . ' has it checked out (open in DPCalendar\'s '
				. 'editor), and their Save would overwrite this change. Nothing was saved.',
			'checked_out_by' => $by,
			'resolution'     => 'Wait for them to save or close it, check it in from DPCalendar\'s list, or pass force_checked_out: true.',
		], true);
	}

	/**
	 * DPCalendar's admin deletes events and locations only from the trash (its
	 * models relax that for API clients only). Null when the row is trashed or missing.
	 */
	protected function dpRequireTrashed(string $table, int $id, string $what): ?ToolResult
	{
		$state = $this->db->setQuery(
			$this->db->getQuery(true)->select($this->db->quoteName('state'))
				->from($this->db->quoteName($this->db->getPrefix() . $table))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadResult();

		if ($state === null || (int) $state === -2) {
			return null;
		}

		return ToolResult::error(
			'Refusing to delete ' . $what . ' ' . $id . ': it is not in the trash. DPCalendar deletes only trashed items. '
			. 'Trash it first (state -2), then delete it; nothing was deleted.'
		);
	}

	/**
	 * Run a booking or ticket write with site links working. DPCalendar's booking
	 * and ticket saves build links for their emails with Route::_() (RouteHelper),
	 * which under Joomla's API application asks the API router to build a URL, and
	 * ApiRouter has no build(): the save dies with "Call to undefined method
	 * ApiRouter::build()". For the duration of the call Route's router cache gets
	 * the site router under the "api" key (the same router Route::link('site', …)
	 * uses), so the emails carry real site links; the cache is put back afterwards.
	 */
	protected function dpWithSiteRoutes(callable $fn): mixed
	{
		$app = \Joomla\CMS\Factory::getApplication();

		if ($app->getName() === 'site' || $app->getName() === 'administrator') {
			return $fn();
		}

		$cache   = new \ReflectionProperty(\Joomla\CMS\Router\Route::class, '_router');
		$routers = $cache->getValue();
		$client  = $app->getName();
		$had     = \array_key_exists($client, $routers);
		$before  = $routers[$client] ?? null;

		$routers[$client] = \Joomla\CMS\Factory::getContainer()->get(\Joomla\CMS\Router\SiteRouter::class);
		$cache->setValue(null, $routers);

		try {
			return $fn();
		} finally {
			$routers = $cache->getValue();

			if ($had) {
				$routers[$client] = $before;
			} else {
				unset($routers[$client]);
			}

			$cache->setValue(null, $routers);
		}
	}

	/** Input-schema entry for the check-out override. */
	protected function dpForceCheckedOutSchema(): array
	{
		return ['type' => 'boolean', 'description' => 'Save even when another user has the item checked out. Default false.'];
	}

	/** Does this account see every access level, as DPCalendar's lists allow core.admin holders? */
	protected function dpSeesAllLevels(User $actor): bool
	{
		return $actor->authorise('core.admin') || $actor->authorise('core.admin', 'com_dpcalendar');
	}

	/** The view levels this account has, as ints. */
	protected function dpViewLevels(User $actor): array
	{
		return array_values(array_unique(array_map('intval', Access::getAuthorisedViewLevels((int) $actor->id))));
	}

	/** A model error, without SQL or server paths for anyone but a Super User. */
	protected function dpModelError(object $model, User $actor): string
	{
		$error = method_exists($model, 'getError') ? (string) ($model->getError() ?: '') : '';

		if ($error === '' || $actor->authorise('core.admin')) {
			return $error;
		}

		if (preg_match('/SQL|database|Duplicate entry|Unknown column|\bTable\b|#__|' . preg_quote($this->db->getPrefix(), '/') . '/i', $error)) {
			return 'a database error occurred';
		}

		return \defined('JPATH_ROOT') ? str_replace(JPATH_ROOT, '[site root]', $error) : $error;
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
