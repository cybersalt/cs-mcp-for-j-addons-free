<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjgantry5\Tools;

\defined('_JEXEC') or die;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;

/**
 * YAML reading and writing, backups, and cache clearing. Used through
 * Gantry5BootTrait.
 *
 * Every write goes through g5WriteConfigFile(), which:
 *   1. refuses any path outside templates/<theme>/custom/config/<outline>/;
 *   2. copies the current file to the backup folder first (or records that it
 *      did not exist, so a restore deletes it again);
 *   3. dumps the YAML the way Gantry does (Yaml::dump($data, $inline, 2) in
 *      RocketTheme\Toolbox\File\YamlFile::encode());
 *   4. re-parses the dump and refuses to write if it does not round-trip to
 *      the intended data;
 *   5. writes a temporary file and renames it over the original, then reads
 *      it back.
 *
 * Backups live in <Joomla tmp_path>/csmcpforjgantry5-backups/ rather than under
 * the template: Gantry's ConfigFileFinder loads EVERY *.yaml below an outline's
 * config folder as configuration (Component/Config/ConfigFileFinder.php:49-56),
 * so a backup kept beside the original would be read as a particle or page
 * setting. The folder gets an .htaccess deny and an index.html.
 */
trait Gantry5FilesTrait
{
	// -----------------------------------------------------------------------
	// YAML
	// -----------------------------------------------------------------------

	protected function g5YamlAvailable(): bool
	{
		return class_exists(\Symfony\Component\Yaml\Yaml::class);
	}

	/** Where symfony/yaml was loaded from (Joomla's copy or Gantry's bundled one). */
	protected function g5YamlSource(): ?string
	{
		if (!$this->g5YamlAvailable()) {
			return null;
		}

		$file = (new \ReflectionClass(\Symfony\Component\Yaml\Yaml::class))->getFileName();

		return $file ? $this->g5Rel((string) $file) : null;
	}

	/** @return array<mixed> */
	protected function g5ParseYaml(string $yaml, string $label = 'YAML'): array
	{
		if (!$this->g5YamlAvailable()) {
			throw new \RuntimeException('symfony/yaml is not loadable on this site (Joomla ships it in libraries/vendor/symfony/yaml).');
		}

		if (trim($yaml) === '') {
			return [];
		}

		try {
			$data = \Symfony\Component\Yaml\Yaml::parse($yaml);
		} catch (\Throwable $e) {
			throw new \RuntimeException($label . ' is not valid YAML: ' . $e->getMessage());
		}

		if ($data === null) {
			return [];
		}

		if (!\is_array($data)) {
			throw new \RuntimeException($label . ' does not contain a YAML mapping.');
		}

		return $data;
	}

	/** @return array<mixed> */
	protected function g5ParseYamlFile(string $file): array
	{
		if (!is_file($file)) {
			return [];
		}

		$raw = @file_get_contents($file);

		if ($raw === false) {
			throw new \RuntimeException($this->g5Rel($file) . ' cannot be read.');
		}

		return $this->g5ParseYaml($raw, $this->g5Rel($file));
	}

	/** Gantry's own encoding: YamlFile::encode() = Yaml::dump($var, inline (5 default, 20 for layouts), 2). */
	protected function g5DumpYaml(array $data, int $inline = 5): string
	{
		if (!$this->g5YamlAvailable()) {
			throw new \RuntimeException('symfony/yaml is not loadable on this site.');
		}

		return \Symfony\Component\Yaml\Yaml::dump($data, $inline, 2);
	}

	// -----------------------------------------------------------------------
	// Writing
	// -----------------------------------------------------------------------

	/**
	 * A relative config path like "particles/logo.yaml", "styles.yaml" or
	 * "layout.yaml": safe segments only, .yaml only.
	 */
	protected function g5RequireSafeRelPath(string $rel): string
	{
		$rel = str_replace('\\', '/', trim($rel, '/'));

		if ($rel === '' || !str_ends_with($rel, '.yaml')) {
			throw new \InvalidArgumentException('Refusing to write "' . $rel . '": only .yaml config files are written.');
		}

		foreach (explode('/', $rel) as $segment) {
			if (!$this->g5SafeName($segment)) {
				throw new \InvalidArgumentException('Refusing unsafe path segment "' . $segment . '".');
			}
		}

		return $rel;
	}

