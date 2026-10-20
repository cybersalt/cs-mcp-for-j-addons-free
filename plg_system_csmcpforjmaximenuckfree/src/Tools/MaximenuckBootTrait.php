<?php
/**
 * @package     MCP for Maximenu CK
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

/**
 * Shared orientation for the Maximenu CK tools: is it installed, which
 * edition, where its modules / styles / compiled CSS live, and the guards that
 * keep a well-formed write from silently doing nothing.
 *
 * ---------------------------------------------------------------------------
 * EDITION DETECTION
 * ---------------------------------------------------------------------------
 *
 * Unlike Page Builder CK (same vendor), Maximenu CK Light and Pro are NOT one
 * codebase with a `pro/` directory switch. They are two different builds of the
 * same `com_maximenuck` element:
 *
 *   - Light: manifest `<ckpro>0</ckpro><variant>free</variant>`; installs the
 *     component and `mod_maximenuck` only.
 *   - Pro:   manifest `<ckpro>1</ckpro><variant>pro</variant>`; additionally
 *     installs `plg_system_maximenuck` (which adds the Maximenu tab to Joomla's
 *     menu-item form and renders per-item styles) and the `maximenuck` source
 *     plugins (K2, VirtueMart, HikaShop, ...).
 *
 * The vendor's own Light installer decides "is Pro installed?" by reading
 * `<ckpro>` from the installed component manifest (install.php:134), so that is
 * the authority here too. The system plugin is reported as a second signal.
 *
 * ---------------------------------------------------------------------------
 * THE PAYWALL WE DO NOT ROUTE AROUND
 * ---------------------------------------------------------------------------
 *
 * In Light, every per-item mega-menu control in the Maximenu menu manager
 * (create column, column width, new row, submenu width/height/margins, full
 * width, tab, desktop/mobile toggles, icon, module insertion) calls the vendor's
 * `ckProOny()` nag instead of saving. The renderer and the save controller
 * (`joomlamenu.ajaxSaveParam`) are identical in both builds, so writing
 * `maximenu_createcolumn` into `#__menu.params` would work perfectly on Light.
 *
 * This free add-on deliberately does not. It READS those settings (a read is not
 * a feature, and a site that downgraded from Pro keeps them) but every write to
 * them lives in the separate Pro add-on, which additionally refuses unless the
 * vendor's Pro build is installed. A weak paywall is not permission to walk
 * through it.
 */
trait MaximenuckBootTrait
{
	private static ?array $mxManifestCache = null;
	private static array $mxTableCache = [];

	// -----------------------------------------------------------------------
	// Presence
	// -----------------------------------------------------------------------

	public function getCategory(): string
	{
		return 'maximenuck';
	}

	protected function mxAdminBase(): ?string
	{
		$path = JPATH_ADMINISTRATOR . '/components/com_maximenuck';

		return is_dir($path) ? $path : null;
	}

	protected function mxModuleBase(): ?string
	{
		$path = JPATH_SITE . '/modules/mod_maximenuck';

		return is_dir($path) ? $path : null;
	}

	protected function mxNotInstalledError(): ToolResult
	{
		return ToolResult::json([
			'ok'    => false,
			'error' => 'Maximenu CK is not installed on this site. Expected '
				. 'administrator/components/com_maximenuck. Install it from '
				. 'https://www.joomlack.fr/en/joomla-extensions/maximenu-ck before using these tools.',
		], true);
	}

	// -----------------------------------------------------------------------
	// Edition
	// -----------------------------------------------------------------------

