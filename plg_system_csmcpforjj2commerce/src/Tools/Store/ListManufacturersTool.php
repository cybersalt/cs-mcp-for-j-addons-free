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

final class ListManufacturersTool extends AbstractTool
{
	use J2commerceBootTrait;

	public function getName(): string { return 'list_j2commerce_manufacturers'; }

	public function getDescription(): string
	{
		return 'List J2Commerce manufacturers (brands) with id, name, enabled state, location and how many '
			. 'products use each. A manufacturer has no name column of its own: J2Commerce stores the name as '
			. 'the COMPANY on an address row (#__j2commerce_addresses, via manufacturers.address_id), so a '
			. 'manufacturer whose address row is missing has no name and is flagged. Products reference '
			. 'manufacturer_id; coupons can be restricted to manufacturers (brand_ids). Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => ['search' => ['type' => 'string']],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->j2Installed()) {
			return $this->j2NotInstalledError();
		}

		$this->j2RequireAccess($actor, 'j2commerce.viewproducts');

		$db    = $this->db;
		$where = '';

		if (($s = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$where = ' WHERE a.company LIKE ' . $this->j2Like($s);
		}

		$rows = $db->setQuery(
			'SELECT m.j2commerce_manufacturer_id, m.enabled, m.address_id, a.j2commerce_address_id AS addr, a.company, a.city, c.country_name,'
			. ' (SELECT COUNT(*) FROM ' . $db->quoteName($this->j2Table('products')) . ' p WHERE p.manufacturer_id = m.j2commerce_manufacturer_id) AS products'
			. ' FROM ' . $db->quoteName($this->j2Table('manufacturers')) . ' m'
			. ' LEFT JOIN ' . $db->quoteName($this->j2Table('addresses')) . ' a ON a.j2commerce_address_id = m.address_id'
			. ' LEFT JOIN ' . $db->quoteName($this->j2Table('countries')) . ' c ON c.j2commerce_country_id = a.country_id'
			. $where . ' ORDER BY a.company'
		)->loadAssocList() ?: [];

		$out = array_map(static function (array $r): array {
			$entry = [
				'manufacturer_id' => (int) $r['j2commerce_manufacturer_id'],
				'name'            => $r['company'],
				'enabled'         => (int) $r['enabled'] === 1,
				'city'            => $r['city'],
				'country'         => $r['country_name'],
				'products'        => (int) $r['products'],
			];

			if ($r['addr'] === null) {
				$entry['warning'] = 'Its address row (' . (int) $r['address_id'] . ') is missing, so it has no name.';
			}

			return $entry;
		}, $rows);

		return ToolResult::json(['ok' => true, 'count' => \count($out), 'manufacturers' => $out]);
	}
}
