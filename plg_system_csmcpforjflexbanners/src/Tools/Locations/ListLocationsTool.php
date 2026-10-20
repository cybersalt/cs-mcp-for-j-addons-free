<?php
/**
 * @package     MCP for FlexBanners
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Locations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

final class ListLocationsTool extends AbstractTool
{
	use FlexbannersBootTrait;
	use LocationSupportTrait;

	public function getName(): string { return 'list_flexbanners_locations'; }

	public function getDescription(): string
	{
		return 'List FlexBanners locations — the "groups" banners are assigned to. A mod_flexbanners module shows '
			. 'the banners of ONE location (its locationid param), so "a row of ads" is one location plus one '
			. 'module. For each location: banner counts by state, and the mod_flexbanners modules that show it '
			. '(id, title, position, published). The location row\'s own state is cosmetic — the module never reads '
			. 'it — but unpublished locations are hidden from the admin\'s location pickers.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->fbInstalled()) {
			return $this->fbNotInstalledError();
		}

		$this->fbRequireAccess($actor);

		$rows = $this->db->setQuery(
			'SELECT * FROM ' . $this->db->quoteName($this->fbTable('locations')) . ' ORDER BY locationid'
		)->loadAssocList() ?: [];

		$known = [];
		$out   = [];

		foreach ($rows as $row) {
			$id      = (int) $row['locationid'];
			$known[] = $id;
			$usage   = $this->locationUsage($id);

			$out[] = [
				'id'          => $id,
				'name'        => (string) $row['name'],
				'state'       => $this->fbStateLabel((int) $row['state']),
				'checked_out' => (int) ($row['checked_out'] ?? 0) ?: null,
				'banners'     => $usage['banners'],
				'modules'     => $usage['modules'],
			];
		}

		// Banners pointing at a location id with no row: they still show if a module has that id.
		$orphans = $this->db->setQuery(
			'SELECT locationid, COUNT(*) AS n FROM ' . $this->db->quoteName($this->fbTable(''))
			. ($known !== [] ? ' WHERE locationid NOT IN (' . implode(',', $known) . ')' : '')
			. ' GROUP BY locationid'
		)->loadAssocList() ?: [];

		$response = ['ok' => true, 'count' => \count($out), 'locations' => $out];

		if ($orphans !== []) {
			$response['banners_with_unknown_location'] = array_map(
				static fn (array $o): array => ['locationid' => (int) $o['locationid'], 'banners' => (int) $o['n']],
				$orphans
			);
		}

		return ToolResult::json($response);
	}
}
