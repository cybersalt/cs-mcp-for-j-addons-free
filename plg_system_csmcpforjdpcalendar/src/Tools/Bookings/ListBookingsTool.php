<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Bookings;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

/**
 * List DPCalendar bookings. Note: bookings don't hold an event_id directly —
 * a booking is a multi-event checkout container. The `event_id` filter here
 * matches bookings whose tickets include the given event, via a subquery.
 */
final class ListBookingsTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'list_dpcalendar_bookings'; }

	public function getDescription(): string
	{
		return 'List DPCalendar bookings (#__dpcalendar_bookings). Filters: state '
			. '(0=pending, 1=confirmed, 2=cancelled, 3=refunded, 4=denied, '
			. '5=cancelled_by_user), user_id, event_id (matches bookings containing '
			. 'tickets for that event), search (uid / first_name+name / email), '
			. 'date_from / date_to (matches book_date), payment_provider. Returns id, '
			. 'uid, user_id, first_name, name, email, book_date, state, state_label, '
			. 'gross_amount, currency, ticket_count.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'state'            => ['type' => 'integer'],
				'user_id'          => ['type' => 'integer'],
				'event_id'         => ['type' => 'integer'],
				'search'           => ['type' => 'string'],
				'date_from'        => ['type' => 'string'],
				'date_to'          => ['type' => 'string'],
				'payment_provider' => ['type' => 'string'],
				'order_by'         => ['type' => 'string', 'enum' => ['id', 'book_date', 'gross_amount']],
				'order_dir'        => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
				'limit'            => ['type' => 'integer'],
				'offset'           => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$p  = $this->db->getPrefix();
		$tB = $p . 'dpcalendar_bookings';
		$tT = $p . 'dpcalendar_tickets';

		$q = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('b.id'),
				$this->db->quoteName('b.uid'),
				$this->db->quoteName('b.user_id'),
				$this->db->quoteName('b.first_name'),
				$this->db->quoteName('b.name'),
				$this->db->quoteName('b.email'),
				$this->db->quoteName('b.book_date'),
				$this->db->quoteName('b.state'),
				$this->db->quoteName('b.gross_amount'),
				$this->db->quoteName('b.net_amount'),
				$this->db->quoteName('b.tax_amount'),
				$this->db->quoteName('b.currency'),
				$this->db->quoteName('b.payment_provider'),
				$this->db->quoteName('b.transaction_id'),
				'(SELECT COUNT(*) FROM ' . $this->db->quoteName($tT, 't2') . ' WHERE ' . $this->db->quoteName('t2.booking_id') . ' = ' . $this->db->quoteName('b.id') . ') AS ticket_count',
			])
			->from($this->db->quoteName($tB, 'b'));

		if (array_key_exists('state', $arguments)) $q->where($this->db->quoteName('b.state') . ' = ' . (int) $arguments['state']);
		if (array_key_exists('user_id', $arguments)) $q->where($this->db->quoteName('b.user_id') . ' = ' . (int) $arguments['user_id']);
		if (array_key_exists('event_id', $arguments)) {
			$q->where($this->db->quoteName('b.id') . ' IN (SELECT ' . $this->db->quoteName('booking_id') . ' FROM ' . $this->db->quoteName($tT) . ' WHERE ' . $this->db->quoteName('event_id') . ' = ' . (int) $arguments['event_id'] . ')');
		}
		if (!empty($arguments['payment_provider'])) $q->where($this->db->quoteName('b.payment_provider') . ' = ' . $this->db->quote((string) $arguments['payment_provider']));
		if (!empty($arguments['search'])) {
			$s = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $arguments['search']) . '%';
			$qs = $this->db->quote($s);
			$q->where('(' . $this->db->quoteName('b.uid') . ' LIKE ' . $qs
				. ' OR ' . $this->db->quoteName('b.email') . ' LIKE ' . $qs
				. ' OR ' . $this->db->quoteName('b.first_name') . ' LIKE ' . $qs
				. ' OR ' . $this->db->quoteName('b.name') . ' LIKE ' . $qs . ')');
		}
		if (!empty($arguments['date_from'])) $q->where($this->db->quoteName('b.book_date') . ' >= ' . $this->db->quote((string) $arguments['date_from']));
		if (!empty($arguments['date_to']))   $q->where($this->db->quoteName('b.book_date') . ' <= ' . $this->db->quote((string) $arguments['date_to']));

		$orderBy = (string) ($arguments['order_by'] ?? 'id');
		$orderDir = strtoupper((string) ($arguments['order_dir'] ?? 'DESC'));
		if (!in_array($orderBy, ['id', 'book_date', 'gross_amount'], true)) $orderBy = 'id';
		if (!in_array($orderDir, ['ASC', 'DESC'], true)) $orderDir = 'DESC';
		$q->order($this->db->quoteName('b.' . $orderBy) . ' ' . $orderDir);

		$limit  = max(1, min(200, (int) ($arguments['limit'] ?? 50)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));
		$this->db->setQuery($q, $offset, $limit);
		$rows = $this->db->loadAssocList() ?: [];
		foreach ($rows as &$r) {
			foreach (['id', 'user_id', 'state', 'ticket_count'] as $k) {
				if (array_key_exists($k, $r)) $r[$k] = (int) $r[$k];
			}
			foreach (['gross_amount', 'net_amount', 'tax_amount'] as $k) {
				if (array_key_exists($k, $r)) $r[$k] = (float) $r[$k];
			}
			$r['state_label'] = $this->bookingStateLabel((int) $r['state']);
		}
		unset($r);

		$total = (int) $this->db->setQuery($this->db->getQuery(true)->select('COUNT(*)')->from($this->db->quoteName($tB)))->loadResult();

		return ToolResult::json([
			'ok' => true, 'count' => count($rows), 'limit' => $limit, 'offset' => $offset,
			'total_unfiltered' => $total, 'bookings' => $rows,
		]);
	}
}
