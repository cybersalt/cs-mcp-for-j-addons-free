<?php
/**
 * @package     MCP for FlexBanners
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Modules;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Locations\LocationSupportTrait;
use Joomla\CMS\User\User;

/**
 * "Add another row of ads" in one call: a new location, a mod_flexbanners
 * module cloned from an existing one and pointed at it, and optionally the
 * banners moved into it. This is the job that was done by hand-written SQL on
 * a client site before this add-on existed.
 */
final class CreateLocationModuleTool extends AbstractTool
{
	use FlexbannersBootTrait;
	use LocationSupportTrait;
	use ModuleSupportTrait;

	public function getName(): string { return 'create_flexbanners_location_module'; }

	public function getDescription(): string
	{
		return 'Add another row (group) of FlexBanners ads in ONE call: creates a new location, clones an existing '
			. 'mod_flexbanners module through Joomla\'s own module model with locationid pointed at the new location '
			. '(same position, the next ordering in that position so it stacks underneath, same params, access, '
			. 'language and menu assignment), and optionally moves banners into the new location. Every '
			. 'mod_flexbanners module shows exactly one location, so this is how a site gets several independent '
			. 'rows. source_module_id defaults to the only mod_flexbanners module if there is exactly one. Module '
			. 'params can be overridden for the clone (number_of_banners, banner_order, ...). The response lists '
			. 'which banners the new module will show.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'location_name'    => ['type' => 'string', 'description' => 'Name of the new location, e.g. "Group 2".'],
				'source_module_id' => ['type' => 'integer', 'description' => 'mod_flexbanners module to clone. Optional when the site has exactly one.'],
				'title'            => ['type' => 'string', 'description' => 'New module title. Default: the location name.'],
				'position'         => ['type' => 'string', 'description' => 'Default: the source module\'s position.'],
				'ordering'         => ['type' => 'integer', 'description' => 'Default: after the last module in that position.'],
				'published'        => ['type' => 'boolean', 'description' => 'Default true.'],
				'banner_ids'       => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Banners to move into the new location.'],
			] + $this->moduleParamSchema(),
			'required'             => ['location_name'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->fbInstalled()) {
			return $this->fbNotInstalledError();
		}

		$this->fbRequireAccess($actor, 'core.create');
		$this->moduleRequireWrite($actor, true);

		$name = trim((string) ($arguments['location_name'] ?? ''));
		$this->locationRequireName(['name' => $name]);
		$this->locationRequireUniqueName($name);

		$modules = $this->fbModules();

		if (isset($arguments['source_module_id'])) {
			$source = $this->moduleRequireFlexbanners((int) $arguments['source_module_id']);
		} else {
			$live = array_values(array_filter($modules, static fn (array $m): bool => $m['published'] >= 0));

			if (\count($live) !== 1) {
				return ToolResult::json([
					'ok'      => false,
					'error'   => 'source_module_id is required: this site has ' . \count($live) . ' mod_flexbanners modules, so there is no single one to clone.',
					'modules' => array_map(static fn (array $m): array => ['id' => $m['id'], 'title' => $m['title'], 'position' => $m['position'], 'published' => $m['published'], 'locationid' => $m['locationid']], array_values($modules)),
				], true);
			}

			$source = $live[0];
		}

		$position = trim((string) ($arguments['position'] ?? $source['position']));

		if ($position === '') {
			throw new \InvalidArgumentException('The source module has no position and none was given, so the new module would render nowhere. Pass position.');
		}

		$bannerIds = array_values(array_unique(array_filter(array_map('intval', (array) ($arguments['banner_ids'] ?? [])))));

		if ($bannerIds !== []) {
			$this->fbRequireAccess($actor, 'core.edit');

			$found = array_map('intval', $this->db->setQuery(
				'SELECT id FROM ' . $this->db->quoteName($this->fbTable('')) . ' WHERE id IN (' . implode(',', $bannerIds) . ')'
			)->loadColumn() ?: []);

			if (($missing = array_values(array_diff($bannerIds, $found))) !== []) {
				throw new \InvalidArgumentException('banner_ids not found: ' . implode(', ', $missing) . '. Nothing was created.');
			}
		}

		$overrides = $this->moduleParamsFromArgs($arguments);
		$title     = trim((string) ($arguments['title'] ?? '')) ?: $name;
		$published = \array_key_exists('published', $arguments) && empty($arguments['published']) ? 0 : 1;
		$ordering  = isset($arguments['ordering']) ? (int) $arguments['ordering'] : null;

		$locationId = $this->locationInsert($name, 1);
		$overrides['locationid'] = (string) $locationId;

		try {
			$clone = $this->moduleClone($source, $title, $position, $overrides, $published, $ordering);
		} catch (\Throwable $e) {
			// Do not leave a location behind for a module that was never made.
			$this->db->setQuery('DELETE FROM ' . $this->db->quoteName($this->fbTable('locations')) . ' WHERE locationid = ' . $locationId)->execute();

			throw $e;
		}

		$moved = [];

		foreach ($bannerIds as $bid) {
			$this->db->setQuery(
				'UPDATE ' . $this->db->quoteName($this->fbTable('')) . ' SET locationid = ' . $locationId
				. ', modified = ' . $this->db->quote($this->fbNow()) . ', modified_by = ' . (int) $actor->id
				. ' WHERE id = ' . $bid
			)->execute();
			$moved[] = $bid;
		}

		$after = $this->fbModules();
		$new   = $after[$clone['id']] ?? null;

		if ($new === null) {
			throw new \RuntimeException('com_modules reported module ' . $clone['id'] . ' but it cannot be read back as a mod_flexbanners module.');
		}

		$checks = [
			'locationid_set'      => $new['locationid'] === $locationId,
			'same_position'       => $new['position'] === $position,
			'menu_assignment_copied' => $this->sameAssignment($source['menu_ids'], $new['menu_ids']),
		];

		// What the new module will show.
		$sizes      = $this->fbLookup('size', 'sizeid');
		$links      = $this->fbLookup('link', 'linkid');
		$rows       = $this->db->setQuery('SELECT * FROM ' . $this->db->quoteName($this->fbTable('')) . ' WHERE locationid = ' . $locationId . ' ORDER BY id')->loadAssocList() ?: [];
		$categories = $this->fbCategories(array_column($rows, 'catid'));
		$enforces   = $this->fbModuleEnforcesLimits();
		$banners    = [];

		foreach ($rows as $r) {
			$audit     = $this->fbDisplayAudit($r, $sizes, $categories, $links, $after, $enforces);
			$banners[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'displays' => $audit['problems'] === [], 'why_not' => $audit['problems'][0] ?? null];
		}

		$response = [
			'ok'              => !\in_array(false, $checks, true),
			'location'        => ['id' => $locationId, 'name' => $name],
			'module'          => $this->moduleShape($new),
			'cloned_from'     => ['id' => $source['id'], 'title' => $source['title'], 'locationid' => $source['locationid']],
			'checks'          => $checks,
			'banners_moved'   => $moved,
			'banners_in_location' => $banners,
		];

		if ($banners === []) {
			$response['note'] = 'The new location has no banners yet, so the module renders nothing. Assign banners with update_flexbanner {"location_id": ' . $locationId . '} or create_flexbanner.';
		}

		if ($clone['warning'] !== null) {
			$response['post_save_warning'] = $clone['warning'];
		}

		return ToolResult::json($response);
	}

	private function sameAssignment(array $a, array $b): bool
	{
		sort($a);
		sort($b);

		return $a === $b;
	}
}
