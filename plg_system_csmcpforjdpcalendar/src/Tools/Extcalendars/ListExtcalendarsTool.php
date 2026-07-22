<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Extcalendars;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class ListExtcalendarsTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'list_dpcalendar_extcalendars'; }

	public function getDescription(): string
	{
		return 'List DPCalendar external calendars (#__dpcalendar_extcalendars) — the '
			. 'Google / iCloud / iCal-URL / CalDAV feeds DPCalendar syncs from. Filters: '
			. 'state, plugin (calendar type — e.g. googlecalendar / ical / ...), search '
			. '(title / alias LIKE %term%). Returns id, title, alias, plugin, state, '
			. 'state_label, color, sync_date, ordering.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'state'  => ['description' => 'published/unpublished/archived/trashed OR 1/0/2/-2'],
				'plugin' => ['type' => 'string'],
				'search' => ['type' => 'string'],
				'limit'  => ['type' => 'integer'],
				'offset' => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$t = $this->db->getPrefix() . 'dpcalendar_extcalendars';
		$q = $this->db->getQuery(true)
			->select(['id', 'title', 'alias', 'plugin', 'description', 'color', 'color_force',
				'state', 'ordering', 'sync_date', 'access', 'access_content', 'language', 'created'])
			->from($this->db->quoteName($t))
			->order($this->db->quoteName('ordering') . ' ASC');

		$state = $this->normaliseContentStateFilter($arguments['state'] ?? null);
		if ($state !== null) $q->where($this->db->quoteName('state') . ' = ' . $state);
		if (!empty($arguments['plugin'])) $q->where($this->db->quoteName('plugin') . ' = ' . $this->db->quote((string) $arguments['plugin']));
		if (!empty($arguments['search'])) {
			$s = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $arguments['search']) . '%';
			$qs = $this->db->quote($s);
			$q->where('(' . $this->db->quoteName('title') . ' LIKE ' . $qs . ' OR ' . $this->db->quoteName('alias') . ' LIKE ' . $qs . ')');
		}

		$limit  = max(1, min(200, (int) ($arguments['limit'] ?? 50)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));
		$this->db->setQuery($q, $offset, $limit);
		$rows = $this->db->loadAssocList() ?: [];
		foreach ($rows as &$r) {
			foreach (['id', 'color_force', 'state', 'ordering', 'access', 'access_content'] as $k) {
				if (array_key_exists($k, $r)) $r[$k] = (int) $r[$k];
			}
			$r['state_label'] = $this->contentStateLabel((int) $r['state']);
		}
		unset($r);
		$total = (int) $this->db->setQuery($this->db->getQuery(true)->select('COUNT(*)')->from($this->db->quoteName($t)))->loadResult();
		return ToolResult::json(['ok' => true, 'count' => count($rows), 'limit' => $limit, 'offset' => $offset,
			'total_unfiltered' => $total, 'extcalendars' => $rows]);
	}
}
