<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Coupons;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class ListCouponsTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'list_dpcalendar_coupons'; }

	public function getDescription(): string
	{
		return 'List DPCalendar coupons (#__dpcalendar_coupons). Filters: state '
			. '(1/0/2/-2 or friendly), type (percentage / value), search (title / code '
			. 'LIKE %term%). Returns id, title, code, value, type, area, limit, state, '
			. 'state_label, access.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'state'  => ['description' => 'published/unpublished/archived/trashed OR 1/0/2/-2'],
				'type'   => ['type' => 'string', 'enum' => ['percentage', 'value']],
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

		$t = $this->db->getPrefix() . 'dpcalendar_coupons';
		$q = $this->db->getQuery(true)
			->select(['id', 'title', 'code', 'value', 'type', 'area', 'limit', 'state', 'access', 'ordering', 'publish_up', 'publish_down'])
			->from($this->db->quoteName($t))
			->order($this->db->quoteName('ordering') . ' ASC');

		$state = $this->normaliseContentStateFilter($arguments['state'] ?? null);
		if ($state !== null) $q->where($this->db->quoteName('state') . ' = ' . $state);
		if (!empty($arguments['type'])) $q->where($this->db->quoteName('type') . ' = ' . $this->db->quote((string) $arguments['type']));
		if (!empty($arguments['search'])) {
			$s = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $arguments['search']) . '%';
			$qs = $this->db->quote($s);
			$q->where('(' . $this->db->quoteName('title') . ' LIKE ' . $qs . ' OR ' . $this->db->quoteName('code') . ' LIKE ' . $qs . ')');
		}

		$limit  = max(1, min(200, (int) ($arguments['limit'] ?? 50)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));
		$this->db->setQuery($q, $offset, $limit);
		$rows = $this->db->loadAssocList() ?: [];
		foreach ($rows as &$r) {
			foreach (['id', 'value', 'area', 'limit', 'state', 'access', 'ordering'] as $k) {
				if (array_key_exists($k, $r)) $r[$k] = $r[$k] === null ? null : (int) $r[$k];
			}
			$r['state_label'] = $this->contentStateLabel((int) $r['state']);
		}
		unset($r);
		$total = (int) $this->db->setQuery($this->db->getQuery(true)->select('COUNT(*)')->from($this->db->quoteName($t)))->loadResult();
		return ToolResult::json(['ok' => true, 'count' => count($rows), 'limit' => $limit, 'offset' => $offset,
			'total_unfiltered' => $total, 'coupons' => $rows]);
	}
}
