<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * VirtueMart MCP add-on. Built against com_virtuemart 4.6.8 (revision 11258),
 * targeting 4.8.0.
 *
 * WHY THIS ADD-ON IS FREE. VirtueMart is GPL and ships a single edition. There
 * is no Pro build, no licence key, no download id and no feature gate anywhere
 * in the component — nothing for us to honour and, equally, nothing for us to
 * route around. Unlike the SP Page Builder and Page Builder CK add-ons in this
 * family, there is no paywall question to answer here at all, so the whole
 * surface ships free.
 *
 * ---------------------------------------------------------------------------
 * FOUR FACTS SHAPED EVERY TOOL HERE
 * ---------------------------------------------------------------------------
 *
 *   1. A PARTIAL SAVE DESTROYS DATA. `VirtueMartModelProduct::store()` treats
 *      its `$data` argument as a complete admin form POST, so every satellite
 *      absent from it is deleted: prices (`models/product.php:2895-2913`),
 *      categories, shopper groups and manufacturers (`:2935-2960` via
 *      `helpers/vmtablexarray.php:226-237`) and custom fields
 *      (`models/customfields.php:1590-1599`). The category case is the worst,
 *      because the guard at `:2940` reads "empty means store the empty set",
 *      i.e. delete them all, and the only escape is the literal sentinel `-2`.
 *      Every write tool here is therefore either a read-modify-write of the
 *      whole object or a targeted UPDATE on one column, and no tool ever hands
 *      a partial array to a VirtueMart model.
 *
 *   2. THE CONFIGURATION IS READ-ONLY, PERMANENTLY. `ConfigModel::store()`
 *      re-reads `virtuemart.cfg` from disk and calls `setParams()` on it
 *      (`models/config.php:413-420` against `helpers/config.php:610-627`),
 *      and `setParams()` replaces the parameter set wholesale. A partial write
 *      therefore resets every setting the caller did not name to the shipped
 *      default — a wipe-the-whole-shop primitive that reports success. There is
 *      `get_virtuemart_config` and there is no setter.
 *
 *   3. THE `_<lang>` TABLES ARE THE COMPONENT. `#__virtuemart_products` has no
 *      `product_name` column at all; name, descriptions, meta and slug live in
 *      `#__virtuemart_products_<langsuffix>`, joined with an INNER JOIN
 *      (`helpers/vmtable.php:1065-1068`). A base row without its language row
 *      is an invisible product — not untranslated, absent — and nothing logs
 *      it. Seven tables work this way (`helpers/tableupdater.php:51-57`), the
 *      suffix is `strtolower(strtr($tag,'-','_'))`, and the column set is
 *      GENERATED at install time. None of it is hardcoded here; it is all
 *      resolved at runtime from the shop's own `active_languages`.
 *      `check_virtuemart_language_tables` is the audit for it.
 *
 *   4. ORDERS ARE READ AND STATUS-CHANGE ONLY. `updateStatusForOneOrder()`
 *      (`models/orders.php:1146`) is the one supported write, because it alone
 *      handles stock, item status propagation, invoice numbering, history and
 *      email. There is no state machine anywhere — any code to any code — so
 *      the transition is validated here instead. Order CREATION is not exposed:
 *      it needs a fully primed `VirtueMartCart` through four private methods.
 *
 * ---------------------------------------------------------------------------
 * WHAT WE DELIBERATELY DO NOT BUILD
 * ---------------------------------------------------------------------------
 *
 *   - No media or file upload of any kind. CVE-2025-25229 and CVE-2025-25230
 *     were an unrestricted-upload and CSRF-bypass pair in exactly VirtueMart's
 *     product-image path, chaining to RCE, and 4.8.0 was still hardening it.
 *     We do not rebuild that surface.
 *   - No payment or shipment plugin parameters. `payment_params` and
 *     `shipment_params` hold live gateway secrets and API keys; they are
 *     redacted everywhere, including as filter and sort targets.
 *   - No userfield DDL. VirtueMart can `ALTER TABLE` from its userfields screen
 *     (`helpers/vmtable.php:2712`), guarded only by an ACL check. An
 *     arbitrary-DDL primitive is not something to expose over MCP.
 *   - No order creation, no invoice-number writes, no calc-rule writes.
 *
 * ---------------------------------------------------------------------------
 * JOOMLA 6
 * ---------------------------------------------------------------------------
 *
 * VirtueMart 4.x has zero version gating for legacy classes and fatals at
 * `helpers/config.php:367` without the compat plugin's aliases. Worse, the
 * compat plugin alone is not sufficient on Joomla 6:
 * `helpers/vmdefines.php:165` uses `JPATH_PLATFORM`, which compat6 redefines to
 * its own directory rather than `<root>/libraries`, breaking TCPDF and
 * therefore invoices. This is REPORTED by
 * `get_virtuemart_component_info` and `check_virtuemart_health` and gated on by
 * nothing: 47 of these 48 tools work directly against the database and load
 * none of VirtueMart's PHP.
 *
 * v1.0.0 ships 48 tools across 10 categories.
 */
