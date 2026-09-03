<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;

/**
 * Shared orientation and safety rails for every VirtueMart tool.
 *
 * ---------------------------------------------------------------------------
 * THE ONE DESIGN DECISION THAT EXPLAINS EVERYTHING ELSE
 * ---------------------------------------------------------------------------
 *
 * These tools talk to VirtueMart's TABLES, not to VirtueMart's PHP. The single
 * exception is the order-status write, which goes through
 * `VirtueMartModelOrders::updateStatusForOneOrder()` because reimplementing its
 * stock/history/invoice/email cascade would be far more dangerous than calling
 * it (`models/orders.php:1146`).
 *
 * Three vendor facts force that split.
 *
 *   1. EVERY VirtueMart write model opens with `vRequest::vmCheckToken()`
 *      (47 call sites). On failure it calls `$app->redirect()` and then
 *      `$app->close()` — `helpers/vrequest.php:517-540`. It does not throw and
 *      it does not return false to the caller: the PHP process DIES. An MCP
 *      tool that trips it emits nothing at all, which is the worst possible
 *      failure mode for an agent. There is a documented escape hatch
 *      (`helpers/vrequest.php:507-513`, see vmBootVendor()), but priming it is
 *      only worth the risk where the model earns its keep.
 *
 *   2. Merely LOADING VirtueMart can be fatal. Both entry points require
 *      `helpers/config.php`, whose line 367 is
 *      `VmDefines::defines(JFactory::getApplication()->getName());` — so on a
 *      Joomla 6 site without the compat aliases registered, the require alone
 *      kills the request. A read tool has no business risking that.
 *
 *   3. `ProductModel::store()` treats `$data` as a COMPLETE admin form POST.
 *      Omit `categories`, `mprices`, `field`, `virtuemart_shoppergroup_id` or
 *      `virtuemart_manufacturer_id` and those satellites are DELETED
 *      (`models/product.php:2895-2913`, `:2935-2960`;
 *      `helpers/vmtablexarray.php:226-237`; `models/customfields.php:1590-1599`).
 *      Not writing through the model removes that entire class of accident by
 *      construction.
 *
 * WHAT WE GIVE UP by not calling the product/category models, stated plainly
 * because every write tool repeats it to its caller:
 *
 *   - `plgVmBeforeStoreProduct` / `plgVmAfterStoreProduct` do not fire, so
 *      third-party custom-field, SEO and index plugins never see the change.
 *   - `checkByShowColumns()` (`helpers/vmtable.php:1829`) does not run, so we
 *      have to length-check values ourselves instead of being silently
 *      truncated. We refuse rather than truncate.
 *   - Slug generation and the `has_*` denormalisation flags are reimplemented
 *      here. vmSlugify() mirrors `helpers/vmtable.php:1754-1776` transform for
 *      transform, and vmHasFlags() mirrors `models/product.php:2765-2776`.
 *
 * ---------------------------------------------------------------------------
 * THE LANGUAGE TABLES — the fact that makes VirtueMart unlike anything else
 * ---------------------------------------------------------------------------
 *
 * `#__virtuemart_products` has NO `product_name` column. Name, both
 * descriptions, the meta fields and `slug` live in
 * `#__virtuemart_products_<langsuffix>`, and VirtueMart reads them back with an
 * INNER JOIN (`helpers/vmtable.php:1065-1068`). A base row with no matching
 * language row is therefore an INVISIBLE product — not an untranslated one.
 * Nothing logs it, nothing warns, and on a single-language shop there is not
 * even a fallback pass (`helpers/vmtable.php:1184` requires langCount > 1).
 *
 * Seven base tables are translatable — `helpers/tableupdater.php:51-57`:
 * products, vendors, categories, manufacturers, manufacturercategories,
 * paymentmethods, shipmentmethods.
 *
 * The suffix is `strtolower(strtr($tag, '-', '_'))`, applied identically in
 * `helpers/vmlanguage.php:64`, `:156`, `helpers/tableupdater.php:75`, `:206`
 * and `helpers/vmtable.php:377-380`. So `en-GB` becomes `en_gb` and `pt-BR`
 * becomes `pt_br`. It is NEVER hardcoded here: it is derived at runtime from
 * the shop's own `active_languages` config key.
 *
 * ---------------------------------------------------------------------------
 * WHY THERE IS NO CONFIG SETTER
 * ---------------------------------------------------------------------------
 *
 * `VirtueMartModelConfig::store()` re-reads `virtuemart.cfg` from disk and
 * calls `setParams()` on it — `models/config.php:413-420` against
 * `helpers/config.php:610-627`, where `setParams()` REPLACES `_params`
 * wholesale. The effective result of any partial write is therefore
 * "shipped file defaults, plus whatever you passed", i.e. every setting the
 * caller did not name is reset. That is a wipe-the-whole-shop primitive, so
 * v1 of this add-on reads the configuration and offers no setter at all.
 *
 * Our own parser also deliberately declines VirtueMart's legacy fallback:
 * `VmConfig::parseJsonUnSerialize()` (`helpers/config.php:630-661`) hands any
 * value that fails `json_decode` to `unserialize()`. We return the raw string
 * instead. There is no CVE for that path, but it is a PHP object-injection
 * primitive and we are not going to be the thing that reaches it.
 */
trait VirtuemartBootTrait
{
	/**
	 * Columns never returned in cleartext by any tool here.
	 *
	 * `order_pass` and `order_create_invoice_pass` are bearer credentials — a
	 * guest views an order with nothing but the pass
	 * (`models/orders.php:83`, `:216`). `o_hash`/`oi_hash` are the invoice
	 * staleness detectors and leak nothing useful. `payment_params` and
	 * `shipment_params` hold live gateway secrets, merchant ids and API keys in
	 * VirtueMart's pipe-param format (`install.sql:771`, `:1089`).
	 */
	private const REDACTED_COLUMNS = [
		'order_pass',
		'order_create_invoice_pass',
		'o_hash',
		'oi_hash',
		'payment_params',
		'shipment_params',
	];

	/**
	 * Tables the generic reader refuses even though they match `virtuemart_*`.
	 *
	 * `configs` is a single serialised blob best read through
	 * get_virtuemart_config, which parses it and strips the two synthetic keys.
	 * `carts` holds live serialised cart state: full shopper PII plus a
	 * serialised payload we have no reason to hand to anybody.
	 */
	private const READ_DENYLIST = [
		'virtuemart_configs',
		'virtuemart_carts',
	];

