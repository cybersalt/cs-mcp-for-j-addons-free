<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Shared orientation and safety rails for every J2Commerce tool.
 *
 * Built against J2Commerce 6.6.5 (2026-10-12). This is J2Commerce SIX —
 * `com_j2commerce` with `#__j2commerce_*` tables — a ground-up Joomla 5/6
 * rewrite. It is NOT J2Store 4 (`com_j2store`, `#__j2store_*`), and nothing here
 * reads or writes J2Store tables. The 6.x installer does not migrate J2Store
 * data; that is a separate `com_j2commercemigrator`, so both can sit side by side.
 *
 * ---------------------------------------------------------------------------
 * READS GO TO THE TABLES; WRITES GO WHERE THE VENDOR PUTS THEM
 * ---------------------------------------------------------------------------
 *
 * Every read is plain SQL. It is faster, it has no side effects, and several
 * vendor read paths do have them (list models read the request through
 * `populateState()`, and `DatabaseHealthHelper::getResults()` dispatches a plugin
 * event that lets third parties rewrite the check list).
 *
 * Writes split into two kinds, and the split is the design:
 *
 *   1. Where the vendor has a narrow, side-effect-correct method, we call it —
 *      `OrderModel::updateOrderStatus()` (`src/Model/OrderModel.php:1112`),
 *      `addAdminNote()` (`:1275`), `saveTrackingNumber()` (`:1627`),
 *      `InventoryHelper::adjustStockAndAvailability()`
 *      (`src/Helper/InventoryHelper.php:1332`) and `VoucherModel::adjustBalance()`
 *      (`src/Model/VoucherModel.php:649`). These carry the stock transitions,
 *      history rows, compare-and-swap guards, row locks and plugin events that
 *      reimplementing would get subtly wrong.
 *
 *   2. Everything else is a TARGETED UPDATE of the named columns, never a
 *      whole-object save. The reason is the single most dangerous fact about
 *      J2Commerce: `ProductService::saveProduct()` treats its payload as a
 *      complete admin form. Every Simple / Variable / Flexivariable
 *      `onBeforeSave` forces a missing price, dimension, weight, sale-quantity
 *      limit or notify threshold to 0 and a missing tax profile, manufacturer or
 *      vendor to 0 (`src/Model/Behavior/Simple.php:133-166`,
 *      `Variable.php:163-172`, `Flexivariable.php:185-194`), resets cross- and
 *      up-sells, and resets child-variant stock to 0 when `quantity` is absent
 *      (`Variable.php:342-367`). Geozone and tax-profile saves are
 *      delete-every-rule-then-reinsert with no transaction
 *      (`src/Model/GeozoneModel.php:331-391`, `TaxprofileModel.php:165-213`).
 *      No tool in this add-on hands a partial array to any of those.
 *
 * ---------------------------------------------------------------------------
 * CALLING VENDOR CODE FROM ANOTHER COMPONENT UNDER THE API APPLICATION
 * ---------------------------------------------------------------------------
 *
 * J2Commerce 6 ships its own API application (`api/components/com_j2commerce`)
 * and the code paths above contain no `isClient()` branch, CSRF check,
 * redirect or `close()`, so they are safe to call from an MCP request. Four
 * things still have to be done by the caller, and j2Model() / j2Run() do them:
 *
 *   - `ignore_request` on every model. `OrderModel::populateState()` reads the
 *     request `id` (`OrderModel.php:195`); an MCP request has its own input.
 *   - Load the component's ADMIN language from the component folder. The
 *     system plugin loads it only in the administrator
 *     (`plg_system_j2commerce/src/Extension/J2Commerce.php:411-424`), and
 *     `updateOrderStatus()` writes `Text::sprintf(...)` into order history
 *     (`OrderModel.php:1202`) — without the language that row stores the raw
 *     key, permanently.
 *   - ACL. The models check nothing. The vendor's own API controller does it in
 *     the controller (`J2CommerceApiController.php:170-185`), so we replicate
 *     that: non-guest, `core.manage`, then the `j2commerce.*` action with the
 *     vendor's own fallback (see j2RequireAccess()).
 *   - Errors are swallowed into the message queue rather than thrown
 *     (`Behavior/Simple.php:65,80,292`; `InventoryModel.php:375-379`), so
 *     j2Run() collects the queue around every vendor call and returns it.
 *
 * ---------------------------------------------------------------------------
 * EDITION
 * ---------------------------------------------------------------------------
 *
 * There is nothing to gate. `version.php` defines a `J2COMMERCE_PRO` constant
 * (true in the 6.6.5 package studied), but `isPro()` gates no capability
 * anywhere, whatever its value — its call sites choose a UTM tag on help links
 * (`J2CommerceHelper.php:920,942`), fill a diagnostics field, and assign a layout
 * variable nothing reads.
 * J2Commerce is sold as paid APPS (gateways, shipping integrations); this
 * add-on wraps the GPL core only.
 */
