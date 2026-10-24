<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjgantry5\Tools;

\defined('_JEXEC') or die;

/**
 * Effective outline configuration, with the source of every value. Used
 * through Gantry5BootTrait.
 *
 * ConfigServiceProvider::load() (Framework/Services/ConfigServiceProvider.php:110-155)
 * merges, lowest priority first:
 *
 *   1. the blueprint defaults (CompiledConfig::createObject() with
 *      $withDefaults, Component/Config/CompiledConfig.php:72-79);
 *   2. gantry-config://default, i.e. config/default/ in each theme path, from
 *      common/ up to custom/ (the Base Outline);
 *   3. gantry-config://<outline> the same way (skipped for the Base Outline).
 *
 * CompiledBase::loadFiles() walks the path list in reverse and Config::join()
 * merges each file with BlueprintSchema::mergeArrays(): key by key where the
 * blueprint nests, whole-value replacement everywhere else (collections and
 * unknown keys included). Every config key therefore comes from exactly one
 * layer, which these helpers report.
 */
trait Gantry5ConfigTrait
{
	/**
	 * The config folder an outline id uses: itself for the built-in outlines,
	 * g5StyleConfigFolder() for a template style.
	 *
	 * @return array{folder:string,reason:string,exists:bool}
	 */
	protected function g5OutlineFolder(array $theme, array $outline): array
	{
		if ($outline['style'] === null) {
			$folder = (string) $outline['id'];

			return ['folder' => $folder, 'reason' => 'built-in outline', 'exists' => $this->g5ConfigDirs($theme, $folder) !== []];
		}

		$resolved = $this->g5StyleConfigFolder($theme, $outline['style']);

		return $resolved + ['exists' => $this->g5ConfigDirs($theme, $resolved['folder']) !== []];
	}

	/**
	 * A write target must be the outline's own folder. A style that renders some
	 * other folder either has none of its own (Gantry falls back to the preset)
	 * or carries another outline's `configuration` (a style duplicated in
	 * Joomla's Template Styles, which Gantry's admin copies on its next visit:
	 * Framework/Outlines.php:104-111). Writing in the first case would create a
	 * near-empty folder the style then switches to; in the second it would edit
	 * the other outline. Refuse both.
	 */
	protected function g5RequireWritableOutline(array $theme, array $outline): string
	{
		$folder = $this->g5OutlineFolder($theme, $outline);

		if ($outline['style'] !== null && $folder['folder'] !== (string) $outline['id']) {
			throw new \RuntimeException(
				'Outline ' . $outline['id'] . ' has no config folder of its own; it currently renders "' . $folder['folder'] . '" ('
				. $folder['reason'] . '). Writing here would either create a near-empty folder that the style then switches to (losing '
				. 'its layout) or change outline "' . $folder['folder'] . '". Open the outline once in Gantry\'s admin so Gantry gives it '
				. 'its own folder, or edit outline "' . $folder['folder'] . '" if that is what you mean. Nothing was written.'
			);
		}

		if (!$folder['exists']) {
			throw new \RuntimeException('Outline ' . $outline['id'] . ' has no config folder in any theme path. Nothing was written.');
		}

		return $folder['folder'];
	}

	/**
	 * The layers for one config file of an outline, lowest priority first.
	 *
	 * @param string $rel e.g. "particles/logo.yaml" or "styles.yaml"
	 * @return array<int,array{source:string,outline:?string,file:?string,data:array,error:?string}>
	 */
	protected function g5ConfigLayers(array $theme, array $outline, string $rel, array $blueprintDefaults, ?string $blueprintFile): array
	{
		$layers = [[
			'source'  => 'blueprint_default',
			'outline' => null,
			'file'    => $blueprintFile,
			'data'    => $blueprintDefaults,
			'error'   => null,
		]];

		$folder  = $this->g5OutlineFolder($theme, $outline)['folder'];
		$folders = $folder === 'default' ? ['default'] : ['default', $folder];
		$custom  = str_replace('\\', '/', $this->g5CustomPath($theme)) . '/';

		foreach ($folders as $f) {
			// g5ConfigDirs() is highest priority first; layers are lowest first.
			foreach (array_reverse($this->g5ConfigDirs($theme, $f)) as $dir) {
				$file = $dir . '/' . $rel;

				if (!is_file($file)) {
					continue;
				}

				$isCustom = str_starts_with(str_replace('\\', '/', $dir) . '/', $custom);
				$source   = $f === 'default'
					? ($isCustom ? 'base_outline' : 'theme_default')
					: ($isCustom ? 'outline' : 'theme_outline_default');

				$data  = [];
				$error = null;

				try {
					$data = $this->g5ParseYamlFile($file);
				} catch (\Throwable $e) {
					$error = $e->getMessage();
				}

				$layers[] = ['source' => $source, 'outline' => $f, 'file' => $this->g5Rel($file), 'data' => $data, 'error' => $error];
			}
		}

		return $layers;
	}

