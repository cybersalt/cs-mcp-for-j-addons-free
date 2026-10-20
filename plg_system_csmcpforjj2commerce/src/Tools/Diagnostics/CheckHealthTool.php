<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;

/**
 * Shop-wide health audit. Strictly read-only.
 *
 * Two layers. The first is J2Commerce's OWN Database Health checks, called
 * directly through their public static `count*()` functions
 * (`src/Helper/DatabaseHealthHelper.php:428-1310`). Every one of those was
 * verified to be a pure SELECT. We deliberately do not call
 * `DatabaseHealthHelper::getResults()`: it dispatches `onJ2CommerceGetHealthChecks`
 * (`:148-161`), which lets any installed plugin add or rewrite checks, and a
 * read-only tool should not run third-party code. The vendor's `runFix()` is
 * its write path and is never touched.
 *
 * The second layer is what the vendor's checks do not look for — the problems
 * that show up as a quietly wrong shop rather than as orphaned rows.
 */
final class CheckHealthTool extends AbstractTool
{
	use J2commerceBootTrait;

	/** id => [vendor count function, destructive-to-fix per the vendor's own flag] */
	private const VENDOR_CHECKS = [
		'cart_gc_backlog'                 => ['countCartGcBacklog', false],
		'orphan_cartitems'                => ['countOrphanCartitems', true],
		'orphan_productquantities'        => ['countOrphanProductquantities', false],
		'missing_productquantity'         => ['countMissingProductquantity', false],
		'stale_on_hold'                   => ['countStaleOnHold', false],
		'orphan_productimages'            => ['countOrphanProductimages', false],
		'zero_date_variants'              => ['countZeroDateVariants', false],
		'price_index_stale'               => ['countPriceIndexStale', false],
		'products_without_master_variant' => ['countProductsWithoutMasterVariant', false],
		'orphan_product_options'          => ['countOrphanProductOptions', true],
		'orphan_product_optionvalues'     => ['countOrphanProductOptionvalues', true],
		'orphan_orderitems'               => ['countOrphanOrderitems', true],
		'orphan_orderhistories'           => ['countOrphanOrderhistories', true],
		'orphan_orderitemattributes'      => ['countOrphanOrderitemattributes', true],
		'orphan_uploads'                  => ['countOrphanUploads', true],
		'orphan_ordertransactions'        => ['countOrphanOrdertransactions', false],
		'orphan_voucheradjustments'       => ['countOrphanVoucheradjustments', false],
		'orphan_zones'                    => ['countOrphanZones', true],
		'orphan_geozonerules'             => ['countOrphanGeozonerules', true],
		'orphan_shippingrates'            => ['countOrphanShippingrates', true],
		'orphan_taxrules'                 => ['countOrphanTaxrules', true],
		'entity_encoded_zone_names'       => ['countEntityEncodedZoneNames', false],
		'migrator_residue'                => ['countMigratorResidue', false],
	];

	/** `DatabaseHealthHelper::ORPHAN_ORDER_CHILD_TABLES` (`:384-391`). */
	private const ORDER_CHILD_TABLES = [
		'orderinfos'     => 'j2commerce_orderinfo_id',
		'ordershippings' => 'j2commerce_ordershipping_id',
		'orderdiscounts' => 'j2commerce_orderdiscount_id',
		'orderfees'      => 'j2commerce_orderfee_id',
		'ordertaxes'     => 'j2commerce_ordertax_id',
		'orderdownloads' => 'j2commerce_orderdownload_id',
	];

	public function getName(): string { return 'check_j2commerce_health'; }

	public function getDescription(): string
	{
		return 'Shop-wide health audit for J2Commerce 6. Strictly read-only; nothing is repaired. '
			. 'Runs J2Commerce\'s OWN Database Health checks (about 30 orphan and staleness counts from '
			. 'DatabaseHealthHelper — orphaned stock rows, variants with no stock record, held stock that '
			. 'never released, order children with no order, tax rules with no profile, and so on), calling '
			. 'their read-only count functions directly so no third-party plugin code runs. '
			. 'Then adds checks the vendor does not have, each of which shows up as a silently wrong shop: '
			. 'products whose Joomla article is gone (J2Commerce only DISABLES a product when its article '
			. 'is deleted, so these accumulate); enabled products whose article is unpublished or trashed; '
			. 'enabled products priced at zero; oversold variants (stock below zero — J2Commerce stores the '
			. 'signed truth and never clamps); coupons whose value_type the discount maths does not '
			. 'recognise (they silently discount nothing); orders pointing at an order status that no longer '
			. 'exists; custom order statuses (they HOLD stock, because only status ids 3, 5 and 6 release '
			. 'it); a base currency missing from the currency table or not at rate 1; failed or dead '
			. 'integration-queue items; and the disabled-plugin conditions that break product saving. '
			. 'Each finding names the tool or J2Commerce screen that fixes it. Repairs of the vendor\'s own '
			. 'checks belong on J2Commerce\'s Database Health screen, which previews destructive fixes.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->j2Installed()) {
			return $this->j2NotInstalledError();
		}