trait J2commerceBootTrait
{
	/**
	 * Columns never returned in cleartext.
	 *
	 * `token` is the guest order-view key carried in emailed links and accepted by
	 * the shopper's order page in place of a login
	 * (`components/com_j2commerce/src/Controller/MyprofileController.php:204-231`).
	 * `transaction_details` is raw gateway JSON (PayPal NVP tokens and the like),
	 * `order_params` and `all_payment` carry payment state, `payment_token` and
	 * `customer_profile_id` are stored-card references. The vendor's own JSON:API
	 * order view omits the same set (`api/.../View/Orders/JsonapiView.php:24-56`).
	 * `ip_address` / `customer_ip` / `client_ip` are personal data. `voucher_code`
	 * is a bearer credential — nothing ties a voucher to its recipient, so whoever
	 * holds the code spends the balance (`src/Model/VoucherModel.php:909-936`); the
	 * voucher tools mask it and reveal it only on an explicit request.
	 */
	private const J2_REDACTED_COLUMNS = [
		'voucher_code',
		'token',
		'transaction_details',
		'order_params',
		'all_payment',
		'payment_token',
		'customer_profile_id',
		'ip_address',
		'customer_ip',
		'client_ip',
		'cart_browser',
		'session_id',
	];

	/**
	 * Tables the generic reader refuses outright.
	 *
	 * `carts`/`cartitems` hold live session-bound shopper state; `paymentprofiles`
	 * is stored-card references; `geocode_cache` is customer addresses keyed by
	 * hash; `uploads` maps customer files to orders. None has a use over MCP that
	 * the purpose-built tools do not cover better.
	 */
	private const J2_READ_DENYLIST = [
		'j2commerce_carts',
		'j2commerce_cartitems',
		'j2commerce_paymentprofiles',
		'j2commerce_geocode_cache',
		'j2commerce_uploads',
	];

	/**
	 * Component configuration keys that are secrets.
	 *
	 * `queue_key` is the cron endpoint secret
	 * (`components/com_j2commerce/src/Controller/CronController.php:54`) AND the HMAC
	 * key for payment return hashes (`src/Library/Plugins/Payment.php:204-218`);
	 * rotating it breaks every payment in flight. `downloadid` is the merchant's
	 * J2Commerce licence for paid-app updates.
	 *
	 * Note that the vendor's own `GET /v1/j2commerce/config` returns both of these
	 * in cleartext (`api/.../Controller/ConfigController.php:32-48` strips only
	 * `id`). We do not.
	 */
	private const J2_SECRET_CONFIG_KEYS = [
		'queue_key',
		'downloadid',
	];

	/** Seeded order statuses (`sql/install.mysql.utf8.sql:923-931`). */
	private const J2_CORE_STATUSES = [
		1 => 'Confirmed',
		2 => 'Processed',
		3 => 'Failed',
		4 => 'Pending',
		5 => 'New',
		6 => 'Cancelled',
		7 => 'Shipped',
		8 => 'Delivered',
	];

	/**
	 * The only statuses that RELEASE stock. Everything else holds it —
	 * hardwired by id, not by `orderstatus_type` (`src/Helper/InventoryHelper.php:986-996`).
	 */
	private const J2_STOCK_RELEASING_STATUSES = [3, 5, 6];

	/** `orderstatus_type` values that count as paid (`src/Helper/OrderStatusHelper.php:69-74`). */
	private const J2_FINALIZED_TYPES = ['complete', 'approved', 'shipped', 'delivered'];

	/** The only coupon value types the discount maths recognises (`forms/coupon.xml:33-43`). */
	private const J2_COUPON_VALUE_TYPES = ['fixed_cart', 'fixed_product', 'percentage_cart', 'percentage_product'];

