<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Particles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Gantry5BootTrait;
use Joomla\CMS\User\User;

final class GetParticleDefaultsTool extends AbstractTool
{
	use Gantry5BootTrait;

	public function getName(): string { return 'get_gantry5_particle_defaults'; }

	public function getDescription(): string
	{
		return 'An outline\'s defaults for one particle TYPE (Gantry admin: Particle Defaults tab), which every instance of that '
			. 'type on the outline\'s pages starts from. Each setting shows its value and source: this outline\'s override file, the '
			. 'Base Outline, the theme\'s shipped config, or the blueprint default, so you can see what is inherited from the Base '
			. 'Outline. Also lists the keys this outline overrides, keys stored but unknown to the blueprint, the instances of this '
			. 'type in the outline\'s layout, and whether the type is switched off for the outline (which hides every instance).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'theme'   => $this->g5ThemeProp(),
				'outline' => $this->g5OutlineProp(),
				'type'    => ['type' => 'string', 'description' => 'Particle type, e.g. "logo", "menu", "copyright", "position". list_gantry5_particle_types lists them.'],
			],
			'required'             => ['outline', 'type'],
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

		$theme     = $this->g5RequireTheme($arguments);
		$outline   = $this->g5RequireOutline($theme, $arguments['outline'] ?? null);
		$type      = $this->requireString($arguments, 'type');
		$blueprint = $this->g5ParticleBlueprint($theme, $type);
		$defaults  = $this->g5ParticleDefaults($theme, $outline, $type, $blueprint);
		$describe  = $this->g5DescribeSettings($blueprint['fields'], $defaults['data'], $defaults['sources']);
		$folder    = $this->g5OutlineFolder($theme, $outline)['folder'];

		$own = [];

		foreach ($defaults['layers'] as $layer) {
			if ($layer['source'] === ($folder === 'default' ? 'base_outline' : 'outline')) {
				$own = array_keys($this->g5FlattenData($layer['data']));
			}
		}

		$instances = [];

		try {
			foreach ($this->g5LayoutItems($this->g5ResolvedLayout($theme, $folder)['nodes']) as $item) {
				if ($item['subtype'] === $type) {
					$instances[] = ['id' => $item['id'], 'section' => $item['section'], 'enabled' => $item['enabled'], 'inherited_from' => $item['inherited_from']];
				}
			}
		} catch (\Throwable $e) {
			$instances = ['error' => $e->getMessage()];
		}

		$enabled = $describe['settings']['enabled']['value'] ?? '1';

		return ToolResult::json([
			'theme'            => $theme['element'],
			'outline'          => $outline['id'],
			'outline_title'    => $outline['title'],
			'config_folder'    => $folder,
			'particle_type'    => $type,
			'label'            => $blueprint['label'],
			'type_enabled_for_outline' => !($enabled === '0' || $enabled === 0 || $enabled === false),
			'lookup_order'     => 'blueprint default < theme config/default < Base Outline (custom/config/default) < theme config/<outline> < this outline (custom/config/<outline>); highest wins, key by key where the blueprint nests, whole value otherwise.',
			'layers'           => $this->g5LayerSummary($defaults['layers']),
			'overridden_here'  => $own,
			'settings'         => $describe['settings'],
			'unknown_keys'     => $describe['unknown_keys'],
			'instances_in_layout' => $instances,
			'blueprint_file'   => $blueprint['file'],
		]);
	}
}