		$this->j2RequireAccess($actor, 'j2commerce.viewsetup');
		$this->j2LoadLanguage();

		$vendor  = $this->vendorChecks();
		$ours    = $this->ownChecks();
		$problem = array_values(array_filter(
			array_merge($vendor, $ours),
			static fn (array $c): bool => ($c['status'] ?? '') !== 'ok'
		));

		return ToolResult::json([
			'ok'            => true,
			'summary'       => [
				'checks_run' => \count($vendor) + \count($ours),
				'findings'   => \count($problem),
			],
			'findings'      => $problem,
			'vendor_checks' => $vendor,
			'own_checks'    => $ours,
			'note'          => 'Nothing was changed. Vendor-check findings are repaired from J2Commerce\'s '
				. 'Database Health screen (it previews destructive fixes). The fix for each own-check finding '
				. 'is named on the finding.',
		]);
	}

	/** @return array<int,array<string,mixed>> */
	private function vendorChecks(): array
	{
		$class = '\\J2Commerce\\Component\\J2commerce\\Administrator\\Helper\\DatabaseHealthHelper';

		if (!class_exists($class)) {
			return [[
				'id'     => 'vendor_health_helper',
				'status' => 'failed',
				'detail' => 'J2Commerce\'s DatabaseHealthHelper class could not be loaded, so its own checks '
					. 'were skipped. The component install may be incomplete.',
			]];
		}

		$out    = [];
		$checks = [];

		foreach (self::VENDOR_CHECKS as $id => [$fn, $destructive]) {
			$checks[$id] = [static fn ($db) => $class::$fn($db), $destructive];
		}

		foreach (self::ORDER_CHILD_TABLES as $table => $pk) {
			$checks['orphan_' . $table] = [static fn ($db) => $class::countOrphanOrderChild($db, $table, $pk), true];
		}

		foreach ($checks as $id => [$count, $destructive]) {
			$labelKey = 'COM_J2COMMERCE_DATABASE_HEALTH_CHECK_' . strtoupper($id) . '_LABEL';
			$label    = Text::_($labelKey);

			try {
				$n = (int) $count($this->db);

				$entry = [
					'id'     => $id,
					'label'  => $label !== $labelKey ? $label : $id,
					'source' => 'j2commerce',
					'status' => $n > 0 ? 'warning' : 'ok',
					'count'  => $n,
				];

				if ($n > 0) {
					$entry['fix'] = 'J2Commerce Database Health screen'
						. ($destructive ? ' — the vendor flags this repair as DESTRUCTIVE (it deletes rows).' : '.');
				}
			} catch (\Throwable $e) {
				// The vendor's own convention: a check that throws is "failed", never 0.
				$entry = [
					'id'     => $id,
					'label'  => $label !== $labelKey ? $label : $id,
					'source' => 'j2commerce',
					'status' => 'failed',
					'error'  => $e->getMessage(),
				];
			}

			$out[] = $entry;
		}

		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	private function ownChecks(): array
	{
		$out = [];
		$p   = $this->db->getPrefix();
		$db  = $this->db;

		$products = $db->quoteName($this->j2Table('products'));
		$variants = $db->quoteName($this->j2Table('variants'));

		// 1. Products whose article is gone.
		$out[] = $this->sqlCheck(
			'product_article_missing',
			'Products whose Joomla article no longer exists',
			'SELECT COUNT(*) FROM ' . $products . ' p LEFT JOIN ' . $db->quoteName('#__content') . ' c'
			. ' ON c.id = p.product_source_id WHERE p.product_source = ' . $db->quote('com_content')
			. ' AND c.id IS NULL',
			'A product is created from, and named by, its article. Deleting the article only DISABLES the '
			. 'product unless the content plugin\'s purge_on_delete option is on (default off), so these '
			. 'rows are normal residue. Their variants, prices and stock rows remain. Inspect with '
			. 'list_j2commerce_products (orphaned: true); remove with delete_j2commerce_product.'
		);

		// 2. Enabled products whose article is not published.
		$out[] = $this->sqlCheck(
			'enabled_product_unpublished_article',
			'Enabled products whose article is unpublished, archived or trashed',
			'SELECT COUNT(*) FROM ' . $products . ' p JOIN ' . $db->quoteName('#__content') . ' c'
			. ' ON c.id = p.product_source_id WHERE p.product_source = ' . $db->quote('com_content')
			. ' AND p.enabled = 1 AND c.state <> 1',
			'Shoppers cannot reach these products: the article is the product page. The content plugin '
			. 'mirrors article state onto the product only when its mirror_state option is on, so the two '
			. 'drift apart. Publish the article (core update_article) or disable the product '
			. '(update_j2commerce_product enabled: false).'
		);

		// 3. Enabled products at price zero.
		$out[] = $this->sqlCheck(
			'enabled_product_zero_price',
			'Enabled products whose master variant price is zero',
			'SELECT COUNT(*) FROM ' . $products . ' p JOIN ' . $variants . ' v'
			. ' ON v.product_id = p.j2commerce_product_id AND v.is_master = 1'
			. ' WHERE p.enabled = 1 AND p.product_type IN (' . $db->quote('simple') . ',' . $db->quote('downloadable')
			. ',' . $db->quote('configurable') . ') AND (v.price IS NULL OR v.price = 0)',
			'Usually a product created and never priced, or a partial save: J2Commerce\'s ProductService '
			. 'forces a missing price to 0 (src/Model/Behavior/Simple.php:133-166). Variable and '
			. 'flexivariable products are excluded because their price lives on child variants. Fix with '
			. 'update_j2commerce_variant.'
		);

		// 4. Oversold variants.
		if ($this->j2TableExists('productquantities')) {
			$out[] = $this->sqlCheck(
				'oversold_variants',
				'Stock-managed variants with stock below zero',
				'SELECT COUNT(*) FROM ' . $db->quoteName($this->j2Table('productquantities')) . ' q JOIN ' . $variants
				. ' v ON v.j2commerce_variant_id = q.variant_id WHERE v.manage_stock = 1 AND q.quantity < 0',
				'J2Commerce records stock as the signed truth and never clamps it '
				. '(src/Helper/InventoryHelper.php adjustVariantStock), so a negative figure means more was '
				. 'sold than was on hand. Inspect with list_j2commerce_stock (filter: negative); correct with '
				. 'set_j2commerce_stock after a count.'
			);
		}

		// 5. Coupons with a value_type the discount maths ignores.
		if ($this->j2TableExists('coupons')) {
			$valid = implode(',', array_map([$db, 'quote'], self::J2_COUPON_VALUE_TYPES));

			$out[] = $this->sqlCheck(
				'coupon_invalid_value_type',
				'Coupons with a value_type J2Commerce cannot apply',
				'SELECT COUNT(*) FROM ' . $db->quoteName($this->j2Table('coupons'))
				. ' WHERE value_type IS NULL OR value_type NOT IN (' . $valid . ')',
				'CouponTable::check() defaults value_type to "percentage", which CouponModel::getDiscountAmount '
				. 'does not recognise, so the coupon validates at checkout and discounts nothing. Fix with '
				. 'update_j2commerce_coupon value_type (one of ' . implode(', ', self::J2_COUPON_VALUE_TYPES) . ').'
			);
		}

		// 6. Orders pointing at a missing status.
		if ($this->j2TableExists('orders')) {
			$out[] = $this->sqlCheck(
				'orders_unknown_status',
				'Orders whose order_state_id matches no order status',
				'SELECT COUNT(*) FROM ' . $db->quoteName($this->j2Table('orders')) . ' o LEFT JOIN '
				. $db->quoteName($this->j2Table('orderstatuses')) . ' s ON s.j2commerce_orderstatus_id = o.order_state_id'
				. ' WHERE s.j2commerce_orderstatus_id IS NULL',
				'Core statuses can be deleted once trashed; nothing prevents it. These orders display with no '
				. 'status and fall out of every status-based report. Move them with set_j2commerce_order_status.'
			);
		}

		// 7. Custom statuses hold stock.
		$custom = [];

		foreach ($this->j2StatusMap() as $id => $status) {
			if (!$status['core']) {
				$custom[] = ['id' => $id, 'name' => $status['label'], 'type' => $status['type']];
			}
		}

		$out[] = [
			'id'     => 'custom_statuses_hold_stock',
			'label'  => 'Merchant-defined order statuses',
			'source' => 'cybersalt',
			'status' => $custom === [] ? 'ok' : 'info',
			'count'  => \count($custom),
		] + ($custom === [] ? [] : [
			'statuses' => $custom,
			'detail'   => 'Stock release is hardwired to status ids 3, 5 and 6 '
				. '(src/Helper/InventoryHelper.php:986-996), not to orderstatus_type. Every custom status '
				. 'therefore HOLDS stock — a merchant\'s own "Refunded" or "Returned" status never puts stock '
				. 'back. Move such orders through Cancelled (6) if the stock should return.',
		]);

		// 8. Base currency.
		$base = $this->j2StoreCurrency();

		if ($this->j2TableExists('currencies')) {
			$row = $db->setQuery(
				$db->getQuery(true)
					->select([$db->quoteName('currency_value'), $db->quoteName('enabled')])
					->from($db->quoteName($this->j2Table('currencies')))
					->where($db->quoteName('currency_code') . ' = ' . $db->quote($base))
			)->loadAssoc();

			if (!\is_array($row)) {
				$out[] = [
					'id' => 'base_currency', 'label' => 'Store base currency', 'source' => 'cybersalt',
					'status' => 'warning',
					'detail' => 'config_currency is ' . $base . ' but no currency row has that code. Prices are '
						. 'formatted and converted against a currency that does not exist.',
				];
			} elseif ((float) $row['currency_value'] !== 1.0 || (int) $row['enabled'] !== 1) {
				$out[] = [
					'id' => 'base_currency', 'label' => 'Store base currency', 'source' => 'cybersalt',
					'status' => 'warning',
					'detail' => 'The base currency ' . $base . ' has rate ' . $row['currency_value']
						. ((int) $row['enabled'] !== 1 ? ' and is DISABLED' : '') . '. Rates are relative to the '
						. 'base, which must be enabled at exactly 1; the currency updater pins it there on its next '
						. 'run (plg_j2commerce_app_currencyupdater).',
				];
			} else {
				$out[] = ['id' => 'base_currency', 'label' => 'Store base currency', 'source' => 'cybersalt', 'status' => 'ok', 'currency' => $base];
			}
		}

		// 9. Integration queue failures.
		if ($this->j2TableExists('queues')) {
			$out[] = $this->sqlCheck(
				'queue_failed',
				'Integration queue items that failed or died',
				'SELECT COUNT(*) FROM ' . $db->quoteName($this->j2Table('queues'))
				. ' WHERE status IN (' . $db->quote('failed') . ',' . $db->quote('dead') . ')',
				'#__j2commerce_queues carries work for integration apps (ShipStation, QuickBooks, Avalara and '
				. 'the like), processed by the J2Commerce scheduled task. Dead items will not be retried. Read '
				. 'them with query_j2commerce_table table: queues.'
			);
		}

		// 10. The email log table the vendor writes to but never creates.
		$out[] = [
			'id'     => 'email_log_table',
			'label'  => 'Email send log table',
			'source' => 'cybersalt',
			'status' => \in_array($p . 'j2commerce_email_log', $this->j2AllTables(), true) ? 'ok' : 'info',
		] + (\in_array($p . 'j2commerce_email_log', $this->j2AllTables(), true) ? [] : [
			'detail' => 'EmailHelper logs sends to #__j2commerce_email_log (src/Helper/EmailHelper.php:2543-2566), '
				. 'but no install or update SQL in J2Commerce 6.6.5 creates it, so email logging fails silently. '
				. 'This is a vendor bug, not a fault on this site; order emails themselves are unaffected.',
		]);

		// 11. The two plugins products and the shop depend on.
		foreach (['system' => 'onAfterInitialise boot and the daily unpaid-order sweep', 'content' => 'the ONLY product save path from the article editor'] as $folder => $why) {
			$enabled = (int) $db->setQuery(
				$db->getQuery(true)
					->select($db->quoteName('enabled'))
					->from($db->quoteName('#__extensions'))
					->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
					->where($db->quoteName('folder') . ' = ' . $db->quote($folder))
					->where($db->quoteName('element') . ' = ' . $db->quote('j2commerce'))
			)->loadResult();

			$out[] = [
				'id'     => 'plugin_' . $folder . '_enabled',
				'label'  => 'plg_' . $folder . '_j2commerce enabled',
				'source' => 'cybersalt',
				'status' => $enabled === 1 ? 'ok' : 'warning',
			] + ($enabled === 1 ? [] : [
				'detail' => 'Disabled. It provides ' . $why . '. Enable with core set_extension_enabled.',
			]);
		}

		return $out;
	}

	private function sqlCheck(string $id, string $label, string $sql, string $detail): array
	{
		try {
			$n = (int) $this->db->setQuery($sql)->loadResult();
		} catch (\Throwable $e) {
			return ['id' => $id, 'label' => $label, 'source' => 'cybersalt', 'status' => 'failed', 'error' => $e->getMessage()];
		}

		$entry = ['id' => $id, 'label' => $label, 'source' => 'cybersalt', 'status' => $n > 0 ? 'warning' : 'ok', 'count' => $n];

		if ($n > 0) {
			$entry['detail'] = $detail;
		}

		return $entry;
	}
}