	private static ?bool $j2InstalledCache = null;

	private static ?array $j2TableListCache = null;

	private static bool $j2LanguageLoaded = false;

	// -----------------------------------------------------------------------
	// Presence and identity
	// -----------------------------------------------------------------------

	public function getCategory(): string
	{
		return 'j2commerce';
	}

	protected function j2AdminBase(): string
	{
		return JPATH_ADMINISTRATOR . '/components/com_j2commerce';
	}

	protected function j2Installed(): bool
	{
		if (self::$j2InstalledCache !== null) {
			return self::$j2InstalledCache;
		}

		self::$j2InstalledCache = is_dir($this->j2AdminBase()) && $this->j2TableExists('products');

		return self::$j2InstalledCache;
	}

	protected function j2NotInstalledError(): ToolResult
	{
		$legacy = $this->j2LegacyJ2StorePresent();

		return ToolResult::json([
			'ok'    => false,
			'error' => 'J2Commerce 6 (com_j2commerce) is not installed on this site. Expected '
				. 'administrator/components/com_j2commerce and the #__j2commerce_* tables.',
			'note'  => $legacy
				? 'This site has J2Store 4 (com_j2store / #__j2store_* tables) instead. J2Store 4 and '
					. 'J2Commerce 6 are different products with different schemas; these tools cover '
					. 'J2Commerce 6 only.'
				: 'Install J2Commerce from https://www.j2commerce.com before using these tools.',
		], true);
	}

	/** True when the legacy J2Store 4 schema is on this database. */
	protected function j2LegacyJ2StorePresent(): bool
	{
		$prefix = $this->db->getPrefix();

		foreach ($this->j2AllTables() as $name) {
			if (str_starts_with($name, $prefix . 'j2store_')) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Installed version. `version.php` is read by regular expression, not
	 * included: including it would `define()` constants into this request.
	 */
	protected function j2Version(): ?string
	{
		$file = $this->j2AdminBase() . '/version.php';

		if (is_file($file)) {
			$raw = (string) @file_get_contents($file);

			if (preg_match("/define\\(\\s*'J2COMMERCE_VERSION'\\s*,\\s*'([^']+)'/", $raw, $m) === 1) {
				return $m[1];
			}
		}

		$cache = (string) ($this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('manifest_cache'))
				->from($this->db->quoteName('#__extensions'))
				->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
				->where($this->db->quoteName('element') . ' = ' . $this->db->quote('com_j2commerce'))
		)->loadResult() ?? '');

		$decoded = json_decode($cache, true);

		return \is_array($decoded) && !empty($decoded['version']) ? (string) $decoded['version'] : null;
	}

	// -----------------------------------------------------------------------
	// Tables
	// -----------------------------------------------------------------------

	/** `#__j2commerce_<short>` with the prefix placeholder, for quoteName(). */
	protected function j2Table(string $short): string
	{
		return '#__j2commerce_' . $short;
	}

	/** @return string[] every table name on this database, real prefix included. */
	protected function j2AllTables(): array
	{
		if (self::$j2TableListCache === null) {
			self::$j2TableListCache = $this->db->getTableList();
		}

		return self::$j2TableListCache;
	}

	protected function j2TableExists(string $short): bool
	{
		return \in_array($this->db->getPrefix() . 'j2commerce_' . $short, $this->j2AllTables(), true);
	}

	protected function j2MissingTableError(string $short): ToolResult
	{
		return ToolResult::json([
			'ok'    => false,
			'error' => 'Table #__j2commerce_' . $short . ' does not exist on this site. The J2Commerce '
				. 'install looks incomplete; run check_j2commerce_health.',
		], true);
	}

	// -----------------------------------------------------------------------
	// Arguments
	// -----------------------------------------------------------------------

	protected function j2Limit(array $arguments, int $default = 50, int $max = 200): int
	{
		$limit = (int) ($arguments['limit'] ?? $default);

		return max(1, min($max, $limit));
	}

	protected function j2Offset(array $arguments): int
	{
		return max(0, (int) ($arguments['offset'] ?? 0));
	}

	protected function j2Like(string $value): string
	{
		return $this->db->quote('%' . $this->db->escape($value, true) . '%', false);
	}

	protected function j2Now(): string
	{
		return Factory::getDate()->toSql();
	}