	/** `helpers/tableupdater.php:51-57` — the seven tables with `_<lang>` satellites. */
	private const TRANSLATABLE_TABLES = [
		'products'               => 'virtuemart_product_id',
		'vendors'                => 'virtuemart_vendor_id',
		'categories'             => 'virtuemart_category_id',
		'manufacturers'          => 'virtuemart_manufacturer_id',
		'manufacturercategories' => 'virtuemart_manufacturercategories_id',
		'paymentmethods'         => 'virtuemart_paymentmethod_id',
		'shipmentmethods'        => 'virtuemart_shipmentmethod_id',
	];

	/** `install_essential_data.sql:64-73` plus the `order_stock_handle` each seeds. */
	private const CORE_ORDER_STATES = [
		'P' => ['label' => 'Pending',               'stock' => 'R'],
		'U' => ['label' => 'Confirmed by shopper',  'stock' => 'R'],
		'C' => ['label' => 'Confirmed',             'stock' => 'R'],
		'X' => ['label' => 'Cancelled',             'stock' => 'A'],
		'R' => ['label' => 'Refunded',              'stock' => 'A'],
		'S' => ['label' => 'Shipped',               'stock' => 'O'],
		'F' => ['label' => 'Completed',             'stock' => 'R'],
		'D' => ['label' => 'Denied',                'stock' => 'A'],
	];

	private static ?string $vmBaseCache = null;

	private static ?array $vmConfigCache = null;

	private static ?array $vmTableListCache = null;

	private static ?array $vmCompatCache = null;

	private static bool $vmVendorBooted = false;

	// -----------------------------------------------------------------------
	// Presence and identity
	// -----------------------------------------------------------------------

	public function getCategory(): string
	{
		return 'virtuemart';
	}

	/** Absolute path to the admin component, or null when VirtueMart is absent. */
	protected function vmAdminBase(): ?string
	{
		if (self::$vmBaseCache !== null) {
			return self::$vmBaseCache === '' ? null : self::$vmBaseCache;
		}

		$path              = JPATH_ADMINISTRATOR . '/components/com_virtuemart';
		self::$vmBaseCache = is_dir($path) ? $path : '';

		return self::$vmBaseCache === '' ? null : self::$vmBaseCache;
	}

	protected function vmSiteBase(): ?string
	{
		$path = JPATH_SITE . '/components/com_virtuemart';

		return is_dir($path) ? $path : null;
	}

	protected function vmNotInstalledError(): ToolResult
	{
		return ToolResult::json([
			'ok'    => false,
			'error' => 'VirtueMart is not installed on this site. Expected '
				. 'administrator/components/com_virtuemart. Install it from https://virtuemart.net '
				. 'before using these tools.',
		], true);
	}

