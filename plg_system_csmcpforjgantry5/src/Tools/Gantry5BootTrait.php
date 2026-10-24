<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjgantry5\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;

/**
 * Shared orientation and safety rails for every Gantry 5 tool.
 *
 * Built against Gantry 5.6.4 (Tiger12, 2026-09-03) with the Helium 5.6.4 theme,
 * on Joomla 6.2.0.
 *
 * ---------------------------------------------------------------------------
 * WHY THESE TOOLS READ GANTRY'S YAML THEMSELVES INSTEAD OF BOOTING GANTRY
 * ---------------------------------------------------------------------------
 *
 * Gantry keeps an outline's layout, particle defaults, styles and page settings
 * in YAML under templates/<theme>/custom/config/<outline>/, falling back to the
 * theme's own config/ folders and to the particle and style blueprints. These
 * tools read those files with symfony/yaml (Joomla ships it in
 * libraries/vendor/symfony/yaml) and re-implement Gantry's lookup order, with
 * the vendor file and line cited at each step. Booting Gantry under the MCP
 * request was rejected because:
 *
 *   - Gantry is one theme per request. Gantry::instance() registers PHP stream
 *     wrappers (gantry-theme://, gantry-config://) bound to the theme it was
 *     initialised for (ThemeDetails::addStreams()), so a site with two Gantry
 *     themes could not be read in one call.
 *   - It needs the SITE application. Outlines::current() and
 *     AssignmentsStyle::getRules() call $app->getTemplate(true), and
 *     Assignments::loadAssignments() returns nothing unless
 *     $app->isClient('site') (Framework/Assignments.php:47-49). The MCP request
 *     runs under the API application, where getTemplate() does not exist.
 *   - Gantry's readers write. Layout::index() calls loadIndex($name, true), which
 *     SAVES layout.yaml and index.yaml whenever the index is missing or older
 *     than the layout (Component/Layout/Layout.php:1117-1160). A "read" tool that
 *     booted Gantry would modify the site.
 *   - Gantry's admin save paths take a whole posted form. Styles::save($id)
 *     writes the MERGED styles (inherited values included) into the outline's
 *     styles.yaml (Admin/Controller/Html/Configurations/Styles.php:221-238),
 *     which silently breaks inheritance from the Base Outline. A targeted key
 *     write is the only way to change one value and leave the rest inheriting.
 *   - Under the API application Gantry's own code paths call Route::_(), which
 *     ApiRouter cannot build (seen live: both the package and Helium installers
 *     end with "Call to undefined method ApiRouter::build()").
 *
 * So: reads never write, writes touch exactly one file under custom/config/
 * (never the theme's shipped defaults), every write is backed up first and
 * re-parsed before it replaces the original, and the YAML is dumped the way
 * Gantry dumps it (RocketTheme YamlFile::encode(): Yaml::dump($data, inline, 2),
 * inline 5 for config files and 20 for layout.yaml, Layout.php:337).
 *
 * ---------------------------------------------------------------------------
 * OUTLINES ON JOOMLA
 * ---------------------------------------------------------------------------
 *
 * An outline is a Joomla template style. Its config folder is the style's
 * `configuration` param, else the style id; if that folder does not exist the
 * style falls back to its `preset` (Framework/Outlines.php:54-84). Besides the
 * styles there are four built-in outlines: `default` (the Base Outline),
 * `_body_only`, `_error` and `_offline` (OutlineCollection::addDefaults()).
 * Which outline a page uses is the menu item's template_style_id, else the
 * default (home) style (Framework/Assignments.php:52-60).
 */
trait Gantry5BootTrait
{
	use Gantry5FilesTrait;
	use Gantry5BlueprintTrait;
	use Gantry5ConfigTrait;
	use Gantry5LayoutTrait;

	/** The four outlines Gantry adds to every theme (OutlineCollection::addDefaults()). */
	private const G5_SYSTEM_OUTLINES = [
		'default'    => 'Base Outline',
		'_body_only' => 'Body Only',
		'_error'     => 'Error',
		'_offline'   => 'Offline',
	];

