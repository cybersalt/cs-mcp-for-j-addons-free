<?php
/**
 * @package     MCP for Cookies Policy Notification Bar
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Shared orientation, edition gate and settings I/O for every Cookies Policy
 * Notification Bar (CPNB) tool.
 *
 * Built against CPNB 5.1.2 (Pro package, 2026-10-07) on Joomla 6.2.0.
 *
 * ---------------------------------------------------------------------------
 * WHERE THE SETTINGS LIVE (5.x)
 * ---------------------------------------------------------------------------
 *
 * Every setting is one JSON blob: `#__extensions.params` of the SYSTEM plugin
 * `plg_system_cookiespolicynotificationbar`. 5.x moved the editor out of the
 * plugin's Options page into a React dashboard (`com_cookiespolicynotificationbar`),
 * but the storage did not move. The dashboard talks to `plg_ajax_cookiespolicynotificationbar`,
 * whose `Web357\CookiesPolicyNotificationBarApi\Model\SettingsModel` is the only
 * writer. The legacy Options page refuses to save
 * (`PLG_SYSTEM_CPNB_LEGACY_PAGE_SAVE_BLOCKED`).
 *
 * We write through that same `SettingsModel::saveFromApiArray()` so the vendor's
 * own validation rules and cache purge (page cache, every cache group, and JCH
 * Optimize's page cache) apply. Two things about it shape how we call it:
 *
 *   1. IT MERGES, IT DOES NOT TRUNCATE. `array_replace($current, $incoming)`
 *      at the top level. Unlike Events Booking or Membership Pro there is no
 *      truncate-and-reinsert, so a partial payload is safe — EXCEPT that a
 *      subform key (categories, cookie declarations, blocking rules) is
 *      replaced WHOLE. Every subform tool here therefore reads the full list,
 *      edits one row and writes the full list back.
 *
 *   2. ITS "CURRENT" PARAMS COME FROM PluginHelper::getPlugin(), WHICH ONLY
 *      RETURNS ENABLED PLUGINS. With the system plugin disabled the model
 *      starts from `[]` and a one-key save WIPES THE ENTIRE CONFIGURATION —
 *      every text, colour and category — down to that one key. We never let
 *      the model read its own params: cpnbModel() builds the instance without
 *      its constructor and injects the params we read straight from the
 *      database. (PluginHelper's per-request cache would also make it stale.)
 *
 * The model also SILENTLY DROPS keys it has no validation rule for
 * (`array_intersect_key`). cpnbSave() refuses unknown keys up front instead, so
 * a typo is an error rather than a success that changed nothing.
 *
 * ---------------------------------------------------------------------------
 * EDITION — HOW WEB357 TELLS FREE FROM PRO
 * ---------------------------------------------------------------------------
 *
 * Web357 builds Free and Pro from one source with a preprocessor: Pro-only code
 * is STRIPPED from the free build rather than branched at runtime. The tells
 * that survive into an installed site are:
 *
 *   - `<variant>pro</variant>` / `<variant>free</variant>` in each manifest;
 *   - `ExtensionVersionModel::isProVersion()`, whose body is literally
 *     `return true;` in the Pro build (blank lines either side mark where the
 *     preprocessor cut). The dashboard reads that flag.
 *
 * cpnbEdition() reads both — the manifest from disk and the method through
 * reflection (it is private and side-effect free) — and reports any
 * disagreement. Pro-only capabilities are, per Web357's own documentation
 * (docs.web357.com, CPNB intro): "cookie blocking, consent logs, GeoIP
 * targeting, multilingual texts, and import/export". This add-on refuses to
 * WRITE the settings behind those on a free build, even though the JSON blob
 * would accept them — the weakness of a paywall is not permission to walk
 * through it. Dedicated tools for those capabilities ship in the Pro add-on
 * (csmcpforjcpnbpro), which additionally requires the vendor's Pro build.
 */
trait CpnbBootTrait
{
	/** The consent cookie the front end writes: URL-encoded JSON `{"v":{"<category_id>":0|1,...},"exp":"..."}`. */
	private const CPNB_CONSENT_COOKIE = 'cpnb_cookiesSettings';

