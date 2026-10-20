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

final class ListGeozonesTool extends AbstractTool
{
	use J2commerceBootTrait;

	public function getName(): string { return 'list_j2commerce_geozones'; }

	public function getDescription(): string
	{
		return 'List J2Commerce geozones — the named groups of countries and zones that tax rates and shipping '
			. 'rates are attached to — with every rule (country, and zone or "whole country"), and what uses '
			. 'each geozone: tax rates and shipping rates. A geozone with NO rules matches no address, so any '
			. 'tax rate or shipping rate on it never applies; those are flagged. Note J2Commerce ignores a '
			. 'geozone\'s enabled flag when calculating tax. Read-only, deliberately: saving a geozone in '
			. 'J2Commerce deletes every rule and reinserts from the form with no transaction, and a partial save '
			. 'wipes them — edit geozones in the J2Commerce admin.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->j2Installed()) {
			return $this->j2NotInstalledError();
		}

		$this->j2RequireAccess($actor, 'j2commerce.viewsetup');

		$db = $this->db;

		$zones = $db->setQuery(
			'SELECT * FROM ' . $db->quoteName($this->j2Table('geozones')) . ' ORDER BY ordering, geozone_name'
		)->loadAssocList() ?: [];

		$rules = [];

		foreach ($db->setQuery(
			'SELECT r.geozone_id, r.country_id, r.zone_id, c.country_name, c.country_isocode_2, z.zone_name, z.zone_code'
			. ' FROM ' . $db->quoteName($this->j2Table('geozonerules')) . ' r'
			. ' LEFT JOIN ' . $db->quoteName($this->j2Table('countries')) . ' c ON c.j2commerce_country_id = r.country_id'
			. ' LEFT JOIN ' . $db->quoteName($this->j2Table('zones')) . ' z ON z.j2commerce_zone_id = r.zone_id'
		)->loadAssocList() ?: [] as $r) {
			$rules[(int) $r['geozone_id']][] = [
				'country_id' => (int) $r['country_id'],
				'country'    => $r['country_name'] ?? ('missing country ' . $r['country_id']),
				'iso2'       => $r['country_isocode_2'],
				'zone_id'    => (int) $r['zone_id'],
				'zone'       => (int) $r['zone_id'] === 0 ? 'whole country' : ($r['zone_name'] ?? ('missing zone ' . $r['zone_id'])),
			];
		}

		$taxRates = [];

		foreach ($db->setQuery(
			'SELECT j2commerce_taxrate_id, geozone_id, taxrate_name, tax_percent FROM ' . $db->quoteName($this->j2Table('taxrates'))
		)->loadAssocList() ?: [] as $t) {
			$taxRates[(int) $t['geozone_id']][] = ['taxrate_id' => (int) $t['j2commerce_taxrate_id'], 'name' => (string) $t['taxrate_name'], 'percent' => (float) $t['tax_percent']];
		}

		$shipRates = [];

		if ($this->j2TableExists('shippingrates')) {
			foreach ($db->setQuery(
				'SELECT geozone_id, COUNT(*) AS n FROM ' . $db->quoteName($this->j2Table('shippingrates')) . ' GROUP BY geozone_id'
			)->loadAssocList() ?: [] as $s) {
				$shipRates[(int) $s['geozone_id']] = (int) $s['n'];
			}
		}

		$out = [];

		foreach ($zones as $g) {
			$id    = (int) $g['j2commerce_geozone_id'];
			$entry = [
				'geozone_id'     => $id,
				'name'           => (string) $g['geozone_name'],
				'enabled'        => (int) $g['enabled'] === 1,
				'rules'          => $rules[$id] ?? [],
				'tax_rates'      => $taxRates[$id] ?? [],
				'shipping_rates' => $shipRates[$id] ?? 0,
			];

			if (empty($rules[$id])) {
				$entry['warning'] = 'No rules: this geozone matches no address'
					. (!empty($taxRates[$id]) || !empty($shipRates[$id]) ? ', so its tax and shipping rates NEVER apply.' : '.');
			}

			$out[] = $entry;
		}

		return ToolResult::json(['ok' => true, 'count' => \count($out), 'geozones' => $out]);
	}
}
