<?php
/**
 * @package     MCP for jDownloads
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Table\Table;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * Shared orientation and safety rails for every jDownloads tool.
 *
 * Built against jDownloads 4.1.7 Stable (Arno Betz, 2026-07-24), the current
 * J4/J5/J6 release, on Joomla 6.2.0.
 *
 * ---------------------------------------------------------------------------
 * THE DATA MODEL IN ONE PARAGRAPH
 * ---------------------------------------------------------------------------
 *
 * Categories are jDownloads' OWN nested set, `#__jdownloads_categories`, not
 * `#__categories` (`admin/sql/install.mysql.utf8.sql:4`, ROOT row id 1 with
 * `parent_id` 0). Each category owns a folder on disk:
 * `<files_uploaddir>/<cat_dir_parent>/<cat_dir>`, where `cat_dir_parent` is the
 * slash-joined `cat_dir` of every ancestor. A "download" is a row in
 * `#__jdownloads_files` whose `url_download` holds ONLY the file name; the file
 * lives in its category's folder. `license` holds a `#__jdownloads_licenses`
 * id, `release` is the version string, `file_date` the file date, `downloads`
 * the counter. Both item types carry an asset row
 * (`com_jdownloads.category.<id>`, `com_jdownloads.download.<id>`) and
 * Joomla tags (TaggableTableTrait). There is NO trash state: `published` is
 * 0 or 1 (`admin/src/Field/JDStatusField.php`).
 *
 * ---------------------------------------------------------------------------
 * WHY THE DOWNLOAD MODEL IS NEVER USED, AND THE CATEGORY MODEL ONLY TO CREATE
 * ---------------------------------------------------------------------------
 *
 * `DownloadModel::save()` hands over to `DownloadTable::checkData()`, which
 * reads the posted admin form rather than the data it was given
 * (`admin/src/Table/DownloadTable.php:96-460`). Called from outside that form
 * it would:
 *   - blank `author`, `url_author`, `url_home`, `extern_file`, both mirrors and
 *     zero `extern_site`, `submitted_by`, `set_aup_points` (lines 373-387, all
 *     read from `jform`/request);
 *   - treat every edit as a category change (`cat_dir_org` request value 0 vs
 *     the real catid, line 411) and try to move the file;
 *   - DELETE every screenshot of the download from disk and empty `images`,
 *     because the `keep_image` list it compares against is not posted
 *     (lines 1005-1029);
 *   - reset the download's permissions: `save()` replaces missing `rules`
 *     with empty arrays (`DownloadModel.php:433-442`).
 * `DownloadModel::delete()` refuses outright under the API application: it
 * checks `canDelete()` only for the administrator client and prunes every
 * other request as "not permitted" (`DownloadModel.php:1436-1546`).
 *
 * So downloads are written through the vendor's `DownloadTable` directly
 * (bind, store) — that is where the asset row, the tag map and
 * `modified`/`modified_by` are handled — with every field re-supplied from the
 * loaded row, and the file work `checkData()` would do reproduced here with
 * path checks.
 *
 * `CategoryModel::save()` is fine for a NEW category (it builds the folder
 * name, inserts the nested-set node through `JDCategoryTable`, creates the
 * folder). For an EXISTING one it is not: it works out the old folder from
 * hidden form fields (`cat_dir_org`, `cat_dir_parent_org`, `cat_title_org`,
 * `admin/tmpl/category/edit.php:186-188`), and its folder-move helper
 * `JDownloadsHelper::moveDirs()` passes its arguments in the wrong order when
 * it recurses (`JDownloadsHelper.php:1694`), so a grandchild category's folder
 * is never copied and is then deleted with its parent's source folder. It also
 * never returns true (it returns the `$msg` it was handed, which the caller
 * never defined), so every parent change is treated as a failure and the
 * category is silently unpublished (`CategoryModel.php:554-558`; the test is
 * `if ($table->published = 1)`, an assignment). Category updates therefore use the vendor's `JDCategoryTable` for the nested
 * set (`setLocation()` + `store()` → `moveByReference()`, then `rebuild()` of
 * the subtree for `cat_dir_parent`), and a single `rename()` of the folder.
 *
 * ---------------------------------------------------------------------------
 * PARTIAL-SAVE SAFETY
 * ---------------------------------------------------------------------------
 *
 * Any `Table::store()` on a taggable row that does not carry `newTags` deletes
 * that row's tags (TagsHelper::preStoreProcess sees old tags != none). Every
 * store here re-supplies the current tag ids. Asset rules are left alone by
 * not binding `rules` (Table::store only rewrites rules when `_rules` is set).
 * Custom field values are untouched: no content save event carries
 * `com_fields`.
 */
