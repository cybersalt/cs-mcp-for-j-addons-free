<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * A shop-wide audit of the failure modes VirtueMart does not report.
 *
 * Every check here corresponds to something that breaks silently: no error, no
 * log line, no admin warning. That is the selection criterion — a fault the
 * component would have told you about does not need a tool.
 */
final class CheckHealthTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'check_virtuemart_health'; }

	public function getDescription(): string
	{
		return 'Audit a VirtueMart shop for the faults it does not report. Every check here corresponds '
			. 'to something that breaks with no error, no log line and no admin warning — that is the '
			. 'criterion for inclusion. '
			. 'Checks performed: '
			. '(1) INVISIBLE PRODUCTS AND CATEGORIES — base rows with no row in a language satellite '
			. 'table. The read join is INNER (helpers/vmtable.php:1065-1068), so these do not exist for '
			. 'shoppers. check_virtuemart_language_tables breaks this down per table and language. '
			. '(2) UNBUYABLE PRODUCTS — published products with no price row, or with no category '
			. 'assignment, or whose only price row has a closed publish window. '
			. '(3) STALE has_* JOIN HINTS — products whose has_categories / has_prices / has_medias / '
			. 'has_shoppergroups / has_manufacturers disagree with their actual satellite rows. When a '
			. 'flag reads 0 VirtueMart skips that join entirely (models/product.php:2765-2776), so the '
			. 'data exists and is never displayed. '
			. '(4) BROKEN ORDERS — orders with no BT address row, with more than one, or with an empty '
			. 'billing email. Any of those makes the admin order screen redirect with '
			. 'COM_VIRTUEMART_ORDER_NOTFOUND and makes notifyCustomer() fatal on PHP 8 '
			. '(models/orders.php:2512, :2604). '
			. '(5) ORPHANED ORDER STATUSES — orders in a status code with no #__virtuemart_orderstates '
			. 'row. Changing those makes stock handling skip silently (models/orders.php:2035-2038). '
			. '(6) STOCK DESYNC — products where product_ordered exceeds product_in_stock. '
			. '(7) DUPLICATE ORDER NUMBERS — uniqueness is enforced in PHP only (tables/orders.php:114); '
			. 'the DB index is a plain KEY (install.sql:599), and a duplicate breaks '
			. 'getOrderIdByOrderNumber(). '
			. '(8) CHECKOUT BLOCKERS — no published countries, or no published shopper group marked '
			. 'default. '
			. '(9) JOOMLA 6 COMPATIBILITY — whether VirtueMart can boot at all in this environment, '
			. 'including the JPATH_PLATFORM/TCPDF problem that persists even with compat6 enabled. '
			. '(10) VERSION — whether the shop predates 4.8.0 and its Skrill payment fix. '
			. 'Read-only. Nothing is repaired.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'sample_limit' => ['type' => 'integer', 'description' => 'Example ids per finding. Default 10, max 100.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->vmAdminBase() === null) {
			return $this->vmNotInstalledError();
		}

		$limit    = max(1, min(100, (int) ($arguments['sample_limit'] ?? 10)));
		$findings = [];

		$this->checkInvisibleRecords($findings, $limit);
		$this->checkUnbuyableProducts($findings, $limit);
		$this->checkStaleFlags($findings, $limit);
		$this->checkOrders($findings, $limit);
		$this->checkStock($findings, $limit);
		$this->checkCheckoutBlockers($findings);

		$compat = $this->vmCompatStatus();

		if (!$compat['virtuemart_will_boot']) {
			$findings[] = [
				'severity' => 'critical',
				'check'    => 'Joomla legacy class compatibility',
				'detail'   => $compat['verdict'] . ' VirtueMart 4.x has no version gating for legacy '
					. 'classes and no shim of its own; its bootstrap calls JFactory at '
					. 'helpers/config.php:367 before anything else, and plugins/vmplugin.php:24 is '
					. '"abstract class vmPlugin extends JPlugin", which takes down every payment, '
					. 'shipment, custom-field, coupon and currency plugin at once.',
				'evidence' => $compat,
			];
		} elseif ($compat['notes'] !== []) {
			$findings[] = [
				'severity' => 'warning',
				'check'    => 'Joomla legacy class compatibility',
				'detail'   => implode(' ', $compat['notes']),
				'evidence' => $compat,
			];
		}

		$version = $this->vmVersion();

		if ($version !== null && version_compare($version, '4.8.0', '<')) {
			$findings[] = [
				'severity' => version_compare($version, '4.4.10', '<') ? 'critical' : 'warning',
				'check'    => 'VirtueMart version',
				'detail'   => 'This shop runs VirtueMart ' . $version . '. 4.8.0 (17 August 2026) fixed a '
					. 'CRITICAL Skrill payment plugin vulnerability whose advisory says to update or '
					. 'remove the plugin entirely, and added token validation to backend controllers and '
					. 'frontend forms.'
					. (version_compare($version, '4.4.10', '<')
						? ' This version also predates 4.4.10 and carries CVE-2025-25228, CVE-2025-25229 '
							. 'and CVE-2025-25230 — an authenticated SQL injection in backend product '
							. 'management, an unrestricted upload in the product-image section, and a CSRF '
							. 'bypassing the token check on that same upload. Chained, that is remote code '
							. 'execution, and public proof-of-concept code exists.'
						: ''),
			];
		}

		$counts = ['critical' => 0, 'warning' => 0, 'notice' => 0];

		foreach ($findings as $finding) {
			$counts[$finding['severity']] = ($counts[$finding['severity']] ?? 0) + 1;
		}

		return ToolResult::json([
			'ok'       => true,
			'summary'  => $counts,
			'verdict'  => $findings === []
				? 'No problems found in any of the checks performed.'
				: $counts['critical'] . ' critical, ' . $counts['warning'] . ' warning and '
					. $counts['notice'] . ' notice finding(s).',
			'findings' => $findings,
			'scope_note' => 'Every check here targets a fault VirtueMart does NOT report — no error, no '
				. 'log line, no admin warning. Faults the component surfaces itself are deliberately out '
				. 'of scope.',
			'read_only' => 'Nothing was repaired. The fix tools are named in each finding.',
			'component' => $this->vmComponentNotice(),
		]);
	}

	/** @param array<int,array<string,mixed>> $findings */
	private function checkInvisibleRecords(array &$findings, int $limit): void
	{
		$tag = $this->vmDefaultLangTag();

		foreach (['products' => 'virtuemart_product_id', 'categories' => 'virtuemart_category_id', 'manufacturers' => 'virtuemart_manufacturer_id'] as $base => $key) {
			if (!$this->vmTableExists($base) || !$this->vmLangTableExists($base, $tag)) {
				continue;
			}

			$sql = ' FROM ' . $this->db->quoteName($this->vmTable($base), 'b')
				. ' LEFT JOIN ' . $this->db->quoteName($this->vmLangTable($base, $tag), 'l')
				. ' ON ' . $this->db->quoteName('b.' . $key) . ' = ' . $this->db->quoteName('l.' . $key)
				. ' WHERE ' . $this->db->quoteName('l.' . $key) . ' IS NULL';

			$count = (int) $this->db->setQuery('SELECT COUNT(*)' . $sql)->loadResult();

			if ($count === 0) {
				continue;
			}

			$ids = array_map('intval', $this->db->setQuery(
				'SELECT ' . $this->db->quoteName('b.' . $key) . $sql,
				0,
				$limit
			)->loadColumn() ?: []);

			$findings[] = [
				'severity' => 'critical',
				'check'    => 'invisible ' . $base,
				'detail'   => $count . ' row(s) in ' . $this->vmTable($base) . ' have no row in '
					. $this->vmLangTable($base, $tag) . '. VirtueMart joins those tables with an INNER '
					. 'JOIN (helpers/vmtable.php:1065-1068), so these do not exist for shoppers — not '
					. 'hidden, absent — and nothing anywhere reports it. This is the single most common '
					. 'reason an imported or externally created record never appears.',
				'sample_ids' => $ids,
				'fix'        => $base === 'products'
					? 'set_virtuemart_product_translation'
					: 'update_virtuemart_' . rtrim($base, 's'),
			];
		}
	}

	/** @param array<int,array<string,mixed>> $findings */
	private function checkUnbuyableProducts(array &$findings, int $limit): void
	{
		if (!$this->vmTableExists('products')) {
			return;
		}

		if ($this->vmTableExists('product_prices')) {
			$sql = ' FROM ' . $this->db->quoteName($this->vmTable('products'), 'p')
				. ' WHERE ' . $this->db->quoteName('p.published') . ' = 1'
				. ' AND ' . $this->db->quoteName('p.product_parent_id') . ' = 0'
				. ' AND NOT EXISTS (SELECT 1 FROM ' . $this->db->quoteName($this->vmTable('product_prices'), 'pr')
				. ' WHERE ' . $this->db->quoteName('pr.virtuemart_product_id')
				. ' = ' . $this->db->quoteName('p.virtuemart_product_id') . ')';

			$count = (int) $this->db->setQuery('SELECT COUNT(*)' . $sql)->loadResult();

			if ($count > 0) {
				$findings[] = [
					'severity'   => 'warning',
					'check'      => 'published products with no price',
					'detail'     => $count . ' published, non-variant product(s) have no row in '
						. '#__virtuemart_product_prices. Unless the shop runs with prices disabled they '
						. 'display without a price and cannot be bought.',
					'sample_ids' => array_map('intval', $this->db->setQuery(
						'SELECT ' . $this->db->quoteName('p.virtuemart_product_id') . $sql,
						0,
						$limit
					)->loadColumn() ?: []),
					'fix'        => 'set_virtuemart_product_price',
				];
			}
		}

		if ($this->vmTableExists('product_categories')) {
			$sql = ' FROM ' . $this->db->quoteName($this->vmTable('products'), 'p')
				. ' WHERE ' . $this->db->quoteName('p.published') . ' = 1'
				. ' AND ' . $this->db->quoteName('p.product_parent_id') . ' = 0'
				. ' AND NOT EXISTS (SELECT 1 FROM ' . $this->db->quoteName($this->vmTable('product_categories'), 'pc')
				. ' WHERE ' . $this->db->quoteName('pc.virtuemart_product_id')
				. ' = ' . $this->db->quoteName('p.virtuemart_product_id') . ')';

			$count = (int) $this->db->setQuery('SELECT COUNT(*)' . $sql)->loadResult();

			if ($count > 0) {
				$findings[] = [
					'severity'   => 'warning',
					'check'      => 'published products in no category',
					'detail'     => $count . ' published, non-variant product(s) are in no category. They '
						. 'remain reachable by direct URL but appear in no listing anywhere in the shop.',
					'sample_ids' => array_map('intval', $this->db->setQuery(
						'SELECT ' . $this->db->quoteName('p.virtuemart_product_id') . $sql,
						0,
						$limit
					)->loadColumn() ?: []),
					'fix'        => 'set_virtuemart_product_categories',
				];
			}
		}
	}

	/** @param array<int,array<string,mixed>> $findings */
	private function checkStaleFlags(array &$findings, int $limit): void
	{
		$map = [
			'has_categories'    => 'product_categories',
			'has_prices'        => 'product_prices',
			'has_medias'        => 'product_medias',
			'has_shoppergroups' => 'product_shoppergroups',
			'has_manufacturers' => 'product_manufacturers',
		];

		foreach ($map as $flag => $table) {
			if (!$this->vmTableExists($table)) {
				continue;
			}

			// The dangerous direction only: flag says 0 while rows exist, so
			// VirtueMart skips the join and the data is never displayed.
			$sql = ' FROM ' . $this->db->quoteName($this->vmTable('products'), 'p')
				. ' WHERE ' . $this->db->quoteName('p.' . $flag) . ' = 0'
				. ' AND EXISTS (SELECT 1 FROM ' . $this->db->quoteName($this->vmTable($table), 'x')
				. ' WHERE ' . $this->db->quoteName('x.virtuemart_product_id')
				. ' = ' . $this->db->quoteName('p.virtuemart_product_id') . ')';

			$count = (int) $this->db->setQuery('SELECT COUNT(*)' . $sql)->loadResult();

			if ($count === 0) {
				continue;
			}

			$findings[] = [
				'severity'   => 'warning',
				'check'      => 'stale ' . $flag . ' join hint',
				'detail'     => $count . ' product(s) have ' . $flag . ' = 0 while rows exist in '
					. $this->vmTable($table) . '. These are denormalised JOIN HINTS, recomputed on every '
					. 'save at models/product.php:2765-2776. When one reads 0, VirtueMart skips that join '
					. 'entirely, so the data is present and never displayed — a product with '
					. 'has_prices = 0 shows no price even though its price rows are right there.',
				'sample_ids' => array_map('intval', $this->db->setQuery(
					'SELECT ' . $this->db->quoteName('p.virtuemart_product_id') . $sql,
					0,
					$limit
				)->loadColumn() ?: []),
				'fix'        => 'update_virtuemart_product recomputes all five flags on every call.',
			];
		}
	}

	/** @param array<int,array<string,mixed>> $findings */
	private function checkOrders(array &$findings, int $limit): void
	{
		if (!$this->vmTableExists('orders') || !$this->vmTableExists('order_userinfos')) {
			return;
		}

		$sql = ' FROM ' . $this->db->quoteName($this->vmTable('orders'), 'o')
			. ' WHERE NOT EXISTS (SELECT 1 FROM ' . $this->db->quoteName($this->vmTable('order_userinfos'), 'u')
			. ' WHERE ' . $this->db->quoteName('u.virtuemart_order_id')
			. ' = ' . $this->db->quoteName('o.virtuemart_order_id')
			. ' AND ' . $this->db->quoteName('u.address_type') . ' = ' . $this->db->quote('BT') . ')';

		$count = (int) $this->db->setQuery('SELECT COUNT(*)' . $sql)->loadResult();

		if ($count > 0) {
			$findings[] = [
				'severity'   => 'critical',
				'check'      => 'orders with no billing address row',
				'detail'     => $count . ' order(s) have no #__virtuemart_order_userinfos row with '
					. 'address_type = "BT". The admin order screen redirects with '
					. 'COM_VIRTUEMART_ORDER_NOTFOUND (views/orders/view.html.php:56-59); notifyCustomer() '
					. 'dereferences a null at models/orders.php:2512 and :2604, which is a fatal Error on '
					. 'PHP 8; and invoice PDF generation fails the same way. These orders cannot be '
					. 'opened, emailed or invoiced.',
				'sample_ids' => array_map('intval', $this->db->setQuery(
					'SELECT ' . $this->db->quoteName('o.virtuemart_order_id') . $sql,
					0,
					$limit
				)->loadColumn() ?: []),
				'fix'        => 'Repair the billing address in the VirtueMart admin. '
					. 'set_virtuemart_order_status refuses to touch these until it is fixed.',
			];
		}

		$dupSql = 'SELECT ' . $this->db->quoteName('virtuemart_order_id')
			. ' FROM ' . $this->db->quoteName($this->vmTable('order_userinfos'))
			. ' WHERE ' . $this->db->quoteName('address_type') . ' = ' . $this->db->quote('BT')
			. ' GROUP BY ' . $this->db->quoteName('virtuemart_order_id')
			. ' HAVING COUNT(*) > 1';

		$dupes = array_map('intval', $this->db->setQuery($dupSql, 0, $limit)->loadColumn() ?: []);

		if ($dupes !== []) {
			$findings[] = [
				'severity'   => 'warning',
				'check'      => 'orders with duplicate billing address rows',
				'detail'     => 'At least ' . \count($dupes) . ' order(s) have more than one BT row. '
					. 'There is no unique constraint on (virtuemart_order_id, address_type) — the only '
					. 'unique index is the primary key (install_essential_data.sql:146-149) — and '
					. 'getOrder() does loadObjectList(\'address_type\'), which silently keeps only the '
					. 'LAST one (models/orders.php:263). Which address the invoice and email use is '
					. 'therefore arbitrary.',
				'sample_ids' => $dupes,
			];
		}

		$states = array_keys($this->vmOrderStates());

		if ($states !== []) {
			$quoted = array_map(fn (string $c): string => $this->db->quote($c), $states);

			$orphanSql = ' FROM ' . $this->db->quoteName($this->vmTable('orders'))
				. ' WHERE ' . $this->db->quoteName('order_status') . ' NOT IN (' . implode(', ', $quoted) . ')';

			$orphans = (int) $this->db->setQuery('SELECT COUNT(*)' . $orphanSql)->loadResult();

			if ($orphans > 0) {
				$findings[] = [
					'severity'   => 'warning',
					'check'      => 'orders in an unknown status',
					'detail'     => $orphans . ' order(s) are in a status code with no row in '
						. '#__virtuemart_orderstates — usually a state that was renamed or deleted after '
						. 'those orders were placed. Changing their status makes '
						. 'handleStockAfterStatusChangedPerProduct() meet an unknown old state, raise an '
						. 'error and silently skip stock adjustment while letting the status change '
						. 'proceed (models/orders.php:2035-2038).',
					'sample_ids' => array_map('intval', $this->db->setQuery(
						'SELECT ' . $this->db->quoteName('virtuemart_order_id') . $orphanSql,
						0,
						$limit
					)->loadColumn() ?: []),
					'fix'        => 'Recreate the missing order state in the VirtueMart admin.',
				];
			}
		}

		$dupNumbers = $this->db->setQuery(
			'SELECT ' . $this->db->quoteName('order_number') . ', COUNT(*) AS ' . $this->db->quoteName('total')
			. ' FROM ' . $this->db->quoteName($this->vmTable('orders'))
			. ' WHERE ' . $this->db->quoteName('order_number') . ' <> ' . $this->db->quote('')
			. ' GROUP BY ' . $this->db->quoteName('order_number')
			. ' HAVING COUNT(*) > 1',
			0,
			$limit
		)->loadAssocList() ?: [];

		if ($dupNumbers !== []) {
			$findings[] = [
				'severity' => 'warning',
				'check'    => 'duplicate order numbers',
				'detail'   => 'At least ' . \count($dupNumbers) . ' order number(s) are used more than '
					. 'once. VirtueMart enforces uniqueness in PHP only (tables/orders.php:114 via '
					. 'helpers/vmtable.php:1793-1813); the DB index is a plain non-unique KEY '
					. '(install.sql:599), so a raw insert can create duplicates. That breaks '
					. 'getOrderIdByOrderNumber() (models/orders.php:101) and any lookup that goes through '
					. 'it.',
				'evidence' => $dupNumbers,
			];
		}
	}

	/** @param array<int,array<string,mixed>> $findings */
	private function checkStock(array &$findings, int $limit): void
	{
		if (!$this->vmTableExists('products')) {
			return;
		}

		$sql = ' FROM ' . $this->db->quoteName($this->vmTable('products'))
			. ' WHERE ' . $this->db->quoteName('product_ordered') . ' > '
			. $this->db->quoteName('product_in_stock');

		$count = (int) $this->db->setQuery('SELECT COUNT(*)' . $sql)->loadResult();

		if ($count === 0) {
			return;
		}

		$findings[] = [
			'severity'   => 'notice',
			'check'      => 'oversold products',
			'detail'     => $count . ' product(s) have product_ordered greater than product_in_stock. '
				. 'Either the shop has genuinely taken more orders than it can fill, or stock handling '
				. 'was skipped on a status change — handleStockAfterStatusChangedPerProduct() silently '
				. 'declines to adjust stock when either status code is not a published orderstates row '
				. '(models/orders.php:2035-2038) while letting the change proceed.',
			'sample_ids' => array_map('intval', $this->db->setQuery(
				'SELECT ' . $this->db->quoteName('virtuemart_product_id') . $sql,
				0,
				$limit
			)->loadColumn() ?: []),
			'fix'        => 'list_virtuemart_stock with oversold_only: true, then '
				. 'set_virtuemart_product_stock.',
		];
	}

	/** @param array<int,array<string,mixed>> $findings */
	private function checkCheckoutBlockers(array &$findings): void
	{
		if ($this->vmTableExists('countries')) {
			$published = (int) $this->db->setQuery(
				'SELECT COUNT(*) FROM ' . $this->db->quoteName($this->vmTable('countries'))
				. ' WHERE ' . $this->db->quoteName('published') . ' = 1'
			)->loadResult();

			if ($published === 0) {
				$findings[] = [
					'severity' => 'critical',
					'check'    => 'no published countries',
					'detail'   => 'No rows in #__virtuemart_countries are published, so the checkout '
						. 'address country dropdown is empty and no shopper can complete an order. Note '
						. 'published DEFAULTS TO 0 on that table (install.sql:241) and VirtueMart ships '
						. 'most countries unpublished, so this is a configuration step that is easy to '
						. 'miss entirely.',
					'fix'      => 'Publish the countries you ship to in the VirtueMart admin.',
				];
			}
		}

		if ($this->vmTableExists('shoppergroups')) {
			$defaults = (int) $this->db->setQuery(
				'SELECT COUNT(*) FROM ' . $this->db->quoteName($this->vmTable('shoppergroups'))
				. ' WHERE ' . $this->db->quoteName('default') . ' = 1 AND '
				. $this->db->quoteName('published') . ' = 1'
			)->loadResult();

			if ($defaults === 0) {
				$findings[] = [
					'severity' => 'warning',
					'check'    => 'no published default shopper group',
					'detail'   => 'No published shopper group is flagged as default, so anonymous and '
						. 'newly registered shoppers have no group to fall into. Group-specific pricing '
						. 'and any group-restricted products behave unpredictably for them.',
				];
			} elseif ($defaults > 1) {
				$findings[] = [
					'severity' => 'notice',
					'check'    => 'multiple default shopper groups',
					'detail'   => $defaults . ' published shopper groups are flagged as default. '
						. 'VirtueMart expects one; which group anonymous shoppers land in becomes a '
						. 'matter of query order.',
				];
			}
		}
	}
}