	/** @return array{version:?string,ckpro:?string,variant:?string}|null */
	protected function mxManifest(): ?array
	{
		if (self::$mxManifestCache !== null) {
			return self::$mxManifestCache === [] ? null : self::$mxManifestCache;
		}

		self::$mxManifestCache = [];
		$base = $this->mxAdminBase();

		if ($base === null || !is_file($base . '/maximenuck.xml')) {
			return null;
		}

		$xml = @simplexml_load_file($base . '/maximenuck.xml');

		if ($xml === false) {
			return null;
		}

		return self::$mxManifestCache = [
			'version' => trim((string) ($xml->version ?? '')) ?: null,
			'ckpro'   => isset($xml->ckpro) ? trim((string) $xml->ckpro) : null,
			'variant' => isset($xml->variant) ? trim((string) $xml->variant) : null,
		];
	}

	protected function mxVersion(): ?string
	{
		return $this->mxManifest()['version'] ?? null;
	}

	/** The vendor's own test: `(int) $xml->ckpro` on the installed component manifest. */
	protected function mxVendorIsPro(): bool
	{
		return (int) ($this->mxManifest()['ckpro'] ?? 0) === 1;
	}

	/**
	 * The Pro system plugin's state: present on disk, registered, enabled.
	 *
	 * @return array{on_disk:bool,registered:bool,enabled:bool}
	 */
	protected function mxSystemPluginState(): array
	{
		$onDisk = is_file(JPATH_SITE . '/plugins/system/maximenuck/maximenuck.php');

		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('enabled'))
				->from($this->db->quoteName('#__extensions'))
				->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
				->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('system'))
				->where($this->db->quoteName('element') . ' = ' . $this->db->quote('maximenuck'))
		)->loadResult();

		return [
			'on_disk'    => $onDisk,
			'registered' => $row !== null,
			'enabled'    => $row !== null && (int) $row === 1,
		];
	}

	/** @return array<int,array{element:string,enabled:bool}> */
	protected function mxSourcePlugins(): array
	{
		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([$this->db->quoteName('element'), $this->db->quoteName('enabled')])
				->from($this->db->quoteName('#__extensions'))
				->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
				->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('maximenuck'))
				->order($this->db->quoteName('element'))
		)->loadAssocList() ?: [];

		return array_map(static fn ($r) => [
			'element' => (string) $r['element'],
			'enabled' => (int) $r['enabled'] === 1,
		], $rows);
	}

	/** Compact edition summary for inclusion in tool responses. */
	protected function mxEditionNotice(): array
	{
		$manifest = $this->mxManifest() ?? [];
		$plugin   = $this->mxSystemPluginState();
		$isPro    = $this->mxVendorIsPro();

		$notice = [
			'name'                  => 'Maximenu CK',
			'version'               => $manifest['version'] ?? null,
			'edition'               => $isPro ? 'pro' : 'light',
			'manifest_ckpro'        => $manifest['ckpro'] ?? null,
			'manifest_variant'      => $manifest['variant'] ?? null,
			'pro_system_plugin'     => $plugin,
		];

		if ($isPro && !$plugin['enabled']) {
			$notice['warning'] = 'The component manifest says Pro, but the Pro system plugin '
				. '(plg_system_maximenuck) is ' . ($plugin['registered'] ? 'disabled' : 'not installed')
				. '. Per-item Maximenu settings stored on menu items are still honoured for columns and '
				. 'widths, but per-item STYLES are not rendered and the Maximenu tab is missing from '
				. 'Joomla\'s menu-item form until it is enabled.';
		} elseif (!$isPro && $plugin['registered']) {
			$notice['warning'] = 'The component manifest says Light, but the Pro system plugin '
				. 'plg_system_maximenuck is still registered. This normally means Pro was uninstalled '
				. 'and Light installed in its place, leaving the plugin behind.';
		}

		return $notice;
	}

	// -----------------------------------------------------------------------
	// Tables
	// -----------------------------------------------------------------------

	protected function mxTableExists(string $unprefixed): bool
	{
		if (\array_key_exists($unprefixed, self::$mxTableCache)) {
			return self::$mxTableCache[$unprefixed];
		}

		$prefixed = $this->db->replacePrefix('#__maximenuck_' . $unprefixed);

		return self::$mxTableCache[$unprefixed] = \in_array($prefixed, $this->db->getTableList() ?: [], true);
	}

	protected function mxMissingTableError(string $unprefixed): ToolResult
	{
		return ToolResult::json([
			'ok'    => false,
			'error' => '#__maximenuck_' . $unprefixed . ' does not exist. Maximenu CK creates it at '
				. 'install (and re-checks on every install/update via Helper::checkDbIntegrity()), so '
				. 'reinstalling the component over itself recreates it without touching existing data.',
		], true);
	}

	// -----------------------------------------------------------------------
	// Modules
	// -----------------------------------------------------------------------

	/**
	 * Every mod_maximenuck module (site client), newest-first by id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function mxModules(?int $onlyId = null): array
	{
		$q = $this->db->getQuery(true)
			->select(['id', 'title', 'position', 'published', 'params', 'access', 'language', 'showtitle'])
			->from($this->db->quoteName('#__modules'))
			->where($this->db->quoteName('module') . ' = ' . $this->db->quote('mod_maximenuck'))
			->where($this->db->quoteName('client_id') . ' = 0')
			->order($this->db->quoteName('id') . ' ASC');

		if ($onlyId !== null) {
			$q->where($this->db->quoteName('id') . ' = ' . (int) $onlyId);
		}

		$rows = $this->db->setQuery($q)->loadAssocList() ?: [];

		foreach ($rows as &$row) {
			$row['id']        = (int) $row['id'];
			$row['published'] = (int) $row['published'];
			$decoded          = json_decode((string) $row['params'], true);
			$row['params']    = \is_array($decoded) ? $decoded : [];
		}

		return $rows;
	}

	/**
	 * The HTML id Maximenu renders for a module — `mod_maximenuck.php:24-27`:
	 * an empty or numeric `menuid` param becomes "maximenuck<moduleid>".
	 */
	protected function mxMenuHtmlId(array $module): string
	{
		$menuid = (string) ($module['params']['menuid'] ?? '');

		if ($menuid === '' || is_numeric($menuid)) {
			return 'maximenuck' . (int) $module['id'];
		}

		return $menuid;
	}

	/**
	 * A safe projection of the module params that matter for layout and CSS.
	 *
	 * @return array<string,mixed>
	 */
	/**
	 * A module param read the way Joomla's Registry::get() reads it: a missing,
	 * null OR EMPTY-STRING value yields the default. This matters: the module
	 * form stores the hidden `isv9` field as "" and Maximenu's
	 * `$params->get('isv9', '1') == '1'` therefore treats it as v9.
	 */
	protected function mxModParam(array $module, string $key, string $default = ''): string
	{
		$v = $module['params'][$key] ?? null;

		return ($v === null || $v === '' || \is_array($v)) ? $default : (string) $v;
	}

	protected function mxModuleSummary(array $module): array
	{
		$compiled = $this->mxCompiledCssInfo($module);

		return [
			'id'               => $module['id'],
			'title'            => $module['title'],
			'position'         => $module['position'],
			'published'        => $module['published'],
			'menu_html_id'     => $this->mxMenuHtmlId($module),
			'source'           => $this->mxModParam($module, 'source', 'menu'),
			'menutype'         => $this->mxModParam($module, 'menutype', ''),
			'style_id'         => (int) $this->mxModParam($module, 'styles', '0'),
			'theme'            => $this->mxModParam($module, 'theme', 'mega9'),
			'layout'           => $this->mxModParam($module, 'layout', '_:default'),
			'orientation'      => $this->mxModParam($module, 'orientation', 'horizontal'),
			'start_level'      => (int) $this->mxModParam($module, 'startLevel', '0'),
			'end_level'        => (int) $this->mxModParam($module, 'endLevel', '0'),
			'testoverflow'     => $this->mxModParam($module, 'testoverflow', '0'),
			'loadcompiledcss'  => $compiled['mode'],
			'compiled_css'     => $compiled,
			'v9_mode'          => $this->mxModParam($module, 'isv9', '1') === '1',
			'dead_module_customcss_bytes' => \strlen((string) ($module['params']['customcss'] ?? '')),
		];
	}

	// -----------------------------------------------------------------------
	// Compiled CSS (modules/mod_maximenuck/themes/custom/css/maximenuck_<id>.css)
	// -----------------------------------------------------------------------

	/**
	 * Where a module's compiled stylesheet lives, and whether it can go stale.
	 *
	 * `loadcompiledcss` (mod_maximenuck.php:31-44, 486-503, 827-838):
	 *   '0' = no file; all CSS is inlined into the page on every render.
	 *   '2' = "Compile" (the vendor default): the file is rewritten on EVERY render.
	 *   '1' = "Yes": the file is written ONCE, then served as-is for ever. Any
	 *         later style or menu-item change is invisible until the file is
	 *         deleted. This is the stale-CSS trap.
	 *
	 * @return array<string,mixed>
	 */
	protected function mxCompiledCssInfo(array $module): array
	{
		$mode   = $this->mxModParam($module, 'loadcompiledcss', '0');
		$htmlId = $this->mxMenuHtmlId($module);
		$safe   = preg_match('/^[A-Za-z0-9_-]+$/', $htmlId) === 1;
		$rel    = 'modules/mod_maximenuck/themes/custom/css/maximenuck_' . $htmlId . '.css';
		$abs    = JPATH_SITE . '/' . $rel;
		$exists = $safe && is_file($abs);

		$info = [
			'mode'        => $mode,
			'mode_label'  => match ($mode) {
				'0'     => 'inline (no compiled file)',
				'1'     => 'compiled once (served stale until the file is deleted)',
				'2'     => 'compiled on every render',
				default => 'unknown',
			},
			'file'        => $rel,
			'file_exists' => $exists,
			'path_safe'   => $safe,
		];

		if ($exists) {
			$info['file_bytes'] = (int) @filesize($abs);
			$info['file_mtime'] = gmdate('c', (int) @filemtime($abs));
		}

		if ($mode === '1' && $exists) {
			$info['stale_risk'] = true;
		}

		if ($mode !== '0') {
			$info['url']          = rtrim(Uri::root(), '/') . '/' . $rel;
			$info['cache_buster'] = false;
		}

		return $info;
	}

	/**
	 * Maximenu links the compiled file with NO version query
	 * (mod_maximenuck.php:502), so a browser or CDN that cached it keeps the
	 * old CSS. Measured on mcpfree.basicjoomla.com 2026-10-19: Cloudflare HIT
	 * with Cache-Control max-age=2592000 (30 days) after the file had changed.
	 *
	 * @param array<int,array<string,mixed>> $modules
	 */
	protected function mxCompiledCacheWarning(array $modules): ?string
	{
		$urls = [];

		foreach ($modules as $m) {
			$info = $this->mxCompiledCssInfo($m);

			if ($info['mode'] !== '0' && isset($info['url'])) {
				$urls[] = $info['url'];
			}
		}

		if ($urls === []) {
			return null;
		}

		return 'This CSS is served from a compiled file that Maximenu links WITHOUT a cache-buster: '
			. implode(', ', array_unique($urls)) . '. A CDN or browser that already cached it keeps serving the '
			. 'OLD CSS (up to its max-age — 30 days on a typical server config) even though the file on disk is '
			. 'new. Purge that URL at the CDN (e.g. Cloudflare) after the next page render, or set the module\'s '
			. '"Load compiled CSS" to No (loadcompiledcss = 0, via core update_module) so the CSS is inlined in '
			. 'the page and changes with it. Verify with a cache-busted fetch (?v=<time>) — a plain reload can '
			. 'show the stale copy.';
	}

	/**
	 * Delete a module's compiled stylesheet so the next front-end render
	 * rebuilds it. Returns what happened, never throws.
	 *
	 * @return array<string,mixed>
	 */
	protected function mxPurgeCompiledCss(array $module): array
	{
		$info = $this->mxCompiledCssInfo($module);
		$out  = ['module_id' => $module['id'], 'file' => $info['file'], 'mode' => $info['mode']];

		if (!$info['path_safe']) {
			$out['action'] = 'skipped';
			$out['reason'] = 'The module\'s menuid param contains characters outside [A-Za-z0-9_-]; '
				. 'refusing to build a filesystem path from it.';

			return $out;
		}

		if (!$info['file_exists']) {
			$out['action'] = 'nothing_to_do';

			return $out;
		}

		$abs = JPATH_SITE . '/' . $info['file'];
		$dir = realpath(JPATH_SITE . '/modules/mod_maximenuck/themes/custom/css');
		$real = realpath($abs);

		if ($dir === false || $real === false || !str_starts_with($real, $dir . DIRECTORY_SEPARATOR)) {
			$out['action'] = 'skipped';
			$out['reason'] = 'Resolved path is outside the compiled-CSS directory.';

			return $out;
		}

		$out['action'] = @unlink($real) ? 'deleted' : 'delete_failed';

		if ($out['action'] === 'deleted') {
			$out['note'] = $info['mode'] === '0'
				? 'Mode 0 does not use this file; it was a leftover.'
				: 'Rebuilt on the next front-end render of a page showing this module.';
		}

		return $out;
	}

	/**
	 * Clean the Joomla cache groups that can hold a rendered Maximenu.
	 *
	 * @param array<int,string> $groups
	 * @return array<int,string> groups cleaned
	 */
	protected function mxCleanCacheGroups(array $groups): array
	{
		$cleaned = [];

		try {
			$factory = Factory::getContainer()->get(CacheControllerFactoryInterface::class);
		} catch (\Throwable $e) {
			return $cleaned;
		}

		foreach ($groups as $group) {
			try {
				$controller = $factory->createCacheController('callback', [
					'defaultgroup' => $group,
					'cachebase'    => JPATH_SITE . '/cache',
					'caching'      => true,
				]);
				$controller->clean($group);
				$cleaned[] = $group;
			} catch (\Throwable $e) {
				// best effort
			}
		}

		return $cleaned;
	}

	// -----------------------------------------------------------------------
	// Styles
	// -----------------------------------------------------------------------

	/** @return array<string,mixed>|null */
	protected function mxLoadStyle(int $id): ?array
	{
		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName('#__maximenuck_styles'))
				->where($this->db->quoteName('id') . ' = ' . (int) $id)
		)->loadAssoc();

		if (!$row) {
			return null;
		}

		$row['id']    = (int) $row['id'];
		$row['state'] = (int) $row['state'];

		return $row;
	}

	/** @return array<int,array<string,mixed>> modules whose `styles` param points at $styleId */
	protected function mxModulesUsingStyle(int $styleId): array
	{
		return array_values(array_filter(
			$this->mxModules(),
			fn ($m) => (int) $this->mxModParam($m, 'styles', '0') === $styleId
		));
	}

	// -----------------------------------------------------------------------
	// Menu items
	// -----------------------------------------------------------------------

	/**
	 * Menu items for a menutype in tree order, with decoded params.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function mxMenuItems(string $menutype): array
	{
		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select(['id', 'title', 'alias', 'level', 'parent_id', 'published', 'type', 'link', 'params', 'lft', 'access', 'language'])
				->from($this->db->quoteName('#__menu'))
				->where($this->db->quoteName('menutype') . ' = ' . $this->db->quote($menutype))
				->where($this->db->quoteName('client_id') . ' = 0')
				->where($this->db->quoteName('published') . ' != -2')
				->order($this->db->quoteName('lft') . ' ASC')
		)->loadAssocList() ?: [];

		foreach ($rows as &$row) {
			foreach (['id', 'level', 'parent_id', 'published', 'lft', 'access'] as $k) {
				$row[$k] = (int) $row[$k];
			}

			$decoded       = json_decode((string) $row['params'], true);
			$row['params'] = \is_array($decoded) ? $decoded : [];
		}

		return $rows;
	}

	/** @return array<string,mixed>|null */
	protected function mxMenuItem(int $id): ?array
	{
		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select(['id', 'title', 'alias', 'menutype', 'level', 'parent_id', 'published', 'type', 'link', 'params', 'client_id'])
				->from($this->db->quoteName('#__menu'))
				->where($this->db->quoteName('id') . ' = ' . (int) $id)
		)->loadAssoc();

		if (!$row) {
			return null;
		}

		foreach (['id', 'level', 'parent_id', 'published', 'client_id'] as $k) {
			$row[$k] = (int) $row[$k];
		}

		$decoded       = json_decode((string) $row['params'], true);
		$row['params'] = \is_array($decoded) ? $decoded : [];

		return $row;
	}

	/**
	 * Every Maximenu key present in an item's params.
	 *
	 * Includes `mobilemenuck_*` / `maximenumobile_*` because the renderer reads
	 * them for the show/hide classes, and the per-item style groups
	 * (`itemnormalstyles*`, `itemhoverstyles*`, `itemactivestyles*`,
	 * `submenustyles*`) that the Pro system plugin renders.
	 *
	 * @return array<string,mixed>
	 */
	protected function mxItemMaximenuParams(array $params): array
	{
		$out = [];

		foreach ($params as $k => $v) {
			if (preg_match('/^(maximenu_|maximenumobile_|mobilemenuck_|itemnormalstyles|itemhoverstyles|itemactivestyles|submenustyles)/', (string) $k)) {
				$out[$k] = $v;
			}
		}

		ksort($out);

		return $out;
	}

	/**
	 * Values the vendor's own title-marker syntax encodes (helpers/source/menu.php):
	 * `[col=N]`, `[newrow]`, `[subwidth=N]`, `[modid=N]`, `[rel=x]`, `Title||desc`.
	 * These still work in v10 Light and Pro, as a legacy alternative to params.
	 *
	 * @return array<string,mixed>
	 */
	protected function mxTitleMarkers(string $title): array
	{
		$m = [];

		if (preg_match('/\[col=([0-9]+)\]/', $title, $r)) {
			$m['col'] = $r[1];
		}

		if (stristr($title, '[newrow]')) {
			$m['newrow'] = true;
		}

		if (preg_match('/\[subwidth=([0-9]+)\]/', $title, $r)) {
			$m['subwidth'] = $r[1];
		}

		if (preg_match('/\[modid=([0-9]+)\]/', $title, $r)) {
			$m['modid'] = (int) $r[1];
		}

		if (preg_match('/\[rel=([a-z]+)\]/i', $title, $r)) {
			$m['rel'] = $r[1];
		}

		if (str_contains($title, '||')) {
			$m['description'] = explode('||', $title, 2)[1];
		}

		return $m;
	}

	// -----------------------------------------------------------------------
	// Small shared helpers
	// -----------------------------------------------------------------------

	protected function mxPreview(?string $value, int $max = 600): array
	{
		$value = (string) $value;
		$bytes = \strlen($value);

		return [
			'bytes'     => $bytes,
			'truncated' => $bytes > $max,
			'text'      => $bytes > $max ? substr($value, 0, $max) . '…' : $value,
		];
	}

	/**
	 * `#__maximenuck_*` tables are utf8 (3-byte). A 4-byte character either
	 * errors or truncates the value at that byte, depending on SQL mode.
	 */
	protected function mxHasAstral(string $value): bool
	{
		return preg_match('/[\x{10000}-\x{10FFFF}]/u', $value) === 1;
	}
}
