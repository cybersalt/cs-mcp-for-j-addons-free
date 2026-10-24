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

final class ListParticleTypesTool extends AbstractTool
{
	use Gantry5BootTrait;
	use ParticleSupportTrait;

	public function getName(): string { return 'list_gantry5_particle_types'; }

	public function getDescription(): string
	{
		return 'The particle types a Gantry theme can use (the theme\'s own particles/ first, then the engine\'s), with each blueprint\'s '
			. 'label, kind (particle, position, atom, system) and number of settings. With `type`, the full field list of that '
			. 'blueprint: every setting key the update tools accept, with its field type, default, allowed options, pattern and '
			. 'whether it can be overridden per outline.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'theme' => $this->g5ThemeProp(),
				'type'  => ['type' => 'string', 'description' => 'Optional particle type to describe in full, e.g. "logo".'],
			],
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

		$theme = $this->g5RequireTheme($arguments);
		$type  = trim((string) ($arguments['type'] ?? ''));

		if ($type !== '') {
			$bp = $this->g5ParticleBlueprint($theme, $type);

			return ToolResult::json([
				'theme'          => $theme['element'],
				'type'           => $type,
				'label'          => $bp['label'],
				'description'    => $bp['description'],
				'kind'           => $bp['kind'],
				'hidden'         => $bp['hidden'],
				'file'           => $bp['file'],
				'has_directives' => $bp['has_directives'],
				'fields'         => $this->particleFieldList($bp['fields']),
				'note'           => $bp['kind'] === 'atom'
					? 'Atoms live in page/head.yaml (Page Settings > Atoms), not in the layout; these tools read but do not write them.'
					: null,
			]);
		}

		$out = [];

		foreach ($this->g5ParticleTypes($theme) as $name => $info) {
			try {
				$bp    = $this->g5ParticleBlueprint($theme, $name);
				$out[] = [
					'type'   => $name,
					'label'  => $bp['label'],
					'kind'   => $bp['kind'],
					'hidden' => $bp['hidden'],
					'fields' => \count($bp['fields']),
					'source' => str_contains($bp['file'], 'media/gantry5/engines/') ? 'engine' : 'theme',
					'file'   => $bp['file'],
				];
			} catch (\Throwable $e) {
				$out[] = ['type' => $name, 'error' => $e->getMessage(), 'file' => $this->g5Rel($info['file'])];
			}
		}

		return ToolResult::json(['theme' => $theme['element'], 'count' => \count($out), 'types' => $out]);
	}
}
