<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

/**
 * List individual DPCalendar tickets. Each row = one attendee's spot at
 * one event within one booking. JOINs to event + booking for readable
 * output.
 */
final class ListTicketsTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'list_dpcalendar_tickets'; }

	public function getDescription(): string
	{
		return 'List DPCalendar tickets (#__dpcalendar_tickets). Each row is one '
			. 'attendee\'s spot at one event within a booking. Filters: state '
			. '(0=pending, 1=confirmed, 2=cancelled, 3=checked_in, -2=trashed), '
			. 'event_id, booking_id, user_id, search (first_name / name / email / uid), '
			. 'public (0/1). Returns id, uid, event_id, event_title, booking_id, '
			. 'first_name, name, email, state, state_label, price, type.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'state'      => ['type' => 'integer'],
				'event_id'   => ['type' => 'integer'],
				'booking_id' => ['type' => 'integer'],
				'user_id'    => ['type' => 'integer'],
				'search'     => ['type' => 'string'],
				'public'     => ['type' => 'integer', 'enum' => [0, 1]],
				'order_by'   => ['type' => 'string', 'enum' => ['id', 'created', 'price']],
				'order_dir'  => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
				'limit'      => ['type' => 'integer'],
				'offset'     => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$p = $this->db->getPrefix();
		$tT = $p . 'dpcalendar_tickets';
		$tE = $p . 'dpcalendar_events';

		$q = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('t.id'),
				$this->db->quoteName('t.uid'),
				$this->db->quoteName('t.event_id'),
				$this->db->quoteName('e.title', 'event_title'),
				$this->db->quoteName('e.start_date', 'event_start_date'),
				$this->db->quoteName('t.booking_id'),
				$this->db->quoteName('t.user_id'),
				$this->db->quoteName('t.first_name'),
				$this->db->quoteName('t.name'),
				$this->db->quoteName('t.email'),
				$this->db->quoteName('t.state'),
				$this->db->quoteName('t.price'),
				$this->db->quoteName('t.type'),
				$this->db->quoteName('t.public'),
				$this->db->quoteName('t.created'),
			])
			->from($this->db->quoteName($tT, 't'))
			->join('LEFT', $this->db->quoteName($tE, 'e') . ' ON ' . $this->db->quoteName('e.id') . ' = ' . $this->db->quoteName('t.event_id'));

		foreach (['state', 'event_id', 'booking_id', 'user_id', 'public'] as $k) {
			if (array_key_exists($k, $arguments)) $q->where($this->db->quoteName('t.' . $k) . ' = ' . (int) $arguments[$k]);
		}
		if (!empty($arguments['search'])) {
			$s = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $arguments['search']) . '%';
			$qs = $this->db->quote($s);
			$q->where('(' . $this->db->quoteName('t.uid') . ' LIKE ' . $qs
				. ' OR ' . $this->db->quoteName('t.email') . ' LIKE ' . $qs
				. ' OR ' . $this->db->quoteName('t.first_name') . ' LIKE ' . $qs
				. ' OR ' . $this->db->quoteName('t.name') . ' LIKE ' . $qs . ')');
		}

		$orderBy = (string) ($arguments['order_by'] ?? 'id');
		$orderDir = strtoupper((string) ($arguments['order_dir'] ?? 'DESC'));
		if (!in_array($orderBy, ['id', 'created', 'price'], true)) $orderBy = 'id';
		if (!in_array($orderDir, ['ASC', 'DESC'], true)) $orderDir = 'DESC';
		$q->order($this->db->quoteName('t.' . $orderBy) . ' ' . $orderDir);

		$limit  = max(1, min(200, (int) ($arguments['limit'] ?? 50)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));
		$this->db->setQuery($q, $offset, $limit);
		$rows = $this->db->loadAssocList() ?: [];
		foreach ($rows as &$r) {
			foreach (['id', 'event_id', 'booking_id', 'user_id', 'state', 'type', 'public'] as $k) {
				if (array_key_exists($k, $r)) $r[$k] = $r[$k] === null ? null : (int) $r[$k];
			}
			if (array_key_exists('price', $r)) $r['price'] = (float) $r['price'];
			$r['state_label'] = $this->ticketStateLabel((int) $r['state']);
		}
		unset($r);

		$total = (int) $this->db->setQuery($this->db->getQuery(true)->select('COUNT(*)')->from($this->db->quoteName($tT)))->loadResult();
		return ToolResult::json([
			'ok' => true, 'count' => count($rows), 'limit' => $limit, 'offset' => $offset,
			'total_unfiltered' => $total, 'tickets' => $rows,
		]);
	}
}
