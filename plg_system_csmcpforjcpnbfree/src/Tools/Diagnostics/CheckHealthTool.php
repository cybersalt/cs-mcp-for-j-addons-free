<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\CpnbBootTrait;
use Joomla\CMS\User\User;

/**
 * Configuration audit: everything that makes the bar silently not show, show
 * the wrong text, or ignore a rule the admin believes is active.
 */
final class CheckHealthTool extends AbstractTool
{
	use CpnbBootTrait;

	public function getName(): string { return 'check_cpnb_health'; }

	public function getDescription(): string
	{
		return 'Audit a Cookies Policy Notification Bar install for problems that fail silently. Checks: the '
			. 'system plugin (renders the bar) and ajax plugin (settings API) are enabled; the Web357 framework is '
			. 'present; the stored settings are valid JSON and seeded; the site\'s DEFAULT language has its own '
			. 'texts (texts are stored per language tag, so a site defaulting to en-US shows language-file '
			. 'fallbacks if only en_GB was saved); cookie categories are non-empty, unique and include '
			. 'required-cookies (an empty list makes the front end fall back to four hard-coded sample '
			. 'categories); blocking rules point at categories that exist; menu include/exclude lists reference '
			. 'real menu items; GeoIP lists are not set while GeoIP is off, and only one list is set (CPNB only '
			. 'evaluates the first non-empty one); orphaned settings keys left over from CPNB 4.x; and that '
			. 'consent logs exist and would be DROPPED if CPNB were uninstalled. Each finding has a severity '
			. '(critical/warning/info). Read-only.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cpnbInstalled()) {
			return $this->cpnbNotInstalledError();
		}

		$this->cpnbRequireAccess($actor);

		$findings = [];
		$add      = static function (string $severity, string $check, string $message) use (&$findings): void {
			$findings[] = ['severity' => $severity, 'check' => $check, 'message' => $message];
		};

		// --- Package members -------------------------------------------------
		foreach ([['system', 'cookiespolicynotificationbar', 'critical', 'renders the bar and owns every setting'],
			['ajax', 'cookiespolicynotificationbar', 'critical', 'carries the settings API and the front-end consent endpoints'],
			['system', 'web357framework', 'warning', 'is the Web357 framework CPNB depends on']] as [$folder, $element, $sev, $why]) {
			$row = $this->cpnbExtensionRow('plugin', $element, $folder);

			if ($row === null) {
				$add($sev, 'plugin_' . $folder . '_' . $element, 'plg_' . $folder . '_' . $element . ' is not installed; it ' . $why . '.');
			} elseif (!$row['enabled']) {
				$add($sev, 'plugin_' . $folder . '_' . $element, 'plg_' . $folder . '_' . $element . ' is DISABLED; it ' . $why . '.');
			}
		}

		$system = $this->cpnbExtensionRow('plugin', 'cookiespolicynotificationbar', 'system');

		if ($system !== null && $system['access'] !== 1) {
			$add('warning', 'system_plugin_access', 'The system plugin\'s access level is ' . $system['access']
				. ', not Public (1). Joomla only loads a plugin for visitors who can see its access level, so the bar '
				. 'will not appear for guests.');
		}

		// --- Edition ---------------------------------------------------------
		$edition = $this->cpnbEdition();

		if (!$edition['agree']) {
			$add('warning', 'edition', 'The manifest variant and the settings API disagree about the edition (likely a partial upgrade).');
		}

		// --- Stored settings -------------------------------------------------
		try {
			$stored = $this->cpnbStoredParams();
		} catch (\RuntimeException $e) {
			$add('critical', 'settings_json', $e->getMessage());

			return $this->report($findings, $edition);
		}

		$default = $this->cpnbDefaultLanguageTag();
		$active  = $this->cpnbActiveLanguageTags();