	/** Refuse a value that would not fit a varchar column, rather than let MySQL truncate it. */
	protected function j2RequireFits(string $field, string $value, int $max): void
	{
		if (mb_strlen($value) > $max) {
			throw new \InvalidArgumentException(
				$field . ' is ' . mb_strlen($value) . ' characters; the column holds ' . $max
				. '. Refusing rather than truncating. Nothing was written.'
			);
		}
	}

	// -----------------------------------------------------------------------
	// Access control
	// -----------------------------------------------------------------------

	/**
	 * Replicates the vendor API's `assertAllowed()` (`J2CommerceApiController.php:170-185`)
	 * against the MCP actor: non-guest, `core.manage` on com_j2commerce, then the
	 * J2Commerce action.
	 *
	 * The action check mirrors `J2CommerceHelper::canAccess()` (`J2CommerceHelper.php:192-220`)
	 * but is evaluated for `$actor`, not for the application identity — the vendor
	 * method reads `Factory::getApplication()->getIdentity()`, and we do not want the
	 * answer to depend on which user the request happened to authenticate as. An
	 * explicit allow or deny on the action is honoured as-is; with no explicit rule
	 * the vendor falls back to `core.manage`, and so do we.
	 */
	protected function j2RequireAccess(User $actor, string $action): void
	{
		if ($actor->guest || (int) $actor->id === 0) {
			throw new \RuntimeException('J2Commerce tools require an authenticated user.');
		}

		if (!$actor->authorise('core.manage', 'com_j2commerce')) {
			throw new \RuntimeException(
				'This user lacks core.manage on com_j2commerce. J2Commerce\'s own REST API requires it '
				. 'for every route, and so does this add-on.'
			);
		}

		if ($actor->authorise($action, 'com_j2commerce')) {
			return;
		}

		$explicit = \array_key_exists($action, Access::getAssetRules('com_j2commerce')->getData());

		if ($explicit) {
			throw new \RuntimeException(
				'This user is not granted ' . $action . ' on com_j2commerce (an explicit rule for that '
				. 'action exists in J2Commerce -> Options -> Permissions, and it does not allow this user).'
			);
		}
		// No explicit rule for this action: the vendor falls back to core.manage, already checked.
	}

	// -----------------------------------------------------------------------
	// Calling vendor code
	// -----------------------------------------------------------------------

	/** Load com_j2commerce's admin language so vendor code writes real text, not keys. */
	protected function j2LoadLanguage(): void
	{
		if (self::$j2LanguageLoaded) {
			return;
		}

		$lang = Factory::getApplication()->getLanguage();

		// Same order as plg_system_j2commerce/src/Extension/J2Commerce.php:422-423.
		$lang->load('com_j2commerce', JPATH_ADMINISTRATOR)
			|| $lang->load('com_j2commerce', $this->j2AdminBase());
		$lang->load('com_j2commerce.sys', JPATH_ADMINISTRATOR)
			|| $lang->load('com_j2commerce.sys', $this->j2AdminBase());

		self::$j2LanguageLoaded = true;
	}

	/** An Administrator model with `ignore_request`, from J2Commerce's own MVC factory. */
	protected function j2Model(string $name): object
	{
		$this->j2LoadLanguage();

		$factory = Factory::getApplication()->bootComponent('com_j2commerce')->getMVCFactory();
		$model   = $factory->createModel($name, 'Administrator', ['ignore_request' => true]);

		if (!\is_object($model)) {
			throw new \RuntimeException('J2Commerce did not return a ' . $name . ' model. Is the component intact?');
		}

		return $model;
	}

	/**
	 * Run a vendor call and capture everything it reports sideways.
	 *
	 * Returns `[result, messages, stray_output]`. Messages are those the call
	 * enqueued (vendor code reports failures there instead of throwing). Stray
	 * output is anything a plugin echoed — the vendor buffers the same way around
	 * bulk status changes (`src/Controller/OrdersController.php:403-405`) — and it
	 * must not reach the JSON-RPC response body.
	 *
	 * @return array{0:mixed,1:array,2:string}
	 */
	protected function j2Run(callable $call): array
	{
		$app    = Factory::getApplication();
		$before = \count($app->getMessageQueue());

		ob_start();

		try {
			$result = $call();
		} finally {
			$stray = (string) ob_get_clean();
		}

		$queue    = $app->getMessageQueue();
		$messages = [];

		foreach (\array_slice($queue, $before) as $entry) {
			$messages[] = [
				'type'    => (string) ($entry['type'] ?? 'message'),
				'message' => strip_tags((string) ($entry['message'] ?? '')),
			];
		}

		return [$result, $messages, trim($stray)];
	}