	/**
	 * Installed VirtueMart version.
	 *
	 * Read by regular expression out of `version.php` rather than by including
	 * the file: including it would declare `class vmVersion` into the request
	 * for no benefit, and on a Joomla 6 site with the aliases missing we want
	 * to touch as little VirtueMart PHP as possible. `#__extensions.
	 * manifest_cache` is consulted first because it is what Joomla itself
	 * believes is installed.
	 */
	protected function vmVersion(): ?string
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName('manifest_cache'))
			->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
			->where($this->db->quoteName('element') . ' = ' . $this->db->quote('com_virtuemart'));

		$cache = (string) ($this->db->setQuery($query)->loadResult() ?? '');

		if ($cache !== '') {
			$decoded = json_decode($cache, true);

			if (\is_array($decoded) && !empty($decoded['version'])) {
				return (string) $decoded['version'];
			}
		}

		$base = $this->vmAdminBase();

		if ($base === null || !is_file($base . '/version.php')) {
			return null;
		}

		$raw = (string) @file_get_contents($base . '/version.php');

		if (preg_match('/\$RELEASE\s*=\s*\'([^\']+)\'/', $raw, $m) === 1) {
			return $m[1];
		}

		return null;
	}

	/**
	 * Short component summary for inclusion in tool responses.
	 *
	 * Kept cheap on purpose: version, edition, and the language facts a caller
	 * needs in order to interpret anything else it is looking at. The full
	 * Joomla-6 compatibility picture costs a query, so it lives in
	 * get_virtuemart_component_info and check_virtuemart_health instead.
	 */
	protected function vmComponentNotice(): array
	{
		return [
			'name'             => 'VirtueMart',
			'version'          => $this->vmVersion(),
			'edition'          => 'GPL — VirtueMart ships one free edition, there is no Pro build and no '
				. 'licence gate for this add-on to honour.',
			'default_language' => $this->vmDefaultLangTag(),
			'active_languages' => $this->vmActiveLangTags(),
		];
	}

	// -----------------------------------------------------------------------
	// Tables
	// -----------------------------------------------------------------------

	/** `'products'` becomes `'#__virtuemart_products'`. */
	protected function vmTable(string $unprefixed): string
	{
		return '#__virtuemart_' . $unprefixed;
	}

	protected function vmTableExists(string $unprefixed): bool
	{
		if (self::$vmTableListCache === null) {
			self::$vmTableListCache = $this->db->getTableList() ?: [];
		}

		return \in_array(
			$this->db->replacePrefix($this->vmTable($unprefixed)),
			self::$vmTableListCache,
			true
		);
	}

	/** @return array<int,string> */
	protected function vmColumns(string $unprefixed): array
	{
		if (!$this->vmTableExists($unprefixed)) {
			return [];
		}

		return array_keys($this->db->getTableColumns($this->vmTable($unprefixed)) ?: []);
	}

	protected function vmMissingTableError(string $unprefixed): ToolResult
	{
		$extra = '';

		if (\in_array($unprefixed, ['configs', 'userinfos', 'order_userinfos'], true)) {
			$extra = ' This table is NOT in install.sql — VirtueMart creates it at runtime '
				. '(configs at models/config.php:640-651; userinfos and order_userinfos from '
				. 'install/install_essential_data.sql:78 and :119, then reshaped by the userfields '
				. 'screen). Its absence means the install never completed.';
		}

		if (str_contains($unprefixed, '_') && preg_match('/_[a-z]{2}(_[a-z]{2})?$/', $unprefixed) === 1) {
			$extra = ' This looks like a language satellite table. VirtueMart only creates those for '
				. 'languages listed in its own `active_languages` config key, and only when that list '
				. 'GROWS (models/config.php:541-546). Adding a Joomla content language does not create '
				. 'them.';
		}

		return ToolResult::json([
			'ok'    => false,
			'error' => sprintf('Table %s does not exist on this site.%s', $this->vmTable($unprefixed), $extra),
		], true);
	}

	/**
	 * Every `#__virtuemart_*` table on the site, unprefixed.
	 *
	 * `models/config.php:604-622` calls the install broken below 55 tables, so
	 * the count is itself a diagnostic.
	 *
	 * @return array<int,string>
	 */
	protected function vmAllTables(): array
	{
		if (self::$vmTableListCache === null) {
			self::$vmTableListCache = $this->db->getTableList() ?: [];
		}

		$prefix = $this->db->replacePrefix('#__');
		$out    = [];

		foreach (self::$vmTableListCache as $table) {
			if (str_starts_with($table, $prefix . 'virtuemart_')) {
				$out[] = substr($table, \strlen($prefix));
			}
		}

		sort($out);

		return $out;
	}

	/** @return array<int,string> */
	protected function vmReadDenylist(): array
	{
		return self::READ_DENYLIST;
	}

	// -----------------------------------------------------------------------
	// Configuration (read only — see the class docblock)
	// -----------------------------------------------------------------------

	protected function vmConfigRaw(): ?string
	{
		if (!$this->vmTableExists('configs')) {
			return null;
		}

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName('config'))
			->from($this->db->quoteName($this->vmTable('configs')))
			->where($this->db->quoteName('virtuemart_config_id') . ' = 1');

		$raw = $this->db->setQuery($query)->loadResult();

		return $raw === null ? null : (string) $raw;
	}

	/**
	 * The shop configuration, parsed.
	 *
	 * Reimplements `VmConfig::setParams()` (`helpers/config.php:610-627`):
	 * pipe-separated `key=<json>` pairs, split on `=` with a limit of 2 so an
	 * `=` inside a value is safe. Two deliberate divergences:
	 *
	 *   - We do NOT fall back to `unserialize()` on a JSON parse failure the
	 *     way `parseJsonUnSerialize()` does (`helpers/config.php:630-661`).
	 *     Unparseable values come back as their raw string, flagged.
	 *   - `|` is VirtueMart's unescaped record separator, so a value containing
	 *     a literal pipe corrupts every key after it. We surface that as a
	 *     parse anomaly rather than silently losing settings.
	 *
	 * @return array<string,mixed>
	 */
	protected function vmConfigParams(): array
	{
		if (self::$vmConfigCache !== null) {
			return self::$vmConfigCache;
		}

		$raw = $this->vmConfigRaw();

		if ($raw === null || $raw === '') {
			return self::$vmConfigCache = [];
		}

		$params = [];

		foreach (explode('|', $raw) as $item) {
			$pair = explode('=', $item, 2);

			if ($pair[0] === '') {
				continue;
			}

			if (!isset($pair[1]) || $pair[1] === '') {
				$params[$pair[0]] = '';

				continue;
			}

			$value = json_decode($pair[1], true);

			$params[$pair[0]] = json_last_error() === \JSON_ERROR_NONE ? $value : $pair[1];
		}

		return self::$vmConfigCache = $params;
	}

	protected function vmConfigGet(string $key, mixed $default = null): mixed
	{
		$params = $this->vmConfigParams();

		return \array_key_exists($key, $params) ? $params[$key] : $default;
	}

	// -----------------------------------------------------------------------
	// Language resolution — never hardcode `_en_gb`
	// -----------------------------------------------------------------------

	/**
	 * `en-GB` becomes `en_gb`.
	 *
	 * The exact transform VirtueMart applies in all five places it derives a
	 * suffix: `helpers/vmlanguage.php:64`, `:156`,
	 * `helpers/tableupdater.php:75`, `:206`, `helpers/vmtable.php:377-380`.
	 */
	protected function vmLangSuffix(string $tag): string
	{
		return strtolower(strtr(trim($tag), '-', '_'));
	}

	/**
	 * The shop's fallback language tag.
	 *
	 * `helpers/vmlanguage.php:60-63`: VirtueMart's own `vmDefLang` key wins,
	 * falling back to Joomla's `com_languages` site default, falling back to
	 * `en-GB`.
	 */
	protected function vmDefaultLangTag(): string
	{
		$vmDefault = $this->vmConfigGet('vmDefLang', '');

		if (\is_string($vmDefault) && trim($vmDefault) !== '') {
			return trim($vmDefault);
		}

		$joomla = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');

		return $joomla !== '' ? $joomla : 'en-GB';
	}

	/**
	 * Every language tag that has (or should have) satellite tables.
	 *
	 * `helpers/vmlanguage.php:81` reads `active_languages`;
	 * `models/config.php:583-596` always unions in the Joomla site default, so
	 * that language's tables always exist. We reproduce both halves.
	 *
	 * @return array<int,string>
	 */
	protected function vmActiveLangTags(): array
	{
		$active = $this->vmConfigGet('active_languages', []);

		if (\is_string($active) && $active !== '') {
			$active = [$active];
		}

		if (!\is_array($active)) {
			$active = [];
		}

		$tags = [];

		foreach ($active as $tag) {
			$tag = trim((string) $tag);

			if ($tag !== '' && !\in_array($tag, $tags, true)) {
				$tags[] = $tag;
			}
		}

		$default = $this->vmDefaultLangTag();

		if (!\in_array($default, $tags, true)) {
			$tags[] = $default;
		}

		return $tags;
	}

	/** `('products', 'en-GB')` becomes `'#__virtuemart_products_en_gb'`. */
	protected function vmLangTable(string $base, string $tag): string
	{
		return $this->vmTable($base . '_' . $this->vmLangSuffix($tag));
	}

	protected function vmLangTableExists(string $base, string $tag): bool
	{
		return $this->vmTableExists($base . '_' . $this->vmLangSuffix($tag));
	}

	/** @return array<string,string> Base table name to its primary key. */
	protected function vmTranslatableTables(): array
	{
		return self::TRANSLATABLE_TABLES;
	}

	/**
	 * Resolve a caller-supplied language tag against the shop's active set.
	 *
	 * Refusing an inactive tag matters: writing into a `_<lang>` table that no
	 * shopper ever reads looks like success and changes nothing anyone can see.
	 *
	 * @return string|ToolResult The tag, or a refusal.
	 */
	protected function vmResolveLangTag(array $arguments, string $key = 'language'): string|ToolResult
	{
		$requested = trim((string) ($arguments[$key] ?? ''));
		$active    = $this->vmActiveLangTags();

		if ($requested === '') {
			return $this->vmDefaultLangTag();
		}

		foreach ($active as $tag) {
			if (strcasecmp($tag, $requested) === 0) {
				return $tag;
			}
		}

		return ToolResult::json([
			'ok'    => false,
			'error' => sprintf(
				'"%s" is not one of this shop\'s active VirtueMart languages, so it has no '
					. '#__virtuemart_*_%s satellite tables and writing to it would be invisible to every '
					. 'shopper.',
				$requested,
				$this->vmLangSuffix($requested)
			),
			'active_languages' => $active,
			'resolution'       => 'Use one of the active languages, or add the language to VirtueMart\'s '
				. 'own `active_languages` configuration key in the shop admin first — adding a Joomla '
				. 'content language alone does NOT create the VirtueMart satellite tables '
				. '(models/config.php:541-546 only reacts when that list grows).',
		], true);
	}

	// -----------------------------------------------------------------------
	// Slugs — mirroring helpers/vmtable.php:1754-1783
	// -----------------------------------------------------------------------

	/**
	 * Derive a slug the way VirtueMart's own `check()` would.
	 *
	 * Transform for transform from `helpers/vmtable.php:1754-1776`: dashes to
	 * spaces, entity decode, strip backticks and apostrophes, lowercase and
	 * trim, then `vRequest::filterUword($s, '-,_,|', '-')`, collapse doubled
	 * dashes, trim dashes.
	 *
	 * Note the odd allow-list: `filterUword`'s custom argument is the literal
	 * string `-,_,|`, so COMMAS and PIPES survive into slugs alongside word
	 * characters. That is VirtueMart's behaviour, not a mistake here.
	 *
	 * One knowing divergence: VirtueMart's collapse loop is
	 * `while (strpos($s, '--'))`, which is falsy at offset 0 and therefore
	 * skips a leading `--`. The subsequent `trim($s, '-')` removes it anyway,
	 * so the observable result is identical and we use the correct comparison.
	 *
	 * Transliteration is NOT applied. VirtueMart only transliterates when its
	 * `transliterateSlugs` config key is on, and then rawurlencodes the result;
	 * a tool guessing at that would produce a slug the component would not.
	 */
	protected function vmSlugify(string $value): string
	{
		$slug = str_replace('-', ' ', $value);
		$slug = html_entity_decode($slug, \ENT_QUOTES, 'UTF-8');
		$slug = str_replace(['`', '´', "'"], '', $slug);
		$slug = \function_exists('mb_strtolower') ? trim(mb_strtolower($slug)) : trim(strtolower($slug));

		// [^\w-,_|] with unicode word characters, matching mb_ereg_replace's
		// behaviour under a UTF-8 internal encoding.
		$slug = (string) preg_replace('~[^\w\-,_\|]~u', '-', $slug);

		while (str_contains($slug, '--')) {
			$slug = str_replace('--', '-', $slug);
		}

		return trim($slug, '-');
	}

	/**
	 * Make a slug unique within a language table.
	 *
	 * `checkCreateUnique()` (`helpers/vmtable.php:1487-1523`) appends `-1`, then
	 * increments a trailing number, up to 40 attempts, then gives up. The
	 * `slug` column carries a UNIQUE KEY on every language table
	 * (`helpers/tableupdater.php:193-198`), so a collision is a hard insert
	 * failure rather than a cosmetic one.
	 *
	 * @return array{slug:string,unique:bool,attempts:int}
	 */
	protected function vmUniqueSlug(string $slug, string $langTable, string $keyColumn, int $excludeId = 0): array
	{
		$candidate = $slug;

		for ($i = 0; $i < 40; $i++) {
			$query = $this->db->getQuery(true)
				->select($this->db->quoteName('slug'))
				->from($this->db->quoteName($langTable))
				->where($this->db->quoteName('slug') . ' = ' . $this->db->quote($candidate));

			if ($excludeId > 0) {
				$query->where($this->db->quoteName($keyColumn) . ' <> ' . $excludeId);
			}

			if ((string) ($this->db->setQuery($query)->loadResult() ?? '') === '') {
				return ['slug' => $candidate, 'unique' => true, 'attempts' => $i];
			}

			$pos = strrpos($candidate, '-');

			if ($pos !== false && $pos > 0 && is_numeric(substr($candidate, $pos + 1))) {
				$candidate = substr($candidate, 0, $pos + 1) . ((int) substr($candidate, $pos + 1) + 1);
			} else {
				$candidate .= '-1';
			}
		}

		return ['slug' => $candidate, 'unique' => false, 'attempts' => 40];
	}

	// -----------------------------------------------------------------------
	// The read-modify-write contract
	// -----------------------------------------------------------------------

	/**
	 * Merge a caller's deltas onto a COMPLETE current-state array.
	 *
	 * This is the single guard against VirtueMart's defining hazard. Every
	 * write tool here loads the whole current row first, passes it through this
	 * method, and writes the whole merged result — so a column the caller did
	 * not mention keeps its value rather than being reset to a class default or
	 * dropped.
	 *
	 * Contract, all of it load-bearing:
	 *
	 *   - The returned `data` always contains EVERY key of `$current`. It is
	 *     structurally impossible for this method to hand back a partial row.
	 *   - Only keys in `$writable` may be changed. A delta for anything else is
	 *     reported in `rejected` and NOT applied — silently ignoring it would
	 *     let a caller believe it had set `virtuemart_vendor_id` or `o_hash`.
	 *   - A key present in `$deltas` with a null value is a real assignment,
	 *     not "leave alone". Callers must omit a key to leave it alone; that is
	 *     why every write tool builds `$deltas` with array_key_exists().
	 *   - `changed` lists only keys whose value genuinely differs, so a tool
	 *     can tell the caller it wrote nothing rather than claiming success.
	 *
	 * @param  array<string,mixed> $current  Complete current state.
	 * @param  array<string,mixed> $deltas   Only the keys the caller supplied.
	 * @param  array<int,string>   $writable Columns a caller may change.
	 * @return array{data:array<string,mixed>,changed:array<int,string>,rejected:array<int,string>}
	 */
	protected function vmMergeForWrite(array $current, array $deltas, array $writable): array
	{
		if ($current === []) {
			throw new \InvalidArgumentException(
				'vmMergeForWrite refuses an empty current state. Passing deltas alone would produce '
				. 'exactly the partial write this add-on exists to prevent.'
			);
		}

		$data     = $current;
		$changed  = [];
		$rejected = [];

		foreach ($deltas as $column => $value) {
			if (!\in_array($column, $writable, true)) {
				$rejected[] = (string) $column;

				continue;
			}

			$before = $current[$column] ?? null;

			if (!$this->vmValuesEqual($before, $value)) {
				$changed[] = (string) $column;
			}

			$data[$column] = $value;
		}

		return ['data' => $data, 'changed' => $changed, 'rejected' => $rejected];
	}

	/**
	 * Compare two stored values without tripping over MySQL string round-trips.
	 *
	 * Everything comes back from the driver as a string, so a caller sending
	 * the integer 1 for a column currently holding "1" has changed nothing.
	 * Comparing with `!==` would report a change that did not happen; comparing
	 * with `==` would treat "" and 0 as equal, which for a price column is a
	 * lie. Scalars are compared as strings; anything else by encoding.
	 */
	private function vmValuesEqual(mixed $a, mixed $b): bool
	{
		if ($a === null || $b === null) {
			return $a === $b;
		}

		if (\is_scalar($a) && \is_scalar($b)) {
			return (string) $a === (string) $b;
		}

		return json_encode($a) === json_encode($b);
	}

	/**
	 * Recompute the denormalised join hints on a product base row.
	 *
	 * `models/product.php:2765-2776` derives these from the submitted data.
	 * They are not decorative: when `has_prices` is 0 VirtueMart's listing
	 * queries skip the price join even though price rows exist, so the product
	 * shows with no price at all.
	 *
	 * @param  array<string,array<int,mixed>> $satellites
	 * @return array<string,int>
	 */
	protected function vmHasFlags(array $satellites): array
	{
		return [
			'has_categories'    => empty($satellites['categories']) ? 0 : 1,
			'has_manufacturers' => empty($satellites['manufacturers']) ? 0 : 1,
			'has_medias'        => empty($satellites['medias']) ? 0 : 1,
			'has_prices'        => empty($satellites['prices']) ? 0 : 1,
			'has_shoppergroups' => empty($satellites['shoppergroups']) ? 0 : 1,
		];
	}

	// -----------------------------------------------------------------------
	// Product state — the whole constellation, read in one place
	// -----------------------------------------------------------------------

	/**
	 * Load a product's COMPLETE state: base row, every language row, and every
	 * satellite set.
	 *
	 * Deliberately uses separate queries and LEFT-JOIN semantics rather than
	 * VirtueMart's INNER JOIN, because the whole point of a diagnostic-capable
	 * tool is to make a missing language row visible instead of making the
	 * product disappear.
	 *
	 * @return array<string,mixed>|null Null when no base row exists.
	 */
	protected function vmReadProductState(int $productId): ?array
	{
		$base = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->vmTable('products')))
				->where($this->db->quoteName('virtuemart_product_id') . ' = ' . $productId)
		)->loadAssoc();

		if (!\is_array($base)) {
			return null;
		}

		$translations = [];

		foreach ($this->vmActiveLangTags() as $tag) {
			if (!$this->vmLangTableExists('products', $tag)) {
				$translations[$tag] = ['exists' => false, 'table_missing' => true, 'row' => null];

				continue;
			}

			$row = $this->db->setQuery(
				$this->db->getQuery(true)
					->select('*')
					->from($this->db->quoteName($this->vmLangTable('products', $tag)))
					->where($this->db->quoteName('virtuemart_product_id') . ' = ' . $productId)
			)->loadAssoc();

			$translations[$tag] = [
				'exists'        => \is_array($row),
				'table_missing' => false,
				'row'           => \is_array($row) ? $row : null,
			];
		}

		return [
			'base'          => $base,
			'translations'  => $translations,
			'categories'    => $this->vmXrefIds('product_categories', 'virtuemart_product_id', $productId, 'virtuemart_category_id'),
			'manufacturers' => $this->vmXrefIds('product_manufacturers', 'virtuemart_product_id', $productId, 'virtuemart_manufacturer_id'),
			'shoppergroups' => $this->vmXrefIds('product_shoppergroups', 'virtuemart_product_id', $productId, 'virtuemart_shoppergroup_id'),
			'medias'        => $this->vmXrefIds('product_medias', 'virtuemart_product_id', $productId, 'virtuemart_media_id'),
			'prices'        => $this->vmProductPrices($productId),
			'customfields'  => $this->vmProductCustomfields($productId),
			'variants'      => $this->vmProductVariantIds($productId),
		];
	}

	/** @return array<int,int> */
	protected function vmXrefIds(string $table, string $parentColumn, int $parentId, string $childColumn): array
	{
		if (!$this->vmTableExists($table)) {
			return [];
		}

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName($childColumn))
			->from($this->db->quoteName($this->vmTable($table)))
			->where($this->db->quoteName($parentColumn) . ' = ' . $parentId);

		if (\in_array('ordering', $this->vmColumns($table), true)) {
			$query->order($this->db->quoteName('ordering') . ' ASC');
		}

		$rows = $this->db->setQuery($query)->loadColumn() ?: [];

		return array_values(array_map('intval', $rows));
	}

	/** @return array<int,array<string,mixed>> */
	protected function vmProductPrices(int $productId): array
	{
		if (!$this->vmTableExists('product_prices')) {
			return [];
		}

		return $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->vmTable('product_prices')))
				->where($this->db->quoteName('virtuemart_product_id') . ' = ' . $productId)
				->order($this->db->quoteName('virtuemart_product_price_id') . ' ASC')
		)->loadAssocList() ?: [];
	}

	/** @return array<int,array<string,mixed>> */
	protected function vmProductCustomfields(int $productId): array
	{
		if (!$this->vmTableExists('product_customfields')) {
			return [];
		}

		return $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->vmTable('product_customfields')))
				->where($this->db->quoteName('virtuemart_product_id') . ' = ' . $productId)
				->order($this->db->quoteName('ordering') . ' ASC')
		)->loadAssocList() ?: [];
	}

	/** @return array<int,int> */
	protected function vmProductVariantIds(int $productId): array
	{
		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('virtuemart_product_id'))
				->from($this->db->quoteName($this->vmTable('products')))
				->where($this->db->quoteName('product_parent_id') . ' = ' . $productId)
				->order($this->db->quoteName('pordering') . ' ASC')
		)->loadColumn() ?: [];

		return array_values(array_map('intval', $rows));
	}

	/**
	 * Replace an xref set, the way VirtueMart's own xarray tables would.
	 *
	 * DELETE-then-INSERT rather than a diff, because these tables carry a
	 * UNIQUE composite key and the row ids are meaningless. An EMPTY `$ids`
	 * genuinely clears the set — every caller of this must have decided that
	 * deliberately, which is why no write tool ever reaches here without the
	 * caller having named the satellite explicitly.
	 */
	protected function vmReplaceXref(string $table, string $parentColumn, int $parentId, string $childColumn, array $ids, bool $ordered = false): int
	{
		$this->db->setQuery(
			$this->db->getQuery(true)
				->delete($this->db->quoteName($this->vmTable($table)))
				->where($this->db->quoteName($parentColumn) . ' = ' . $parentId)
		)->execute();

		$clean = [];

		foreach ($ids as $id) {
			$id = (int) $id;

			if ($id > 0 && !\in_array($id, $clean, true)) {
				$clean[] = $id;
			}
		}

		$ordering = 0;

		foreach ($clean as $id) {
			$row                  = new \stdClass();
			$row->{$parentColumn} = $parentId;
			$row->{$childColumn}  = $id;

			if ($ordered) {
				$row->ordering = $ordering++;
			}

			$this->db->insertObject($this->vmTable($table), $row);
		}

		return \count($clean);
	}

	// -----------------------------------------------------------------------
	// Value guards
	// -----------------------------------------------------------------------

	/**
	 * Normalise a money value, or refuse it.
	 *
	 * `VmTable::convertDec()` (`helpers/vmtable.php:489-501`) does
	 * `floatval(str_replace([',',' '], ['.',''], $v))`. It replaces EVERY comma
	 * with a dot, so "1,234.56" becomes "1.234.56" and floatval stops at the
	 * second dot: 1.234. A price with an English thousands separator is
	 * silently reduced by three orders of magnitude. The same substitution runs
	 * again at `models/product.php:2746`.
	 *
	 * We therefore refuse anything containing a comma or a space rather than
	 * guessing which convention the caller meant. Guessing wrong here mis-prices
	 * a product by 1000x.
	 *
	 * @return string|ToolResult A bare dot-decimal string, or a refusal.
	 */
	protected function vmNormaliseMoney(mixed $raw, string $label): string|ToolResult
	{
		$value = trim((string) $raw);

		if ($value === '') {
			return '0';
		}

		if (str_contains($value, ',') || str_contains($value, ' ')) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf(
					'%s ("%s") contains a comma or a space. VirtueMart\'s convertDec() replaces every '
						. 'comma with a dot and then calls floatval(), so "1,234.56" becomes "1.234.56" and '
						. 'is stored as 1.234 — a thousandfold error, silently. Refusing; nothing was '
						. 'written.',
					$label,
					$value
				),
				'resolution' => 'Send a bare dot-decimal string with no thousands separator, e.g. "1234.56".',
			], true);
		}

		if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf('%s ("%s") is not a plain decimal number. Refusing.', $label, $value),
			], true);
		}

		return $value;
	}

	/**
	 * Refuse a value that would be truncated at the DB.
	 *
	 * VirtueMart's `checkByShowColumns()` (`helpers/vmtable.php:1829`) reads
	 * SHOW FULL COLUMNS and silently truncates over-long values to fit. We
	 * bypass that code path, so MySQL's own silent truncation in non-strict
	 * mode is what we would get instead. Refusing is the only honest option:
	 * a description cut at an arbitrary byte is worse than a rejected write.
	 */
	protected function vmAssertFits(string $value, string $label, int $maxBytes): ?ToolResult
	{
		if (\strlen($value) <= $maxBytes) {
			return null;
		}

		return ToolResult::json([
			'ok'    => false,
			'error' => sprintf(
				'%s is %d bytes, over the %d-byte limit of its column. VirtueMart would truncate this '
					. 'silently (helpers/vmtable.php:1829) and so would MySQL in non-strict mode. Refusing; '
					. 'nothing was written.',
				$label,
				\strlen($value),
				$maxBytes
			),
		], true);
	}

	/**
	 * Redact credentials and gateway secrets from a result row.
	 *
	 * @param  array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	protected function vmRedactRow(array $row): array
	{
		foreach (self::REDACTED_COLUMNS as $column) {
			if (\array_key_exists($column, $row) && $row[$column] !== null && $row[$column] !== '') {
				$row[$column] = '[redacted]';
			}
		}

		return $row;
	}

	/** @return array<int,string> */
	protected function vmRedactedColumns(): array
	{
		return self::REDACTED_COLUMNS;
	}

	// -----------------------------------------------------------------------
	// Order status reference data
	// -----------------------------------------------------------------------

	/** @return array<string,array{label:string,stock:string}> */
	protected function vmCoreOrderStates(): array
	{
		return self::CORE_ORDER_STATES;
	}

	/**
	 * The shop's actual order states, keyed by code.
	 *
	 * These are user-editable rows, not an enum: `order_status_code` carries a
	 * UNIQUE KEY (`install.sql:753`) and a shop may add, rename or unpublish
	 * any of them. `getVMCoreStatusCode()` returns only `['P','S','X']`
	 * (`models/orderstatus.php:43-45`), and that is a "do not let the admin
	 * delete these" list, NOT a transition table. There is no state machine
	 * anywhere in VirtueMart.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function vmOrderStates(): array
	{
		if (!$this->vmTableExists('orderstates')) {
			return [];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->vmTable('orderstates')))
				->order($this->db->quoteName('ordering') . ' ASC')
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$out[(string) $row['order_status_code']] = $row;
		}

		return $out;
	}

	// -----------------------------------------------------------------------
	// Joomla 6 / Backwards-Compatibility detection
	// -----------------------------------------------------------------------

	/**
	 * Whether VirtueMart's legacy-class dependencies are satisfied here.
	 *
	 * VirtueMart 4.x has ZERO version gating for legacy classes, ships no shim,
	 * and hard-fatals at `helpers/config.php:367`
	 * (`VmDefines::defines(JFactory::getApplication()->getName())`) — the first
	 * executable line of its own bootstrap — when the `J*` aliases are absent.
	 * `plugins/vmplugin.php:24` is `abstract class vmPlugin extends JPlugin`,
	 * which takes down every payment, shipment, custom-field, coupon, currency
	 * and userfield plugin at once.
	 *
	 * The DB row is the authoritative answer and the runtime probe is the
	 * in-request confirmation. Both are reported because they can disagree: a
	 * CLI/console context never runs `PluginHelper::importPlugin('behaviour')`,
	 * so the aliases are absent regardless of what the database says.
	 *
	 * The subtle part is the params default asymmetry
	 * (`plugins/behaviour/compat/src/Extension/Compat.php` defaults
	 * `classes_aliases` to '1'; compat6 defaults it to '0'). An empty params
	 * blob therefore means ON for `compat` and OFF for `compat6`. Treating a
	 * missing key uniformly gets this wrong.
	 *
	 * @return array<string,mixed>
	 */
	protected function vmCompatStatus(): array
	{
		if (self::$vmCompatCache !== null) {
			return self::$vmCompatCache;
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('element'),
					$this->db->quoteName('enabled'),
					$this->db->quoteName('params'),
				])
				->from($this->db->quoteName('#__extensions'))
				->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
				->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('behaviour'))
				->where($this->db->quoteName('client_id') . ' = 0')
				->whereIn($this->db->quoteName('element'), ['compat', 'compat6'], \Joomla\Database\ParameterType::STRING)
		)->loadAssocList() ?: [];

		$plugins       = [];
		$aliasesFromDb = false;

		foreach ($rows as $row) {
			$element = (string) $row['element'];
			$params  = json_decode((string) ($row['params'] ?? ''), true);
			$params  = \is_array($params) ? $params : [];
			$enabled = (int) $row['enabled'] === 1;

			// The asymmetry: absent means "1" for compat, "0" for compat6.
			$aliasDefault = $element === 'compat' ? '1' : '0';
			$aliases      = (string) ($params['classes_aliases'] ?? $aliasDefault) === '1';

			// compat6 only. Default '1'. Without it, Joomla\CMS\Filesystem\*
			// does not exist on 6.x and VirtueMart's 92 JFile:: / 70 JFolder::
			// / 13 JPath:: calls all fail.
			$legacy = (string) ($params['legacy_classes'] ?? '1') === '1';

			$plugins[$element] = [
				'present'         => true,
				'enabled'         => $enabled,
				'classes_aliases' => $aliases,
				'legacy_classes'  => $element === 'compat6' ? $legacy : null,
			];

			if ($enabled && $aliases) {
				$aliasesFromDb = true;
			}
		}

		foreach (['compat', 'compat6'] as $element) {
			if (!isset($plugins[$element])) {
				$plugins[$element] = ['present' => false, 'enabled' => false, 'classes_aliases' => false, 'legacy_classes' => null];
			}
		}

		$runtimeAliases = class_exists('JFactory')
			&& class_exists('JPlugin')
			&& interface_exists('JTableInterface');

		$isJ6            = version_compare(JVERSION, '6.0', 'ge');
		$platformDefined = \defined('JPATH_PLATFORM');
		$filesystemLive  = class_exists('JFile') && class_exists('JFolder');

		$willBoot = $runtimeAliases && (!$isJ6 || ($platformDefined && $filesystemLive));

		$verdict = $willBoot
			? 'VirtueMart\'s legacy-class dependencies are satisfied in this request.'
			: 'VirtueMart would FATAL in this request: its bootstrap calls JFactory at '
				. 'helpers/config.php:367 before anything else.';

		$notes = [];

		if ($isJ6 && $platformDefined && $willBoot) {
			// helpers/vmdefines.php:165 does $vmPathLibraries = JPATH_PLATFORM.
			// Joomla 6 removed the constant from core; compat6 redefines it as
			// its OWN __DIR__, not <root>/libraries.
			$notes[] = 'JPATH_PLATFORM is defined, but on Joomla 6 it is defined only by the compat6 '
				. 'plugin and points at plugins/behaviour/compat6/src/Extension, not at <root>/libraries. '
				. 'helpers/vmdefines.php:165 assigns it to VMPATH_LIBS, which breaks the TCPDF lookup at '
				. 'helpers/vmdefines.php:214-229 — invoices and PDF generation fail with '
				. 'COM_VIRTUEMART_TCPDF_NINSTALLED even though the rest of the shop works. Enabling '
				. 'compat6 does NOT fully fix VirtueMart on Joomla 6.';
		}

		if ($isJ6 && !$platformDefined) {
			$notes[] = 'JPATH_PLATFORM is not defined. Joomla 6 removed it from core and VirtueMart '
				. 'requires it at helpers/vmdefines.php:165, so every VirtueMart request raises '
				. '"Undefined constant". Four of its config form fields also guard with '
				. 'defined(\'JPATH_PLATFORM\') or die, which produces a BLANK configuration screen rather '
				. 'than an error.';
		}

		if ($aliasesFromDb && !$runtimeAliases) {
			$notes[] = 'The database says a compat plugin is enabled with class aliases on, but the '
				. 'aliases are not live in this request. That is normal in a CLI or console context, '
				. 'which never runs PluginHelper::importPlugin(\'behaviour\').';
		}

		return self::$vmCompatCache = [
			'joomla_version'          => JVERSION,
			'plugins'                 => $plugins,
			'aliases_enabled_in_db'   => $aliasesFromDb,
			'aliases_live_in_request' => $runtimeAliases,
			'jpath_platform_defined'  => $platformDefined,
			'virtuemart_will_boot'    => $willBoot,
			'verdict'                 => $verdict,
			'notes'                   => $notes,
			'policy'                  => 'This add-on REPORTS this and does not gate writes on it. Every '
				. 'tool except set_virtuemart_order_status works directly against the database and needs '
				. 'none of VirtueMart\'s PHP, so a broken compat setup does not stop them.',
		];
	}

	// -----------------------------------------------------------------------
	// The one place we do load VirtueMart's PHP
	// -----------------------------------------------------------------------

	/**
	 * Boot VirtueMart far enough to call one of its models, or refuse.
	 *
	 * Used only by set_virtuemart_order_status. Three things happen here, in
	 * this order, and the order matters.
	 *
	 *   1. Check the legacy aliases FIRST. `require`ing VirtueMart's
	 *      `helpers/config.php` on a site without them is an immediate fatal at
	 *      its line 367, which would kill the MCP request rather than return an
	 *      error. We refuse cleanly instead.
	 *   2. Load the config, which is what defines VmConfig, vRequest, VmModel
	 *      and the rest of the parallel stack.
	 *   3. Prime the CSRF token. `vRequest::vmCheckToken()`
	 *      (`helpers/vrequest.php:502-541`) opens every write model. Its
	 *      failure path is `$app->redirect()` then `$app->close()` — process
	 *      death with no exception and no return value. The check passes if a
	 *      request variable NAMED after the token hash exists
	 *      (`helpers/vrequest.php:507`), or if a var called `token` equals the
	 *      hash (`:509-513`). We set both. Note this must happen BEFORE the
	 *      session-is-new branch at `:517` can be reached, which is exactly
	 *      what setting the primary variable achieves.
	 *
	 *      VirtueMart 4.8.0's release notes say it "added token validation to
	 *      backend controllers and frontend forms", so the number of gates is a
	 *      floor rather than a ceiling. Priming once, globally, is the only
	 *      approach that survives that.
	 *
	 * @return ToolResult|null Null on success; a refusal otherwise.
	 */
	protected function vmBootVendor(): ?ToolResult
	{
		if (self::$vmVendorBooted) {
			return null;
		}

		$base = $this->vmAdminBase();

		if ($base === null) {
			return $this->vmNotInstalledError();
		}

		$compat = $this->vmCompatStatus();

		if (!$compat['aliases_live_in_request']) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Refusing to load VirtueMart in this request: the Joomla legacy class aliases '
					. '(JFactory, JPlugin, JTableInterface) are not registered. VirtueMart 4.x has no shim '
					. 'and no version gating for them, so requiring its helpers/config.php would fatal at '
					. 'line 367 — VmDefines::defines(JFactory::getApplication()->getName()) — and kill this '
					. 'request outright rather than returning an error.',
				'compat'     => $compat,
				'resolution' => 'Enable the Behaviour - Backward Compatibility plugin and switch its '
					. '"classes_aliases" parameter ON. On Joomla 6 the plugin is compat6 and BOTH that '
					. 'parameter and "legacy_classes" default differently from Joomla 5 — see the compat '
					. 'block above. Every other tool in this add-on works without any of this.',
			], true);
		}

		$configFile = $base . '/helpers/config.php';

		if (!is_file($configFile)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'VirtueMart is installed but ' . $configFile . ' is missing. The installation '
					. 'is incomplete; refusing to call its models.',
			], true);
		}

		require_once $configFile;

		if (!class_exists('VmConfig')) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Loaded VirtueMart\'s helpers/config.php but class VmConfig was not defined. '
					. 'Refusing to go further.',
			], true);
		}

		\VmConfig::loadConfig();

		if (!class_exists('vRequest')) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'VirtueMart loaded but class vRequest is absent, so its CSRF gate cannot be '
					. 'satisfied. Refusing: calling a write model without priming the token ends in '
					. '$app->close() and an empty response.',
			], true);
		}

		$token = (string) \vRequest::getFormToken();

		// helpers/vrequest.php:507 — a request var NAMED after the token hash.
		$_REQUEST[$token] = 1;
		$_POST[$token]    = 1;
		// helpers/vrequest.php:509-513 — the documented escape hatch.
		$_REQUEST['token'] = $token;

		self::$vmVendorBooted = true;

		return null;
	}

	// -----------------------------------------------------------------------
	// Small shared helpers
	// -----------------------------------------------------------------------

	protected function vmLimit(array $arguments, int $default = 50, int $max = 200): int
	{
		return max(1, min($max, (int) ($arguments['limit'] ?? $default)));
	}

	protected function vmOffset(array $arguments): int
	{
		return max(0, (int) ($arguments['offset'] ?? 0));
	}

	protected function vmNow(): string
	{
		return Factory::getDate()->toSql();
	}

	protected function vmPublishedLabel(mixed $raw): string
	{
		return (int) $raw === 1 ? 'published' : 'unpublished';
	}

	/**
	 * Normalise a published flag, or null when unrecognised.
	 *
	 * VirtueMart's SQL and PHP defaults disagree — `#__virtuemart_products.
	 * published` is `DEFAULT '0'` in `install.sql:842` but `var $published = 1`
	 * in `tables/products.php:93`, and categories are the reverse
	 * (`install.sql:180`). Every write tool here sets it explicitly rather than
	 * relying on either.
	 */
	protected function vmNormalisePublished(mixed $raw): ?int
	{
		if (\is_bool($raw)) {
			return $raw ? 1 : 0;
		}

		if (is_numeric($raw)) {
			return (int) $raw === 1 ? 1 : 0;
		}

		return match (strtolower(trim((string) $raw))) {
			'published', 'publish', 'true', 'yes', 'on'      => 1,
			'unpublished', 'unpublish', 'false', 'no', 'off' => 0,
			default                                          => null,
		};
	}

	protected function vmPreview(?string $value, int $max = 400): array
	{
		$value = (string) $value;
		$bytes = \strlen($value);

		return [
			'bytes'     => $bytes,
			'truncated' => $bytes > $max,
			'preview'   => $bytes > $max ? substr($value, 0, $max) . '…' : $value,
		];
	}

	/**
	 * The standing warning every catalog write repeats.
	 *
	 * Callers routinely assume an MCP write is equivalent to a save in the
	 * component's own admin screen. For VirtueMart it is not, in both
	 * directions, and both directions matter.
	 */
	protected function vmDirectWriteNotice(): string
	{
		return 'This write went to the database directly rather than through VirtueMart\'s '
			. 'ProductModel/CategoryModel. That is deliberate: those models treat their $data argument as '
			. 'a COMPLETE admin form POST, so a partial save DELETES the satellites it was not given — '
			. 'prices (models/product.php:2895-2913), categories, shoppergroups and manufacturers '
			. '(:2935-2960 via helpers/vmtablexarray.php:226-237) and custom fields '
			. '(models/customfields.php:1590-1599). Nothing was deleted here. The trade-off is that '
			. 'plgVmBeforeStoreProduct and plgVmAfterStoreProduct did NOT fire, so third-party '
			. 'custom-field, SEO and search-index plugins have not seen this change.';
	}
}
