<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Outlines;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Gantry5BootTrait;
use Joomla\CMS\User\User;

/**
 * The same write Gantry's Assignments tab makes (Framework/Assignments.php
 * saveMenu(), lines 118-178): #__menu.template_style_id, skipping items another
 * user has checked out, refused when the template is disabled. Unlike that tab
 * it only touches the items named; it never clears the outline's other
 * assignments.
 */
final class SetOutlineAssignmentsTool extends AbstractTool
{
	use Gantry5BootTrait;

	public function getName(): string { return 'set_gantry5_outline_assignments'; }

	public function getDescription(): string
	{
		return 'Assign menu items (pages) to an outline, or take them off it. `assign` points each item at this outline\'s template '
			. 'style; `unassign` resets items currently on this outline to "no style" (they then use the site default). Only the '
			. 'items named change. Items checked out by another user are skipped and reported. Needs core.edit on com_menus and '
			. 'core.edit.state on com_templates, as Gantry\'s Assignments tab does.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'theme'    => $this->g5ThemeProp(),
				'outline'  => $this->g5OutlineProp(),
				'assign'   => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Menu item ids to assign to this outline.'],
				'unassign' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Menu item ids to take off this outline.'],
			],
			'required'             => ['outline'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->g5Installed()) {
			return $this->g5NotInstalledError();
		}

		$this->g5RequireAccess($actor, true);

		if (!$actor->authorise('core.edit', 'com_menus') || !$actor->authorise('core.edit.state', 'com_templates')) {
			throw new \RuntimeException('Assigning outlines needs core.edit on com_menus and core.edit.state on com_templates (Gantry: outline.assign).');
		}

		$theme   = $this->g5RequireTheme($arguments);
		$outline = $this->g5RequireOutline($theme, $arguments['outline'] ?? null);

		if ($outline['style'] === null) {
			throw new \InvalidArgumentException('Only template-style outlines can be assigned to pages; "' . $outline['id'] . '" is a built-in outline.');
		}

		if (!(int) $theme['enabled']) {
			throw new \RuntimeException('The ' . $theme['element'] . ' template is disabled; Joomla (and Gantry) refuse assignments to a disabled template.');
		}

		$assign   = array_values(array_unique(array_map('intval', (array) ($arguments['assign'] ?? []))));
		$unassign = array_values(array_unique(array_map('intval', (array) ($arguments['unassign'] ?? []))));

		if ($assign === [] && $unassign === []) {
			throw new \InvalidArgumentException('Nothing to change: pass assign and/or unassign.');
		}

		if (array_intersect($assign, $unassign) !== []) {
			throw new \InvalidArgumentException('An item cannot be in both assign and unassign.');
		}

		$styleId = (int) $outline['id'];
		$ids     = array_merge($assign, $unassign);
		$rows    = $this->db->setQuery(
			$this->db->getQuery(true)
				->select(['id', 'title', 'menutype', 'client_id', 'published', 'template_style_id', 'checked_out'])
				->from($this->db->quoteName('#__menu'))
				->whereIn($this->db->quoteName('id'), $ids)
		)->loadAssocList('id') ?: [];

		foreach ($ids as $id) {
			if (!isset($rows[$id]) || (int) $rows[$id]['client_id'] !== 0 || (int) $rows[$id]['published'] === -2 || $id === 1) {
				throw new \InvalidArgumentException('Menu item ' . $id . ' is not a site menu item (or is trashed). Nothing was changed.');
			}
		}

		$changes = [];
		$skipped = [];
		$me      = (int) $actor->id;

		foreach ($ids as $id) {
			$row     = $rows[$id];
			$checked = (int) ($row['checked_out'] ?? 0);

			if ($checked !== 0 && $checked !== $me) {
				$skipped[] = ['id' => $id, 'title' => (string) $row['title'], 'reason' => 'checked out by user ' . $checked];
				continue;
			}

			$before = (int) $row['template_style_id'];
			$after  = \in_array($id, $assign, true) ? $styleId : ($before === $styleId ? 0 : $before);

			if ($after === $before) {
				$changes[] = ['id' => $id, 'title' => (string) $row['title'], 'before' => $before, 'after' => $after, 'changed' => false,
					'note' => \in_array($id, $unassign, true) ? 'not assigned to this outline, left alone' : 'already assigned'];
				continue;
			}

			$this->db->setQuery(
				$this->db->getQuery(true)
					->update($this->db->quoteName('#__menu'))
					->set($this->db->quoteName('template_style_id') . ' = ' . $after)
					->where($this->db->quoteName('id') . ' = ' . $id)
			)->execute();

			$changes[] = ['id' => $id, 'title' => (string) $row['title'], 'menutype' => (string) $row['menutype'], 'before' => $before, 'after' => $after, 'changed' => true];
		}

		$cache = $this->g5ClearCache($theme);
		$now   = $this->g5MenuAssignments([$styleId]);

		return ToolResult::json([
			'ok'                  => true,
			'theme'               => $theme['element'],
			'outline'             => $outline['id'],
			'outline_title'       => $outline['title'],
			'changes'             => $changes,
			'skipped'             => $skipped,
			'menu_items_assigned_now' => $now[$styleId] ?? [],
			'cache'               => $cache,
		]);
	}
}
