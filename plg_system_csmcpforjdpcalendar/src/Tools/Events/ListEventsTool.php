<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Events;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

/**
 * List DPCalendar events with the most-useful filters surfaced. JOINs to
 * #__categories (calendar title) and #__users (creator name) for readable
 * output.
 */
final class ListEventsTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'list_dpcalendar_events'; }

	public function getDescription(): string
	{
		return 'List DPCalendar events (#__dpcalendar_events). Filters: state '
			. '(published/unpublished/archived/trashed or numeric 1/0/2/-2), catid '
			. '(calendar id — string, since catid is varchar(191); accepts int too), '
			. 'featured (1/0), search (title / alias LIKE %term%), date_from / date_to '
			. '(matches start_date), created_by (user id), has_capacity (1 = capacity IS '
			. 'NOT NULL, ie bookable). Default limit 50, max 200. Order defaults to '
			. 'start_date DESC (upcoming/recent first). Returns id, title, alias, catid, '
			. 'calendar_title, start_date, end_date, all_day, state, state_label, '
			. 'featured, capacity, capacity_used, created_by, created_by_name.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'state'        => ['description' => 'published/unpublished/archived/trashed OR 1/0/2/-2'],
				'catid'        => ['description' => 'calendar id (string or int; the column is varchar(191))'],
				'featured'     => ['type' => 'integer', 'enum' => [0, 1]],
				'search'       => ['type' => 'string'],
				'date_from'    => ['type' => 'string'],
				'date_to'      => ['type' => 'string'],
				'created_by'   => ['type' => 'integer'],
				'has_capacity' => ['type' => 'integer', 'enum' => [0, 1]],
				'order_by'     => ['type' => 'string', 'enum' => ['id', 'title', 'start_date', 'end_date', 'created', 'modified', 'hits']],
				'order_dir'    => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
				'limit'        => ['type' => 'integer'],
				'offset'       => ['type' => 'integer'],
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
		$tC = $p . 'categories';
		$tU = $p . 'users';

		$q = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('e.id'),
				$this->db->quoteName('e.title'),
				$this->db->quoteName('e.alias'),
				$this->db->quoteName('e.catid'),
				$this->db->quoteName('c.title', 'calendar_title'),
				$this->db->quoteName('e.start_date'),
				$this->db->quoteName('e.end_date'),
				$this->db->quoteName('e.all_day'),
				$this->db->quoteName('e.state'),
				$this->db->quoteName('e.featured'),
				$this->db->quoteName('e.capacity'),
				$this->db->quoteName('e.capacity_used'),
				$this->db->quoteName('e.max_tickets'),
				$this->db->quoteName('e.hits'),
				$this->db->quoteName('e.rrule'),
				$this->db->quoteName('e.created_by'),
				$this->db->quoteName('u.name', 'created_by_name'),
				$this->db->quoteName('e.created'),
			])
			->from($this->db->quoteName($tE, 'e'))
			->join('LEFT', $this->db->quoteName($tC, 'c') . ' ON CAST(' . $this->db->quoteName('c.id') . ' AS CHAR) = ' . $this->db->quoteName('e.catid'))
			->join('LEFT', $this->db->quoteName($tU, 'u') . ' ON ' . $this->db->quoteName('u.id') . ' = ' . $this->db->quoteName('e.created_by'));

		$state = $this->normaliseContentStateFilter($arguments['state'] ?? null);
		if ($state !== null) {
			$q->where($this->db->quoteName('e.state') . ' = ' . $state);
		}
		if (array_key_exists('catid', $arguments)) {
			$q->where($this->db->quoteName('e.catid') . ' = ' . $this->db->quote((string) $arguments['catid']));
		}
		if (array_key_exists('featured', $arguments)) {
			$q->where($this->db->quoteName('e.featured') . ' = ' . (int) $arguments['featured']);
		}
		if (!empty($arguments['search'])) {
			$s = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $arguments['search']) . '%';
			$qs = $this->db->quote($s);
			$q->where('(' . $this->db->quoteName('e.title') . ' LIKE ' . $qs . ' OR ' . $this->db->quoteName('e.alias') . ' LIKE ' . $qs . ')');
		}
		if (!empty($arguments['date_from'])) {
			$q->where($this->db->quoteName('e.start_date') . ' >= ' . $this->db->quote((string) $arguments['date_from']));
		}
		if (!empty($arguments['date_to'])) {
			$q->where($this->db->quoteName('e.start_date') . ' <= ' . $this->db->quote((string) $arguments['date_to']));
		}
		if (array_key_exists('created_by', $arguments)) {
			$q->where($this->db->quoteName('e.created_by') . ' = ' . (int) $arguments['created_by']);
		}
		if (array_key_exists('has_capacity', $arguments)) {
			$q->where(((int) $arguments['has_capacity']) === 1
				? $this->db->quoteName('e.capacity') . ' IS NOT NULL'
				: $this->db->quoteName('e.capacity') . ' IS NULL');
		}

		$orderBy = (string) ($arguments['order_by'] ?? 'start_date');
		$orderDir = strtoupper((string) ($arguments['order_dir'] ?? 'DESC'));
		if (!in_array($orderBy, ['id', 'title', 'start_date', 'end_date', 'created', 'modified', 'hits'], true)) $orderBy = 'start_date';
		if (!in_array($orderDir, ['ASC', 'DESC'], true)) $orderDir = 'DESC';
		$q->order($this->db->quoteName('e.' . $orderBy) . ' ' . $orderDir);

		$limit  = max(1, min(200, (int) ($arguments['limit'] ?? 50)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));
		$this->db->setQuery($q, $offset, $limit);
		$rows = $this->db->loadAssocList() ?: [];
		foreach ($rows as &$r) {
			foreach (['id', 'all_day', 'state', 'featured', 'capacity', 'capacity_used', 'max_tickets', 'hits', 'created_by'] as $k) {
				if (array_key_exists($k, $r)) $r[$k] = $r[$k] === null ? null : (int) $r[$k];
			}
			$r['state_label'] = $this->contentStateLabel((int) $r['state']);
			$r['is_recurring'] = !empty($r['rrule']);
		}
		unset($r);

		$total = (int) $this->db->setQuery($this->db->getQuery(true)->select('COUNT(*)')->from($this->db->quoteName($tE)))->loadResult();

		return ToolResult::json([
			'ok' => true,
			'count' => count($rows),
			'limit' => $limit,
			'offset' => $offset,
			'total_unfiltered' => $total,
			'events' => $rows,
		]);
	}
}
