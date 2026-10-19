<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

/**
 * Site-wide FlexBanners audit. Strictly read-only.
 */
final class CheckHealthTool extends AbstractTool
{
	use FlexbannersBootTrait;

	public function getName(): string { return 'check_flexbanners_health'; }

	public function getDescription(): string
	{
		return 'FlexBanners health audit, read-only. First checks whether FlexBanners can run at all on this Joomla: '
			. 'on Joomla 6 both 5.1.10 and 6.0.05 need the "Behaviour - Backward Compatibility 6" plugin (a JPATH_PLATFORM '
			. 'guard that silently kills every page showing a banner, plus JRoute/JModuleHelper calls). Reports component / module / package versions and which '
			. 'behaviour the installed module has (5.1.x never enforces end dates or impression/click limits; 6.x '
			. 'enforces them AND re-publishes unpublished banners whose start date has passed), table presence and '
			. 'row counts, every mod_flexbanners module and whether it can render, and every banner that will NOT '
			. 'appear for an anonymous visitor, with the reason as mod_flexbanners decides it: unpublished or '
			. 'finished, a missing size or category row (both INNER JOINed), a category visitors cannot see, a '
			. 'language other than "*" or the site default, no module showing its location, a missing image file, '
			. 'an absolute URL in the image field. Also flags: categories outside com_flexbanners, broken or empty '
			. 'click-through links, orphaned restriction rows (including the contentid 0 rows FlexBanners\' own '
			. 'admin writes), locations with banners but no module, more qualifying banners than a module shows, '
			. 'module caching, and the inline-anchor CSS issue that makes only a thin strip of each image clickable.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$versions = $this->fbVersions();

		if (!$this->fbInstalled()) {
			$out = json_decode((string) $this->fbNotInstalledError()->content[0]['text'], true);
			$out['versions'] = $versions;

			return ToolResult::json($out, true);
		}

		$this->fbRequireAccess($actor);

		$db       = $this->db;
		$enforces = $this->fbModuleEnforcesLimits();
		$issues   = [];
		$add      = static function (string $severity, string $check, string $message, array $ids = []) use (&$issues): void {
			$issues[] = ['severity' => $severity, 'check' => $check, 'message' => $message] + ($ids !== [] ? ['ids' => $ids] : []);
		};

		// Tables.
		$tables = [];

		foreach (self::FB_TABLES as $label => $suffix) {
			$exists          = $this->fbTableExists($suffix);
			$tables[$label]  = ['table' => '#__flexbanners' . $suffix, 'exists' => $exists, 'rows' => $exists ? $this->fbCount($suffix) : null];

			if (!$exists) {
				$add('error', 'table_missing', '#__flexbanners' . $suffix . ' is missing; the install is incomplete.');
			}
		}

		if (\in_array(false, array_column($tables, 'exists'), true)) {
			return ToolResult::json(['ok' => false, 'versions' => $versions, 'tables' => $tables, 'issues' => $issues], true);
		}

		if ($versions['component'] !== null && $versions['module'] !== null && $versions['component'] !== $versions['module']) {
			$add('warning', 'version_mismatch', 'com_flexbanners is ' . $versions['component'] . ' but mod_flexbanners is ' . $versions['module'] . '. Install the whole package so they match.');
		}

		if (!is_file($this->fbModuleFile())) {
			$add('error', 'module_missing', 'mod_flexbanners is not installed; nothing can display banners.');
		}

		foreach ($this->joomla6Breakage() as [$check, $message]) {
			$add('error', $check, $message);
		}

		// Modules.
		$modules = $this->fbModules();
		$locs    = $this->fbLookup('locations', 'locationid');
		$modOut  = [];

		foreach ($modules as $m) {
			$problems = $this->fbModuleRenderProblems($m);
			$modOut[] = [
				'id'                => $m['id'],
				'title'             => $m['title'],
				'position'          => $m['position'],
				'published'         => $m['published'],
				'locationid'        => $m['locationid'],
				'location_name'     => isset($locs[$m['locationid']]) ? (string) $locs[$m['locationid']]['name'] : null,
				'number_of_banners' => $m['numberbanner'],
				'banner_order'      => $m['banner_order'],
				'content_specific'  => $m['enablecsa'] === 1,
				'menu_assignment'   => $m['assignment'],
				'render_problems'   => $problems,
			];

			if ($m['published'] === 1 && $problems !== []) {
				$add('warning', 'module_cannot_render', 'Module ' . $m['id'] . ' "' . $m['title'] . '" is published but: ' . implode('; ', $problems) . '.', [$m['id']]);
			}

			if ($m['locationid'] > 0 && !isset($locs[$m['locationid']])) {
				$add('info', 'module_location_row_missing', 'Module ' . $m['id'] . ' shows location ' . $m['locationid'] . ', which has no #__flexbannerslocations row. It still works (the module only matches the id), but the module settings cannot display or re-select it.', [$m['id']]);
			}

			if ($m['banner_order'] !== '' && !\in_array($m['banner_order'], ['down', 'up', 'random'], true)) {
				$add('info', 'module_legacy_ordering', 'Module ' . $m['id'] . ' has ordering "' . $m['banner_order'] . '", which is not a current choice; it behaves as "down" (database order, no rotation).', [$m['id']]);
			}

			if ($m['cache'] === 1) {
				$add('warning', 'module_cached', 'Module ' . $m['id'] . ' has caching on: banners do not rotate and impressions are not counted while a cached copy is served.', [$m['id']]);
			}
		}

		if ($modules === []) {
			$add('warning', 'no_modules', 'There is no mod_flexbanners module on the site, so no banner can display.');
		}

		// Banners.
		$rows       = $db->setQuery('SELECT * FROM ' . $db->quoteName($this->fbTable('')) . ' ORDER BY id')->loadAssocList() ?: [];
		$sizes      = $this->fbLookup('size', 'sizeid');
		$links      = $this->fbLookup('link', 'linkid');
		$categories = $this->fbCategories(array_column($rows, 'catid'));
		$hidden     = [];
		$displayCnt = [];

		foreach ($rows as $r) {
			$audit = $this->fbDisplayAudit($r, $sizes, $categories, $links, $modules, $enforces);
			$state = (int) $r['state'];

			// `modules` lists every module serving the banner's location; only a banner
			// with no problems actually appears in it.
			if ($audit['problems'] === []) {
				foreach ($audit['modules'] as $mid) {
					$displayCnt[$mid] = ($displayCnt[$mid] ?? 0) + 1;
				}
			}

			// Unpublished/archived/trashed banners are hidden on purpose; report only
			// published banners that fail, plus every banner's structural faults.
			$structural = array_values(array_filter($audit['problems'], static fn (string $p): bool => !str_starts_with($p, 'state is ')));

			if ($state === 1 && $audit['problems'] !== []) {
				$hidden[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'location' => (int) $r['locationid'], 'reasons' => $audit['problems']];
			} elseif ($state === 0 && $structural !== []) {
				$hidden[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'location' => (int) $r['locationid'], 'reasons' => $audit['problems'], 'note' => 'unpublished; these would still stop it showing once published'];
			}

			foreach ($audit['warnings'] as $w) {
				if ($state >= 0) {
					$add('info', 'banner_warning', 'Banner ' . $r['id'] . ' "' . $r['name'] . '": ' . $w, [(int) $r['id']]);
				}
			}
		}

		if ($hidden !== []) {
			$add('warning', 'banners_not_displaying', \count($hidden) . ' published (or structurally broken unpublished) banner(s) will not appear for anonymous visitors. See not_displaying.');
		}

		foreach ($modules as $m) {
			$n = $displayCnt[$m['id']] ?? 0;

			if ($m['published'] === 1 && $this->fbModuleRenderProblems($m) === [] && $n === 0) {
				$add('warning', 'module_empty', 'Module ' . $m['id'] . ' "' . $m['title'] . '" renders nothing: no displayable banner is in location ' . $m['locationid'] . '.', [$m['id']]);
			}

			if ($n > $m['numberbanner'] && $m['banner_order'] !== 'random') {
				$add('info', 'module_truncates', 'Module ' . $m['id'] . ' has ' . $n . ' displayable banners but shows ' . $m['numberbanner'] . ' and does not rotate, so the same first ' . $m['numberbanner'] . ' always show.', [$m['id']]);
			}
		}

		// Locations with live banners and no module.
		$moduleLocs = array_unique(array_column($modules, 'locationid'));

		foreach ($db->setQuery('SELECT locationid, COUNT(*) AS n FROM ' . $db->quoteName($this->fbTable('')) . ' WHERE state = 1 GROUP BY locationid')->loadAssocList() ?: [] as $r) {
			if (!\in_array((int) $r['locationid'], $moduleLocs, true)) {
				$add('warning', 'location_without_module', 'Location ' . $r['locationid'] . (isset($locs[$r['locationid']]) ? ' "' . $locs[$r['locationid']]['name'] . '"' : '') . ' has ' . $r['n'] . ' published banner(s) but no mod_flexbanners module shows it. create_flexbanners_location_module (or update_flexbanners_module) fixes that.');
			}
		}

		// Categories.
		$fbCats = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__categories') . ' WHERE extension = ' . $db->quote('com_flexbanners') . ' AND published = 1')->loadResult();

		if ($fbCats === 0) {
			$add('warning', 'no_flexbanners_category', 'There is no published com_flexbanners category. Every banner needs one (the module INNER JOINs on #__categories) and FlexBanners does not create one on install. create_category {"title": "Uncategorised", "extension": "com_flexbanners"}.');
		}

		$foreign = $db->setQuery(
			'SELECT a.id FROM ' . $db->quoteName($this->fbTable('')) . ' a JOIN ' . $db->quoteName('#__categories') . ' c ON c.id = a.catid WHERE c.extension <> ' . $db->quote('com_flexbanners')
		)->loadColumn() ?: [];

		if ($foreign !== []) {
			$add('info', 'foreign_category', \count($foreign) . ' banner(s) use a category from another component (often com_banners). They display, but the FlexBanners admin form will move them to a com_flexbanners category — or create a stray one — on their next save.', array_map('intval', $foreign));
		}

		// Restriction rows.
		$orphanIn = (int) $db->setQuery(
			'SELECT COUNT(*) FROM ' . $db->quoteName($this->fbTable('in')) . ' i LEFT JOIN ' . $db->quoteName($this->fbTable('')) . ' a ON a.id = i.bannerid WHERE a.id IS NULL'
		)->loadResult();

		if ($orphanIn > 0) {
			$add('info', 'orphan_restrictions', $orphanIn . ' restriction row(s) in #__flexbannersin belong to no banner (FlexBanners\' admin save keys new restrictions on the request id, which is 0 for a new banner).');
		}

		$zeroContent = (int) $db->setQuery(
			'SELECT COUNT(*) FROM ' . $db->quoteName($this->fbTable('in')) . ' WHERE contentid = 0 AND (categoryid IS NULL OR categoryid = 0)'
		)->loadResult();

		if ($zeroContent > 0) {
			$add('info', 'zero_article_restrictions', $zeroContent . ' restriction row(s) have article id 0: FlexBanners\' admin form inserts an undefined variable for article restrictions. Article restrictions set in the admin never work; set them with update_flexbanner.');
		}

		// Links.
		$unused = (int) $db->setQuery(
			'SELECT COUNT(*) FROM ' . $db->quoteName($this->fbTable('link')) . ' l LEFT JOIN ' . $db->quoteName($this->fbTable('')) . ' a ON a.linkid = l.linkid WHERE a.id IS NULL'
		)->loadResult();

		if ($unused > 0) {
			$add('info', 'unused_links', $unused . ' link row(s) are used by no banner.');
		}

		// Checked out.
		$checkedOut = $db->setQuery('SELECT id FROM ' . $db->quoteName($this->fbTable('')) . ' WHERE checked_out > 0')->loadColumn() ?: [];

		if ($checkedOut !== []) {
			$add('info', 'checked_out', \count($checkedOut) . ' banner(s) are checked out in the admin; an open editor will overwrite tool changes when its user saves.', array_map('intval', $checkedOut));
		}

		$add('info', 'clickable_area', 'FlexBanners wraps each image in an inline <a>, so in many templates only a thin strip through the middle of the image is clickable. Template CSS fixes it: .flexbannergroup .advert a { display:block; line-height:0 } .flexbannergroup .advert a img { display:block; max-width:100%; height:auto }.');

		if ($enforces) {
			$add('info', 'module_6x_behaviour', 'This mod_flexbanners enforces end dates and impression/click limits on every render (finished=1, state=0), and also sets state=1 on EVERY unfinished banner whose start date has passed — unpublishing such a banner lasts only until the next page view unless its start date is cleared.');
		} else {
			$add('info', 'module_5x_behaviour', 'This mod_flexbanners (5.1.x) never enforces end dates or impression/click limits; banners past them keep showing. Start dates are never checked either.');
		}

		$counts = ['error' => 0, 'warning' => 0, 'info' => 0];

		usort($issues, static fn (array $a, array $b): int => array_search($a['severity'], ['error', 'warning', 'info'], true) <=> array_search($b['severity'], ['error', 'warning', 'info'], true));

		foreach ($issues as $i) {
			$counts[$i['severity']]++;
		}

		return ToolResult::json([
			'ok'                     => $counts['error'] === 0,
			'versions'               => $versions,
			'module_enforces_limits' => $enforces,
			'site_default_language'  => $this->fbSiteLanguage(),
			'tables'                 => $tables,
			'modules'                => $modOut,
			'not_displaying'         => $hidden,
			'summary'                => $counts,
			'issues'                 => $issues,
		]);
	}

