<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Shared orientation and safety rails for every FlexBanners tool.
 *
 * Built against FlexBanners 5.1.10 (2025-12-02) by Inch Hosting, and checked
 * against 6.0.05 (2025-12-11). Both ship the same six tables with the same
 * columns; what differs is the MODULE (see fbModuleEnforcesLimits()).
 *
 * ---------------------------------------------------------------------------
 * WHY EVERY WRITE IS A TARGETED SQL STATEMENT, NOT THE VENDOR'S MODEL
 * ---------------------------------------------------------------------------
 *
 * FlexBanners is a com_banners fork with an AdminModel per entity, and the
 * usual rule is to write through it. Its banner model cannot be used:
 *
 *   - `FlexbannerModel::save()` (`backend/src/Model/FlexbannerModel.php:236-370`)
 *     validates `catid` against `com_flexbanners` and, when that fails, treats
 *     the value as the TITLE of a new com_flexbanners category and creates it
 *     (the admin form's "#new#" path) — so a save carrying a com_banners
 *     category id 3 creates a category called "3". (Source reading.)
 *   - It boots the component, and on Joomla 6 without compat6 the component
 *     class dies on load (see CheckHealthTool::joomla6Breakage()).
 *   - It deletes the banner's `#__flexbannersin` restriction rows keyed on the
 *     REQUEST `id` and re-inserts them keyed on `$data['id']`, which is empty for
 *     a new banner; the article branch inserts an undefined variable, so every
 *     article restriction is stored as contentid 0. Under the API application
 *     the request `id` is not the banner at all.
 *   - `if ($data['finished']=1) { $data['finished']=0; }` is an assignment, so
 *     every save clears `finished` whatever the end date says.
 *   - It reads `$input->get('task')` for save2copy and `post->get('categoryid')`
 *     for restrictions — request state that an MCP call does not carry.
 *
 * The tables underneath are plain (no assets, no nested sets, no workflow, no
 * tags, no custom fields), so a named-column INSERT/UPDATE is both safe and
 * exact. The vendor's own conventions are reproduced where they matter:
 * aliases via `ApplicationHelper::stringURLSafe()` and unique per category
 * (`FlexbannerTable::store()` refuses a duplicate on the next admin save),
 * `created`/`created_by`, `version` increments, and `published` kept equal to
 * `state` (`FlexbannerTable` aliases one to the other).
 *
 * Module rows are the exception: they are created through com_modules' own
 * `ModuleModel`, because a module carries an asset row and menu assignments
 * that core owns.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE FRONT END ACTUALLY DOES (mod_flexbanners.php)
 * ---------------------------------------------------------------------------
 *
 * A banner shows only when ALL of these hold — the module's query is
 * `INNER JOIN #__flexbannerssize USING(sizeid)`, `INNER JOIN #__categories c`
 * with `c.id = a.catid AND c.access IN (<viewer's levels>)`, and
 * `a.locationid = <module param> AND a.state = 1 AND a.finished = 0 AND
 * (a.language = <SITE DEFAULT language> OR a.language = '*')`:
 *
 *   - the size row exists (its own `state` is not checked);
 *   - the category row exists and the viewer can see its access level — its
 *     `extension` and `published` are NOT checked, so a com_content or com_banners
 *     category works on the front end;
 *   - the language is `*` or the site's DEFAULT language from global config —
 *     not the language of the page being viewed;
 *   - the location ROW is not consulted at all; only the id matters.
 *
 * There is no ORDER BY. Display order is whatever MySQL returns unless the
 * module's `ordering` param is `up` (an `asort()` on the row objects, which in
 * practice sorts by id) or `random`. The `ordering` column does nothing on the
 * front end.
 *
 * Each rendered banner is `load()`ed and `store()`d to count an impression, so
 * a module showing N banners costs N SELECTs and N UPDATEs per page view, and
 * every render also runs an UPDATE across the whole table to reset daily counts.
 */
trait FlexbannersBootTrait
{
	/** `type` column values and the module's render branch for each. */
	private const FB_TYPES = [
		0 => 'image',        // imageurl, used as-is relative to the site root
		1 => 'flash_html5',  // `flash` column, a file name under images/banners/
		2 => 'custom_code',  // customcode, echoed raw with no click tracking
		3 => 'cloud_image',  // cloud_imageurl, an absolute URL
	];