	private const G5_EXTENSIONS = [
		'package'   => ['type' => 'package', 'element' => 'pkg_gantry5', 'folder' => ''],
		'library'   => ['type' => 'library', 'element' => 'gantry5', 'folder' => ''],
		'component' => ['type' => 'component', 'element' => 'com_gantry5', 'folder' => ''],
		'plugin'    => ['type' => 'plugin', 'element' => 'gantry5', 'folder' => 'system'],
		'nucleus'   => ['type' => 'file', 'element' => 'gantry5_nucleus', 'folder' => ''],
		'module'    => ['type' => 'module', 'element' => 'mod_gantry5_particle', 'folder' => ''],
	];

	private static ?array $g5ThemesCache = null;

	private static array $g5StylesCache = [];

	// -----------------------------------------------------------------------
	// Presence and identity
	// -----------------------------------------------------------------------

	/**
	 * One category for every tool. Deriving it from the namespace would give
	 * `outlines`, `modules` and the like, which collide with core MCP for J and
	 * with sibling add-ons.
	 */
	public function getCategory(): string
	{
		return 'gantry5';
	}

	protected function g5LibraryPath(): string
	{
		return JPATH_LIBRARIES . '/gantry5';
	}

	protected function g5Installed(): bool
	{
		return is_dir($this->g5LibraryPath()) && is_dir(JPATH_SITE . '/media/gantry5');
	}

	protected function g5NotInstalledError(): ToolResult
	{
		return ToolResult::json([
			'ok'    => false,
			'error' => 'Gantry 5 is not installed on this site. Expected libraries/gantry5 and media/gantry5 '
				. '(installed by pkg_gantry5).',
			'note'  => 'Gantry 5 is free from github.com/gantry/gantry5/releases (joomla-pkg_gantry5_v*.zip). '
				. 'This add-on was built against 5.6.4; Joomla 6 needs 5.6.2 or later.',
		], true);
	}

	/** @return array<string,array{version:?string,enabled:?int,extension_id:?int}> */
	protected function g5Versions(): array
	{
		$out = [];

		foreach (self::G5_EXTENSIONS as $label => $ext) {
			$query = $this->db->getQuery(true)
				->select(['extension_id', 'enabled', 'manifest_cache'])
				->from($this->db->quoteName('#__extensions'))
				->where($this->db->quoteName('type') . ' = :type')
				->where($this->db->quoteName('element') . ' = :element')
				->bind(':type', $ext['type'])
				->bind(':element', $ext['element']);

			if ($ext['folder'] !== '') {
				$query->where($this->db->quoteName('folder') . ' = ' . $this->db->quote($ext['folder']));
			}

			$row      = $this->db->setQuery($query)->loadAssoc();
			$manifest = \is_array($row) ? json_decode((string) $row['manifest_cache'], true) : null;

			$out[$label] = [
				'version'      => \is_array($manifest) && !empty($manifest['version']) ? (string) $manifest['version'] : null,
				'enabled'      => \is_array($row) ? (int) $row['enabled'] : null,
				'extension_id' => \is_array($row) ? (int) $row['extension_id'] : null,
			];
		}

		return $out;
	}

	/** GANTRY5_VERSION as the library defines it (src/RealLoader.php), read from the file. */
	protected function g5LibraryVersion(): ?string
	{
		$file = $this->g5LibraryPath() . '/src/RealLoader.php';

		if (!is_file($file)) {
			return null;
		}

		return preg_match("/define\\('GANTRY5_VERSION',\\s*'([^']+)'\\)/", (string) @file_get_contents($file), $m) ? $m[1] : null;
	}

	// -----------------------------------------------------------------------
	// Access control
	// -----------------------------------------------------------------------

