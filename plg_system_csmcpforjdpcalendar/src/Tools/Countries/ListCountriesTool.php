<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Countries;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

/**
 * Reference lookup — the country list DPCalendar uses for location + booking
 * address forms and for tax-rate country matching.
 */
final class ListCountriesTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'list_dpcalendar_countries'; }

	public function getDescription(): string
	{
		return 'List DPCalendar country reference rows (#__dpcalendar_countries). '
			. 'Filters: search (matches name / short_code LIKE %term%). Returns id, '
			. 'short_code, name, currency. Used by location + booking forms; the '
			. 'short_code / id values are referenced by taxrate.countries and '
			. 'booking.country.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
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

		$t = $this->db->getPrefix() . 'dpcalendar_countries';
		$q = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName($t))
			->order($this->db->quoteName('name') . ' ASC');

		if (!empty($arguments['search'])) {
			$s = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $arguments['search']) . '%';
			$qs = $this->db->quote($s);
			$q->where('(' . $this->db->quoteName('name') . ' LIKE ' . $qs
				. ' OR ' . $this->db->quoteName('short_code') . ' LIKE ' . $qs . ')');
		}

		$limit  = max(1, min(500, (int) ($arguments['limit'] ?? 300)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));
		$this->db->setQuery($q, $offset, $limit);
		$rows = $this->db->loadAssocList() ?: [];
		foreach ($rows as &$r) {
			if (isset($r['id'])) $r['id'] = (int) $r['id'];
		}
		unset($r);

		$total = (int) $this->db->setQuery($this->db->getQuery(true)->select('COUNT(*)')->from($this->db->quoteName($t)))->loadResult();

		return ToolResult::json([
			'ok'               => true,
			'count'            => count($rows),
			'limit'            => $limit,
			'offset'           => $offset,
			'total_unfiltered' => $total,
			'countries'        => $rows,
		]);
	}
}