trait JdownloadsBootTrait
{
	/** Tables the tools read, by short name. */
	private const JD_TABLES = [
		'categories'        => 'jdownloads_categories',
		'downloads'         => 'jdownloads_files',
		'licenses'          => 'jdownloads_licenses',
		'logs'              => 'jdownloads_logs',
		'ratings'           => 'jdownloads_ratings',
		'usergroups_limits' => 'jdownloads_usergroups_limits',
	];

	/**
	 * Columns never returned. Category and download passwords are stored in
	 * PLAIN TEXT next to their hash (`install.mysql.utf8.sql:30,95`); the log
	 * and rating tables hold visitor IP addresses.
	 */
	private const JD_REDACTED = ['password', 'password_md5', 'log_ip', 'lastip'];

	private static ?bool $jdInstalledCache = null;

	private static ?array $jdTableListCache = null;

	// -----------------------------------------------------------------------
	// Presence and identity
	// -----------------------------------------------------------------------

	/**
	 * One category for every tool. Deriving it from the namespace would give
	 * `categories` and `downloads`, which collide with core MCP for J.
	 */
	public function getCategory(): string
	{
		return 'jdownloads';
	}

	protected function jdAdminBase(): string
	{
		return JPATH_ADMINISTRATOR . '/components/com_jdownloads';
	}

	protected function jdInstalled(): bool
	{
		if (self::$jdInstalledCache !== null) {
			return self::$jdInstalledCache;
		}

		self::$jdInstalledCache = is_dir($this->jdAdminBase())
			&& $this->jdTableExists('jdownloads_categories')
			&& $this->jdTableExists('jdownloads_files');

		return self::$jdInstalledCache;
	}

	protected function jdNotInstalledError(): ToolResult
	{
		return ToolResult::json([
			'ok'    => false,
			'error' => 'jDownloads (com_jdownloads) is not installed on this site. Expected '
				. 'administrator/components/com_jdownloads and the #__jdownloads_* tables.',
			'note'  => 'jDownloads is free from jdownloads.com. This add-on was built against 4.1.7 Stable.',
		], true);
	}

	/** @return array{version:?string,enabled:bool} */
	protected function jdComponentInfo(): array
	{
		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([$this->db->quoteName('manifest_cache'), $this->db->quoteName('enabled')])
				->from($this->db->quoteName('#__extensions'))
				->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
				->where($this->db->quoteName('element') . ' = ' . $this->db->quote('com_jdownloads'))
		)->loadAssoc();

		$manifest = json_decode((string) ($row['manifest_cache'] ?? ''), true);