	/**
	 * Mirrors Gantry's own gate (administrator/components/com_gantry5/gantry5.php:27-32):
	 * core.manage on com_gantry5 OR on com_templates opens the Gantry admin, and
	 * inside it Platform::authorize('outline.edit') is unconditionally true.
	 * Writes additionally require core.edit on com_templates, because they change
	 * how a template style renders (Gantry itself requires that for renaming an
	 * outline, Framework/Platform.php:737-738).
	 */
	protected function g5RequireAccess(User $actor, bool $write = false): void
	{
		if ($actor->guest || (int) $actor->id === 0) {
			throw new \RuntimeException('Gantry 5 tools require an authenticated user.');
		}

		if (!$actor->authorise('core.manage', 'com_gantry5') && !$actor->authorise('core.manage', 'com_templates')) {
			throw new \RuntimeException('This user lacks core.manage on both com_gantry5 and com_templates; Gantry\'s admin requires one of them.');
		}

		if ($write && !$actor->authorise('core.edit', 'com_templates')) {
			throw new \RuntimeException('Changing a Gantry outline requires core.edit on com_templates, which this user is not granted.');
		}
	}

	// -----------------------------------------------------------------------
	// Themes
	// -----------------------------------------------------------------------

	/**
	 * Every installed site template that is a Gantry 5 theme: it has
	 * gantry/theme.yaml (or custom/gantry/theme.yaml, which wins:
	 * Component/Theme/ThemeDetails.php:51).
	 *
	 * @return array<string,array<string,mixed>> keyed by template element
	 */
	protected function g5Themes(): array
	{
		if (self::$g5ThemesCache !== null) {
			return self::$g5ThemesCache;
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select(['extension_id', 'element', 'name', 'enabled', 'manifest_cache'])
				->from($this->db->quoteName('#__extensions'))
				->where($this->db->quoteName('type') . ' = ' . $this->db->quote('template'))
				->where($this->db->quoteName('client_id') . ' = 0')
				->order($this->db->quoteName('element'))
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$element = (string) $row['element'];

			// Template elements are folder names; refuse anything that is not one.
			if (!preg_match('/^[A-Za-z0-9_.-]+$/', $element) || str_contains($element, '..')) {
				continue;
			}

			$root     = JPATH_SITE . '/templates/' . $element;
			$custom   = $root . '/custom/gantry/theme.yaml';
			$shipped  = $root . '/gantry/theme.yaml';
			$yamlFile = is_file($custom) ? $custom : (is_file($shipped) ? $shipped : null);

			if ($yamlFile === null) {
				continue;
			}

			$details  = [];
			$error    = null;

			try {
				$details = $this->g5ParseYamlFile($yamlFile);
			} catch (\Throwable $e) {
				$error = $e->getMessage();
			}

			$manifest = json_decode((string) $row['manifest_cache'], true);

			$out[$element] = [
				'element'       => $element,
				'name'          => (string) ($details['details']['name'] ?? $row['name']),
				'version'       => (string) ($details['details']['version'] ?? (\is_array($manifest) ? ($manifest['version'] ?? '') : '')),
				'extension_id'  => (int) $row['extension_id'],
				'enabled'       => (int) $row['enabled'],
				'root'          => $root,
				'theme_yaml'    => $yamlFile,
				'theme_yaml_error' => $error,
				'engine'        => (string) ($details['configuration']['gantry']['engine'] ?? 'nucleus'),
				'parent'        => isset($details['configuration']['theme']['parent']) && (string) $details['configuration']['theme']['parent'] !== $element
					? (string) $details['configuration']['theme']['parent'] : null,
				'required_gantry' => isset($details['configuration']['dependencies']['gantry']) ? (string) $details['configuration']['dependencies']['gantry'] : null,
				'css'           => \is_array($details['configuration']['css'] ?? null) ? $details['configuration']['css'] : [],
				'details'       => $details['configuration']['theme'] ?? [],
			];
		}

		self::$g5ThemesCache = $out;

		return $out;
	}

	/**
	 * A theme argument, validated against the installed Gantry themes. With no
	 * argument and exactly one Gantry theme on the site, that one.
	 *
	 * @return array<string,mixed> the g5Themes() entry
	 */
	protected function g5RequireTheme(array $arguments): array
	{
		$themes = $this->g5Themes();

		if ($themes === []) {
			throw new \RuntimeException('Gantry 5 is installed but no Gantry theme is (no site template has gantry/theme.yaml). Install a Gantry theme such as Helium.');
		}

		$wanted = trim((string) ($arguments['theme'] ?? ''));

		if ($wanted === '') {
			if (\count($themes) === 1) {
				return reset($themes);
			}

			throw new \InvalidArgumentException('theme is required: this site has several Gantry themes (' . implode(', ', array_keys($themes)) . ').');
		}

		if (!isset($themes[$wanted])) {
			throw new \InvalidArgumentException('theme "' . $wanted . '" is not an installed Gantry theme. Gantry themes here: ' . implode(', ', array_keys($themes)) . '.');
		}

		return $themes[$wanted];
	}

