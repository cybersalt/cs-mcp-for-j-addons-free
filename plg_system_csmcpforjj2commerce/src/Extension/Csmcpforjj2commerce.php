<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * J2Commerce MCP add-on. Built against J2Commerce 6.6.5 (2026-10-12) on
 * Joomla 6.2.0.
 *
 * WHY THIS ADD-ON IS FREE. J2Commerce 6 has no capability-gated Pro edition.
 * `version.php` defines a `J2COMMERCE_PRO` constant, but `isPro()` gates no
 * capability whatever its value — it picks a UTM tag on help links and fills a
 * diagnostics field. J2Commerce sells paid APPS; this add-on wraps the GPL core,
 * so it is free for the same reason the core is.
 *
 * ---------------------------------------------------------------------------
 * FIVE FACTS SHAPED EVERY TOOL HERE
 * ---------------------------------------------------------------------------
 *
 *   1. A PRODUCT IS AN ARTICLE. `#__j2commerce_products` links to `#__content`
 *      through `product_source='com_content'` + `product_source_id`, and is
 *      created and saved only from the article editor, by
 *      `plg_content_j2commerce::onContentAfterSave`. Its name is the article
 *      title. Price and SKU are on the master variant; stock is in
 *      `#__j2commerce_productquantities`. Deleting the article only disables the
 *      product, so orphans are normal.
 *
 *   2. A PARTIAL PRODUCT SAVE DESTROYS DATA. `ProductService::saveProduct()`
 *      forces every field it was not given to 0 or '' — price, dimensions,
 *      weight, tax profile, manufacturer, sale limits, child-variant stock
 *      (`src/Model/Behavior/Simple.php:133-166`, `Variable.php:342-367`). No
 *      tool here calls it. Product writes are targeted UPDATEs of named
 *      columns; stock writes go through the vendor's atomic
 *      `InventoryHelper::adjustStockAndAvailability()`.
 *
 *   3. ORDER STATUS CARRIES THE SIDE EFFECTS. `OrderModel::updateOrderStatus()`
 *      moves stock, writes history, grants downloads, fires
 *      `onJ2CommerceOrderStatusChange` and optionally emails — and it is exactly
 *      what J2Commerce's own REST endpoint calls. We call it too. There is no
 *      state machine, and stock release is hardwired to status ids 3, 5 and 6.
 *
 *   4. TAX AND GEOZONE SAVES WIPE AND REINSERT. Saving a geozone or tax profile
 *      deletes every rule and reinserts from the payload, with no transaction
 *      (`src/Model/GeozoneModel.php:331-391`, `TaxprofileModel.php:165-213`). A
 *      partial save silently zeroes the shop's tax. So tax is read and audited
 *      here — `check_j2commerce_tax_setup` — and never written.
 *
 *   5. SECRETS. `orders.token` opens any order as a guest; voucher codes are
 *      bearer credentials; `queue_key` is the cron secret and the payment-hash
 *      HMAC key. J2Commerce's own config REST endpoint returns `queue_key` and
 *      `downloadid` in cleartext. These tools never do.
 *
 * ---------------------------------------------------------------------------
 * WHAT WE DELIBERATELY DO NOT BUILD
 * ---------------------------------------------------------------------------
 *
 *   - No whole-product save, no product-type change, no variant generation.
 *   - No tax, geozone, shipping-rate or order-status writes (replace-set saves).
 *   - No order creation and no order deletion. Deleting an order never restocks
 *     and hands voucher balance back; J2Commerce's admin is the place for it.
 *   - No voucher creation. A voucher code is money.
 *   - No payment-plugin parameters, ever. They hold live gateway credentials as
 *     plain text fields.
 *   - No cart or checkout helpers: they are session-bound and the API
 *     application's session does not persist.
 */
final class Csmcpforjj2commerce extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Diagnostics — orientation, health, tax audit, configuration and scoped table access.
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Diagnostics\GetComponentInfoTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Diagnostics\CheckHealthTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Diagnostics\CheckTaxSetupTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Diagnostics\GetConfigTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Diagnostics\SetConfigTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Diagnostics\ListTablesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Diagnostics\QueryTableTool::class,

		// Products — the article-backed product row and its variants. Targeted writes only.
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Products\ListProductsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Products\GetProductTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Products\CreateProductTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Products\UpdateProductTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Products\UpdateVariantTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Products\DeleteProductTool::class,

		// Advanced prices — one row at a time.
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Pricing\ListProductPricesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Pricing\SetProductPriceTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Pricing\DeleteProductPriceTool::class,

		// Inventory — through J2Commerce's own atomic stock writers.
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Inventory\ListStockTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Inventory\SetStockTool::class,

		// Orders — read, status change, notes, tracking. No create, no delete.
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Orders\ListOrdersTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Orders\GetOrderTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Orders\ListOrderStatusesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Orders\SetOrderStatusTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Orders\AddOrderNoteTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Orders\SetOrderTrackingTool::class,

		// Customers — there is no customers table; these assemble one.
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Customers\ListCustomersTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Customers\GetCustomerTool::class,

		// Coupons.
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Coupons\ListCouponsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Coupons\GetCouponTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Coupons\CreateCouponTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Coupons\UpdateCouponTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Coupons\DeleteCouponTool::class,

		// Gift vouchers — balances derived by the vendor, codes masked.
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Vouchers\ListVouchersTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Vouchers\GetVoucherTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Vouchers\AdjustVoucherBalanceTool::class,

		// Store reference data — read-only by design.
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Store\ListCurrenciesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Store\ListCountriesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Store\ListGeozonesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Store\ListManufacturersTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Store\ListOptionsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Store\ListShippingMethodsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Store\ListPaymentMethodsTool::class,

		// Reports.
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Reports\GetSalesSummaryTool::class,
		\Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Reports\GetTopProductsTool::class,
	];

	public static function getSubscribedEvents(): array
	{
		/*
		 * This runs on EVERY request, for every enabled system plugin, before
		 * anything has a chance to check whether MCP for Joomla is present.
		 *
		 * RegisterToolsEvent lives in com_csmcpforj. Reading ::EVENT_NAME off it
		 * is a class-constant access, which triggers the autoloader — and if the
		 * component is not installed, PHP raises
		 * `Error: Class "...RegisterToolsEvent" not found`. That is a fatal on
		 * every page load, front end AND administrator, which locks the operator
		 * out of the very screen they would use to disable this plugin.
		 *
		 * class_exists() returns false instead of throwing, so without the core
		 * component this plugin subscribes to nothing and the site is untouched.
		 * Do not "simplify" this back to a bare constant access.
		 */
		if (!class_exists(RegisterToolsEvent::class)) {
			return [];
		}

		return [RegisterToolsEvent::EVENT_NAME => 'onRegisterTools'];
	}

	public function onRegisterTools(RegisterToolsEvent $event): void
	{
		$registry = $event->getRegistry();
		$db       = $this->getDatabase();

		/*
		 * Money and weights. Some hosts set serialize_precision to 17, and then
		 * json_encode(0.4) emits 0.40000000000000002220446… in every tool result.
		 * -1 is PHP's own default (shortest round-trip representation). This event
		 * fires only inside an MCP request, so nothing else on the site is affected.
		 */
		if ((string) ini_get('serialize_precision') !== '-1') {
			@ini_set('serialize_precision', '-1');
		}

		foreach (self::TOOLS as $toolClass) {
			$registry->register(new $toolClass($db));
		}
	}

	public static function getToolClasses(): array
	{
		return self::TOOLS;
	}
}
