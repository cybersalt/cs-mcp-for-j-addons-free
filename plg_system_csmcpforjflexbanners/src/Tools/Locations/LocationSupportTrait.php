<?php
/**
 * @package     MCP for FlexBanners
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Locations;

\defined('_JEXEC') or die;

/**
 * Location rows and their usage. Needs FlexbannersBootTrait alongside it.
 */
trait LocationSupportTrait
{
	protected function locationRequireName(array $args): string
	{
		$name = trim((string) ($args['name'] ?? ''));

		if ($name === '') {
			throw new \InvalidArgumentException('name is required (LocationTable::check() refuses an empty name).');
		}

		$this->fbRequireFits('name', $name);

		return $name;
	}

	/** Refuse a name another location already has: the admin's location dropdowns show names only. */
	protected function locationRequireUniqueName(string $name, int $exceptId = 0): void
	{
		$dupe = (int) $this->db->setQuery(
			'SELECT locationid FROM ' . $this->db->quoteName($this->fbTable('locations'))
			. ' WHERE name = ' . $this->db->quote($name) . ' AND locationid <> ' . $exceptId,
			0,
			1
		)->loadResult();

		if ($dupe > 0) {
			throw new \InvalidArgumentException('Location ' . $dupe . ' is already called "' . $name . '". Location pickers in the FlexBanners admin and module settings show names only, so two identical names cannot be told apart.');
		}
	}

	/** @return array{banners:array<string,int>,modules:array<int,array{id:int,title:string,published:int,position:string}>} */
	protected function locationUsage(int $locationId): array
	{
		$rows = $this->db->setQuery(
			'SELECT state, COUNT(*) AS n FROM ' . $this->db->quoteName($this->fbTable(''))
			. ' WHERE locationid = ' . $locationId . ' GROUP BY state'
		)->loadAssocList() ?: [];

		$banners = [];

		foreach ($rows as $r) {
			$banners[$this->fbStateLabel((int) $r['state'])] = (int) $r['n'];
		}

		$modules = [];

		foreach ($this->fbModules() as $m) {
			if ($m['locationid'] === $locationId) {
				$modules[] = ['id' => $m['id'], 'title' => $m['title'], 'published' => $m['published'], 'position' => $m['position']];
			}
		}

		return ['banners' => $banners, 'modules' => $modules];
	}

	protected function locationInsert(string $name, int $state = 1): int
	{
		$row = (object) ['name' => $name, 'state' => $state];
		$this->db->insertObject($this->fbTable('locations'), $row, 'locationid');

		return (int) $row->locationid;
	}
}