	/**
	 * The folders Gantry searches for gantry-theme://, highest priority first:
	 * custom/, the theme root, common/ (configuration.theme.base), then the
	 * parent theme without its custom/ (ThemeDetails::getPaths() and addStreams(),
	 * Component/Theme/ThemeDetails.php:73-100, 126-143).
	 *
	 * @return string[] absolute paths that exist
	 */
	protected function g5ThemePaths(array $theme, bool $withCustom = true, int $depth = 0): array
	{
		$root  = (string) $theme['root'];
		$paths = [];

		$details   = \is_array($theme['details'] ?? null) ? $theme['details'] : [];
		$overrides = (array) ($details['overrides'] ?? 'gantry-theme://custom');
		$base      = (array) ($details['base'] ?? 'gantry-theme://common');

		$map = static function (string $uri) use ($root): ?string {
			if (str_starts_with($uri, 'gantry-theme://')) {
				$rel = trim(substr($uri, \strlen('gantry-theme://')), '/');

				return $rel === '' ? $root : $root . '/' . $rel;
			}

			// Anything else (an absolute stream) is not resolvable without Gantry.
			return null;
		};

		if ($withCustom) {
			foreach ($overrides as $uri) {
				$p = $map((string) $uri);

				if ($p !== null) {
					$paths[] = $p;
				}
			}
		}

		$paths[] = $root;

		foreach ($base as $uri) {
			$p = $map((string) $uri);

			if ($p !== null) {
				$paths[] = $p;
			}
		}

		if (!empty($theme['parent']) && $depth < 5) {
			$themes = $this->g5Themes();

			if (isset($themes[$theme['parent']])) {
				$paths = array_merge($paths, $this->g5ThemePaths($themes[$theme['parent']], false, $depth + 1));
			}
		}

		return array_values(array_unique(array_filter($paths, 'is_dir')));
	}

	/** The engine folder (gantry-engine://), media/gantry5/engines/<engine> (Framework/Platform.php getEnginesPaths()). */
	protected function g5EnginePath(array $theme): string
	{
		$engine = preg_replace('/[^a-z0-9_-]/i', '', (string) ($theme['engine'] ?? 'nucleus')) ?: 'nucleus';

		return JPATH_SITE . '/media/gantry5/engines/' . $engine;
	}

	/** gantry-theme://custom for writing. */
	protected function g5CustomPath(array $theme): string
	{
		return (string) $theme['root'] . '/custom';
	}

	// -----------------------------------------------------------------------
	// Outlines
	// -----------------------------------------------------------------------