	/** Subform (repeatable) settings — each is replaced whole by the vendor's merge. */
	private const CPNB_SUBFORM_KEYS = [
		'buttons_ordering_group',
		'cookie_categories_group',
		'cookie_descriptions_group',
		'block_cookies_group',
		'block_scripts_by_url_group',
		'block_elements_group',
	];

	/**
	 * Settings behind the capabilities Web357 documents as Pro-only. Keyed by
	 * capability so a refusal can name it in Web357's own vocabulary.
	 */
	private const CPNB_PRO_KEY_FAMILIES = [
		'cookie blocking' => [
			'blockCookies', 'allowSessionCookies', 'force_allow_cookies', 'disableReCAPTCHACookies',
			'block_cookies_group', 'block_scripts_by_url_group', 'block_elements_group',
		],
		'consent logs' => [
			'store_acceptance_logs_into_db', 'store_ip_address_into_db',
		],
		'GeoIP targeting' => [
			'enableGeoIP2Webservice', 'include_continents', 'exclude_continents', 'include_countries', 'exclude_countries',
		],
	];

	private static ?array $cpnbRulesCache = null;

	// -----------------------------------------------------------------------
	// Presence and identity
	// -----------------------------------------------------------------------

	public function getCategory(): string
	{
		return 'cpnb';
	}

	protected function cpnbSystemPluginDir(): string
	{
		return JPATH_PLUGINS . '/system/cookiespolicynotificationbar';
	}

	protected function cpnbApiDir(): string
	{
		return JPATH_PLUGINS . '/ajax/cookiespolicynotificationbar/api';
	}

	/**
	 * The `#__extensions` row of one CPNB package member, or null.
	 *
	 * @return array{extension_id:int,enabled:bool,access:int,params:string,version:string,element:string,folder:string}|null
	 */
	protected function cpnbExtensionRow(string $type, string $element, string $folder = ''): ?array
	{
		$db    = $this->db;
		$query = $db->getQuery(true)
			->select($db->quoteName(['extension_id', 'enabled', 'access', 'params', 'manifest_cache', 'element', 'folder']))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote($type))
			->where($db->quoteName('element') . ' = ' . $db->quote($element));

		if ($type === 'plugin') {
			$query->where($db->quoteName('folder') . ' = ' . $db->quote($folder));
		}

		$row = $db->setQuery($query)->loadAssoc();

		if (!$row) {
			return null;
		}

		$manifest = json_decode((string) $row['manifest_cache'], true);

