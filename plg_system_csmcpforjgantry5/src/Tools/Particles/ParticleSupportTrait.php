<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Particles;

\defined('_JEXEC') or die;

/**
 * One particle instance as it renders. Needs Gantry5BootTrait.
 *
 * ThemeTrait::getContent() (Component/Theme/ThemeTrait.php:743-770) renders an
 * instance as follows: if the outline's particle defaults say
 * particles.<type>.enabled is off, nothing renders at all; otherwise the
 * instance's attributes are joined over the outline's particle defaults
 * (Config::getJoined(), the same blueprint-aware merge as the config layers).
 * So an instance setting always wins, and anything it does not set comes from
 * the outline, then the Base Outline, then the theme, then the blueprint.
 */
trait ParticleSupportTrait
{
	/**
	 * @return array<string,mixed>
	 */
	protected function particleView(array $theme, array $outline, string $id): array
	{
		$folder   = $this->g5OutlineFolder($theme, $outline)['folder'];
		$resolved = $this->g5ResolvedLayout($theme, $folder);
		$items    = array_column($this->g5LayoutItems($resolved['nodes']), null, 'id');

		if (!isset($items[$id])) {
			throw new \InvalidArgumentException(
				'Outline ' . $outline['id'] . ' has no particle "' . $id . '". Its items: ' . implode(', ', array_keys($items))
				. '. get_gantry5_layout shows where each one sits.'
			);
		}

		$item = $items[$id];
		$type = (string) $item['subtype'];

		$blueprint = null;
		$defaults  = null;
		$note      = null;

		try {
			$blueprint = $this->g5ParticleBlueprint($theme, $type);
			$defaults  = $this->g5ParticleDefaults($theme, $outline, $type, $blueprint);
		} catch (\InvalidArgumentException $e) {
			$note = 'No blueprint for "' . $type . '": Gantry cannot render this item, and its settings cannot be validated. ' . $e->getMessage();
		}

		// Where the instance's own attributes physically live.
		if (!empty($item['inherited_from'])) {
			$from      = (string) $item['inherited_from']['outline'];
			$fromId    = (string) $item['inherited_from']['id'];
			$fromFile  = null;

			try {
				$fromFile = $this->g5Rel($this->g5LayoutSource($theme, $from)['file']);
			} catch (\Throwable $e) {
				// Reported through the layout's problems.
			}

			$instanceSource = ['source' => 'inherited_instance', 'outline' => $from, 'particle' => $fromId, 'file' => $fromFile];
		} else {
			$instanceSource = ['source' => 'instance', 'outline' => $folder, 'particle' => $id, 'file' => $this->g5Rel($resolved['source']['file'])];
		}

		$stored = $item['attributes'];

		$fields   = $blueprint['fields'] ?? [];
		$nested   = $this->g5NestedPaths($fields);
		$layers   = $defaults['layers'] ?? [];
		$layers[] = ['source' => $instanceSource['source'], 'outline' => $instanceSource['outline'], 'file' => $instanceSource['file'], 'particle' => $instanceSource['particle'], 'data' => $stored, 'error' => null];
		$merged   = $this->g5MergeLayers($layers, $nested);

		$typeEnabled = $defaults !== null ? $this->g5GetPath($defaults['data'], ['enabled']) : 1;
		$typeSource  = $defaults !== null ? $this->g5SourceOf($defaults['sources'], 'enabled') : null;
		$typeOn      = $typeEnabled === null || (bool) $typeEnabled && (string) $typeEnabled !== '0';

		$reasons = [];

		if (!$item['enabled']) {
			$reasons[] = 'this instance is disabled (attributes.enabled = 0)';
		}

		if (!$typeOn) {
			$reasons[] = 'the "' . $type . '" particle is disabled for the whole outline (particles.' . $type . '.enabled = 0, from '
				. ($typeSource['source'] ?? '?') . ($typeSource['file'] ?? null ? ' ' . $typeSource['file'] : '') . ')';
		}

		if ($blueprint === null) {
			$reasons[] = 'no blueprint for "' . $type . '"';
		}

		if (!empty($item['inherit_broken'])) {
			$reasons[] = 'its inheritance points at ' . $item['inherit_broken']['outline'] . '/' . $item['inherit_broken']['id'] . ', which is gone, so it renders with no settings of its own';
		}

		$describe = $blueprint !== null ? $this->g5DescribeSettings($fields, $merged['data'], $merged['sources']) : ['settings' => [], 'unknown_keys' => []];

		return [
			'item'            => $item,
			'type'            => $type,
			'folder'          => $folder,
			'resolved'        => $resolved,
			'blueprint'       => $blueprint,
			'defaults'        => $defaults,
			'instance_source' => $instanceSource,
			'stored'          => $stored,
			'effective'       => $merged['data'],
			'settings'        => $describe['settings'],
			'unknown_keys'    => $describe['unknown_keys'],
			'renders'         => $reasons === [],
			'not_rendering_because' => $reasons,
			'note'            => $note,
		];
	}

	/** A short blueprint field list for responses. */
	protected function particleFieldList(array $fields): array
	{
		$out = [];

		foreach ($fields as $key => $f) {
			$out[$key] = array_filter([
				'type'        => $f['type'],
				'label'       => $f['label'],
				'default'     => $f['default'],
				'options'     => $f['options'] !== null ? array_map('strval', array_keys($f['options'])) : null,
				'pattern'     => $f['pattern'],
				'overridable' => $f['overridable'] ? null : false,
				'item_fields' => isset($f['item_fields']) ? array_keys($f['item_fields']) : null,
			], static fn ($v) => $v !== null);
		}

		return $out;
	}
}
