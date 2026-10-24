<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Layouts;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Gantry5BootTrait;
use Joomla\CMS\User\User;

/**
 * "Module settings per outline" in Gantry terms. Joomla modules reach a Gantry
 * page in two ways, both of them items in the outline's layout:
 *   - a Module Position (type position, subtype position): renders the Joomla
 *     position named by its `key`, with its `chrome`;
 *   - a Module Instance (type position, subtype module): renders ONE module by
 *     `module_id`, wherever that module is positioned
 *     (nucleus/particles/module.html.twig: gantry.platform.displayModule()).
 * Which of the position's modules appear on a page is then Joomla's menu
 * assignment. Both items are edited with update_gantry5_particle.
 */
final class ListOutlineModulesTool extends AbstractTool
{
	use Gantry5BootTrait;

	public function getName(): string { return 'list_gantry5_outline_modules'; }

	public function getDescription(): string
	{
		return 'Module settings per outline: every Module Position and Module Instance item in the outline\'s layout (id, position '
			. 'key or module id, chrome, section, enabled, inherited from), the Joomla modules each one renders, and whether each '
			. 'module is assigned to the pages that use this outline. Also lists positions that hold published modules but that '
			. 'this outline\'s layout never renders (a common "my module does not show" cause). Change a position\'s key or chrome, '
			. 'or a module instance\'s module_id, with update_gantry5_particle.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'theme'   => $this->g5ThemeProp(),
				'outline' => $this->g5OutlineProp(),
			],
			'required'             => ['outline'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->g5Installed()) {
			return $this->g5NotInstalledError();
		}

		$this->g5RequireAccess($actor);

		$theme   = $this->g5RequireTheme($arguments);
		$outline = $this->g5RequireOutline($theme, $arguments['outline'] ?? null);
		$folder  = $this->g5OutlineFolder($theme, $outline)['folder'];
		$items   = $this->g5LayoutItems($this->g5ResolvedLayout($theme, $folder)['nodes']);

		// Pages that use this outline.
		$pages   = [];
		$default = $this->g5DefaultStyle();
		$isSiteDefault = $outline['style'] !== null && $default !== null && $default['id'] === (int) $outline['id'];

		if ($outline['style'] !== null) {
			$pages = $this->g5MenuAssignments([(int) $outline['id']])[(int) $outline['id']] ?? [];
		}

		$positions = [];
		$instances = [];

		foreach ($items as $item) {
			if ($item['type'] !== 'position') {
				continue;
			}

			if ($item['subtype'] === 'module') {
				$instances[] = $item;
			} else {
				$positions[] = $item;
			}
		}

		$byPosition = $this->g5ModulesInPositions(array_map(static fn (array $p): string => (string) ($p['attributes']['key'] ?? ''), $positions));

		$posOut = [];

		foreach ($positions as $p) {
			$key     = (string) ($p['attributes']['key'] ?? '');
			$modules = [];

			foreach ($byPosition[$key] ?? [] as $m) {
				$modules[] = $m + ['on_this_outlines_pages' => $this->showsOn($m['menu_ids'], $pages, $isSiteDefault)];
			}

			$posOut[] = [
				'id'             => $p['id'],
				'title'          => $p['title'],
				'position_key'   => $key,
				'chrome'         => $p['attributes']['chrome'] ?? null,
				'section'        => $p['section'],
				'enabled'        => $p['enabled'],
				'inherited_from' => $p['inherited_from'],
				'modules'        => $modules,
			];
		}

		$instOut = [];
		$ids     = array_values(array_filter(array_map(static fn (array $i): int => (int) ($i['attributes']['module_id'] ?? 0), $instances)));
		$rows    = [];

		if ($ids !== []) {
			$found = $this->db->setQuery(
				$this->db->getQuery(true)
					->select(['id', 'title', 'module', 'position', 'published', 'ordering', 'access', 'language', 'showtitle', 'publish_up', 'publish_down', 'client_id'])
					->from($this->db->quoteName('#__modules'))
					->whereIn($this->db->quoteName('id'), $ids)
			)->loadAssocList('id') ?: [];
			$menus = $this->g5ModuleMenus(array_keys($found));

			foreach ($found as $id => $row) {
				$rows[(int) $id] = $this->g5ModuleShape($row, $menus[(int) $id] ?? []) + ['client_id' => (int) $row['client_id']];
			}
		}

