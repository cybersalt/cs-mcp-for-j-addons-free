<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Modules;

\defined('_JEXEC') or die;

/**
 * Gantry Particle modules (mod_gantry5_particle). Needs Gantry5BootTrait.
 *
 * The module keeps the whole particle in ONE module param, `particle`, a JSON
 * string: {"type": "particle", "particle": "<type>", "title": "...",
 * "options": {"particle": {<settings>}, "block": {<block settings>}}}
 * (Admin/Controller/Json/Particle.php validate(), lines 152-186;
 * modules/mod_gantry5_particle/helper.php render(), lines 104-134). On render
 * those settings are joined over the CURRENT PAGE's outline defaults for that
 * particle type, so the same module can look different on pages with
 * different outlines.
 */
trait ParticleModuleSupportTrait
{
	/** @return array<string,mixed>|null decoded particle, or null when the param is missing */
	protected function particleModuleData(array $params, ?string &$error = null): ?array
	{
		$error = null;
		$raw   = $params['particle'] ?? null;

		if ($raw === null || $raw === '') {
			return null;
		}

		$data = \is_array($raw) ? $raw : json_decode((string) $raw, true);

		if (!\is_array($data)) {
			$error = 'params.particle is not valid JSON';

			return null;
		}

		return $data;
	}

	protected function particleModuleShape(array $row, array $menuIds): array
	{
		$params = json_decode((string) $row['params'], true);
		$params = \is_array($params) ? $params : [];
		$error  = null;
		$data   = $this->particleModuleData($params, $error);

		return $this->g5ModuleShape($row, $menuIds) + [
			'particle_type'   => $data['particle'] ?? null,
			'particle_title'  => $data['title'] ?? null,
			'settings'        => \is_array($data['options']['particle'] ?? null) ? $data['options']['particle'] : [],
			'block'           => \is_array($data['options']['block'] ?? null) ? $data['options']['block'] : [],
			'module_class_sfx' => $params['moduleclass_sfx'] ?? null,
			'caching'         => $params['owncache'] ?? null,
			'problem'         => $error ?? ($data === null ? 'no particle chosen yet (params.particle is empty)' : null),
		];
	}

	/** The theme whose blueprints validate a particle module: the argument, the only Gantry theme, or the site default's. */
	protected function particleModuleTheme(array $arguments): array
	{
		if (trim((string) ($arguments['theme'] ?? '')) !== '' || \count($this->g5Themes()) <= 1) {
			return $this->g5RequireTheme($arguments);
		}

		$default = $this->g5DefaultStyle();
		$themes  = $this->g5Themes();

		if ($default !== null && isset($themes[$default['template']])) {
			return $themes[$default['template']];
		}

		throw new \InvalidArgumentException('theme is required: several Gantry themes are installed and the site default template is not one of them.');
	}
}
