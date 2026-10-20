<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Store;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

final class ListCountriesTool extends AbstractTool
{
	use J2commerceBootTrait;

	public function getName(): string { return 'list_j2commerce_countries'; }

	public function getDescription(): string
	{
		return 'List J2Commerce countries with their numeric country_id (what store, address, geozone and tax '
			. 'settings store — not the ISO code), ISO-2 and ISO-3 codes, enabled state, number of zones '
			. '(states/provinces), and which geozones include the country. A DISABLED country cannot be chosen '
			. 'at checkout, so shoppers there cannot buy. Pass country_id (or an ISO code as search) and '
			. 'with_zones: true to get its zones and their zone_ids. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'search'       => ['type' => 'string', 'description' => 'Name, ISO-2 or ISO-3.'],
				'country_id'   => ['type' => 'integer'],
				'enabled_only' => ['type' => 'boolean'],
				'with_zones'   => ['type' => 'boolean', 'description' => 'Include each matching country\'s zones (best with country_id).'],
				'limit'        => ['type' => 'integer', 'description' => 'Default 50, max 300.'],
				'offset'       => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->j2Installed()) {
			return $this->j2NotInstalledError();
		}

		$this->j2RequireAccess($actor, 'j2commerce.viewsetup');

		$db    = $this->db;
		$where = [];

		if (!empty($arguments['country_id'])) {
			$where[] = 'c.j2commerce_country_id = ' . (int) $arguments['country_id'];
		}

		if (($s = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$where[] = '(c.country_name LIKE ' . $this->j2Like($s) . ' OR c.country_isocode_2 = ' . $db->quote(strtoupper($s))
				. ' OR c.country_isocode_3 = ' . $db->quote(strtoupper($s)) . ')';
		}

		if ((bool) ($arguments['enabled_only'] ?? false)) {
			$where[] = 'c.enabled = 1';
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
		$limit    = $this->j2Limit($arguments, 50, 300);
		$offset   = $this->j2Offset($arguments);
		$total    = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName($this->j2Table('countries')) . ' c' . $whereSql)->loadResult();

		$rows = $db->setQuery(
			'SELECT c.*, (SELECT COUNT(*) FROM ' . $db->quoteName($this->j2Table('zones')) . ' z WHERE z.country_id = c.j2commerce_country_id) AS zone_count'
			. ' FROM ' . $db->quoteName($this->j2Table('countries')) . ' c' . $whereSql . ' ORDER BY c.ordering, c.country_name',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$ids  = array_map(static fn (array $r): int => (int) $r['j2commerce_country_id'], $rows);
		$geos = [];

		if ($ids !== []) {
			foreach ($db->setQuery(
				'SELECT DISTINCT r.country_id, g.j2commerce_geozone_id, g.geozone_name FROM ' . $db->quoteName($this->j2Table('geozonerules')) . ' r'
				. ' JOIN ' . $db->quoteName($this->j2Table('geozones')) . ' g ON g.j2commerce_geozone_id = r.geozone_id'
				. ' WHERE r.country_id IN (' . implode(',', $ids) . ')'
			)->loadAssocList() ?: [] as $g) {
				$geos[(int) $g['country_id']][] = ['geozone_id' => (int) $g['j2commerce_geozone_id'], 'name' => (string) $g['geozone_name']];
			}
		}

		$withZones = (bool) ($arguments['with_zones'] ?? false);
		$store     = (int) ($this->j2RawParams()['country_id'] ?? 0);

		$out = array_map(function (array $r) use ($geos, $withZones, $db, $store): array {
			$id    = (int) $r['j2commerce_country_id'];
			$entry = [
				'country_id' => $id,
				'name'       => (string) $r['country_name'],
				'iso2'       => (string) $r['country_isocode_2'],
				'iso3'       => (string) $r['country_isocode_3'],
				'enabled'    => (int) $r['enabled'] === 1,
				'zone_count' => (int) $r['zone_count'],
				'geozones'   => $geos[$id] ?? [],
				'is_store_country' => $id === $store,
			];

			if ($withZones) {
				$entry['zones'] = array_map(static fn (array $z): array => [
					'zone_id' => (int) $z['j2commerce_zone_id'],
					'code'    => (string) $z['zone_code'],
					'name'    => (string) $z['zone_name'],
					'enabled' => (int) $z['enabled'] === 1,
				], $db->setQuery(
					'SELECT * FROM ' . $db->quoteName($this->j2Table('zones')) . ' WHERE country_id = ' . $id . ' ORDER BY zone_name'
				)->loadAssocList() ?: []);
			}

			return $entry;
		}, $rows);

		return ToolResult::json([
			'ok'        => true,
			'total'     => $total,
			'limit'     => $limit,
			'offset'    => $offset,
			'showing'   => \count($out),
			'countries' => $out,
		]);
	}
}
