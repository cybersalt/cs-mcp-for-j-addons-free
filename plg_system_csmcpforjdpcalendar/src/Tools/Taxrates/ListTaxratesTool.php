<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Taxrates;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class ListTaxratesTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'list_dpcalendar_taxrates'; }

	public function getDescription(): string
	{
		return 'List DPCalendar tax rates (#__dpcalendar_taxrates). Filters: state '
			. '(1/0/2/-2 or friendly), search (title). Returns id, title, rate '
			. '(decimal — 0.20 = 20%), inclusive (0=tax added on top, 1=tax already '
			. 'included in ticket price), countries (JSON list of country ids scoped '
			. 'to; empty = worldwide), state, state_label.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'state'  => ['description' => 'published/unpublished/archived/trashed OR 1/0/2/-2'],
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

		$t = $this->db->getPrefix() . 'dpcalendar_taxrates';
		$q = $this->db->getQuery(true)
			->select(['id', 'title', 'rate', 'countries', 'inclusive', 'state', 'ordering', 'publish_up', 'publish_down'])
			->from($this->db->quoteName($t))
			->order($this->db->quoteName('ordering') . ' ASC');

		$state = $this->normaliseContentStateFilter($arguments['state'] ?? null);
		if ($state !== null) $q->where($this->db->quoteName('state') . ' = ' . $state);
		if (!empty($arguments['search'])) {
			$s = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $arguments['search']) . '%';
			$q->where($this->db->quoteName('title') . ' LIKE ' . $this->db->quote($s));
		}

		$limit  = max(1, min(200, (int) ($arguments['limit'] ?? 50)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));
		$this->db->setQuery($q, $offset, $limit);
		$rows = $this->db->loadAssocList() ?: [];
		foreach ($rows as &$r) {
			foreach (['id', 'inclusive', 'state', 'ordering'] as $k) if (array_key_exists($k, $r)) $r[$k] = (int) $r[$k];
			if (array_key_exists('rate', $r) && $r['rate'] !== null) $r['rate'] = (float) $r['rate'];
			$r['state_label'] = $this->contentStateLabel((int) $r['state']);
		}
		unset($r);
		$total = (int) $this->db->setQuery($this->db->getQuery(true)->select('COUNT(*)')->from($this->db->quoteName($t)))->loadResult();
		return ToolResult::json(['ok' => true, 'count' => count($rows), 'limit' => $limit, 'offset' => $offset,
			'total_unfiltered' => $total, 'taxrates' => $rows]);
	}
}