		if (!\array_key_exists('header_message_' . $default, $stored)) {
			$seeded = array_values(array_filter(array_map(
				static fn ($k) => str_starts_with((string) $k, 'header_message_') ? substr((string) $k, 15) : null,
				array_keys($stored)
			)));

			$add('warning', 'default_language_texts', 'No texts are stored for the site\'s default language ('
				. $default . '). The bar reads header_message_' . $default . ' and friends, and falls back to the '
				. 'plugin\'s language-file defaults when they are missing. Texts are stored for: '
				. ($seeded === [] ? '(none)' : implode(', ', $seeded)) . '. Use set_cpnb_texts.');
		}

		foreach ($active as $tag) {
			if ($tag !== $default && !\array_key_exists('header_message_' . $tag, $stored)) {
				$add('info', 'language_texts_' . $tag, 'Active language ' . $tag . ' has no stored texts; visitors in that language see language-file defaults.');
			}
		}

		// --- Categories ------------------------------------------------------
		$categories = $this->cpnbRows($stored, 'cookie_categories_group');
		$ids        = [];

		if (!\array_key_exists('cookie_categories_group', $stored)) {
			$add('info', 'categories', 'No cookie categories are stored; the front end uses CPNB\'s four built-in sample categories.');
		} elseif ($categories === []) {
			$add('warning', 'categories', 'The stored cookie-category list is EMPTY. CPNB does not show "no categories" — it silently falls back to four hard-coded sample categories on the front end.');
		}

		foreach ($categories as $cat) {
			$id = (string) ($cat['cookie_category_id'] ?? '');

			if ($id === '') {
				$add('warning', 'categories', 'A cookie category has an empty id; visitors\' choices for it cannot be stored.');
				continue;
			}

			if (isset($ids[$id])) {
				$add('critical', 'categories', 'Duplicate cookie category id "' . $id . '": both rows write the same key in the consent cookie.');
			}

			$ids[$id] = (string) ($cat['cookie_category_status'] ?? '1');
		}

		if ($categories !== [] && !isset($ids['required-cookies'])) {
			$add('warning', 'categories', 'There is no "required-cookies" category. CPNB\'s consent-log summary treats that id as always accepted; without it every visitor\'s functional cookies are recorded as a choice.');
		}

		// --- Blocking rules (PRO data; reported on any edition) ---------------
		foreach (['block_cookies_group' => 'script', 'block_scripts_by_url_group' => 'URL', 'block_elements_group' => 'element'] as $key => $label) {
			foreach ($this->cpnbRows($stored, $key) as $i => $rule) {
				$cat = (string) ($rule['blockcookiecategory'] ?? '');

				if ($cat !== '' && $ids !== [] && !isset($ids[$cat])) {
					$add('warning', 'blocking_rules', 'Blocked ' . $label . ' #' . $i . ' ("' . ($rule['block_cookie_name'] ?? $rule['block_url_name'] ?? $rule['block_element_name'] ?? '') . '") belongs to category "' . $cat . '", which does not exist; nobody can opt in to it.');
				}
			}
		}