final class Csmcpforjvirtuemart extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Products — the base row, its language satellites and its satellites.
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Products\ListProductsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Products\GetProductTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Products\CreateProductTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Products\UpdateProductTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Products\DeleteProductTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Products\SetProductStateTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Products\SetProductCategoriesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Products\SetProductManufacturersTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Products\GetProductTranslationTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Products\SetProductTranslationTool::class,

		// Categories — same base/language split, plus the parent xref VirtueMart
		// still writes despite calling it obsolete.
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Categories\ListCategoriesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Categories\GetCategoryTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Categories\CreateCategoryTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Categories\UpdateCategoryTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Categories\DeleteCategoryTool::class,

		// Manufacturers — the third translatable catalog entity.
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Manufacturers\ListManufacturersTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Manufacturers\GetManufacturerTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Manufacturers\CreateManufacturerTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Manufacturers\UpdateManufacturerTool::class,

		// Pricing — row-at-a-time on purpose, because the vendor's price
		// handling is a replace-set that deletes what it was not given.
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Pricing\ListProductPricesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Pricing\SetProductPriceTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Pricing\DeleteProductPriceTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Pricing\ListCalcRulesTool::class,

		// Inventory.
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Inventory\ListStockTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Inventory\SetProductStockTool::class,

		// Orders — read and status change only. SetOrderStatusTool is the one
		// tool in this add-on that calls VirtueMart's own PHP.
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Orders\ListOrdersTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Orders\GetOrderTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Orders\SetOrderStatusTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Orders\ListOrderHistoryTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Orders\ListOrderStatusesTool::class,

		// Customers — reads, plus the one safely writable field.
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Customers\ListCustomersTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Customers\GetCustomerTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Customers\ListShoppergroupsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Customers\SetCustomerShoppergroupsTool::class,

		// Store reference data.
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Store\ListVendorsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Store\ListCurrenciesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Store\ListCountriesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Store\ListStatesTool::class,

		// Ratings and reviews.
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Reviews\ListReviewsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Reviews\SetReviewStateTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Reviews\DeleteReviewTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Reviews\ListRatingsTool::class,

		// Diagnostics — orientation, the invisible-record audit, the shop-wide
		// health check, config read, and scoped read-only table access.
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Diagnostics\GetComponentInfoTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Diagnostics\CheckHealthTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Diagnostics\CheckLanguageTablesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Diagnostics\GetConfigTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Diagnostics\ListTablesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Diagnostics\QueryTableTool::class,
	];

	public static function getSubscribedEvents(): array
	{
		return [RegisterToolsEvent::EVENT_NAME => 'onRegisterTools'];
	}

	public function onRegisterTools(RegisterToolsEvent $event): void
	{
		$registry = $event->getRegistry();
		$db       = $this->getDatabase();

		foreach (self::TOOLS as $toolClass) {
			$registry->register(new $toolClass($db));
		}
	}

	public static function getToolClasses(): array
	{
		return self::TOOLS;
	}
}
