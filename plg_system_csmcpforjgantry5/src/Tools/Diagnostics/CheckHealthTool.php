<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Gantry5BootTrait;
use Joomla\CMS\User\User;

/**
 * Site-wide Gantry 5 audit. Strictly read-only.
 */
final class CheckHealthTool extends AbstractTool
{
	use Gantry5BootTrait;

	/**
	 * Gantry's own compatibility statements (CHANGELOG.md of each release):
	 * 5.6.0 "Minimum Joomla version is now 5.0.0, highest supported Joomla major
	 * is Joomla 5.x", PHP 8.1-8.3; 5.6.2 "Added compatibility with Joomla 6.
	 * Highest supported Joomla major for this release is Joomla 6.x", PHP 8.1-8.4.
	 * 5.5.x and older target Joomla 3.9/4.
	 */
	private const COMPAT = [
		['from' => '5.6.2', 'joomla' => [5, 6], 'php' => ['8.1.0', '8.4.99']],
		['from' => '5.6.0', 'joomla' => [5, 5], 'php' => ['8.1.0', '8.3.99']],
		['from' => '0',     'joomla' => [3, 4], 'php' => ['7.1.3', '8.2.99']],
	];

	public function getName(): string { return 'check_gantry5_health'; }

	public function getDescription(): string
	{
		return 'Gantry 5 health audit, read-only. Versions (package, library, component, plugin, nucleus, particle module) and whether '
			. 'they match; whether this Gantry release supports this Joomla and PHP version (Joomla 6 needs Gantry 5.6.2+; 5.6.0/5.6.1 '
			. 'are Joomla 5 only); the Gantry plugin\'s mode (production/development, YAML/Twig compiling) and what that means for '
			. 'changes showing up; and per theme: writable custom/config and compiled-CSS folders, cache size, every outline\'s config '
			. 'folder (styles without their own folder, styles Gantry\'s admin will overwrite from their preset, orphaned folders of '
			. 'deleted styles), YAML that does not parse, legacy layout formats, broken layout inheritance, particles without a '
			. 'blueprint, Module Instance items pointing at missing or unpublished modules, and menu items assigned to deleted styles.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => ['theme' => ['type' => 'string', 'description' => 'Optional: audit one Gantry theme. Default: all.']], 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$versions = $this->g5Versions();

		if (!$this->g5Installed()) {
			$out             = json_decode((string) $this->g5NotInstalledError()->content[0]['text'], true);
			$out['versions'] = $versions;

			return ToolResult::json($out, true);
		}

		$this->g5RequireAccess($actor);

		$issues = [];
		$add    = static function (string $severity, string $check, string $message) use (&$issues): void {
			$issues[] = ['severity' => $severity, 'check' => $check, 'message' => $message];
		};

		// ---------------------------------------------------------- versions
		$lib    = $this->g5LibraryVersion();
		$gantry = $lib ?? $versions['package']['version'] ?? $versions['library']['version'];

		$distinct = array_values(array_unique(array_filter(array_column($versions, 'version'))));

		if (\count($distinct) > 1) {
			$add('warning', 'version_mismatch', 'Gantry\'s parts are at different versions (' . implode(', ', array_map(static fn ($k, $v) => $k . ' ' . ($v['version'] ?? '-'), array_keys($versions), $versions)) . '). Reinstall the whole pkg_gantry5.');
		}

		foreach (['library', 'plugin', 'nucleus'] as $part) {
			if ($versions[$part]['extension_id'] === null) {
				$add('error', 'missing_' . $part, 'The Gantry ' . $part . ' (' . self::G5_EXTENSIONS[$part]['element'] . ') is not registered in #__extensions; the package install is incomplete.');
			}
		}

		if ($versions['plugin']['enabled'] === 0) {
			$add('error', 'plugin_disabled', '"System - Gantry 5" is disabled. Gantry themes cannot render without it (it loads the library on every request). Enable it in System > Plugins.');
		}

		$jver  = \defined('JVERSION') ? (string) JVERSION : '0';
		$major = (int) $jver;
		$compat = null;

		foreach (self::COMPAT as $row) {
			if ($gantry !== null && version_compare($gantry, $row['from'], '>=')) {
				$compat = $row;
				break;
			}
		}

		$supported = $compat !== null && $major >= $compat['joomla'][0] && $major <= $compat['joomla'][1];

		if ($gantry === null) {
			$add('error', 'gantry_version_unknown', 'Cannot tell which Gantry version is installed.');
		} elseif ($major === 6 && version_compare($gantry, '5.6.2', '<')) {
			$add('error', 'joomla6_unsupported', 'Gantry ' . $gantry . ' does not support Joomla 6: Joomla 6 support arrived in Gantry 5.6.2 (2026-06-03). '
				. (version_compare($gantry, '5.6.0', '>=') ? '5.6.0 and 5.6.1 are Joomla 5 only. ' : '')
				. 'Update Gantry (pkg_gantry5) to 5.6.2 or later from github.com/gantry/gantry5/releases before relying on the front end.');
		} elseif ($major > 6) {
			$add('warning', 'joomla_untested', 'Joomla ' . $jver . ' is newer than any Gantry release claims to support (5.6.2+ says Joomla 6.x).');
		} elseif (!$supported) {
			$add('warning', 'joomla_unsupported', 'Gantry ' . $gantry . ' targets Joomla ' . $compat['joomla'][0] . '-' . $compat['joomla'][1] . '; this is Joomla ' . $jver . '.');
		}

		if ($compat !== null && (version_compare(PHP_VERSION, $compat['php'][0], '<') || version_compare(PHP_VERSION, $compat['php'][1], '>'))) {
			$add('warning', 'php_unsupported', 'PHP ' . PHP_VERSION . ' is outside the range Gantry ' . $gantry . ' supports (' . $compat['php'][0] . ' to ' . substr($compat['php'][1], 0, 3) . '.x).');
		}

		$compat6 = $this->pluginEnabled('behaviour', 'compat6');

		if ($major === 6) {
			$add('info', 'compat6', 'Behaviour - Backward Compatibility 6 is ' . ($compat6 ? 'enabled' : 'disabled') . '. Gantry 5.6.4 with Helium 5.6.4 rendered on Joomla 6.2.0 with it disabled (tested 2026-10-24).');
		}

		// ---------------------------------------------------------- global settings
		$params   = $this->pluginParams('system', 'gantry5');
		$global   = $params + ['production' => true, 'debug' => false, 'compile_yaml' => true, 'compile_twig' => true, 'use_media_folder' => false, 'asset_timestamps' => true];
		$prodMode = (bool) $global['production'];

		$add('info', 'gantry_mode', 'Gantry is in ' . ($prodMode ? 'PRODUCTION' : 'development') . ' mode'
			. ($params === [] ? ' (the plugin has never been saved, so Gantry\'s built-in default applies: production on, although the plugin form shows "off")' : '')
			. '. ' . ($prodMode ? 'Compiled CSS is only rebuilt when the file is missing, so after a styles change the outline\'s compiled CSS must be deleted (update_gantry5_styles does this).' : 'SCSS is recompiled whenever variables or imports change.'));

		if (!$this->g5YamlAvailable()) {
			$add('error', 'yaml_missing', 'symfony/yaml is not loadable; these tools cannot read Gantry configuration.');
		}

		// ---------------------------------------------------------- themes
		$themes = trim((string) ($arguments['theme'] ?? '')) !== '' ? [$this->g5RequireTheme($arguments)] : array_values($this->g5Themes());

		if ($themes === []) {
			$add('warning', 'no_themes', 'Gantry is installed but no Gantry theme is. Install one (Helium or Hydrogen ship on the same releases page).');
		}

		$themeOut = [];

		foreach ($themes as $theme) {
			$themeOut[] = $this->auditTheme($theme, $gantry, $add);
		}

		// ---------------------------------------------------------- menu items pointing at deleted styles
		$dangling = (int) $this->db->setQuery(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__menu') . ' m LEFT JOIN ' . $this->db->quoteName('#__template_styles')
			. ' s ON s.id = m.template_style_id WHERE m.client_id = 0 AND m.published <> -2 AND m.template_style_id > 0 AND s.id IS NULL'
		)->loadResult();

		if ($dangling > 0) {
			$add('warning', 'menu_items_missing_style', $dangling . ' menu item(s) are assigned to a template style that no longer exists; they fall back to the site default. list_gantry5_assignments lists them.');
		}

		$backups = $this->g5ListBackups();

		$counts = ['error' => 0, 'warning' => 0, 'info' => 0];

		usort($issues, static fn (array $a, array $b): int => array_search($a['severity'], ['error', 'warning', 'info'], true) <=> array_search($b['severity'], ['error', 'warning', 'info'], true));

		foreach ($issues as $i) {
			$counts[$i['severity']]++;
		}

		return ToolResult::json([
			'ok'              => $counts['error'] === 0,
			'gantry_version'  => $gantry,
			'versions'        => $versions,
			'joomla'          => $jver,
			'php'             => PHP_VERSION,
			'supported_combination' => $compat !== null ? ['gantry_from' => $compat['from'], 'joomla_majors' => $compat['joomla'], 'php' => [$compat['php'][0], substr($compat['php'][1], 0, 3) . '.x']] : null,
			'global_settings' => $global,
			'yaml_library'    => $this->g5YamlSource(),
			'themes'          => $themeOut,
			'backups'         => ['count' => \count($backups), 'folder' => $this->g5Rel($this->g5BackupRoot())],
			'summary'         => $counts,
			'issues'          => $issues,
		]);
	}