	/**
	 * Every template style of a theme, keyed by id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function g5Styles(string $theme): array
	{
		if (isset(self::$g5StylesCache[$theme])) {
			return self::$g5StylesCache[$theme];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select(['id', 'template', 'home', 'title', 'params'])
				->from($this->db->quoteName('#__template_styles'))
				->where($this->db->quoteName('client_id') . ' = 0')
				->where($this->db->quoteName('template') . ' = :template')
				->bind(':template', $theme)
				->order($this->db->quoteName('id'))
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$params = json_decode((string) $row['params'], true);
			$params = \is_array($params) ? $params : [];

			$out[(int) $row['id']] = [
				'id'            => (int) $row['id'],
				'title'         => (string) $row['title'],
				'home'          => (string) $row['home'],
				'preset'        => isset($params['preset']) ? (string) $params['preset'] : null,
				'configuration' => isset($params['configuration']) ? (string) $params['configuration'] : null,
				'params'        => $params,
			];
		}

		self::$g5StylesCache[$theme] = $out;

		return $out;
	}

	/**
	 * Before writing to a style's folder, make sure the style names that folder
	 * in its `configuration` param. Gantry's admin, every time it lists outlines,
	 * copies custom/config/<preset> OVER custom/config/<id> for any style whose
	 * `configuration` is missing and whose preset differs from its id
	 * (Framework/Outlines.php:104-111, StyleHelper::copy()), and only then sets
	 * `configuration` itself. Gantry's own installer leaves every style without
	 * it, so on a freshly installed theme the first visit to Gantry's admin would
	 * wipe a change made here. Setting the param first is exactly what Gantry
	 * does after that copy (ThemeInstaller::updateStyle($id, ['configuration' =>
	 * $id])) and does not change what the style renders (Outlines::current()
	 * already uses the style id when `configuration` is missing).
	 *
	 * @return string|null what was done, or null when nothing was needed
	 */
	protected function g5EnsureStyleConfiguration(array $outline): ?string
	{
		$style = $outline['style'] ?? null;

		if ($style === null || $style['configuration'] !== null) {
			return null;
		}

		$params                  = $style['params'];
		$params['configuration'] = (string) $style['id'];

		$this->db->setQuery(
			$this->db->getQuery(true)
				->update($this->db->quoteName('#__template_styles'))
				->set($this->db->quoteName('params') . ' = ' . $this->db->quote((string) json_encode($params)))
				->where($this->db->quoteName('id') . ' = ' . (int) $style['id'])
		)->execute();

		$this->g5ResetStyleCache();

		return 'Template style ' . $style['id'] . ' had no "configuration" param; set it to "' . $style['id'] . '" so Gantry\'s admin does not copy '
			. 'custom/config/' . ($style['preset'] ?? 'default') . ' over this outline the next time it lists outlines.';
	}

	/** Forget cached styles after a write that adds one. */
	protected function g5ResetStyleCache(): void
	{
		self::$g5StylesCache = [];
	}

	/**
	 * Every outline of a theme: the four built-in ones, then one per template
	 * style, keyed by outline id (a string).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function g5Outlines(array $theme): array
	{
		$out = [];

		foreach (self::G5_SYSTEM_OUTLINES as $id => $title) {
			$out[$id] = ['id' => $id, 'title' => $title, 'kind' => $id === 'default' ? 'base' : 'system', 'style' => null];
		}

		$prefix = $this->g5StyleTitlePrefix($theme);

		foreach ($this->g5Styles((string) $theme['element']) as $id => $style) {
			$title = $prefix !== '' && str_starts_with($style['title'], $prefix) ? substr($style['title'], \strlen($prefix)) : $style['title'];

			$out[(string) $id] = ['id' => (string) $id, 'title' => $title, 'kind' => 'style', 'style' => $style];
		}

		return $out;
	}

	/**
	 * "Helium - " in "Helium - Default". Gantry names styles with
	 * ThemeInstaller::getStyleName('%s - Title') (Framework/ThemeInstaller.php:138)
	 * and strips the same prefix for display (Framework/Outlines.php:100-112).
	 */
	protected function g5StyleTitlePrefix(array $theme): string
	{
		$name = (string) ($theme['name'] ?? '');

		return $name !== '' ? $name . ' - ' : '';
	}

	/**
	 * An outline argument, validated against the theme's outlines. Accepts the
	 * outline id ("default", "16") or a style title.
	 *
	 * @return array<string,mixed> the g5Outlines() entry
	 */
	protected function g5RequireOutline(array $theme, mixed $value, string $field = 'outline'): array
	{
		$outlines = $this->g5Outlines($theme);
		$wanted   = trim((string) ($value ?? ''));

		if ($wanted === '') {
			throw new \InvalidArgumentException($field . ' is required. Outlines of ' . $theme['element'] . ': ' . $this->g5OutlineListText($outlines) . '.');
		}

		if (isset($outlines[$wanted])) {
			return $outlines[$wanted];
		}

		foreach ($outlines as $outline) {
			if (strcasecmp($outline['title'], $wanted) === 0 || ($outline['style'] !== null && strcasecmp($outline['style']['title'], $wanted) === 0)) {
				return $outline;
			}
		}

		throw new \InvalidArgumentException($field . ' "' . $wanted . '" is not an outline of ' . $theme['element'] . '. Outlines: ' . $this->g5OutlineListText($outlines) . '.');
	}

