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

final class GetParticleTool extends AbstractTool
{
	use Gantry5BootTrait;
	use ParticleSupportTrait;

	public function getName(): string { return 'get_gantry5_particle'; }

	public function getDescription(): string
	{
		return 'One particle (or module position / module instance) in an outline\'s layout, with its EFFECTIVE settings and where '
			. 'each value comes from: this instance, an instance inherited from another outline, the outline\'s particle defaults, the '
			. 'Base Outline, the theme\'s shipped config, or the blueprint default. Also says whether it renders and why not '
			. '(instance disabled, particle type disabled for the outline, broken inheritance), whether it can be edited here or '
			. 'only in the outline it inherits from, and lists the blueprint\'s fields. Ids come from get_gantry5_layout.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'theme'   => $this->g5ThemeProp(),
				'outline' => $this->g5OutlineProp(),
				'id'      => ['type' => 'string', 'description' => 'Particle id, e.g. "logo-9608" or "position-position-4734".'],
			],
			'required'             => ['outline', 'id'],
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
		$id      = $this->requireString($arguments, 'id');
		$view    = $this->particleView($theme, $outline, $id);
		$item    = $view['item'];

		$editable = empty($item['inherited_from']) && !empty($item['id_is_stable']);

		return ToolResult::json([
			'theme'          => $theme['element'],
			'outline'        => $outline['id'],
			'outline_title'  => $outline['title'],
			'config_folder'  => $view['folder'],
			'id'             => $id,
			'type'           => $item['type'],
			'particle_type'  => $view['type'],
			'title'          => $item['title'],
			'section'        => $item['section'],
			'section_path'   => $item['section_path'],
			'size'           => $item['size'],
			'block'          => $this->g5Map($item['block']),
			'renders'        => $view['renders'],
			'not_rendering_because' => $view['not_rendering_because'],
			'instance_settings_live_in' => $view['instance_source'],
			'editable_here'  => $editable,
			'edit_where'     => $editable ? null : (!empty($item['inherited_from'])
				? 'Inherited: update_gantry5_particle {"outline": "' . $item['inherited_from']['outline'] . '", "id": "' . $item['inherited_from']['id'] . '"} changes it there and on every outline that inherits it. To give this outline its own copy, break the inheritance in Gantry\'s Layout editor.'
				: 'This id is not stable (Gantry regenerates it on every load), so it cannot be addressed. Save the layout once in Gantry\'s admin to give it a fixed id.'),
			'stored_attributes' => $view['stored'],
			'settings'       => $view['settings'],
			'unknown_keys'   => $view['unknown_keys'],
			'blueprint'      => $view['blueprint'] !== null ? [
				'file'           => $view['blueprint']['file'],
				'label'          => $view['blueprint']['label'],
				'has_directives' => $view['blueprint']['has_directives'],
				'fields'         => $this->particleFieldList($view['blueprint']['fields']),
			] : null,
			'note'           => $view['note'],
		]);
	}
}