		return [
			'extension_id' => (int) $row['extension_id'],
			'enabled'      => (int) $row['enabled'] === 1,
			'access'       => (int) $row['access'],
			'params'       => (string) $row['params'],
			'version'      => \is_array($manifest) ? (string) ($manifest['version'] ?? '') : '',
			'element'      => (string) $row['element'],
			'folder'       => (string) $row['folder'],
		];
	}

	protected function cpnbInstalled(): bool
	{
		return $this->cpnbExtensionRow('plugin', 'cookiespolicynotificationbar', 'system') !== null
			&& is_file($this->cpnbApiDir() . '/Model/SettingsModel.php');
	}

	protected function cpnbNotInstalledError(): ToolResult
	{
		$hasSystem = $this->cpnbExtensionRow('plugin', 'cookiespolicynotificationbar', 'system') !== null;

		return ToolResult::json([
			'ok'    => false,
			'error' => $hasSystem
				? 'Cookies Policy Notification Bar is installed but its settings API '
					. '(plugins/ajax/cookiespolicynotificationbar/api) is missing. These tools need CPNB 5.x, which '
					. 'moved the settings editor into that API; CPNB 4.x and older kept settings in the plugin '
					. 'Options page and are not supported.'
				: 'Cookies Policy Notification Bar (Web357) is not installed on this site. Expected the system '
					. 'plugin plg_system_cookiespolicynotificationbar and the ajax plugin that carries its settings API.',
			'note'  => 'Web357 sells CPNB at https://www.web357.com/cookies-policy-notification-bar-joomla-plugin.',
		], true);
	}

	protected function cpnbVersion(): ?string
	{
		$row = $this->cpnbExtensionRow('plugin', 'cookiespolicynotificationbar', 'system');

		return $row !== null && $row['version'] !== '' ? $row['version'] : null;
	}

	/**
	 * The vendor's edition, read the vendor's own two ways.
	 *
	 * @return array{is_pro:bool,manifest_variant:?string,api_is_pro_flag:?bool,agree:bool,note:string}
	 */
	protected function cpnbEdition(): array
	{
		$variant = null;
		$xml     = $this->cpnbSystemPluginDir() . '/cookiespolicynotificationbar.xml';

		if (is_file($xml)) {
			$raw = (string) @file_get_contents($xml);

			if (preg_match('#<variant>\s*([a-z]+)\s*</variant>#i', $raw, $m) === 1) {
				$variant = strtolower($m[1]);
			}
		}

		$flag = null;

		try {
			$this->cpnbLoadApi();
			$class = '\\Web357\\CookiesPolicyNotificationBarApi\\Model\\ExtensionVersionModel';

			if (class_exists($class) && method_exists($class, 'isProVersion')) {
				$ref    = new \ReflectionClass($class);
				$method = $ref->getMethod('isProVersion');
				$method->setAccessible(true);
				$flag   = (bool) $method->invoke($ref->newInstanceWithoutConstructor());
			}
		} catch (\Throwable $e) {
			$flag = null;
		}

		// The runtime flag is what the vendor's own dashboard trusts; the
		// manifest is the fallback when the API cannot be loaded.
		$isPro = $flag ?? ($variant === 'pro');
		$agree = $flag === null || $variant === null || ($flag === ($variant === 'pro'));

		return [
			'is_pro'           => $isPro,
			'manifest_variant' => $variant,
			'api_is_pro_flag'  => $flag,
			'agree'            => $agree,
			'note'             => 'Web357 strips Pro-only code from the free build at packaging time. Both signals '
				. 'come from the installed vendor files: <variant> in plugins/system/cookiespolicynotificationbar/'
				. 'cookiespolicynotificationbar.xml, and ExtensionVersionModel::isProVersion() — the flag CPNB\'s '
				. 'own dashboard uses.',
		];
	}

	protected function cpnbIsPro(): bool
	{
		return $this->cpnbEdition()['is_pro'];
	}

	/**
	 * Re-implements the vendor's own gate. The settings API requires
	 * `core.manage` on com_cookiespolicynotificationbar
	 * (`CookiePolicyNotificationBarRequestHandler::hasAccess()`); log deletion
	 * requires `core.delete`, log export `core.admin` (`LogsApiController`).
	 */
	protected function cpnbRequireAccess(User $actor, string $action = 'core.manage'): void
	{
		if ($actor->guest || !$actor->authorise($action, 'com_cookiespolicynotificationbar')) {
			throw new \RuntimeException(
				'Permission denied: this needs ' . $action . ' on com_cookiespolicynotificationbar, the same '
				. 'permission Cookies Policy Notification Bar\'s own dashboard requires.'
			);
		}
	}

	protected function cpnbLoadApi(): void
	{
		$loader = $this->cpnbApiDir() . '/apiclassloader.php';

		if (!class_exists('\\Web357\\CookiesPolicyNotificationBarApi\\Model\\SettingsModel', false)) {
			if (!is_file($loader)) {
				throw new \RuntimeException('CPNB settings API not found at ' . $loader . '.');
			}

			require_once $loader;
		}
	}

	// -----------------------------------------------------------------------
	// Settings I/O
	// -----------------------------------------------------------------------

	/** The stored params, read straight from the database (never via PluginHelper's cache). */
	protected function cpnbStoredParams(): array
	{
		$row = $this->cpnbExtensionRow('plugin', 'cookiespolicynotificationbar', 'system');

		if ($row === null || trim($row['params']) === '') {
			return [];
		}

		$decoded = json_decode($row['params'], true);

		if (!\is_array($decoded)) {
			throw new \RuntimeException(
				'The stored CPNB settings are not valid JSON. Refusing to read or write them; restore from a backup '
				. 'or re-save from the CPNB dashboard.'
			);
		}

		return $decoded;
	}

	/**
	 * A vendor SettingsModel holding exactly $params, built without running its
	 * constructor (which would read PluginHelper — see the class docblock).
	 */
	protected function cpnbModel(?array $params = null): object
	{
		$this->cpnbLoadApi();

		$class = '\\Web357\\CookiesPolicyNotificationBarApi\\Model\\SettingsModel';
		$ref   = new \ReflectionClass($class);
		$model = $ref->newInstanceWithoutConstructor();
		$prop  = $ref->getProperty('params');
		$prop->setAccessible(true);
		$prop->setValue($model, $params ?? $this->cpnbStoredParams());

		return $model;
	}

	/** Stored params merged over the vendor's bundled defaults, exactly as the dashboard shows them. */
	protected function cpnbEffective(): array
	{
		return $this->cpnbModel()->toApiArray();
	}

	/** Call one of the vendor model's private helpers. */
	protected function cpnbModelCall(object $model, string $method, array $args = []): mixed
	{
		$ref = new \ReflectionMethod($model, $method);
		$ref->setAccessible(true);

		return $ref->invokeArgs($model, $args);
	}

	/** The vendor's validation rules (base keys; localized keys carry 'localized' => true). */
	protected function cpnbRules(): array
	{
		if (self::$cpnbRulesCache === null) {
			self::$cpnbRulesCache = (array) $this->cpnbModelCall($this->cpnbModel([]), 'getValidationRules');
		}

		return self::$cpnbRulesCache;
	}

	/** Rules expanded to the concrete per-language keys the vendor will accept right now. */
	protected function cpnbExpandedRules(): array
	{
		return (array) $this->cpnbModelCall(
			$this->cpnbModel([]),
			'expandLocalizedValidationRules',
			[$this->cpnbRules(), $this->cpnbActiveLanguageTags()]
		);
	}

	/** @return string[] Language tags in the vendor's underscore form (en_GB), site default first. */
	protected function cpnbActiveLanguageTags(): array
	{
		$this->cpnbLoadApi();
		$model = new \Web357\CookiesPolicyNotificationBarApi\Model\ActiveLanguagesModel();

		return array_values(array_filter(
			array_column($model->toApiArray(), 'tag'),
			static fn ($t): bool => \is_string($t) && $t !== ''
		));
	}

	/** The site's default front-end language in the vendor's underscore form. */
	protected function cpnbDefaultLanguageTag(): string
	{
		$tag = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');

		return str_replace('-', '_', $tag !== '' ? $tag : 'en-GB');
	}

	/** Base names of the localized (per-language) text settings. */
	protected function cpnbLocalizedBaseKeys(): array
	{
		$out = [];

		foreach ($this->cpnbRules() as $key => $rule) {
			if (!empty($rule['localized'])) {
				$out[] = (string) $key;
			}
		}

		return $out;
	}

	/** Which documented Pro capability a settings key belongs to, or null if it is a base setting. */
	protected function cpnbProFamily(string $key): ?string
	{
		foreach (self::CPNB_PRO_KEY_FAMILIES as $family => $keys) {
			if (\in_array($key, $keys, true)) {
				return $family;
			}
		}

		return null;
	}

	protected function cpnbProRequiredError(string $capability, array $extra = []): ToolResult
	{
		return ToolResult::json([
			'ok'      => false,
			'error'   => 'This changes ' . $capability . ', which Web357 sells as part of Cookies Policy Notification '
				. 'Bar PRO. This site runs the free build, so it is refused rather than written straight into the '
				. 'settings JSON — that would be walking around the vendor\'s paywall.',
			'edition' => $this->cpnbEdition(),
			'upgrade' => 'https://www.web357.com/cookies-policy-notification-bar-joomla-plugin/pricing',
		] + $extra, true);
	}

	/**
	 * Normalise a scalar to the string form the dashboard sends (the plugin
	 * compares several of these as strings).
	 */
	protected function cpnbStringify(mixed $value): mixed
	{
		if (\is_bool($value)) {
			return $value ? '1' : '0';
		}

		if (\is_int($value) || \is_float($value)) {
			return (string) $value;
		}

		if (\is_array($value)) {
			$out = [];

			foreach ($value as $k => $v) {
				$out[$k] = $this->cpnbStringify($v);
			}

			return $out;
		}

		return $value;
	}

	/**
	 * Save a set of settings through the vendor model and prove what changed.
	 *
	 * - Unknown keys are refused (the vendor would silently drop them).
	 * - Pro-only keys are refused on the free build.
	 * - After the save the row is re-read from the database: every requested key
	 *   must hold the requested value, and any OTHER key that moved is reported.
	 *
	 * @param array<string,mixed> $changes
	 *
	 * @return array<string,mixed>
	 */
	protected function cpnbSave(array $changes): array
	{
		if ($changes === []) {
			throw new \InvalidArgumentException('Nothing to save.');
		}

		$rules   = $this->cpnbExpandedRules();
		$unknown = array_values(array_diff(array_keys($changes), array_keys($rules)));

		if ($unknown !== []) {
			throw new \InvalidArgumentException(
				'Unknown CPNB setting(s): ' . implode(', ', $unknown) . '. CPNB\'s settings API silently ignores '
				. 'keys it has no rule for, so these are refused rather than reported as saved. Localized text keys '
				. 'are only accepted for the site\'s active languages (' . implode(', ', $this->cpnbActiveLanguageTags())
				. ').'
			);
		}

		if (!$this->cpnbIsPro()) {
			foreach (array_keys($changes) as $key) {
				$family = $this->cpnbProFamily((string) $key);

				if ($family !== null) {
					throw new CpnbProRequiredException($family, (string) $key);
				}
			}
		}

		$changes = $this->cpnbStringify($changes);
		$before  = $this->cpnbStoredParams();
		$model   = $this->cpnbModel($before);

		try {
			$model->saveFromApiArray($changes);
		} catch (\InvalidArgumentException $e) {
			$field = method_exists($e, 'getField') ? $e->getField() : '';

			throw new \InvalidArgumentException(
				'CPNB rejected the value' . ($field !== '' ? ' for "' . $field . '"' : '') . ': ' . $e->getMessage()
			);
		}

		$after    = $this->cpnbStoredParams();
		$verified = [];
		$failed   = [];

		foreach ($changes as $key => $value) {
			$stored = $after[$key] ?? null;

			if (json_encode($stored) === json_encode($value)) {
				$verified[] = $key;
			} else {
				$failed[$key] = ['requested' => $value, 'stored' => $stored];
			}
		}

		$collateral = [];

		foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
			if (\array_key_exists($key, $changes)) {
				continue;
			}

			if (json_encode($before[$key] ?? null) !== json_encode($after[$key] ?? null)) {
				$collateral[] = $key;
			}
		}

		if ($failed !== []) {
			throw new \RuntimeException(
				'CPNB reported the save as successful but the database does not hold the requested value for: '
				. json_encode($failed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
			);
		}

		$changed = [];

		foreach (array_keys($changes) as $key) {
			if (json_encode($before[$key] ?? null) !== json_encode($after[$key] ?? null)) {
				$changed[] = $key;
			}
		}

		return [
			'saved_keys'        => array_keys($changes),
			'actually_changed'  => $changed,
			'verified_in_db'    => $verified,
			'other_keys_moved'  => $collateral,
			'cache'             => 'CPNB purged Joomla\'s page cache and every cache group (and JCH Optimize\'s page cache when present) as part of the save.',
		];
	}

	// -----------------------------------------------------------------------
	// Subforms
	// -----------------------------------------------------------------------

	/**
	 * A subform setting as an ordered list of rows. Accepts the vendor's
	 * keyed-object shape (`{"cookie_categories_group0": {...}}`) and a plain list.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function cpnbRows(array $settings, string $key): array
	{
		$raw = $settings[$key] ?? [];

		if (!\is_array($raw)) {
			return [];
		}

		$rows = [];

		foreach ($raw as $row) {
			if (\is_array($row)) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/** Back to the vendor's shape: keys `<key>0..N`, re-numbered contiguously as the dashboard does. */
	protected function cpnbKeyed(array $rows, string $key): array
	{
		$out = [];

		foreach (array_values($rows) as $i => $row) {
			$out[$key . $i] = $row;
		}

		return $out;
	}

	/** Was this subform actually stored, or is the dashboard showing the bundled defaults? */
	protected function cpnbSource(string $key): string
	{
		return \array_key_exists($key, $this->cpnbStoredParams()) ? 'stored' : 'vendor_defaults';
	}
}
