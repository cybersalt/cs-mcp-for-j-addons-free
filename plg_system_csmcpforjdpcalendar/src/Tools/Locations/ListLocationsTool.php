<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Locations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class ListLocationsTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'list_dpcalendar_locations'; }

	public function getDescription(): string
	{
		return 'List DPCalendar locations (#__dpcalendar_locations). Filters: state '
			. '(1/0/2/-2 or published/etc.), country (id or short_code — matched '
			. 'against the location.country column which stores country id), search '
			. '(title / city / street LIKE %term%). Returns id, title, alias, city, '
			. 'country, latitude, longitude, state, state_label.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'state'   => ['description' => 'published/unpublished/archived/trashed OR 1/0/2/-2'],
				'country' => ['type' => 'integer'],
				'search'  => ['type' => 'string'],
				'limit'   => ['type' => 'integer'],
				'offset'  => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$t = $this->db->getPrefix() . 'dpcalendar_locations';
		$q = $this->db->getQuery(true)
			->select(['id', 'title', 'alias', 'country', 'province', 'city', 'zip', 'street', 'number',
				'latitude', 'longitude', 'url', 'state', 'ordering', 'created'])
			->from($this->db->quoteName($t))
			->order($this->db->quoteName('ordering') . ' ASC');

		$state = $this->normaliseContentStateFilter($arguments['state'] ?? null);
		if ($state !== null) $q->where($this->db->quoteName('state') . ' = ' . $state);
		if (array_key_exists('country', $arguments)) $q->where($this->db->quoteName('country') . ' = ' . (int) $arguments['country']);
		if (!empty($arguments['search'])) {
			$s = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $arguments['search']) . '%';
			$qs = $this->db->quote($s);
			$q->where('(' . $this->db->quoteName('title') . ' LIKE ' . $qs
				. ' OR ' . $this->db->quoteName('city') . ' LIKE ' . $qs
				. ' OR ' . $this->db->quoteName('street') . ' LIKE ' . $qs . ')');
		}

		$limit  = max(1, min(200, (int) ($arguments['limit'] ?? 50)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));
		$this->db->setQuery($q, $offset, $limit);
		$rows = $this->db->loadAssocList() ?: [];
		foreach ($rows as &$r) {
			foreach (['id', 'country', 'state', 'ordering'] as $k) if (array_key_exists($k, $r)) $r[$k] = (int) $r[$k];
			foreach (['latitude', 'longitude'] as $k) if (array_key_exists($k, $r) && $r[$k] !== null) $r[$k] = (float) $r[$k];
			$r['state_label'] = $this->contentStateLabel((int) $r['state']);
		}
		unset($r);
		$total = (int) $this->db->setQuery($this->db->getQuery(true)->select('COUNT(*)')->from($this->db->quoteName($t)))->loadResult();
		return ToolResult::json(['ok' => true, 'count' => count($rows), 'limit' => $limit, 'offset' => $offset,
			'total_unfiltered' => $total, 'locations' => $rows]);
	}
}
