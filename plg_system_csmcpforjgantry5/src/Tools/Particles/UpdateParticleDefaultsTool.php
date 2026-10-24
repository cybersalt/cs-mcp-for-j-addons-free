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

/**
 * Writes custom/config/<outline>/particles/<type>.yaml, the file Gantry's
 * "Particle Defaults" tab saves (Admin/Controller/Html/Configurations/Settings.php:239-294).
 * Only the keys passed change; keys already in the file stay; a key not in the
 * file keeps inheriting from the Base Outline. When the last key is removed the
 * file is deleted, as Settings::saveItem() does when nothing is posted.
 */
final class UpdateParticleDefaultsTool extends AbstractTool
{
	use Gantry5BootTrait;

	public function getName(): string { return 'update_gantry5_particle_defaults'; }

	public function getDescription(): string
	{
		return 'Change an outline\'s defaults for one particle TYPE (Gantry admin: Particle Defaults), i.e. every instance of that type '
			. 'on the outline\'s pages that does not set the key itself. On a normal outline a key you set becomes an override of the '
			. 'Base Outline; `unset` removes the override so the key inherits again. On the Base Outline ("default") it changes the '
			. 'value for every outline that does not override it (listed in the response). Keys are validated against the particle '
			. 'blueprint; keys marked not overridable can only be set on the Base Outline. Writes only '
			. 'custom/config/<outline>/particles/<type>.yaml, backs it up first, and clears Gantry\'s cache.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'theme'   => $this->g5ThemeProp(),
				'outline' => $this->g5OutlineProp(),
				'type'    => ['type' => 'string', 'description' => 'Particle type, e.g. "logo", "copyright".'],
				'set'     => $this->g5SetProp('the particle type\'s'),
				'unset'   => $this->g5UnsetProp('On a normal outline they inherit from the Base Outline again.'),
				'dry_run' => $this->g5DryRunProp(),
			],
			'required'             => ['outline', 'type'],
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

		$theme   = $this->g5RequireTheme($arguments);
		$outline = $this->g5RequireOutline($theme, $arguments['outline'] ?? null);
		$type    = $this->requireString($arguments, 'type');
		$set     = $this->g5SetList($arguments);
		$unset   = $this->g5UnsetList($arguments);
		$dryRun  = !empty($arguments['dry_run']);

		if ($set === [] && $unset === []) {
			throw new \InvalidArgumentException('Nothing to change: pass set and/or unset.');
		}

		$folder    = $this->g5RequireWritableOutline($theme, $outline);
		$blueprint = $this->g5ParticleBlueprint($theme, $type);
		$fields    = $blueprint['fields'];
		$values    = $this->g5ValidateSet($fields, $set, 'particle type "' . $type . '"');

		if ($folder !== 'default') {
			foreach (array_keys($values) as $key) {
				if (!$fields[$key]['overridable']) {
					throw new \InvalidArgumentException('"' . $key . '" is marked not overridable in the ' . $type . ' blueprint: Gantry only lets it be set on the Base Outline. Nothing was written.');
				}
			}
		}

		if (isset($fields['twig'])) {
			$current = $this->g5ParticleDefaults($theme, $outline, $type, $blueprint)['data'];
			$this->g5GuardTwig($actor, $fields, array_merge(array_keys($values), $unset), $values['twig'] ?? ($current['twig'] ?? null), 'The ' . $type . ' particle');
		}

		$rel      = 'particles/' . $type . '.yaml';
		$file     = $this->g5CustomConfigDir($theme, $folder) . '/' . $rel;
		$current  = is_file($file) ? $this->g5ParseYamlFile($file) : [];
		$next     = $current;
		$changes  = [];

		foreach ($unset as $key) {
			$path   = explode('.', $key);
			$before = $this->g5GetPath($next, $path);

			if (!isset($fields[$key]) && $before === null) {
				throw new \InvalidArgumentException('Cannot unset "' . $key . '": it is neither a ' . $type . ' setting nor stored in ' . $this->g5Rel($file) . '. Nothing was written.');
			}

			if ($this->g5UnsetPath($next, $path)) {
				$changes[$key] = ['before' => $before, 'after' => null, 'now_inherits' => $folder !== 'default'];
			}
		}

		foreach ($values as $key => $value) {
			$path           = explode('.', $key);
			$changes[$key]  = ['before' => $this->g5GetPath($next, $path), 'after' => $value];
			$this->g5SetPath($next, $path, $value);
		}

		$result = [
			'ok'            => true,
			'dry_run'       => $dryRun,
			'theme'         => $theme['element'],
			'outline'       => $outline['id'],
			'particle_type' => $type,
			'file'          => $this->g5Rel($file),
			'changes'       => $changes,
		];

		if ($folder === 'default') {
			$result['outlines_overriding_these_keys'] = $this->overridingOutlines($theme, $rel, array_keys($changes));
		}

		if ($dryRun || $next === $current) {
			return ToolResult::json($result + ($next === $current && !$dryRun ? ['note' => 'Nothing changed; no file was written.'] : []));
		}

		$styleFix = $this->g5EnsureStyleConfiguration($outline);
		$write    = $next === []
			? $this->g5DeleteConfigFile($theme, $folder, $rel)
			: $this->g5WriteConfigFile($theme, $folder, $rel, $next, 5);

		$cache    = $this->g5ClearCache($theme);
		$after    = $this->g5ParticleDefaults($theme, $outline, $type, $blueprint);
		$describe = $this->g5DescribeSettings($fields, $after['data'], $after['sources']);
		$check    = array_intersect_key($describe['settings'], $changes);

		return ToolResult::json($result + [
			'file_deleted'  => !empty($write['deleted']),
			'backup'        => $write['backup'],
			'backup_file'   => $write['backup_file'],
			'undo'          => 'restore_gantry5_backup {"backup": "' . $write['backup'] . '"}',
			'effective_now' => $check,
			'style_fix'     => $styleFix,
			'cache'         => $cache,
		]);
	}

	/** Outlines with their own value for any of these keys (so a Base Outline change does not reach them). */
	private function overridingOutlines(array $theme, string $rel, array $keys): array
	{
		$out = [];

		foreach ($this->g5Outlines($theme) as $oid => $outline) {
			$folder = $this->g5OutlineFolder($theme, $outline)['folder'];

			if ($folder === 'default') {
				continue;
			}

			$file = $this->g5CustomConfigDir($theme, $folder) . '/' . $rel;

			if (!is_file($file)) {
				continue;
			}

			try {
				$flat = $this->g5FlattenData($this->g5ParseYamlFile($file));
			} catch (\Throwable $e) {
				continue;
			}

			$hit = [];

			foreach ($keys as $key) {
				foreach (array_keys($flat) as $k) {
					if ($k === $key || str_starts_with($k, $key . '.') || str_starts_with($key, $k . '.')) {
						$hit[] = $key;
						break;
					}
				}
			}

			if ($hit !== []) {
				$out[(string) $oid] = array_values(array_unique($hit));
			}
		}

		return $out;
	}
}