	/** @return array<string,mixed> */
	private function auditTheme(array $theme, ?string $gantry, callable $add): array
	{
		$el = $theme['element'];

		if ($theme['theme_yaml_error'] !== null) {
			$add('error', 'theme_yaml', $el . ': ' . $theme['theme_yaml_error']);
		}

		if (!(int) $theme['enabled']) {
			$add('warning', 'theme_disabled', $el . ' is disabled; its outlines cannot be assigned or rendered.');
		}

		if ($theme['required_gantry'] !== null && $gantry !== null && version_compare($gantry, (string) $theme['required_gantry'], '<')) {
			$add('error', 'theme_needs_newer_gantry', $el . ' requires Gantry ' . $theme['required_gantry'] . '; ' . $gantry . ' is installed.');
		}

		$custom = $this->g5CustomPath($theme);
		$config = $custom . '/config';
		$cssDir = $this->g5CompiledCssDir($theme);

		foreach (['custom' => $custom, 'custom/config' => $config] as $label => $dir) {
			if (!is_dir($dir)) {
				$add('warning', 'folder_missing', $el . ': ' . $this->g5Rel($dir) . ' does not exist (Gantry creates it on first save).');
			} elseif (!is_writable($dir)) {
				$add('error', 'folder_not_writable', $el . ': ' . $this->g5Rel($dir) . ' is not writable by PHP; neither Gantry\'s admin nor these tools can save.');
			}
		}

		if (is_dir($cssDir) && !is_writable($cssDir)) {
			$add('error', 'css_not_writable', $el . ': ' . $this->g5Rel($cssDir) . ' is not writable, so Gantry cannot compile CSS.');
		}

		// Outlines.
		$styles     = $this->g5Styles($el);
		$outlinesOut = [];

		foreach ($this->g5Outlines($theme) as $id => $outline) {
			$folder = $this->g5OutlineFolder($theme, $outline);
			$entry  = ['id' => (string) $id, 'title' => $outline['title'], 'config_folder' => $folder['folder']];

			if ($outline['style'] !== null) {
				$style = $outline['style'];

				if ($folder['folder'] !== (string) $id) {
					$add('warning', 'style_without_own_folder', $el . ' style ' . $id . ' "' . $style['title'] . '" renders "' . $folder['folder'] . '" (' . $folder['reason'] . '). The write tools refuse it until Gantry\'s admin gives it its own folder.');
				} elseif ($style['configuration'] === null && ($style['preset'] ?? 'default') !== (string) $id && is_dir($config . '/' . ($style['preset'] ?? 'default'))) {
					$add('warning', 'style_without_configuration', $el . ' style ' . $id . ' has no "configuration" param. The next time Gantry\'s admin lists outlines it copies custom/config/'
						. ($style['preset'] ?? 'default') . ' OVER custom/config/' . $id . ' (Framework/Outlines.php:104-111), replacing this outline\'s layout and settings. '
						. 'Gantry\'s own installer leaves styles like this. The write tools set configuration = ' . $id . ' before writing, which stops it.');
				}
			}

			try {
				$resolved = $this->g5ResolvedLayout($theme, $folder['folder']);
				$items    = $this->g5LayoutItems($resolved['nodes']);
				$entry['layout'] = $resolved['source']['kind'] === 'preset' ? 'preset ' . $resolved['source']['preset'] : $this->g5Rel($resolved['source']['file']);
				$entry['items']  = \count($items);

				foreach ($resolved['problems'] as $p) {
					$add('warning', 'broken_inheritance', $el . ' outline ' . $id . ': ' . $p);
				}

				$this->auditItems($theme, (string) $id, $items, $add);
			} catch (\Throwable $e) {
				$add(str_contains($e->getMessage(), 'legacy layout format') ? 'warning' : 'error', 'layout_unreadable', $el . ' outline ' . $id . ': ' . $e->getMessage());
			}

			$outlinesOut[] = $entry;
		}

		// Orphaned folders: numeric folders without a style.
		foreach ((array) glob($config . '/*', GLOB_ONLYDIR) as $dir) {
			$name = basename((string) $dir);

			if (ctype_digit($name) && !isset($styles[(int) $name])) {
				$add('info', 'orphan_outline_folder', $el . ': ' . $this->g5Rel((string) $dir) . ' belongs to no template style (a style deleted in Joomla\'s Template Styles leaves its folder behind). Safe to remove once you are sure.');
			}
		}

		// YAML that does not parse.
		if (is_dir($config)) {
			$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($config, \FilesystemIterator::SKIP_DOTS));

			foreach ($it as $f) {
				if ($f->isFile() && str_ends_with($f->getFilename(), '.yaml')) {
					try {
						$this->g5ParseYamlFile($f->getPathname());
					} catch (\Throwable $e) {
						$add('error', 'yaml_error', $e->getMessage());
					}
				}
			}
		}

