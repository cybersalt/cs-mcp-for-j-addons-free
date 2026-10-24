<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Styles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Gantry5BootTrait;
use Joomla\CMS\User\User;

final class GetStylesTool extends AbstractTool
{
	use Gantry5BootTrait;

	public function getName(): string { return 'get_gantry5_styles'; }

	public function getDescription(): string
	{
		return 'An outline\'s Styles (colours, fonts, section backgrounds, breakpoints: the theme\'s style groups), with each value\'s '
			. 'source: this outline\'s styles.yaml, the Base Outline, the theme\'s shipped config, or the blueprint default. Lists '
			. 'which keys this outline overrides, the style groups available, and the outline\'s compiled CSS files. Pass `group` to '
			. 'limit the output to one group, e.g. "accent".';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'theme'   => $this->g5ThemeProp(),
				'outline' => $this->g5OutlineProp(),
				'group'   => ['type' => 'string', 'description' => 'Optional style group, e.g. "accent", "base", "font".'],
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
		$styles  = $this->g5StyleSettings($theme, $outline);
		$bp      = $styles['blueprint'];
		$group   = trim((string) ($arguments['group'] ?? ''));

		if ($group !== '' && !isset($bp['groups'][$group])) {
			throw new \InvalidArgumentException('Style group "' . $group . '" is not defined by ' . $theme['element'] . '. Groups: ' . implode(', ', array_keys($bp['groups'])) . '.');
		}

		$fields = $group === '' ? $bp['fields'] : array_filter($bp['fields'], static fn (array $f): bool => $f['path'][0] === $group);
		$desc   = $this->g5DescribeSettings($fields, $styles['data'], $styles['sources']);

		// The blueprint layer has no single file for styles; name each group's.
		foreach ($desc['settings'] as $key => &$s) {
			if (($s['source']['source'] ?? null) === 'blueprint_default') {
				$s['source']['file'] = $bp['groups'][explode('.', $key)[0]]['file'] ?? null;
			}
		}

		unset($s);

		if ($group !== '') {
			$desc['unknown_keys'] = array_filter($desc['unknown_keys'], static fn ($k) => str_starts_with((string) $k, $group . '.'), ARRAY_FILTER_USE_KEY);
		}

		$own = [];

		foreach ($styles['layers'] as $layer) {
			if ($layer['source'] === ($folder === 'default' ? 'base_outline' : 'outline')) {
				$own = array_keys($this->g5FlattenData($layer['data']));
			}
		}

		$css = array_values(array_filter($this->g5CompiledCssFiles($theme), static fn (array $c): bool => $c['outline'] === $folder));

		return ToolResult::json([
			'theme'           => $theme['element'],
			'outline'         => $outline['id'],
			'outline_title'   => $outline['title'],
			'config_folder'   => $folder,
			'groups'          => array_values($bp['groups']),
			'layers'          => $this->g5LayerSummary($styles['layers']),
			'overridden_here' => $group === '' ? $own : array_values(array_filter($own, static fn ($k) => str_starts_with($k, $group . '.'))),
			'settings'        => $desc['settings'],
			'unknown_keys'    => $desc['unknown_keys'],
			'compiled_css'    => $css,
		]);
	}
}
