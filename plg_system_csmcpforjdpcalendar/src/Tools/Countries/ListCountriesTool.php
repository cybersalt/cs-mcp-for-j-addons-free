<?php
/**
 * @package     MCP for DPCalendar
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Countries;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\Factory;
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
		$this->dpRequireAccess($actor);

		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		// The table holds only the ISO code; DPCalendar takes the name from its language file
		// (COM_DPCALENDAR_COUNTRY_<code>, DpcountriesField), so names are added and searched here.
		$lang = Factory::getApplication()->getLanguage();
		$lang->load('com_dpcalendar.countries', JPATH_ADMINISTRATOR . '/components/com_dpcalendar');

		$t   = $this->db->getPrefix() . 'dpcalendar_countries';
		$all = $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName(['id', 'short_code', 'state']))
				->from($this->db->quoteName($t))
		)->loadAssocList() ?: [];

		foreach ($all as &$r) {
			$r['id']    = (int) $r['id'];
			$r['state'] = (int) $r['state'];
			$key        = 'COM_DPCALENDAR_COUNTRY_' . $r['short_code'];
			$r['name']  = $lang->hasKey($key) ? $lang->_($key) : $r['short_code'];
		}
		unset($r);

		if (!empty($arguments['search'])) {
			$needle = mb_strtolower(trim((string) $arguments['search']));
			$all    = array_values(array_filter($all, fn ($r) => str_contains(mb_strtolower($r['name']), $needle)
				|| mb_strtolower($r['short_code']) === $needle));
		}

		usort($all, fn ($a, $b) => strcmp($a['name'], $b['name']));

		$limit  = max(1, min(500, (int) ($arguments['limit'] ?? 300)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));
		$rows   = \array_slice($all, $offset, $limit);

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