	private const FB_STATES = [
		1  => 'published',
		0  => 'unpublished',
		2  => 'archived',
		-2 => 'trashed',
	];

	/** Short names after `#__flexbanners`. */
	private const FB_TABLES = [
		'banners'   => '',
		'restrict'  => 'in',
		'locations' => 'locations',
		'clients'   => 'client',
		'links'     => 'link',
		'sizes'     => 'size',
	];

	private static ?bool $fbInstalledCache = null;

	private static ?array $fbTableListCache = null;

	// -----------------------------------------------------------------------
	// Presence and identity
	// -----------------------------------------------------------------------

	/**
	 * One category for every tool. Deriving it from the namespace would give
	 * `modules`, `reports` and the like, which collide with core MCP for Joomla
	 * and with sibling add-ons.
	 */
	public function getCategory(): string
	{
		return 'flexbanners';
	}

	protected function fbAdminBase(): string
	{
		return JPATH_ADMINISTRATOR . '/components/com_flexbanners';
	}

	protected function fbModuleFile(): string
	{
		return JPATH_SITE . '/modules/mod_flexbanners/mod_flexbanners.php';
	}

	protected function fbInstalled(): bool
	{
		if (self::$fbInstalledCache !== null) {
			return self::$fbInstalledCache;
		}

		self::$fbInstalledCache = is_dir($this->fbAdminBase()) && $this->fbTableExists('');

		return self::$fbInstalledCache;
	}

	protected function fbNotInstalledError(): ToolResult
	{
		return ToolResult::json([
			'ok'    => false,
			'error' => 'FlexBanners (com_flexbanners) is not installed on this site. Expected '
				. 'administrator/components/com_flexbanners and the #__flexbanners tables.',
			'note'  => 'FlexBanners is free from Inch Hosting (JED: "FlexBanner"). This add-on was built '
				. 'against 5.1.10 and checked against 6.0.05.',
		], true);
	}

	/** @return array{component:?string,module:?string,package:?string} */
	protected function fbVersions(): array
	{
		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([$this->db->quoteName('element'), $this->db->quoteName('manifest_cache')])
				->from($this->db->quoteName('#__extensions'))
				->whereIn($this->db->quoteName('element'), ['com_flexbanners', 'mod_flexbanners', 'pkg_flexbanners'], \Joomla\Database\ParameterType::STRING)
		)->loadAssocList('element') ?: [];

		$get = static function (?array $row): ?string {
			$decoded = json_decode((string) ($row['manifest_cache'] ?? ''), true);

			return \is_array($decoded) && !empty($decoded['version']) ? (string) $decoded['version'] : null;
		};