	protected function g5OutlineListText(array $outlines): string
	{
		$parts = [];

		foreach ($outlines as $id => $o) {
			$parts[] = $id . ' (' . $o['title'] . ')';
		}

		return implode(', ', $parts);
	}

	/**
	 * The config folder name a style actually renders with, as
	 * Outlines::current() decides it (Framework/Outlines.php:54-84): the
	 * `configuration` param, else the style id, if that folder exists in any
	 * config path; otherwise the `preset` (default "default").
	 *
	 * @return array{folder:string,reason:string}
	 */
	protected function g5StyleConfigFolder(array $theme, array $style): array
	{
		$candidate = $style['configuration'] !== null && $style['configuration'] !== '' ? $style['configuration'] : (string) $style['id'];

		if ($this->g5SafeName($candidate) && $this->g5ConfigDirs($theme, $candidate) !== []) {
			return ['folder' => $candidate, 'reason' => $style['configuration'] !== null ? 'configuration param' : 'style id'];
		}

		$preset = $style['preset'] !== null && $style['preset'] !== '' ? $style['preset'] : 'default';

		return ['folder' => $preset, 'reason' => 'no config folder "' . $candidate . '", so Gantry falls back to the preset name'];
	}

	/**
	 * The config folders Gantry merges for an outline, highest priority first:
	 * <path>/config/<outline> for each theme path (gantry-config:// is
	 * gantry-theme://config, Framework/Base/Platform.php:119-122).
	 *
	 * @return string[] absolute paths that exist
	 */
	protected function g5ConfigDirs(array $theme, string $outline): array
	{
		if (!$this->g5SafeName($outline)) {
			return [];
		}

		$out = [];

		foreach ($this->g5ThemePaths($theme) as $path) {
			if (is_dir($path . '/config/' . $outline)) {
				$out[] = $path . '/config/' . $outline;
			}
		}

		return $out;
	}

	/** Where writes for an outline go: templates/<theme>/custom/config/<outline>. */
	protected function g5CustomConfigDir(array $theme, string $outline): string
	{
		if (!$this->g5SafeName($outline)) {
			throw new \InvalidArgumentException('Unsafe outline folder name "' . $outline . '".');
		}

		return $this->g5CustomPath($theme) . '/config/' . $outline;
	}

	/** Outline ids, particle types and the like become path segments only after this. */
	protected function g5SafeName(string $name): bool
	{
		return $name !== '' && preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/', $name) === 1 && !str_contains($name, '..');
	}

	// -----------------------------------------------------------------------
	// Assignments
	// -----------------------------------------------------------------------

	/**
	 * Site menu items that name a style of this theme in template_style_id.
	 *
	 * @param int[] $styleIds
	 * @return array<int,array<int,array<string,mixed>>> style id => items
	 */
	protected function g5MenuAssignments(array $styleIds): array
	{
		$styleIds = array_values(array_filter(array_map('intval', $styleIds)));

		if ($styleIds === []) {
			return [];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select(['id', 'menutype', 'title', 'alias', 'path', 'link', 'published', 'language', 'home', 'template_style_id'])
				->from($this->db->quoteName('#__menu'))
				->where($this->db->quoteName('client_id') . ' = 0')
				->where($this->db->quoteName('published') . ' <> -2')
				->whereIn($this->db->quoteName('template_style_id'), $styleIds)
				->order($this->db->quoteName('menutype') . ', ' . $this->db->quoteName('lft'))
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$out[(int) $row['template_style_id']][] = [
				'id'        => (int) $row['id'],
				'menutype'  => (string) $row['menutype'],
				'title'     => (string) $row['title'],
				'path'      => (string) $row['path'],
				'published' => (int) $row['published'],
				'language'  => (string) $row['language'],
				'home'      => (int) $row['home'],
			];
		}

		return $out;
	}