	/**
	 * FlexBanners 5.1.10 AND 6.0.05 still depend on things Joomla 6 provides
	 * only through the "Behaviour - Backward Compatibility 6" plugin (compat6):
	 *
	 *   - `FlexbannersComponent.php` opens with `\defined('JPATH_PLATFORM') or die;`.
	 *     Joomla 6 defines that constant only in compat6. Without it, every boot of
	 *     com_flexbanners — the admin, the click redirect, the banner module's first
	 *     route — silently die()s: HTTP 200 with whatever had been printed so far.
	 *     On the front end that is a page consisting of nothing but
	 *     `<div class="flexbannergroup">`. Found live on Joomla 6.2.0, 2026-10-19.
	 *   - mod_flexbanners calls `JRoute::_()` on every render and
	 *     `JModuleHelper::getLayoutPath()` for every banner — legacy aliases that
	 *     exist on Joomla 6 only with compat6's "class aliases" option on. Without
	 *     them the whole page is a 500 "Class JRoute not found".
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	private function joomla6Breakage(): array
	{
		$out = [];

		$component = $this->fbAdminBase() . '/src/Extension/FlexbannersComponent.php';

		if (is_file($component) && !\defined('JPATH_PLATFORM')
			&& preg_match("/defined\\(\\s*'JPATH_PLATFORM'\\s*\\)\\s*or\\s*die/", (string) @file_get_contents($component)) === 1) {
			$out[] = [
				'joomla6_component_dies',
				'com_flexbanners will not run on this site: its FlexbannersComponent.php starts with defined(\'JPATH_PLATFORM\') or die, and '
				. 'JPATH_PLATFORM is undefined (Joomla 6 defines it only in the "Behaviour - Backward Compatibility 6" plugin, which is off). '
				. 'Every FlexBanners admin screen, every banner click, and every page where the module renders a banner stops dead with '
				. 'HTTP 200 and a truncated page. Enable Behaviour - Backward Compatibility 6, or ask Inch Hosting for a Joomla 6-native build. '
				. 'These MCP tools are not affected: they never boot com_flexbanners.',
			];
		}

		$module = is_file($this->fbModuleFile()) ? (string) @file_get_contents($this->fbModuleFile()) : '';
		$missing = [];

		foreach (['JRoute', 'JModuleHelper'] as $alias) {
			if (str_contains($module, $alias . '::') && !class_exists($alias)) {
				$missing[] = $alias;
			}
		}

		if ($missing !== []) {
			$out[] = [
				'joomla6_module_fatal',
				'mod_flexbanners calls ' . implode(' and ', $missing) . ', legacy class aliases this site does not load (on Joomla 6 they come '
				. 'only from "Behaviour - Backward Compatibility 6" with its class-aliases option on). Any page showing a published '
				. 'mod_flexbanners module fails with a 500 error.',
			];
		}

		return $out;
	}
}