	/**
	 * Note any mismatch between the MCP actor and the application identity.
	 *
	 * Vendor methods stamp `created_by` / `modified_by` from
	 * `Factory::getApplication()->getIdentity()` (`OrderModel.php:1116`, `:1279`),
	 * not from a parameter. Under token authentication they are the same user;
	 * when they are not, the audit trail names the wrong person and the caller
	 * should know.
	 */
	protected function j2IdentityNote(User $actor): ?string
	{
		$identity = Factory::getApplication()->getIdentity();
		$id       = $identity ? (int) $identity->id : 0;

		if ($id === (int) $actor->id) {
			return null;
		}

		return 'J2Commerce records the application identity (user ' . $id . ') as the author of this '
			. 'change, not the MCP actor (user ' . (int) $actor->id . ').';
	}

	// -----------------------------------------------------------------------
	// Output shaping
	// -----------------------------------------------------------------------

	/** Redact secret columns in one row. */
	protected function j2Redact(array $row): array
	{
		foreach (self::J2_REDACTED_COLUMNS as $column) {
			if (\array_key_exists($column, $row) && $row[$column] !== null && $row[$column] !== '') {
				$row[$column] = '[redacted]';
			}
		}

		return $row;
	}

	/** Mask a bearer code so it can be recognised but not spent: `SUMM…-8F2Q` style. */
	protected function j2MaskCode(string $code): string
	{
		$len = mb_strlen($code);

		if ($len <= 4) {
			return str_repeat('*', $len);
		}

		return mb_substr($code, 0, 2) . str_repeat('*', max(1, $len - 4)) . mb_substr($code, -2);
	}

	/** The `J2COMMERCE_CONFIRMED` style status name, translated when the language is loaded. */
	protected function j2StatusLabel(?string $raw): string
	{
		$raw = (string) $raw;

		if ($raw === '') {
			return '';
		}

		$this->j2LoadLanguage();
		$text = \Joomla\CMS\Language\Text::_($raw);

		return $text !== '' ? $text : $raw;
	}

	/** @return array<int,array{name:string,label:string,type:?string,enabled:bool,core:bool}> */
	protected function j2StatusMap(): array
	{
		if (!$this->j2TableExists('orderstatuses')) {
			return [];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('j2commerce_orderstatus_id'),
					$this->db->quoteName('orderstatus_name'),
					$this->db->quoteName('orderstatus_type'),
					$this->db->quoteName('orderstatus_core'),
					$this->db->quoteName('enabled'),
				])
				->from($this->db->quoteName($this->j2Table('orderstatuses')))
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$out[(int) $row['j2commerce_orderstatus_id']] = [
				'name'    => (string) $row['orderstatus_name'],
				'label'   => $this->j2StatusLabel((string) $row['orderstatus_name']),
				'type'    => $row['orderstatus_type'] !== null ? (string) $row['orderstatus_type'] : null,
				'enabled' => (int) $row['enabled'] === 1,
				'core'    => (int) $row['orderstatus_core'] === 1,
			];
		}

		return $out;
	}

	/** Component params straight from the database, never through ConfigHelper's static cache. */
	protected function j2RawParams(): array
	{
		$raw = (string) ($this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('params'))
				->from($this->db->quoteName('#__extensions'))
				->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
				->where($this->db->quoteName('element') . ' = ' . $this->db->quote('com_j2commerce'))
		)->loadResult() ?? '');

		$decoded = json_decode($raw === '' ? '{}' : $raw, true);

		return \is_array($decoded) ? $decoded : [];
	}

	/** Default currency code, as configured (`config.xml` `config_currency`, default USD). */
	protected function j2StoreCurrency(): string
	{
		$params = $this->j2RawParams();

		return (string) ($params['config_currency'] ?? 'USD');
	}

	protected function j2Money(mixed $value): ?float
	{
		return $value === null || $value === '' ? null : round((float) $value, 5);
	}
}
