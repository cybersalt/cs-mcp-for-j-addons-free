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

/**
 * Writes custom/config/<outline>/styles.yaml key by key. Gantry's own Styles
 * save writes the whole posted form, and its single-group save writes the
 * MERGED styles (inherited values included) into the file
 * (Admin/Controller/Html/Configurations/Styles.php:216-238), which turns every
 * inherited value into an override. This tool changes only the keys passed.
 *
 * Gantry recompiles an outline's SCSS when its stored CSS is missing; in
 * production mode that is the ONLY trigger (CssCompiler::needsCompile()), so
 * the outline's compiled CSS is deleted after the write and the next page view
 * rebuilds it, as Gantry's own Styles save does with Theme::updateCss().
 */
final class UpdateStylesTool extends AbstractTool
{
	use Gantry5BootTrait;

	public function getName(): string { return 'update_gantry5_styles'; }

	public function getDescription(): string
	{
		return 'Change an outline\'s Styles values (e.g. {"accent.color-1": "#336699"}) without touching the rest. On a normal outline '
			. 'a key becomes an override of the Base Outline and `unset` makes it inherit again; on the Base Outline ("default") the '
			. 'change reaches every outline that does not override the key (listed). Keys and values are validated against the '
			. 'theme\'s style blueprints (unknown keys, bad option values and bad patterns are refused). Writes only '
			. 'custom/config/<outline>/styles.yaml (backed up first), clears Gantry\'s cache and deletes the affected compiled CSS '
			. 'so the next page view recompiles it (in Gantry\'s production mode nothing else triggers a recompile). '
			. 'Load one page of the outline afterwards and check it.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'theme'   => $this->g5ThemeProp(),
				'outline' => $this->g5OutlineProp(),
				'set'     => $this->g5SetProp('"<group>.<field>" style'),
				'unset'   => $this->g5UnsetProp('On a normal outline they inherit from the Base Outline again.'),
				'dry_run' => $this->g5DryRunProp(),
			],
			'required'             => ['outline'],
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
		$set     = $this->g5SetList($arguments);
		$unset   = $this->g5UnsetList($arguments);
		$dryRun  = !empty($arguments['dry_run']);

		if ($set === [] && $unset === []) {
			throw new \InvalidArgumentException('Nothing to change: pass set and/or unset.');
		}

		$folder = $this->g5RequireWritableOutline($theme, $outline);
		$bp     = $this->g5StyleBlueprints($theme);
		$fields = $bp['fields'];
		$values = $this->g5ValidateSet($fields, $set, 'the ' . $theme['element'] . ' styles');

		if ($folder !== 'default') {
			foreach (array_keys($values) as $key) {
				if (!$fields[$key]['overridable']) {
					throw new \InvalidArgumentException('"' . $key . '" is marked not overridable: Gantry only lets it be set on the Base Outline. Nothing was written.');
				}
			}
		}

		$rel     = 'styles.yaml';
		$file    = $this->g5CustomConfigDir($theme, $folder) . '/' . $rel;
		$current = is_file($file) ? $this->g5ParseYamlFile($file) : [];
		$next    = $current;
		$changes = [];

		foreach ($unset as $key) {
			$path   = explode('.', $key);
			$before = $this->g5GetPath($next, $path);

			if (!isset($fields[$key]) && $before === null) {
				throw new \InvalidArgumentException('Cannot unset "' . $key . '": it is neither a style key nor stored in ' . $this->g5Rel($file) . '. Nothing was written.');
			}

			if ($this->g5UnsetPath($next, $path)) {
				$changes[$key] = ['before' => $before, 'after' => null, 'now_inherits' => $folder !== 'default'];
			}
		}

		foreach ($values as $key => $value) {
			$path          = explode('.', $key);
			$changes[$key] = ['before' => $this->g5GetPath($next, $path), 'after' => $value];
			$this->g5SetPath($next, $path, $value);
		}

		$result = [
			'ok'      => true,
			'dry_run' => $dryRun,
			'theme'   => $theme['element'],
			'outline' => $outline['id'],
			'file'    => $this->g5Rel($file),
			'changes' => $changes,
		];

		if ($folder === 'default') {
			$result['outlines_overriding_these_keys'] = $this->overridingStyleOutlines($theme, array_keys($changes));
		}

		if ($dryRun || $next === $current) {
			return ToolResult::json($result + ($next === $current && !$dryRun ? ['note' => 'Nothing changed; no file was written.'] : []));
		}

		$styleFix = $this->g5EnsureStyleConfiguration($outline);
		$write   = $next === [] ? $this->g5DeleteConfigFile($theme, $folder, $rel) : $this->g5WriteConfigFile($theme, $folder, $rel, $next, 5);
		$removed = $this->g5InvalidateCss($theme, $folder === 'default' ? null : [$folder]);
		$cache   = $this->g5ClearCache($theme);

		$after = $this->g5StyleSettings($theme, $outline);
		$desc  = $this->g5DescribeSettings(array_intersect_key($fields, $changes), $after['data'], $after['sources']);

		return ToolResult::json($result + [
			'file_deleted'          => !empty($write['deleted']),
			'backup'                => $write['backup'],
			'backup_file'           => $write['backup_file'],
			'undo'                  => 'restore_gantry5_backup {"backup": "' . $write['backup'] . '"}',
			'effective_now'         => $desc['settings'],
			'style_fix'             => $styleFix,
			'compiled_css_removed'  => $removed,
			'css_note'              => 'The next page view of ' . ($folder === 'default' ? 'each outline' : 'this outline') . ' recompiles its CSS (a second or two once). Load a page that uses it and check it.',
			'cache'                 => $cache,
		]);
	}

	private function overridingStyleOutlines(array $theme, array $keys): array
	{
		$out = [];

		foreach ($this->g5Outlines($theme) as $oid => $outline) {
			$folder = $this->g5OutlineFolder($theme, $outline)['folder'];
			$file   = $this->g5CustomConfigDir($theme, $folder) . '/styles.yaml';

			if ($folder === 'default' || !is_file($file)) {
				continue;
			}

			try {
				$flat = $this->g5FlattenData($this->g5ParseYamlFile($file));
			} catch (\Throwable $e) {
				continue;
			}

			$hit = array_values(array_intersect($keys, array_keys($flat)));

			if ($hit !== []) {
				$out[(string) $oid] = $hit;
			}
		}

		return $out;
	}
}