	/**
	 * Write one config file of an outline under custom/config/, with a backup.
	 *
	 * @return array{file:string,backup:string,backup_file:string,created:bool,bytes:int}
	 */
	protected function g5WriteConfigFile(array $theme, string $outline, string $rel, array $data, int $inline = 5): array
	{
		$rel    = $this->g5RequireSafeRelPath($rel);
		$dir    = $this->g5CustomConfigDir($theme, $outline);
		$target = $dir . '/' . $rel;

		$this->g5RequireInside($target, $this->g5CustomPath($theme) . '/config');

		$yaml = $this->g5DumpYaml($data, $inline);

		// Refuse to write anything that does not read back as exactly what we meant.
		if ($this->g5ParseYaml($yaml, 'the YAML about to be written') != $data) {
			throw new \RuntimeException('The YAML produced for ' . $this->g5Rel($target) . ' does not parse back to the intended data. Nothing was written.');
		}

		$parent = \dirname($target);

		if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
			throw new \RuntimeException('Cannot create ' . $this->g5Rel($parent) . '. Nothing was written.');
		}

		if (!is_writable($parent) || (is_file($target) && !is_writable($target))) {
			throw new \RuntimeException($this->g5Rel($target) . ' is not writable by PHP. Nothing was written.');
		}

		$created = !is_file($target);
		$backup  = $this->g5Backup($theme, $outline, $rel, $target);
		$tmp     = $target . '.csmcp-' . bin2hex(random_bytes(4)) . '.tmp';

		if (@file_put_contents($tmp, $yaml, LOCK_EX) === false) {
			@unlink($tmp);

			throw new \RuntimeException('Writing ' . $this->g5Rel($target) . ' failed. Nothing was changed; backup ' . $backup['id'] . ' is a copy of the untouched file.');
		}

		if (!$created) {
			@chmod($tmp, fileperms($target) & 0777);
		}

		if (!@rename($tmp, $target)) {
			@unlink($tmp);

			throw new \RuntimeException('Replacing ' . $this->g5Rel($target) . ' failed. Nothing was changed.');
		}

		clearstatcache(true, $target);

		if ($this->g5ParseYamlFile($target) != $data) {
			throw new \RuntimeException($this->g5Rel($target) . ' does not read back as written. Restore it with restore_gantry5_backup {"backup": "' . $backup['id'] . '"}.');
		}