		foreach ($instances as $i) {
			$mid    = (int) ($i['attributes']['module_id'] ?? 0);
			$module = $rows[$mid] ?? null;

			$instOut[] = [
				'id'             => $i['id'],
				'title'          => $i['title'],
				'module_id'      => $mid ?: null,
				'chrome'         => $i['attributes']['chrome'] ?? null,
				'section'        => $i['section'],
				'enabled'        => $i['enabled'],
				'inherited_from' => $i['inherited_from'],
				'module'         => $module !== null ? $module + ['on_this_outlines_pages' => $this->showsOn($module['menu_ids'], $pages, $isSiteDefault)] : null,
				'problem'        => $mid === 0 ? 'no module_id set' : ($module === null ? 'module ' . $mid . ' does not exist' : ($module['client_id'] !== 0 ? 'module ' . $mid . ' is an administrator module' : ($module['published'] !== 1 ? 'module ' . $mid . ' is not published' : null))),
			];
		}

		return ToolResult::json([
			'theme'            => $theme['element'],
			'outline'          => $outline['id'],
			'outline_title'    => $outline['title'],
			'pages_using_outline' => [
				'menu_items'   => $pages,
				'site_default' => $isSiteDefault,
				'note'         => $isSiteDefault
					? 'This is the site default style: it also renders every page whose menu item names no style.'
					: ($outline['style'] === null ? 'Built-in outline: used for ' . ($outline['id'] === 'default' ? 'nothing directly (it is the Base Outline others inherit from)' : $outline['title'] . ' pages') . '.' : null),
			],
			'module_positions' => $posOut,
			'module_instances' => $instOut,
			'positions_not_rendered' => $this->unrenderedPositions($theme, array_map(static fn (array $p): string => (string) $p['position_key'], $posOut)),
		]);
	}

	/** Whether a module with this menu assignment appears on the outline's pages. */
	private function showsOn(array $menuIds, array $pages, bool $isSiteDefault): array
	{
		$on  = [];
		$off = [];

		foreach ($pages as $page) {
			if ($this->g5ModuleShowsOn($menuIds, (int) $page['id'])) {
				$on[] = $page['id'];
			} else {
				$off[] = $page['id'];
			}
		}

		return [
			'assignment'       => $this->g5AssignmentMode($menuIds),
			'shown_on_items'   => $on,
			'hidden_on_items'  => $off,
			'other_pages'      => $isSiteDefault ? 'also every unassigned page the module\'s own menu assignment allows' : null,
		];
	}

	/**
	 * Published site modules sitting in one of the theme's declared positions
	 * that no Module Position item of this outline renders.
	 */
	private function unrenderedPositions(array $theme, array $rendered): array
	{
		$declared = [];
		$manifest = $theme['root'] . '/templateDetails.xml';

		if (is_file($manifest)) {
			$xml = @simplexml_load_file($manifest);

			if ($xml !== false && isset($xml->positions)) {
				foreach ($xml->positions->position as $p) {
					$declared[] = trim((string) $p);
				}
			}
		}

		if ($declared === []) {
			return [];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([$this->db->quoteName('position'), 'COUNT(*) AS ' . $this->db->quoteName('n')])
				->from($this->db->quoteName('#__modules'))
				->where($this->db->quoteName('client_id') . ' = 0')
				->where($this->db->quoteName('published') . ' = 1')
				->whereIn($this->db->quoteName('position'), $declared, \Joomla\Database\ParameterType::STRING)
				->group($this->db->quoteName('position'))
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $r) {
			if (!\in_array((string) $r['position'], $rendered, true)) {
				$out[] = ['position' => (string) $r['position'], 'published_modules' => (int) $r['n']];
			}
		}

		return $out;
	}
}