	/**
	 * Merge layers the way BlueprintSchema::mergeArrays() does, remembering which
	 * layer set each key.
	 *
	 * @param array<int,array{source:string,outline:?string,file:?string,data:array}> $layers lowest first
	 * @param array<string,true> $nested from g5NestedPaths()
	 * @return array{data:array,sources:array<string,array{source:string,outline:?string,file:?string}>}
	 */
	protected function g5MergeLayers(array $layers, array $nested): array
	{
		$merged  = [];
		$sources = [];

		foreach ($layers as $layer) {
			$this->g5MergeInto($merged, $sources, $layer['data'], $nested, ['source' => $layer['source'], 'outline' => $layer['outline'], 'file' => $layer['file']] + (isset($layer['particle']) ? ['particle' => $layer['particle']] : []), '');
		}

		return ['data' => $merged, 'sources' => $sources];
	}

	protected function g5MergeInto(array &$merged, array &$sources, array $data, array $nested, array $info, string $prefix): void
	{
		foreach ($data as $key => $value) {
			$dotted = $prefix === '' ? (string) $key : $prefix . '.' . $key;

			if (\is_array($value) && isset($merged[$key]) && \is_array($merged[$key]) && isset($nested[$dotted])) {
				$this->g5MergeInto($merged[$key], $sources, $value, $nested, $info, $dotted);
				continue;
			}

			if (\is_array($value) && !isset($merged[$key]) && isset($nested[$dotted])) {
				// A nested structure that did not exist yet: take it, but attribute each leaf.
				$merged[$key] = [];
				$this->g5MergeInto($merged[$key], $sources, $value, $nested, $info, $dotted);
				continue;
			}

			$merged[$key] = $value;

			foreach (array_keys($sources) as $s) {
				if (str_starts_with($s, $dotted . '.')) {
					unset($sources[$s]);
				}
			}

			$sources[$dotted] = $info;
		}
	}

	/**
	 * The source of a dotted key: its own entry, else the nearest ancestor that
	 * was set whole.
	 */
	protected function g5SourceOf(array $sources, string $dotted): ?array
	{
		$parts = explode('.', $dotted);

		while ($parts !== []) {
			$k = implode('.', $parts);

			if (isset($sources[$k])) {
				return $sources[$k];
			}

			array_pop($parts);
		}

		return null;
	}

	/**
	 * One line per blueprint field (value + where it came from), then any keys
	 * present in the data that the blueprint does not define.
	 *
	 * @return array{settings:array<string,array<string,mixed>>,unknown_keys:array<string,array<string,mixed>>}
	 */
	protected function g5DescribeSettings(array $fields, array $data, array $sources): array
	{
		$settings = [];

		foreach ($fields as $key => $field) {
			$found = false;
			$value = $this->g5GetPath($data, $field['path'], $found);
			$src   = $found ? $this->g5SourceOf($sources, $key) : null;

			$settings[$key] = [
				'value'  => $found ? $value : null,
				'set'    => $found,
				'source' => $src,
				'type'   => $field['type'],
				'label'  => $field['label'],
			];
		}

		$unknown = [];

		foreach ($this->g5FlattenData($data) as $key => $value) {
			$covered = false;

			foreach ($fields as $fkey => $field) {
				if ($key === $fkey || str_starts_with($key, $fkey . '.')) {
					$covered = true;
					break;
				}
			}

			if (!$covered) {
				$unknown[$key] = ['value' => $value, 'source' => $this->g5SourceOf($sources, $key)];
			}
		}

		return ['settings' => $settings, 'unknown_keys' => $unknown];
	}

	/**
	 * Effective particle-type defaults of an outline (config particles.<type>),
	 * which every instance of that particle on the outline's pages starts from.
	 *
	 * @return array<string,mixed>
	 */
	protected function g5ParticleDefaults(array $theme, array $outline, string $type, ?array $blueprint = null): array
	{
		$blueprint ??= $this->g5ParticleBlueprint($theme, $type);
		$layers      = $this->g5ConfigLayers($theme, $outline, 'particles/' . $type . '.yaml', $this->g5BlueprintDefaults($blueprint['fields']), $blueprint['file']);
		$merged      = $this->g5MergeLayers($layers, $this->g5NestedPaths($blueprint['fields']));

		return ['blueprint' => $blueprint, 'layers' => $layers] + $merged;
	}

	/**
	 * Effective styles of an outline (config styles.<group>.<field>).
	 *
	 * @return array<string,mixed>
	 */
	protected function g5StyleSettings(array $theme, array $outline): array
	{
		$bp     = $this->g5StyleBlueprints($theme);
		$layers = $this->g5ConfigLayers($theme, $outline, 'styles.yaml', $this->g5BlueprintDefaults($bp['fields']), null);
		$nested = $this->g5NestedPaths($bp['fields']);

		// Groups always merge key by key (BlueprintSchema nests styles.<group>).
		foreach (array_keys($bp['groups']) as $group) {
			$nested[$group] = true;
		}

		return ['blueprint' => $bp, 'layers' => $layers] + $this->g5MergeLayers($layers, $nested);
	}

	/** Layers as a short list for responses (no data). */
	protected function g5LayerSummary(array $layers): array
	{
		return array_map(static fn (array $l): array => array_filter([
			'source'  => $l['source'],
			'outline' => $l['outline'],
			'file'    => $l['file'],
			'keys'    => \count($l['data']),
			'error'   => $l['error'] ?? null,
		], static fn ($v) => $v !== null), $layers);
	}
}
