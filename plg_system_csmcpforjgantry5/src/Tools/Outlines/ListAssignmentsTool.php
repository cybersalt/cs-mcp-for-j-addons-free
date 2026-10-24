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
 * On Joomla, Gantry's outline assignment IS the menu item's template_style_id
 * (Framework/Assignments.php: saveMenu() writes #__menu.template_style_id, and
 * types() is only "menu" and "style"); a page whose menu item names no style
 * gets the site default style. There is nothing else to read.
 */
final class ListAssignmentsTool extends AbstractTool
{
	use Gantry5BootTrait;

	public function getName(): string { return 'list_gantry5_assignments'; }

	public function getDescription(): string
	{
		return 'Which pages use which outline: for every outline (template style) of the Gantry theme(s), the menu items assigned to '
			. 'it, plus the site default style (used by every page whose menu item names no style) and any per-language defaults. '
			. 'Also flags menu items pointing at a template style that no longer exists (they silently fall back to the default). '
			. 'Change assignments with set_gantry5_outline_assignments.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => ['theme' => ['type' => 'string', 'description' => 'Optional: one Gantry theme. Default: all of them.']],
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

		$themes = trim((string) ($arguments['theme'] ?? '')) !== '' ? [$this->g5RequireTheme($arguments)] : array_values($this->g5Themes());
		$out    = [];

		foreach ($themes as $theme) {
			$outlines = $this->g5Outlines($theme);
			$styleIds = array_map('intval', array_filter(array_keys($outlines), 'is_numeric'));
			$assigned = $this->g5MenuAssignments($styleIds);
			$rows     = [];

			foreach ($styleIds as $sid) {
				$rows[] = [
					'outline'    => (string) $sid,
					'title'      => $outlines[(string) $sid]['title'],
					'home'       => $outlines[(string) $sid]['style']['home'],
					'menu_items' => $assigned[$sid] ?? [],
				];
			}

			$out[] = ['theme' => $theme['element'], 'outlines' => $rows];
		}

		// Menu items naming a style id that does not exist.
		$dangling = $this->db->setQuery(
			$this->db->getQuery(true)
				->select(['m.id', 'm.title', 'm.menutype', 'm.template_style_id'])
				->from($this->db->quoteName('#__menu', 'm'))
				->join('LEFT', $this->db->quoteName('#__template_styles', 's') . ' ON s.id = m.template_style_id')
				->where('m.client_id = 0')
				->where('m.published <> -2')
				->where('m.template_style_id > 0')
				->where('s.id IS NULL')
		)->loadAssocList() ?: [];

		return ToolResult::json([
			'site_default_style' => $this->g5DefaultStyle(),
			'language_default_styles' => $this->g5LanguageDefaultStyles(),
			'rule'               => 'A page uses its menu item\'s template style; with none, the site default (or the language default on a multilingual site). Gantry outlines are template styles.',
			'themes'             => $out,
			'menu_items_with_missing_style' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'menutype' => (string) $r['menutype'], 'template_style_id' => (int) $r['template_style_id']], $dangling),
		]);
	}
}