		return [
			'file'        => $this->g5Rel($target),
			'backup'      => $backup['id'],
			'backup_file' => $backup['file'],
			'created'     => $created,
			'bytes'       => \strlen($yaml),
		];
	}

	/**
	 * Remove one config file of an outline (after backing it up). Used when the
	 * last override in a file is removed, so the file no longer shadows anything,
	 * as Gantry's admin does (Settings::saveItem() deletes the file when nothing
	 * is posted for it).
	 *
	 * @return array{file:string,backup:string,backup_file:string,deleted:bool}
	 */
	protected function g5DeleteConfigFile(array $theme, string $outline, string $rel): array
	{
		$rel    = $this->g5RequireSafeRelPath($rel);
		$target = $this->g5CustomConfigDir($theme, $outline) . '/' . $rel;

		$this->g5RequireInside($target, $this->g5CustomPath($theme) . '/config');

		if (!is_file($target)) {
			return ['file' => $this->g5Rel($target), 'backup' => '', 'backup_file' => '', 'deleted' => false];
		}

		$backup = $this->g5Backup($theme, $outline, $rel, $target);

		if (!@unlink($target)) {
			throw new \RuntimeException('Deleting ' . $this->g5Rel($target) . ' failed. Nothing was changed.');
		}

		clearstatcache(true, $target);

		// Settings::save() also removes the particles/ folder it leaves empty (@rmdir($save_dir)).
		$parent = \dirname($target);

		if ($parent !== $this->g5CustomConfigDir($theme, $outline) && is_dir($parent) && \count((array) scandir($parent)) === 2) {
			@rmdir($parent);
		}

		return ['file' => $this->g5Rel($target), 'backup' => $backup['id'], 'backup_file' => $backup['file'], 'deleted' => true];
	}

	/** Realpath containment check; the target itself may not exist yet. */
	protected function g5RequireInside(string $path, string $root): void
	{
		$rootReal = realpath($root);
		$probe    = $path;

		while (!file_exists($probe) && \dirname($probe) !== $probe) {
			$probe = \dirname($probe);
		}

		$probeReal = realpath($probe);

		if ($rootReal === false) {
			// The root itself does not exist yet (first write to a fresh theme): compare lexically.
			$rootReal  = str_replace('\\', '/', $root);
			$probeReal = str_replace('\\', '/', $path);
		}

		$rootReal  = rtrim(str_replace('\\', '/', (string) $rootReal), '/') . '/';
		$probeReal = rtrim(str_replace('\\', '/', (string) $probeReal), '/') . '/';

		if (!str_starts_with($probeReal, $rootReal) && $probeReal !== $rootReal) {
			throw new \InvalidArgumentException('Refusing to touch ' . $this->g5Rel($path) . ': it resolves outside ' . $this->g5Rel($root) . '.');
		}
	}

	// -----------------------------------------------------------------------
	// Backups
	// -----------------------------------------------------------------------

	protected function g5BackupRoot(): string
	{
		$tmp = (string) (Factory::getApplication()->get('tmp_path') ?: JPATH_ROOT . '/tmp');

		return rtrim($tmp, '/\\') . '/csmcpforjgantry5-backups';
	}

	/**
	 * Copy a config file to the backup folder before it changes. A file that did
	 * not exist is recorded as an empty "*.absent" marker, so restoring it means
	 * deleting the file again.
	 *
	 * @return array{id:string,file:string}
	 */
	protected function g5Backup(array $theme, string $outline, string $rel, string $target): array
	{
		$root = $this->g5BackupRoot();
		$dir  = $root . '/' . $theme['element'] . '/' . $outline;

		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			throw new \RuntimeException('Cannot create the backup folder ' . $this->g5Rel($dir) . '. Nothing was written.');
		}

		if (!is_file($root . '/.htaccess')) {
			@file_put_contents($root . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
		}

		if (!is_file($root . '/index.html')) {
			@file_put_contents($root . '/index.html', '<!DOCTYPE html><title></title>');
		}

		// Microseconds keep two writes in the same second in order.
		$now   = microtime(true);
		$stamp = gmdate('Ymd-His', (int) $now) . sprintf('%06d', (int) (($now - floor($now)) * 1000000)) . '-' . bin2hex(random_bytes(3));
		$name  = str_replace('/', '~', $rel) . '__' . $stamp . (is_file($target) ? '.bak' : '.absent');
		$file  = $dir . '/' . $name;

		$ok = is_file($target) ? @copy($target, $file) : @file_put_contents($file, '') !== false;

		if (!$ok) {
			throw new \RuntimeException('Backing up ' . $this->g5Rel($target) . ' failed, so nothing was written.');
		}

		return ['id' => $theme['element'] . '/' . $outline . '/' . $name, 'file' => $this->g5Rel($file)];
	}

	/**
	 * Every backup, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function g5ListBackups(?string $theme = null, ?string $outline = null): array
	{
		$root = $this->g5BackupRoot();

		if (!is_dir($root)) {
			return [];
		}

		$out = [];

		foreach ((array) glob($root . '/*/*/*', GLOB_NOSORT) as $file) {
			$file = str_replace('\\', '/', (string) $file);

			if (!is_file($file)) {
				continue;
			}

			$id     = substr($file, \strlen(str_replace('\\', '/', $root)) + 1);
			$parsed = $this->g5ParseBackupId($id, false);

			if ($parsed === null || ($theme !== null && $parsed['theme'] !== $theme) || ($outline !== null && $parsed['outline'] !== $outline)) {
				continue;
			}

			$out[] = $parsed + ['bytes' => (int) filesize($file), 'file' => $this->g5Rel($file)];
		}

		usort($out, static fn (array $a, array $b): int => strcmp($b['sort_key'], $a['sort_key']));

		foreach ($out as &$b) {
			unset($b['sort_key']);
		}

		unset($b);

		return $out;
	}

	/**
	 * "<theme>/<outline>/<rel with ~>__<Ymd>-<His><microseconds>-<hex>.(bak|absent)" → its parts,
	 * every one validated, so nothing from the argument reaches a path unchecked.
	 *
	 * @return array{id:string,theme:string,outline:string,rel:string,taken_utc:string,kind:string,sort_key:string}|null
	 */
	protected function g5ParseBackupId(string $id, bool $throw = true): ?array
	{
		$fail = function (string $why) use ($throw, $id): ?array {
			if ($throw) {
				throw new \InvalidArgumentException('backup "' . $id . '" is not a backup id: ' . $why . '. Use list_gantry5_backups.');
			}

			return null;
		};

		if (!preg_match('#^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.~-]+)__(\d{8})-(\d{6})(\d{6})-[0-9a-f]{6}\.(bak|absent)$#', $id, $m)) {
			return $fail('wrong shape');
		}

		if (!$this->g5SafeName($m[1]) || !$this->g5SafeName($m[2])) {
			return $fail('unsafe theme or outline');
		}

		$rel = str_replace('~', '/', $m[3]);

		try {
			$rel = $this->g5RequireSafeRelPath($rel);
		} catch (\InvalidArgumentException $e) {
			return $fail('unsafe file path');
		}

		$taken = substr($m[4], 0, 4) . '-' . substr($m[4], 4, 2) . '-' . substr($m[4], 6, 2) . ' '
			. substr($m[5], 0, 2) . ':' . substr($m[5], 2, 2) . ':' . substr($m[5], 4, 2);

		return [
			'id'        => $id,
			'theme'     => $m[1],
			'outline'   => $m[2],
			'rel'       => $rel,
			'taken_utc' => $taken,
			'kind'      => $m[7] === 'bak' ? 'copy' : 'file_did_not_exist',
			'sort_key'  => $m[4] . $m[5] . $m[6],
		];
	}

	// -----------------------------------------------------------------------
	// Cache
	// -----------------------------------------------------------------------

	/** Gantry's cache root: <cache_path>/gantry5 (Framework/Platform.php getCachePath()). */
	protected function g5CacheRoots(): array
	{
		$configured = (string) (Factory::getApplication()->get('cache_path') ?: '');
		$roots      = array_filter([$configured, JPATH_SITE . '/cache', JPATH_ADMINISTRATOR . '/cache']);
		$out        = [];

		foreach ($roots as $r) {
			$p = rtrim(str_replace('\\', '/', $r), '/') . '/gantry5';

			if (is_dir($p)) {
				$out[$p] = $p;
			}
		}

		return array_values($out);
	}

	/** @return array{files:int,bytes:int} */
	protected function g5DirStats(string $dir): array
	{
		$files = 0;
		$bytes = 0;

		if (!is_dir($dir)) {
			return ['files' => 0, 'bytes' => 0];
		}

		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

		foreach ($it as $f) {
			if ($f->isFile()) {
				$files++;
				$bytes += (int) $f->getSize();
			}
		}

		return ['files' => $files, 'bytes' => $bytes];
	}

	/** Delete a folder's contents and keep the folder, like Gantry's Folder::delete($dir, false). */
	protected function g5EmptyDir(string $dir): int
	{
		if (!is_dir($dir) || is_link($dir)) {
			return 0;
		}

		$removed = 0;
		$it      = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($it as $f) {
			if ($f->isDir() && !$f->isLink()) {
				@rmdir($f->getPathname());
			} elseif (@unlink($f->getPathname())) {
				$removed++;
			}
		}

		@touch($dir);

		return $removed;
	}

	/**
	 * What Gantry's "Clear Cache" button does (Admin/Controller/Html/Cache.php:33-42):
	 * empty gantry-cache://theme (<cache>/gantry5/<theme>) and gantry-cache://admin.
	 * Then the Joomla cache groups Gantry's CacheHelper::cleanTemplates() cleans
	 * after an assignment change (_system and com_templates).
	 *
	 * @return array<string,mixed>
	 */
	protected function g5ClearCache(array $theme): array
	{
		$cleared = [];

		foreach ($this->g5CacheRoots() as $root) {
			foreach ([$root . '/' . $theme['element'], $root . '/admin'] as $dir) {
				if (is_dir($dir)) {
					$cleared[] = ['folder' => $this->g5Rel($dir), 'files_removed' => $this->g5EmptyDir($dir)];
				}
			}
		}

		$groups = [];

		foreach (['_system', 'com_templates'] as $group) {
			foreach ([0 => JPATH_SITE . '/cache', 1 => JPATH_ADMINISTRATOR . '/cache'] as $client => $base) {
				try {
					$cache = Factory::getContainer()->get(CacheControllerFactoryInterface::class)
						->createCacheController('callback', ['defaultgroup' => $group, 'cachebase' => $client === 0 ? (string) (Factory::getApplication()->get('cache_path') ?: $base) : $base]);
					$cache->clean();
					$groups[] = $group . ($client ? ' (administrator)' : ' (site)');
				} catch (\Throwable $e) {
					$groups[] = $group . ($client ? ' (administrator)' : ' (site)') . ': ' . $e->getMessage();
				}
			}
		}

		clearstatcache();

		return ['gantry_cache' => $cleared, 'joomla_cache_groups' => $groups];
	}

	/**
	 * The folder Gantry writes compiled CSS to: css.target in theme.yaml
	 * (default gantry-theme://css-compiled), whose first writable location is
	 * custom/ (ThemeInstaller::initialize() deletes custom/css-compiled on every
	 * theme install for the same reason, Component/Theme/ThemeInstaller.php:242-248).
	 */
	protected function g5CompiledCssDir(array $theme): string
	{
		$target = (string) ($theme['css']['target'] ?? 'gantry-theme://css-compiled');
		$rel    = str_starts_with($target, 'gantry-theme://') ? trim(substr($target, 15), '/') : 'css-compiled';

		if (!$this->g5SafeName(str_replace('/', '_', $rel))) {
			$rel = 'css-compiled';
		}

		return $this->g5CustomPath($theme) . '/' . $rel;
	}

	/** @return array<int,array{file:string,outline:string,bytes:int,modified_utc:string}> */
	protected function g5CompiledCssFiles(array $theme): array
	{
		$dir = $this->g5CompiledCssDir($theme);
		$out = [];

		foreach ((array) glob($dir . '/*.css') as $file) {
			$file = (string) $file;
			$base = basename($file, '.css');
			// CssCompiler::getCssUrl(): "<name>" for the Base Outline, "<name>_<outline>" otherwise.
			// On Joomla an outline is a style id or one of the three system outlines.
			$outline = preg_match('/_(\d+|_body_only|_error|_offline)$/', $base, $m) ? $m[1] : 'default';

			$out[] = [
				'file'         => $this->g5Rel($file),
				'outline'      => $outline,
				'bytes'        => (int) filesize($file),
				'modified_utc' => gmdate('Y-m-d H:i:s', (int) filemtime($file)),
			];
		}

		return $out;
	}

	/**
	 * Delete compiled CSS so the next page view recompiles it. In Gantry's
	 * production mode (the default when the plugin has never been saved:
	 * Framework/Base/Gantry.php:340-349) CssCompiler::needsCompile() only
	 * recompiles when the file is missing or its checksum header is wrong
	 * (Component/Stylesheet/CssCompiler.php:205-243), so a styles change does not
	 * show until the old file is gone.
	 *
	 * @param string[]|null $outlines null = every outline (a Base Outline change)
	 * @return string[] files removed
	 */
	protected function g5InvalidateCss(array $theme, ?array $outlines): array
	{
		$removed = [];

		foreach ($this->g5CompiledCssFiles($theme) as $css) {
			if ($outlines === null || \in_array($css['outline'], $outlines, true)) {
				if (@unlink(JPATH_ROOT . '/' . $css['file'])) {
					$removed[] = $css['file'];
				}
			}
		}

		return $removed;
	}
}