		return [
			'component' => $get($rows['com_flexbanners'] ?? null),
			'module'    => $get($rows['mod_flexbanners'] ?? null),
			'package'   => $get($rows['pkg_flexbanners'] ?? null),
		];
	}

	/**
	 * Does the installed module enforce end dates and impression/click limits?
	 *
	 * Detected from the module's own code rather than its version number,
	 * because that is what decides it. 5.1.10's module never does: the
	 * `disableOldBanners()` / `activateBanners()` helpers exist in the site model
	 * but every call to them is commented out, so `enddate`, `maximpressions` and
	 * `maxclicks` are stored and displayed in the admin and ignored on the site.
	 * 6.0.05's module runs both on EVERY render: it sets `finished=1, state=0` on
	 * expired or capped banners, and sets `state=1` on any banner whose
	 * `startdate` has passed and is not finished, archived or trashed — which
	 * re-publishes a banner someone deliberately unpublished.
	 */
	protected function fbModuleEnforcesLimits(): bool
	{
		$file = $this->fbModuleFile();

		if (!is_file($file)) {
			return false;
		}

		$raw = (string) @file_get_contents($file);

		return preg_match('/SET\s+finished\s*=\s*1/i', $raw) === 1;
	}

	// -----------------------------------------------------------------------
	// Tables
	// -----------------------------------------------------------------------

	/** `#__flexbanners<suffix>` for quoteName(). */
	protected function fbTable(string $suffix): string
	{
		return '#__flexbanners' . $suffix;
	}

	/** @return string[] */
	protected function fbAllTables(): array
	{
		if (self::$fbTableListCache === null) {
			self::$fbTableListCache = $this->db->getTableList();
		}

		return self::$fbTableListCache;
	}

	protected function fbTableExists(string $suffix): bool
	{
		return \in_array($this->db->getPrefix() . 'flexbanners' . $suffix, $this->fbAllTables(), true);
	}

	protected function fbRow(string $suffix, string $key, int $id): ?array
	{
		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->fbTable($suffix)))
				->where($this->db->quoteName($key) . ' = ' . $id)
		)->loadAssoc();

		return \is_array($row) ? $row : null;
	}

	protected function fbBanner(int $id): ?array
	{
		return $this->fbRow('', 'id', $id);
	}

	protected function fbCount(string $suffix, string $where = ''): int
	{
		return (int) $this->db->setQuery(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName($this->fbTable($suffix)) . ($where !== '' ? ' WHERE ' . $where : '')
		)->loadResult();
	}

	// -----------------------------------------------------------------------
	// Arguments
	// -----------------------------------------------------------------------

	protected function fbLimit(array $arguments, int $default = 50, int $max = 200): int
	{
		return max(1, min($max, (int) ($arguments['limit'] ?? $default)));
	}

	protected function fbOffset(array $arguments): int
	{
		return max(0, (int) ($arguments['offset'] ?? 0));
	}

	protected function fbNow(): string
	{
		return Factory::getDate()->toSql();
	}

	protected function fbLike(string $value): string
	{
		return $this->db->quote('%' . $this->db->escape($value, true) . '%', false);
	}

	/** Refuse a value that would not fit a varchar column, rather than let MySQL truncate it. */
	protected function fbRequireFits(string $field, string $value, int $max = 255): void
	{
		if (mb_strlen($value) > $max) {
			throw new \InvalidArgumentException(
				$field . ' is ' . mb_strlen($value) . ' characters; the column holds ' . $max
				. '. Refusing rather than truncating. Nothing was written.'
			);
		}
	}

	/**
	 * A UTC datetime argument, or null for "no date". Accepts anything strtotime()
	 * reads; empty string and null both mean "clear".
	 */
	protected function fbDate(mixed $value, string $field): ?string
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

	/** True when a stored datetime is a real date (not NULL, not the zero date). */
	protected function fbHasDate(?string $value): bool
	{
		return $value !== null && $value !== '' && !str_starts_with($value, '0000-00-00');
	}

	// -----------------------------------------------------------------------
	// Access control
	// -----------------------------------------------------------------------

	/**
	 * Non-guest, `core.manage` on com_flexbanners (the vendor's admin entry point
	 * checks nothing else to show its lists), then the specific action.
	 */
	protected function fbRequireAccess(User $actor, ?string $action = null): void
	{
		if ($actor->guest || (int) $actor->id === 0) {
			throw new \RuntimeException('FlexBanners tools require an authenticated user.');
		}

		if (!$actor->authorise('core.manage', 'com_flexbanners')) {
			throw new \RuntimeException('This user lacks core.manage on com_flexbanners, which the FlexBanners admin requires.');
		}

		if ($action !== null && !$actor->authorise($action, 'com_flexbanners')) {
			throw new \RuntimeException('This user is not granted ' . $action . ' on com_flexbanners.');
		}
	}

	// -----------------------------------------------------------------------
	// Lookups used by the display audit
	// -----------------------------------------------------------------------

	/** The language the module compares against: global config `language`, not the page's. */
	protected function fbSiteLanguage(): string
	{
		return (string) Factory::getApplication()->get('language', 'en-GB');
	}

	/** View levels an anonymous visitor has (Public, Guest). */
	protected function fbGuestViewLevels(): array
	{
		return array_map('intval', Access::getAuthorisedViewLevels(0));
	}

	/** @return array<int,array{id:int,title:string,extension:string,access:int,published:int}> */
	protected function fbCategories(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

		if ($ids === []) {
			return [];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select(['id', 'title', 'extension', 'access', 'published'])
				->from($this->db->quoteName('#__categories'))
				->whereIn($this->db->quoteName('id'), $ids)
		)->loadAssocList('id') ?: [];

		$out = [];

		foreach ($rows as $id => $row) {
			$out[(int) $id] = [
				'id'        => (int) $row['id'],
				'title'     => (string) $row['title'],
				'extension' => (string) $row['extension'],
				'access'    => (int) $row['access'],
				'published' => (int) $row['published'],
			];
		}

		return $out;
	}

	/** The id-keyed rows of a small lookup table. */
	protected function fbLookup(string $suffix, string $key): array
	{
		if (!$this->fbTableExists($suffix)) {
			return [];
		}

		return $this->db->setQuery(
			'SELECT * FROM ' . $this->db->quoteName($this->fbTable($suffix))
		)->loadAssocList($key) ?: [];
	}

	/**
	 * Every mod_flexbanners module on the site, with the parameters that matter
	 * decoded and its menu assignment summarised.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function fbModules(): array
	{
		$db   = $this->db;
		$rows = $db->setQuery(
			$db->getQuery(true)
				->select(['id', 'title', 'position', 'published', 'ordering', 'params', 'access', 'language', 'showtitle', 'publish_up', 'publish_down', 'client_id'])
				->from($db->quoteName('#__modules'))
				->where($db->quoteName('module') . ' = ' . $db->quote('mod_flexbanners'))
				->where($db->quoteName('client_id') . ' = 0')
				->order($db->quoteName('position') . ', ' . $db->quoteName('ordering'))
		)->loadAssocList('id') ?: [];

		if ($rows === []) {
			return [];
		}

		$menuRows = $db->setQuery(
			$db->getQuery(true)
				->select(['moduleid', 'menuid'])
				->from($db->quoteName('#__modules_menu'))
				->whereIn($db->quoteName('moduleid'), array_map('intval', array_keys($rows)))
		)->loadAssocList() ?: [];

		$menus = [];

		foreach ($menuRows as $m) {
			$menus[(int) $m['moduleid']][] = (int) $m['menuid'];
		}

		$out = [];

		foreach ($rows as $id => $row) {
			$params = json_decode((string) ($row['params'] ?? ''), true);
			$params = \is_array($params) ? $params : [];
			$ids    = $menus[(int) $id] ?? [];

			$out[(int) $id] = [
				'id'           => (int) $id,
				'title'        => (string) $row['title'],
				'position'     => (string) $row['position'],
				'published'    => (int) $row['published'],
				'ordering'     => (int) $row['ordering'],
				'access'       => (int) $row['access'],
				'language'     => (string) $row['language'],
				'showtitle'    => (int) $row['showtitle'],
				'publish_up'   => $row['publish_up'],
				'publish_down' => $row['publish_down'],
				'locationid'   => (int) ($params['locationid'] ?? 0),
				'numberbanner' => (int) ($params['numberbanner'] ?? 1),
				'banner_order' => (string) ($params['ordering'] ?? ''),
				'enablecsa'    => (int) ($params['enablecsa'] ?? 0),
				'cache'        => (int) ($params['cache'] ?? 0),
				'params'       => $params,
				'menu_ids'     => $ids,
				'assignment'   => $this->fbAssignmentLabel($ids),
			];
		}

		return $out;
	}

	/** #__modules_menu semantics: [0] = all, positive = only, negative = all except, none = nowhere. */
	protected function fbAssignmentLabel(array $menuIds): string
	{
		if ($menuIds === []) {
			return 'none';
		}

		if (\in_array(0, $menuIds, true)) {
			return 'all';
		}

		return $menuIds[0] < 0 ? 'all_except' : 'only';
	}

	/** Is this module able to render at all (published, positioned, assigned, inside its publish window)? */
	protected function fbModuleRenderProblems(array $module): array
	{
		$problems = [];

		if ($module['published'] !== 1) {
			$problems[] = 'module is not published';
		}

		if (trim($module['position']) === '') {
			$problems[] = 'module has no position';
		}

		if ($module['assignment'] === 'none') {
			$problems[] = 'module is assigned to no pages';
		}

		$now = $this->fbNow();

		if ($this->fbHasDate($module['publish_down'] ?? null) && (string) $module['publish_down'] < $now) {
			$problems[] = 'module publish_down is in the past';
		}

		if ($this->fbHasDate($module['publish_up'] ?? null) && (string) $module['publish_up'] > $now) {
			$problems[] = 'module publish_up is in the future';
		}

		if ($module['locationid'] <= 0) {
			$problems[] = 'module has no locationid set, so it matches no banner';
		}

		return $problems;
	}

	/** Strip the media field's `#joomlaImage://…` suffix and a leading slash. */
	protected function fbImagePath(string $imageurl): string
	{
		$path = preg_replace('/#joomlaImage:.*$/', '', $imageurl) ?? $imageurl;
		$path = ltrim(rawurldecode($path), '/');

		return $path;
	}

	/** @return array{exists:bool,bytes:?int,width:?int,height:?int} */
	protected function fbImageFileInfo(string $relative): array
	{
		$relative = $this->fbImagePath($relative);

		if ($relative === '' || str_contains($relative, '..') || preg_match('#^[a-z][a-z0-9+.-]*://#i', $relative)) {
			return ['exists' => false, 'bytes' => null, 'width' => null, 'height' => null];
		}

		$file = JPATH_ROOT . '/' . $relative;

		if (!is_file($file)) {
			return ['exists' => false, 'bytes' => null, 'width' => null, 'height' => null];
		}

		$dims = @getimagesize($file);

		return [
			'exists' => true,
			'bytes'  => (int) filesize($file),
			'width'  => \is_array($dims) ? (int) $dims[0] : null,
			'height' => \is_array($dims) ? (int) $dims[1] : null,
		];
	}

	/**
	 * Why a banner will not appear for an anonymous visitor, as the module would
	 * decide it. Empty array = the module's query returns it on at least one page.
	 *
	 * @param array<int,array<string,mixed>> $modules  from fbModules()
	 * @return array{problems:string[],warnings:string[],modules:int[]} modules = the modules it appears in
	 */
	protected function fbDisplayAudit(array $banner, array $sizes, array $categories, array $links, array $modules, bool $enforces): array
	{
		$problems = [];
		$warnings = [];
		$state    = (int) ($banner['state'] ?? 0);

		if ($state !== 1) {
			$problems[] = 'state is ' . (self::FB_STATES[$state] ?? (string) $state) . ' (the module shows state = 1 only)';
		}

		if ((int) ($banner['finished'] ?? 0) === 1) {
			$problems[] = 'finished = 1 (the module hides finished banners)';
		}

		$sizeid = (int) ($banner['sizeid'] ?? 0);

		if (!isset($sizes[$sizeid])) {
			$problems[] = 'sizeid ' . $sizeid . ' has no #__flexbannerssize row; the module INNER JOINs on size, so the banner is dropped';
		}

		$catid = (int) ($banner['catid'] ?? 0);
		$cat   = $categories[$catid] ?? null;

		if ($cat === null) {
			$problems[] = 'catid ' . $catid . ' is not a category; the module INNER JOINs on #__categories, so the banner is dropped';
		} else {
			if (!\in_array($cat['access'], $this->fbGuestViewLevels(), true)) {
				$problems[] = 'category ' . $catid . ' has access level ' . $cat['access'] . ', which anonymous visitors do not have (logged-in users with that level still see it)';
			}

			if ($cat['extension'] !== 'com_flexbanners') {
				$warnings[] = 'category ' . $catid . ' belongs to ' . $cat['extension'] . ', not com_flexbanners. The front end does not care, but the FlexBanners admin form lists only com_flexbanners categories, and its save() creates a NEW category when the id fails that check';
			}
		}

		$lang = (string) ($banner['language'] ?? '');
		$site = $this->fbSiteLanguage();

		if ($lang !== '*' && $lang !== $site) {
			$problems[] = 'language "' . $lang . '" is neither "*" nor the site default "' . $site . '" (the module compares against the site default, not the page language)';
		}

		$locationid = (int) ($banner['locationid'] ?? 0);
		$serving    = [];

		foreach ($modules as $module) {
			if ($module['locationid'] === $locationid && $locationid > 0 && $this->fbModuleRenderProblems($module) === []) {
				$serving[] = $module['id'];
			}
		}

		if ($serving === []) {
			$problems[] = $locationid > 0
				? 'no published, positioned and assigned mod_flexbanners module has locationid ' . $locationid
				: 'locationid is not set';
		}

		$type = (int) ($banner['type'] ?? 0);

		switch ($type) {
			case 0:
				$url = (string) ($banner['imageurl'] ?? '');

				if (trim($url) === '') {
					$problems[] = 'type is image but imageurl is empty (the module renders a broken image)';
				} elseif (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
					$problems[] = 'imageurl is an absolute URL; the module prefixes the site base, so it renders "' . 'https://site/https://…' . '". Use type 3 (cloud_image) for remote images';
				} elseif (!$this->fbImageFileInfo($url)['exists']) {
					$problems[] = 'image file "' . $this->fbImagePath($url) . '" does not exist under the site root (broken image)';
				}
				break;

			case 1:
				$flash = (string) ($banner['flash'] ?? '');

				if ($flash === '' || !is_file(JPATH_ROOT . '/images/banners/' . basename($flash))) {
					$problems[] = 'type is flash/HTML5 but images/banners/' . $flash . ' does not exist';
				}

				$warnings[] = 'type 1 (flash/HTML5) is a legacy branch: the HTML5 path references two undefined variables and Flash has no browser support';
				break;

			case 2:
				if (trim((string) ($banner['customcode'] ?? '')) === '') {
					$problems[] = 'type is custom code but customcode is empty';
				}

				$warnings[] = 'custom-code banners are echoed raw with no click tracking and no wrapper div';
				break;

			case 3:
				if (!preg_match('#^https?://#i', (string) ($banner['cloud_imageurl'] ?? ''))) {
					$problems[] = 'type is cloud image but cloud_imageurl is not an http(s) URL';
				}
				break;

			default:
				$problems[] = 'type ' . $type . ' is not one the module knows (0 image, 1 flash/HTML5, 2 custom code, 3 cloud image); it falls through to the image branch';
		}

		if ($type !== 2) {
			$linkid = (int) ($banner['linkid'] ?? 0);

			if (!isset($links[$linkid])) {
				$warnings[] = 'linkid ' . $linkid . ' has no #__flexbannerslink row: the banner shows, but a click redirects to "http://"';
			} elseif (trim((string) $links[$linkid]['linkurl']) === '') {
				$warnings[] = 'link ' . $linkid . ' has an empty URL: a click redirects to "http://"';
			}
		}

		$now = $this->fbNow();

		$expired = $this->fbHasDate($banner['enddate'] ?? null) && (string) $banner['enddate'] < $now;
		$capImp  = (int) ($banner['maximpressions'] ?? 0) > 0 && (int) $banner['impmade'] >= (int) $banner['maximpressions'];
		$capClk  = (int) ($banner['maxclicks'] ?? 0) > 0 && (int) $banner['clicks'] >= (int) $banner['maxclicks'];

		if ($expired || $capImp || $capClk) {
			$why = implode(', ', array_filter([$expired ? 'end date passed' : null, $capImp ? 'impression limit reached' : null, $capClk ? 'click limit reached' : null]));

			if ($enforces) {
				$problems[] = $why . ': this module version sets finished=1, state=0 on its next render';
			} else {
				$warnings[] = $why . ', but this FlexBanners module version never enforces end dates or limits, so it keeps showing';
			}
		}

		if ($this->fbHasDate($banner['startdate'] ?? null) && (string) $banner['startdate'] > $now && $state === 1) {
			$warnings[] = 'start date is in the future, but the module never checks start dates: a published banner shows now';
		}

		if ((int) ($banner['restrictbyid'] ?? 0) === 1) {
			$warnings[] = 'restricted to chosen categories/articles: this only takes effect on modules with enablecsa = 1, and there a restricted banner with no matching restriction row shows nowhere';
		}

		// `modules` = where it actually appears, so empty whenever something stops it.
		return ['problems' => $problems, 'warnings' => $warnings, 'modules' => $problems === [] ? $serving : []];
	}

	// -----------------------------------------------------------------------
	// Validation shared by the write tools
	// -----------------------------------------------------------------------

	/** A click-through URL: http(s), or a site-relative index.php link. */
	protected function fbValidateLinkUrl(string $url): string
	{
		$url = trim($url);

		if ($url === '') {
			throw new \InvalidArgumentException('link_url is empty.');
		}

		if (preg_match('/\s/', $url)) {
			throw new \InvalidArgumentException('link_url contains whitespace; encode it first.');
		}

		if (preg_match('#^https?://[^/\s]+#i', $url) !== 1 && !str_starts_with($url, 'index.php')) {
			throw new \InvalidArgumentException(
				'link_url must start with http:// or https:// (or be a site-relative index.php?… link). '
				. 'FlexBanners prefixes "http://" to anything else, so "www.example.com" works by accident and '
				. '"/page" becomes "http:///page".'
			);
		}

		return $url;
	}

	/**
	 * The module prints `imageurl`, `cloud_imageurl` and `imagealt` into HTML
	 * attributes WITHOUT escaping (`mod_flexbanners/src/Helper/FlexbannersHelper.php`
	 * `FlexBannersloadfirst()`), and the vendor's own `check()` makes it worse by
	 * running `htmlspecialchars_decode()` on `imagealt`. A quote or angle bracket
	 * in any of them breaks out of the attribute on every page the banner shows on.
	 */
	protected function fbRequireAttributeSafe(string $field, string $value): void
	{
		if (preg_match('/["<>]/', $value)) {
			throw new \InvalidArgumentException(
				$field . ' contains a double quote or angle bracket. mod_flexbanners prints this value into an '
				. 'HTML attribute without escaping it, so that would break the page markup (and is a script '
				. 'injection route). Remove those characters.'
			);
		}
	}

	/** A unique alias within the category, as FlexbannerTable::store() requires on the next admin save. */
	protected function fbUniqueAlias(string $base, int $catid, string $language, int $exceptId = 0): string
	{
		$alias = ApplicationHelper::stringURLSafe($base, $language === '*' ? '' : $language);

		if (trim(str_replace('-', '', $alias)) === '') {
			$alias = Factory::getDate()->format('Y-m-d-H-i-s');
		}

		$candidate = $alias;
		$n         = 2;

		while ((int) $this->db->setQuery(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName($this->fbTable(''))
			. ' WHERE alias = ' . $this->db->quote($candidate) . ' AND catid = ' . $catid . ' AND id <> ' . $exceptId
		)->loadResult() > 0) {
			$candidate = $alias . '-' . $n++;
		}

		return $candidate;
	}

	/** Language must be '*' or an installed content language. */
	protected function fbValidateLanguage(string $language): string
	{
		if ($language === '*') {
			return $language;
		}

		$exists = (int) $this->db->setQuery(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__languages') . ' WHERE lang_code = ' . $this->db->quote($language)
		)->loadResult();

		if ($exists === 0) {
			throw new \InvalidArgumentException('language "' . $language . '" is not an installed content language. Use "*" for all languages.');
		}

		return $language;
	}

	/** The default category for new banners: the lowest-id published, public com_flexbanners category. */
	protected function fbDefaultCategoryId(): int
	{
		return (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('id')
				->from($this->db->quoteName('#__categories'))
				->where($this->db->quoteName('extension') . ' = ' . $this->db->quote('com_flexbanners'))
				->where($this->db->quoteName('published') . ' = 1')
				->where($this->db->quoteName('access') . ' = 1')
				->order('id ASC'),
			0,
			1
		)->loadResult();
	}

	protected function fbStateLabel(int $state): string
	{
		return self::FB_STATES[$state] ?? (string) $state;
	}

	/** Parse a state argument that may be a number or a word. */
	protected function fbParseState(mixed $value): int
	{
		if (\is_int($value) || (\is_string($value) && preg_match('/^-?\d+$/', $value))) {
			$state = (int) $value;
		} else {
			$state = array_search(strtolower((string) $value), self::FB_STATES, true);
			$state = $state === false ? 99 : (int) $state;
		}

		if (!isset(self::FB_STATES[$state])) {
			throw new \InvalidArgumentException('state must be one of published (1), unpublished (0), archived (2), trashed (-2).');
		}

		return $state;
	}
}