	/** The site's default (home = '1') style, any template. */
	protected function g5DefaultStyle(): ?array
	{
		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select(['id', 'template', 'title'])
				->from($this->db->quoteName('#__template_styles'))
				->where($this->db->quoteName('client_id') . ' = 0')
				->where($this->db->quoteName('home') . ' = ' . $this->db->quote('1'))
		)->loadAssoc();

		return \is_array($row) ? ['id' => (int) $row['id'], 'template' => (string) $row['template'], 'title' => (string) $row['title']] : null;
	}

	/** Per-language default styles (home = '<lang-code>'). */
	protected function g5LanguageDefaultStyles(): array
	{
		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select(['id', 'template', 'title', 'home'])
				->from($this->db->quoteName('#__template_styles'))
				->where($this->db->quoteName('client_id') . ' = 0')
				->where($this->db->quoteName('home') . ' NOT IN (' . $this->db->quote('0') . ', ' . $this->db->quote('1') . ')')
		)->loadAssocList() ?: [];

		return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'template' => (string) $r['template'], 'title' => (string) $r['title'], 'language' => (string) $r['home']], $rows);
	}

	// -----------------------------------------------------------------------
	// Joomla modules (for module positions inside layouts)
	// -----------------------------------------------------------------------

	/**
	 * Site modules in the given positions, with their menu assignment.
	 *
	 * @param string[] $positions
	 * @return array<string,array<int,array<string,mixed>>> position => modules
	 */
	protected function g5ModulesInPositions(array $positions): array
	{
		$positions = array_values(array_unique(array_filter(array_map('strval', $positions))));

		if ($positions === []) {
			return [];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select(['id', 'title', 'module', 'position', 'published', 'ordering', 'access', 'language', 'showtitle', 'publish_up', 'publish_down'])
				->from($this->db->quoteName('#__modules'))
				->where($this->db->quoteName('client_id') . ' = 0')
				->where($this->db->quoteName('published') . ' <> -2')
				->whereIn($this->db->quoteName('position'), $positions, ParameterType::STRING)
				->order($this->db->quoteName('position') . ', ' . $this->db->quoteName('ordering'))
		)->loadAssocList() ?: [];

		$menus = $this->g5ModuleMenus(array_column($rows, 'id'));
		$out   = [];

		foreach ($rows as $row) {
			$out[(string) $row['position']][] = $this->g5ModuleShape($row, $menus[(int) $row['id']] ?? []);
		}

		return $out;
	}

	/** @return array<int,int[]> module id => menu ids from #__modules_menu */
	protected function g5ModuleMenus(array $moduleIds): array
	{
		$moduleIds = array_values(array_filter(array_map('intval', $moduleIds)));

		if ($moduleIds === []) {
			return [];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select(['moduleid', 'menuid'])
				->from($this->db->quoteName('#__modules_menu'))
				->whereIn($this->db->quoteName('moduleid'), $moduleIds)
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $r) {
			$out[(int) $r['moduleid']][] = (int) $r['menuid'];
		}

		return $out;
	}

	/** #__modules_menu semantics: [0] = all, positive = only, negative = all except, none = nowhere. */
	protected function g5AssignmentMode(array $menuIds): string
	{
		if ($menuIds === []) {
			return 'none';
		}

		if (\in_array(0, $menuIds, true)) {
			return 'all';
		}

		return $menuIds[0] < 0 ? 'all_except' : 'only';
	}

	/** Would a module with this menu assignment show on this menu item? */
	protected function g5ModuleShowsOn(array $menuIds, int $itemId): bool
	{
		$mode = $this->g5AssignmentMode($menuIds);

		return match ($mode) {
			'all'        => true,
			'only'       => \in_array($itemId, $menuIds, true),
			'all_except' => !\in_array(-$itemId, $menuIds, true),
			default      => false,
		};
	}

	protected function g5ModuleShape(array $row, array $menuIds): array
	{
		return [
			'id'              => (int) $row['id'],
			'title'           => (string) $row['title'],
			'module'          => (string) $row['module'],
			'position'        => (string) $row['position'],
			'published'       => (int) $row['published'],
			'ordering'        => (int) $row['ordering'],
			'access'          => (int) $row['access'],
			'language'        => (string) $row['language'],
			'menu_assignment' => $this->g5AssignmentMode($menuIds),
			'menu_ids'        => $menuIds,
		];
	}

	// -----------------------------------------------------------------------
	// Input schema pieces (static: tools/list must not touch the database)
	// -----------------------------------------------------------------------

	protected function g5ThemeProp(): array
	{
		return ['type' => 'string', 'description' => 'Gantry theme (template element, e.g. "g5_helium"). Optional when the site has only one Gantry theme. list_gantry5_themes lists them.'];
	}

	protected function g5OutlineProp(): array
	{
		return ['type' => 'string', 'description' => 'Outline id: "default" (Base Outline), "_body_only", "_error", "_offline", or a template style id such as "16". A style title ("Home - Particles") also works. list_gantry5_outlines lists them.'];
	}

	protected function g5SetProp(string $what): array
	{
		return [
			'type'        => 'object',
			'description' => 'Settings to change, as {"key": value}. Keys are ' . $what . ' blueprint keys; nested keys may be dotted ("css.class") or nested objects ({"css": {"class": "x"}}). Unknown keys are refused. Checkboxes take true/false.',
		];
	}

	protected function g5UnsetProp(string $effect): array
	{
		return ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Keys to remove. ' . $effect];
	}

	protected function g5DryRunProp(): array
	{
		return ['type' => 'boolean', 'description' => 'Validate and show the change without writing anything. Default false.'];
	}

	/** @return string[] */
	protected function g5UnsetList(array $arguments): array
	{
		$unset = $arguments['unset'] ?? [];

		if (!\is_array($unset)) {
			throw new \InvalidArgumentException('unset must be an array of keys.');
		}

		return array_values(array_unique(array_map(static fn ($k): string => trim((string) $k), $unset)));
	}

	/** @return array<string,mixed> */
	protected function g5SetList(array $arguments): array
	{
		$set = $arguments['set'] ?? [];

		if (!\is_array($set) || ($set !== [] && array_is_list($set))) {
			throw new \InvalidArgumentException('set must be an object of key => value.');
		}

		return $set;
	}

	// -----------------------------------------------------------------------
	// Small helpers
	// -----------------------------------------------------------------------

	/** An associative array for JSON output: {} rather than [] when empty. */
	protected function g5Map(array $map): array|\stdClass
	{
		return $map === [] ? new \stdClass() : $map;
	}

	/**
	 * A particle with a `twig` setting (Custom HTML) compiles its content as Twig
	 * when that setting is on (nucleus/particles/custom.html.twig:
	 * gantry.theme.compile(particle.html)). Gantry bundles Twig 2.16, whose
	 * map/filter/reduce filters accept PHP callables by name, so Twig content is
	 * server-side code. Gantry's admin lets anyone into the Gantry admin do it;
	 * these tools only let a Super User turn Twig on, or change the content of a
	 * particle that has it on.
	 */
	protected function g5GuardTwig(User $actor, array $fields, array $changedKeys, mixed $twigAfter, string $what): void
	{
		if (!isset($fields['twig']) || $actor->authorise('core.admin')) {
			return;
		}

		$on = $twigAfter !== null && $twigAfter !== false && (string) $twigAfter !== '0' && (string) $twigAfter !== '';

		if ($on && array_intersect($changedKeys, ['twig', 'html']) !== []) {
			throw new \RuntimeException(
				$what . ' processes its content as Twig, which runs on the server. Only a Super User may enable Twig or change '
				. 'Twig-processed content through these tools. Nothing was written.'
			);
		}
	}

	/** A path relative to the Joomla root, for responses. */
	protected function g5Rel(string $path): string
	{
		$root = rtrim(str_replace('\\', '/', JPATH_ROOT), '/') . '/';
		$path = str_replace('\\', '/', $path);

		return str_starts_with($path, $root) ? substr($path, \strlen($root)) : $path;
	}

	protected function g5Now(): string
	{
		return Factory::getDate()->toSql();
	}
}