		$cache = [];

		foreach ($this->g5CacheRoots() as $root) {
			$cache[$this->g5Rel($root . '/' . $el)] = $this->g5DirStats($root . '/' . $el);
		}

		return [
			'theme'        => $el,
			'version'      => $theme['version'],
			'enabled'      => (bool) $theme['enabled'],
			'custom_writable' => is_dir($custom) && is_writable($custom),
			'compiled_css' => $this->g5CompiledCssFiles($theme),
			'cache'        => $cache,
			'outlines'     => $outlinesOut,
		];
	}

	private function auditItems(array $theme, string $outline, array $items, callable $add): void
	{
		$types   = $this->g5ParticleTypes($theme);
		$modIds  = [];
		$el      = $theme['element'];

		foreach ($items as $item) {
			if (!isset($types[$item['subtype']]) && $item['type'] !== 'spacer') {
				$add('warning', 'particle_without_blueprint', $el . ' outline ' . $outline . ': "' . $item['id'] . '" is a ' . $item['subtype'] . ', and no particles/' . $item['subtype'] . '.yaml exists in the theme or engine (a removed or third-party particle). Gantry cannot render it.');
			}

			if ($item['type'] === 'position' && $item['subtype'] === 'module') {
				$modIds[$item['id']] = (int) ($item['attributes']['module_id'] ?? 0);
			}
		}

		if ($modIds === []) {
			return;
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select(['id', 'published', 'client_id'])
				->from($this->db->quoteName('#__modules'))
				->whereIn($this->db->quoteName('id'), array_values(array_filter($modIds)) ?: [0])
		)->loadAssocList('id') ?: [];

		foreach ($modIds as $itemId => $mid) {
			if ($mid === 0) {
				$add('warning', 'module_instance_empty', $el . ' outline ' . $outline . ': Module Instance "' . $itemId . '" has no module chosen.');
			} elseif (!isset($rows[$mid]) || (int) $rows[$mid]['client_id'] !== 0) {
				$add('warning', 'module_instance_missing', $el . ' outline ' . $outline . ': Module Instance "' . $itemId . '" points at module ' . $mid . ', which does not exist.');
			} elseif ((int) $rows[$mid]['published'] !== 1) {
				$add('info', 'module_instance_unpublished', $el . ' outline ' . $outline . ': Module Instance "' . $itemId . '" points at module ' . $mid . ', which is not published, so it renders nothing.');
			}
		}
	}

	private function pluginEnabled(string $folder, string $element): ?bool
	{
		$v = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('enabled')
				->from($this->db->quoteName('#__extensions'))
				->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
				->where($this->db->quoteName('folder') . ' = ' . $this->db->quote($folder))
				->where($this->db->quoteName('element') . ' = ' . $this->db->quote($element))
		)->loadResult();

		return $v === null ? null : (bool) $v;
	}

	private function pluginParams(string $folder, string $element): array
	{
		$v = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('params')
				->from($this->db->quoteName('#__extensions'))
				->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
				->where($this->db->quoteName('folder') . ' = ' . $this->db->quote($folder))
				->where($this->db->quoteName('element') . ' = ' . $this->db->quote($element))
		)->loadResult();

		$p = json_decode((string) $v, true);

		return \is_array($p) ? $p : [];
	}
}
