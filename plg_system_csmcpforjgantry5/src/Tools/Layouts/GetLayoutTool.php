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

final class GetLayoutTool extends AbstractTool
{
	use Gantry5BootTrait;

	public function getName(): string { return 'get_gantry5_layout'; }

	public function getDescription(): string
	{
		return 'The layout of an outline as Gantry renders it: sections (with their attributes) and, inside them, every particle, '
			. 'module position and module instance with its id, type, block size and enabled state. Inheritance is resolved: '
			. 'sections and particles taken from another outline (usually the Base Outline) are shown with "inherited_from". Also '
			. 'says which file the layout comes from (the outline\'s own layout.yaml, or a preset) and any broken inheritance. '
			. 'format "flat" lists every item with its section path and attributes instead of the tree.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'theme'   => $this->g5ThemeProp(),
				'outline' => $this->g5OutlineProp(),
				'format'  => ['type' => 'string', 'enum' => ['tree', 'flat'], 'description' => 'tree (default) or flat.'],
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

		$theme    = $this->g5RequireTheme($arguments);
		$outline  = $this->g5RequireOutline($theme, $arguments['outline'] ?? null);
		$folder   = $this->g5OutlineFolder($theme, $outline);
		$resolved = $this->g5ResolvedLayout($theme, $folder['folder']);
		$source   = $resolved['source'];
		$items    = $this->g5LayoutItems($resolved['nodes']);

		$inheritsFrom = [];

		foreach ($items as $item) {
			if (!empty($item['inherited_from'])) {
				$inheritsFrom[$item['inherited_from']['outline']] = ($inheritsFrom[$item['inherited_from']['outline']] ?? 0) + 1;
			}
		}

		$out = [
			'theme'          => $theme['element'],
			'outline'        => $outline['id'],
			'outline_title'  => $outline['title'],
			'config_folder'  => $folder['folder'],
			'config_folder_reason' => $folder['reason'],
			'layout_file'    => $this->g5Rel($source['file']),
			'layout_kind'    => $source['kind'] === 'preset' ? 'preset (this outline has no layout.yaml; the first change writes one)' : ($source['custom'] ? 'outline layout.yaml (custom)' : 'theme-shipped layout.yaml'),
			'preset'         => $source['preset'],
			'items_count'    => \count($items),
			'items_inherited_from' => $this->g5Map($inheritsFrom),
			'problems'       => $resolved['problems'],
		];

		if (($arguments['format'] ?? 'tree') === 'flat') {
			$out['items'] = array_map(fn (array $i): array => ['block' => $this->g5Map($i['block']), 'attributes' => $this->g5Map($i['attributes'])] + $i, $items);
		} else {
			$out['sections'] = $this->g5CompactTree($resolved['nodes']);
		}

		return ToolResult::json($out);
	}
}