		// --- Menu include/exclude --------------------------------------------
		foreach (['include_menu_items_show_notification_bar', 'exclude_menu_items_show_notification_bar', 'modal_include_menu_items', 'modal_exclude_menu_items'] as $key) {
			$list = array_values(array_filter(array_map('intval', (array) ($stored[$key] ?? []))));

			if ($list === []) {
				continue;
			}

			$found = array_map('intval', (array) $this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName('id'))
					->from($this->db->quoteName('#__menu'))
					->whereIn($this->db->quoteName('id'), $list)
					->where($this->db->quoteName('published') . ' = 1')
			)->loadColumn());

			$missing = array_values(array_diff($list, $found));

			if ($missing !== []) {
				$add('warning', $key, $key . ' references menu item(s) ' . implode(', ', $missing) . ' that do not exist or are unpublished.');
			}

			if (str_starts_with($key, 'include_')) {
				$add('info', $key, 'The bar is shown ONLY on ' . \count($list) . ' menu item(s); every other page has no bar.');
			}
		}

		// --- GeoIP -----------------------------------------------------------
		$geoLists = array_filter([
			'include_continents' => (array) ($stored['include_continents'] ?? []),
			'exclude_continents' => (array) ($stored['exclude_continents'] ?? []),
			'include_countries'  => (array) ($stored['include_countries'] ?? []),
			'exclude_countries'  => (array) ($stored['exclude_countries'] ?? []),
		], static fn (array $l): bool => array_filter($l, static fn ($v) => (string) $v !== '') !== []);

		if ($geoLists !== []) {
			if ((string) ($stored['enableGeoIP2Webservice'] ?? '0') !== '1') {
				$add('warning', 'geoip', 'GeoIP lists are set (' . implode(', ', array_keys($geoLists)) . ') but GeoIP targeting is OFF, so they have no effect.');
			} elseif (\count($geoLists) > 1) {
				$add('warning', 'geoip', 'More than one GeoIP list is set (' . implode(', ', array_keys($geoLists)) . '). CPNB evaluates them in the order include_continents, exclude_continents, include_countries, exclude_countries and uses ONLY the first non-empty one; ' . array_key_first($geoLists) . ' wins and the rest are ignored.');
			}
		}

		// --- Orphaned keys ---------------------------------------------------
		$rules    = $this->cpnbRules();
		$orphans  = [];

		foreach (array_keys($stored) as $key) {
			$key = (string) $key;

			if (isset($rules[$key])) {
				continue;
			}

			$base = preg_replace('/_[a-z]{2,3}_[A-Z]{2}$/', '', $key);

			if ($base !== null && isset($rules[$base]) && !empty($rules[$base]['localized'])) {
				continue;
			}

			$orphans[] = $key;
		}

		if ($orphans !== []) {
			$add('info', 'orphaned_keys', \count($orphans) . ' stored key(s) have no rule in CPNB 5.x\'s settings API (left over from 4.x or another build): '
				. implode(', ', \array_slice($orphans, 0, 40)) . (\count($orphans) > 40 ? ', …' : '') . '. Harmless, but the dashboard cannot edit them.');
		}

		// --- Consent logs ----------------------------------------------------
		$logTable = $this->db->getPrefix() . 'plg_system_cookiespolicynotificationbar_logs';
		$tables   = $this->db->getTableList();

		if (\in_array($logTable, $tables, true)) {
			$count = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->db->quoteName('#__plg_system_cookiespolicynotificationbar_logs'))->loadResult();

			if ($count > 0) {
				$add('info', 'consent_logs', $count . ' consent-log row(s) exist. CPNB\'s uninstaller DROPS this table, so export them before uninstalling or reinstalling from scratch.');
			}
		}

		// --- Caching ---------------------------------------------------------
		$pageCache = $this->cpnbExtensionRow('plugin', 'cache', 'system');

		if ($pageCache !== null && $pageCache['enabled']) {
			$add('info', 'page_cache', 'Joomla\'s System - Page Cache plugin is enabled. CPNB purges it on every settings save; edits made outside CPNB\'s API (direct SQL) will not show until the cache is cleared.');
		}

		return $this->report($findings, $edition);
	}

	private function report(array $findings, array $edition): ToolResult
	{
		$counts = ['critical' => 0, 'warning' => 0, 'info' => 0];

		foreach ($findings as $f) {
			$counts[$f['severity']]++;
		}

		return ToolResult::json([
			'ok'           => true,
			'cpnb_version' => $this->cpnbVersion(),
			'edition'      => $edition['is_pro'] ? 'pro' : 'free',
			'healthy'      => $counts['critical'] === 0 && $counts['warning'] === 0,
			'counts'       => $counts,
			'findings'     => $findings,
			'next_steps'   => 'check_cpnb_frontend confirms what the live page actually renders.',
		]);
	}
}