		return [
			'version' => \is_array($manifest) && !empty($manifest['version']) ? (string) $manifest['version'] : null,
			'enabled' => (int) ($row['enabled'] ?? 0) === 1,
		];
	}

	protected function jdParams(): Registry
	{
		return ComponentHelper::getParams('com_jdownloads');
	}

	// -----------------------------------------------------------------------
	// Tables
	// -----------------------------------------------------------------------

	protected function jdTable(string $name): string
	{
		return '#__' . $name;
	}

	protected function jdTableExists(string $name): bool
	{
		if (self::$jdTableListCache === null) {
			self::$jdTableListCache = $this->db->getTableList();
		}

		return \in_array($this->db->getPrefix() . $name, self::$jdTableListCache, true);
	}

	protected function jdCategoryRow(int $id): ?array
	{
		$row = $this->db->setQuery(
			'SELECT * FROM ' . $this->db->quoteName('#__jdownloads_categories') . ' WHERE id = ' . $id
		)->loadAssoc();

		return \is_array($row) ? $row : null;
	}

	protected function jdDownloadRow(int $id): ?array
	{
		$row = $this->db->setQuery(
			'SELECT * FROM ' . $this->db->quoteName('#__jdownloads_files') . ' WHERE id = ' . $id
		)->loadAssoc();

		return \is_array($row) ? $row : null;
	}

	/** The ROOT node: the row with parent_id 0 (normally id 1), as JDCategoryTable::getRootId() finds it. */
	protected function jdRootId(): int
	{
		$ids = $this->db->setQuery(
			'SELECT id FROM ' . $this->db->quoteName('#__jdownloads_categories') . ' WHERE parent_id = 0'
		)->loadColumn() ?: [];

		if (\count($ids) !== 1) {
			throw new \RuntimeException(
				'The jDownloads category tree has ' . \count($ids) . ' root rows (parent_id = 0); it must have exactly one. '
				. 'Run check_jdownloads_health. Nothing was written.'
			);
		}

		return (int) $ids[0];
	}

	/**
	 * jDownloads stores some values as language keys (its sample licences are
	 * "COM_JDOWNLOADS_SETTINGS_LICENSE1_TITLE" and so on, defined in
	 * en-GB.com_jdownloads.sys.ini). Translate those; leave anything else.
	 */
	protected function jdText(string $value): string
	{
		if (!preg_match('/^COM_JDOWNLOADS_[A-Z0-9_]+$/', $value)) {
			return $value;
		}

		static $loaded = false;

		$lang = Factory::getApplication()->getLanguage();

		if (!$loaded) {
			$lang->load('com_jdownloads.sys', JPATH_ADMINISTRATOR);
			$lang->load('com_jdownloads', JPATH_ADMINISTRATOR);
			$loaded = true;
		}

		return $lang->_($value);
	}

	/** Remove secret columns and say that a password exists. */
	protected function jdRedact(array $row): array
	{
		if (\array_key_exists('password', $row)) {
			$row['has_password'] = (string) $row['password'] !== '';
		}

		foreach (self::JD_REDACTED as $col) {
			unset($row[$col]);
		}

		return $row;
	}

	// -----------------------------------------------------------------------
	// Vendor tables and helper
	// -----------------------------------------------------------------------

	/**
	 * A vendor Table class (`JDCategory` or `Download`) from the component's own
	 * MVC factory, so its asset, nested-set and Taggable code runs exactly as in
	 * the jDownloads admin.
	 */
	protected function jdVendorTable(string $name): Table
	{
		$table = Factory::getApplication()->bootComponent('com_jdownloads')->getMVCFactory()->createTable($name, 'Administrator');

		if (!$table instanceof Table) {
			throw new \RuntimeException('Could not load the jDownloads ' . $name . ' table class.');
		}

		return $table;
	}

	/** The vendor's static helper, for its folder/file-name cleaning and size formatting. */
	protected function jdHelper(): string
	{
		$class = '\\JDownloads\\Component\\JDownloads\\Administrator\\Helper\\JDownloadsHelper';

		if (!class_exists($class)) {
			Factory::getApplication()->bootComponent('com_jdownloads');
		}

		if (!class_exists($class)) {
			throw new \RuntimeException('jDownloads\' JDownloadsHelper class could not be loaded.');
		}

		return $class;
	}

	/**
	 * A folder or file name cleaned with jDownloads' own rules
	 * (`JDownloadsHelper::getCleanFolderFileName()`, which follows the
	 * "fix blanks / upper case / special characters / unicode" options).
	 */
	protected function jdCleanName(string $name): string
	{
		$helper = $this->jdHelper();

		return (string) $helper::getCleanFolderFileName($name);
	}

	/** Size string in the vendor's own format ("1.5 MB"), from `JDownloadsHelper::fsize()`. */
	protected function jdFormatSize(string $absFile): string
	{
		$helper = $this->jdHelper();

		return (string) $helper::fsize($absFile);
	}

	// -----------------------------------------------------------------------
	// Folders on disk
	// -----------------------------------------------------------------------

	/** The configured upload root, normalised, or '' when not set. */
	protected function jdUploadRoot(): string
	{
		$dir = str_replace('\\', '/', trim((string) $this->jdParams()->get('files_uploaddir', '')));

		return rtrim($dir, '/');
	}

	protected function jdRequireUploadRoot(): string
	{
		$root = $this->jdUploadRoot();

		if ($root === '' || !is_dir($root)) {
			throw new \RuntimeException(
				'jDownloads\' upload folder (Options > files_uploaddir) is ' . ($root === '' ? 'not set' : 'missing: ' . $root)
				. '. Nothing was written. Run check_jdownloads_health.'
			);
		}

		return $root;
	}

	/** Top-level folder names jDownloads reserves for preview files and temporary zips. */
	protected function jdReservedFolders(): array
	{
		$p = $this->jdParams();

		return array_values(array_filter([
			(string) $p->get('preview_files_folder_name', '_preview_files'),
			(string) $p->get('tempzipfiles_folder_name', '_tempzipfiles'),
		]));
	}

	/** `cat_dir_parent/cat_dir` (or just `cat_dir` at the top level). */
	protected function jdCategoryRelPath(array $category): string
	{
		$parent = trim((string) ($category['cat_dir_parent'] ?? ''), '/');
		$dir    = (string) ($category['cat_dir'] ?? '');

		return $parent !== '' ? $parent . '/' . $dir : $dir;
	}

	protected function jdCategoryAbsPath(array $category): ?string
	{
		$root = $this->jdUploadRoot();
		$rel  = $this->jdCategoryRelPath($category);

		if ($root === '' || $rel === '') {
			return null;
		}

		return $root . '/' . $rel;
	}

	/**
	 * A name usable as ONE path segment under the upload root. jDownloads' own
	 * cleaning keeps dots, and with its defaults (no "fix special characters")
	 * a title of ".." would become a folder called "..".
	 */
	protected function jdRequireSafeSegment(string $name, string $what): string
	{
		if ($name === '' || $name === '.' || $name === '..' || str_starts_with($name, '.')
			|| preg_match('#[/\\\\\x00-\x1F]#', $name)) {
			throw new \InvalidArgumentException(
				$what . ' "' . $name . '" is not usable as a folder or file name (empty, starts with a dot, or contains a '
				. 'slash, backslash or control character). Nothing was written.'
			);
		}

		if (\strlen($name) > 240) {
			throw new \InvalidArgumentException($what . ' is longer than 240 bytes. Nothing was written.');
		}

		return $name;
	}

	/**
	 * Resolve a path and confirm it is a real (non-symlink) entry inside the
	 * upload root. Returns the canonical absolute path.
	 */
	protected function jdResolveInsideRoot(string $absPath): string
	{
		$root     = $this->jdRequireUploadRoot();
		$realRoot = realpath($root);
		$real     = realpath($absPath);

		if ($realRoot === false || $real === false) {
			throw new \InvalidArgumentException('Path does not exist: ' . $absPath);
		}

		$realRoot = str_replace('\\', '/', $realRoot);
		$real     = str_replace('\\', '/', $real);

		if (!str_starts_with($real . '/', rtrim($realRoot, '/') . '/')) {
			throw new \InvalidArgumentException('Path resolves outside the jDownloads upload folder. Refusing.');
		}

		if (is_link($absPath)) {
			throw new \InvalidArgumentException('Path is a symbolic link. Refusing: a link can point anywhere on the server.');
		}

		return $real;
	}

	/**
	 * File names directly in a folder (no subfolders, no dot files, no
	 * index.html), as jDownloads' Files view lists the upload root
	 * (`admin/src/Model/FilesModel.php:125`).
	 *
	 * @return string[]
	 */
	protected function jdFilesIn(string $absDir, int $cap = 5000): array
	{
		if (!is_dir($absDir)) {
			return [];
		}

		$out = [];

		foreach ((array) @scandir($absDir) as $entry) {
			$entry = (string) $entry;

			if ($entry === '' || $entry[0] === '.' || \in_array(strtolower($entry), ['index.html', 'index.htm'], true)) {
				continue;
			}

			if (is_file($absDir . '/' . $entry)) {
				$out[] = $entry;

				if (\count($out) >= $cap) {
					break;
				}
			}
		}

		sort($out, SORT_STRING);

		return $out;
	}

	/**
	 * jDownloads' upload type rules (`upload_validation_mode`,
	 * `upload_blocked_extensions` / `upload_allowed_extensions`). Its own
	 * uploader refuses these; attaching one through MCP would sidestep it.
	 */
	protected function jdRequireAllowedExtension(string $fileName): void
	{
		$ext  = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
		$p    = $this->jdParams();
		$mode = (string) $p->get('upload_validation_mode', 'blacklist');
		$list = static fn (string $csv): array => array_values(array_filter(array_map(
			static fn ($v) => strtolower(trim((string) $v, " .\t")),
			explode(',', $csv)
		)));

		// Always refuse server-executable types, whatever the site's list says.
		$alwaysBlocked = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phar', 'pht', 'cgi', 'pl', 'asp', 'aspx', 'jsp', 'htaccess'];

		if ($ext === '' || \in_array($ext, $alwaysBlocked, true)) {
			throw new \InvalidArgumentException('Refusing a file with extension "' . $ext . '": it could run as code on the server.');
		}

		if ($mode === 'whitelist') {
			$allowed = $list((string) $p->get('upload_allowed_extensions', ''));

			if ($allowed !== [] && !\in_array($ext, $allowed, true)) {
				throw new \InvalidArgumentException(
					'jDownloads is set to allow only these upload types: ' . implode(', ', $allowed) . '. "' . $ext . '" is not one of them.'
				);
			}

			return;
		}

		$blocked = $list((string) $p->get('upload_blocked_extensions', ''));

		if (\in_array($ext, $blocked, true)) {
			throw new \InvalidArgumentException('jDownloads blocks uploads of type "' . $ext . '" (Options > upload_blocked_extensions).');
		}
	}

	// -----------------------------------------------------------------------
	// Tags, assets
	// -----------------------------------------------------------------------

	/** @return int[] */
	protected function jdTagIds(string $typeAlias, int $id): array
	{
		return array_map('intval', $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('tag_id'))
				->from($this->db->quoteName('#__contentitem_tag_map'))
				->where($this->db->quoteName('type_alias') . ' = :alias')
				->where($this->db->quoteName('content_item_id') . ' = :id')
				->bind(':alias', $typeAlias)
				->bind(':id', $id, ParameterType::INTEGER)
				->order($this->db->quoteName('tag_id'))
		)->loadColumn() ?: []);
	}

	/** @return array<int,array{id:int,title:string}> */
	protected function jdTags(string $typeAlias, int $id): array
	{
		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([$this->db->quoteName('t.id'), $this->db->quoteName('t.title')])
				->from($this->db->quoteName('#__contentitem_tag_map', 'm'))
				->join('INNER', $this->db->quoteName('#__tags', 't') . ' ON t.id = m.tag_id')
				->where($this->db->quoteName('m.type_alias') . ' = :alias')
				->where($this->db->quoteName('m.content_item_id') . ' = :id')
				->bind(':alias', $typeAlias)
				->bind(':id', $id, ParameterType::INTEGER)
				->order('t.id')
		)->loadAssocList() ?: [];

		return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title']], $rows);
	}

	/**
	 * Re-supply the row's current tags to a vendor table before store(), or
	 * Joomla's Taggable behaviour deletes them (see the class docblock).
	 */
	protected function jdKeepTags(Table $table, string $typeAlias, int $id): array
	{
		$ids = $this->jdTagIds($typeAlias, $id);

		if ($ids !== []) {
			$table->newTags = $ids;
		}

		return $ids;
	}

	/**
	 * After a store that re-processed tags, do what jDownloads' own
	 * "jdownloads_tags_fix" content plugin does on save (it zeroes
	 * `#__ucm_content.core_catid` for jDownloads rows so Joomla's tag views do
	 * not look them up in #__categories). Only when that plugin is enabled.
	 */
	protected function jdApplyTagsFix(string $typeAlias, int $id): void
	{
		$enabled = (int) $this->db->setQuery(
			"SELECT enabled FROM #__extensions WHERE type = 'plugin' AND folder = 'content' AND element = 'jdownloads_tags_fix'"
		)->loadResult();

		if ($enabled !== 1) {
			return;
		}

		$this->db->setQuery(
			'UPDATE ' . $this->db->quoteName('#__ucm_content') . ' SET core_catid = 0'
			. ' WHERE core_type_alias = ' . $this->db->quote($typeAlias) . ' AND core_content_item_id = ' . $id
		)->execute();
	}

	/** The explicit rules on an asset, decoded, or null when the asset row is missing. */
	protected function jdAssetRules(string $name): ?array
	{
		$rules = $this->db->setQuery(
			'SELECT rules FROM ' . $this->db->quoteName('#__assets') . ' WHERE name = ' . $this->db->quote($name)
		)->loadResult();

		if ($rules === null) {
			return null;
		}

		$decoded = json_decode((string) $rules, true);

		return \is_array($decoded) ? $decoded : [];
	}

	// -----------------------------------------------------------------------
	// Access control
	// -----------------------------------------------------------------------

	/**
	 * Non-guest, `core.manage` on com_jdownloads (what its admin dispatcher
	 * checks), then the specific action on the given asset — jDownloads
	 * defines create/edit/edit.state/delete per category and per download
	 * (`admin/access.xml`), and the admin checks the item's own asset.
	 */
	protected function jdRequireAccess(User $actor, ?string $action = null, string $asset = 'com_jdownloads'): void
	{
		if ($actor->guest || (int) $actor->id === 0) {
			throw new \RuntimeException('jDownloads tools require an authenticated user.');
		}

		if (!$actor->authorise('core.manage', 'com_jdownloads')) {
			throw new \RuntimeException('This user lacks core.manage on com_jdownloads, which the jDownloads admin requires.');
		}

		if ($action !== null && !$actor->authorise($action, $asset)) {
			throw new \RuntimeException('This user is not granted ' . $action . ' on ' . $asset . '.');
		}
	}

	protected function jdRequireNotCheckedOut(array $row, User $actor, string $what): void
	{
		$by = (int) ($row['checked_out'] ?? 0);

		if ($by > 0 && $by !== (int) $actor->id) {
			throw new \RuntimeException(
				$what . ' is checked out to user ' . $by . ' in the jDownloads admin. Their next Save would overwrite this '
				. 'change; ask them to close it (or check it in) first. Nothing was written.'
			);
		}
	}

	// -----------------------------------------------------------------------
	// Arguments
	// -----------------------------------------------------------------------

	protected function jdLimit(array $arguments, int $default = 50, int $max = 200): int
	{
		return max(1, min($max, (int) ($arguments['limit'] ?? $default)));
	}

	protected function jdOffset(array $arguments): int
	{
		return max(0, (int) ($arguments['offset'] ?? 0));
	}

	protected function jdNow(): string
	{
		return Factory::getDate()->toSql();
	}

	protected function jdLike(string $value): string
	{
		return $this->db->quote('%' . $this->db->escape($value, true) . '%', false);
	}

	/** Refuse a value that would not fit a varchar column, rather than let MySQL truncate it. */
	protected function jdRequireFits(string $field, string $value, int $max = 255): void
	{
		if (mb_strlen($value) > $max) {
			throw new \InvalidArgumentException(
				$field . ' is ' . mb_strlen($value) . ' characters; the column holds ' . $max
				. '. Refusing rather than truncating. Nothing was written.'
			);
		}
	}

	/** A UTC datetime argument, or null for "no date". */
	protected function jdDate(mixed $value, string $field): ?string
	{
		if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
			return null;
		}

		$ts = strtotime((string) $value . (preg_match('/(Z|[+-]\d{2}:?\d{2}|UTC)$/i', (string) $value) ? '' : ' UTC'));

		if ($ts === false) {
			throw new \InvalidArgumentException($field . ' "' . $value . '" is not a date. Use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS (UTC).');
		}

		return gmdate('Y-m-d H:i:s', $ts);
	}

	protected function jdRequireAccessLevel(int $level): int
	{
		$exists = (int) $this->db->setQuery(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__viewlevels') . ' WHERE id = ' . $level
		)->loadResult();

		if ($exists === 0) {
			throw new \InvalidArgumentException('access ' . $level . ' is not a Joomla viewing access level. Nothing was written.');
		}

		return $level;
	}

	protected function jdRequireLanguage(string $language): string
	{
		if ($language === '*') {
			return $language;
		}

		$exists = (int) $this->db->setQuery(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__languages') . ' WHERE lang_code = ' . $this->db->quote($language)
		)->loadResult();

		if ($exists === 0) {
			throw new \InvalidArgumentException('language "' . $language . '" is not an installed content language. Use "*" for all. Nothing was written.');
		}

		return $language;
	}

	/** Parse a published argument: 1/0, true/false, "published"/"unpublished". */
	protected function jdParsePublished(mixed $value): int
	{
		if (\is_bool($value)) {
			return $value ? 1 : 0;
		}

		$v = strtolower(trim((string) $value));

		return match ($v) {
			'1', 'published', 'publish' => 1,
			'0', 'unpublished', 'unpublish' => 0,
			default => throw new \InvalidArgumentException(
				'published must be 1 (published) or 0 (unpublished). jDownloads has no archived or trashed state.'
			),
		};
	}
}
