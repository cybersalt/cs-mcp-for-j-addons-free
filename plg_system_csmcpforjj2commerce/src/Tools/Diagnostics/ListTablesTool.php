<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

final class ListTablesTool extends AbstractTool
{
	use J2commerceBootTrait;

	/** The 58 tables `sql/install.mysql.utf8.sql` creates in 6.6.5. */
	private const INSTALL_TABLES = [
		'addresses', 'carts', 'cartitems', 'countries', 'coupons', 'currencies', 'customfields',
		'emailtemplates', 'emailtype_tags', 'emailtype_contexts', 'filtergroups', 'filters',
		'geocode_cache', 'geozones', 'geozonerules', 'invoicetemplates', 'lengths', 'manufacturers',
		'metafields', 'options', 'optionvalues', 'orderdiscounts', 'orderfees', 'orderdownloads',
		'orderhistories', 'orderinfos', 'orderitemattributes', 'orderitems', 'orders',
		'ordertransactions', 'ordershippings', 'orderstatuses', 'ordertaxes', 'paymentprofiles',
		'productfiles', 'product_filters', 'productimages', 'productquantities', 'products',
		'product_options', 'product_optionvalues', 'product_prices', 'productprice_index',
		'product_variant_optionvalues', 'queues', 'queue_logs', 'shippingmethods', 'shippingrates',
		'taxprofiles', 'taxrates', 'taxrules', 'uploads', 'variants', 'vendors', 'vouchers',
		'voucheradjustments', 'weights', 'zones',
	];

	/**
	 * Tables 6.6.5 code references that no SQL in the package creates. Their
	 * owners are apps or older versions; their absence is normal.
	 */
	private const REFERENCED_NOT_INSTALLED = [
		'email_log'               => 'EmailHelper writes send logs here (src/Helper/EmailHelper.php:2543-2566); nothing creates it, so email logging fails silently.',
		'migrator_idmap'          => 'Created by the separate J2Store-to-J2Commerce migrator; residue after a migration.',
		'builder_projects'        => 'Used by the layout builder (BuilderProjectTable); not created by the core install SQL.',
		'storeprofiles'           => 'Referenced in 6.6.5 code but not created by the core install SQL.',
		'configurations'          => 'The J2Store-era configuration table name, still referenced in code. J2Commerce 6 stores its configuration in #__extensions.params.',
		'subscriptions'           => 'Referenced in 6.6.5 code but not created by the core install SQL; orders.subscription_id points at it.',
		'appmarketplace_profiles' => 'Referenced in 6.6.5 code but not created by the core install SQL.',
	];

	public function getName(): string { return 'list_j2commerce_tables'; }

	public function getDescription(): string
	{
		return 'List every #__j2commerce_* table on this site with its row count, and say which ones '
			. 'query_j2commerce_table will read. J2Commerce 6.6.5 installs 58 tables. Five are refused by the '
			. 'generic reader: carts and cartitems (live session-bound shopper state), paymentprofiles '
			. '(stored-card references), geocode_cache (customer addresses) and uploads (customer files '
			. 'mapped to orders). Tables present on this database but not in the 6.6.5 install SQL are '
			. 'flagged — usually #__j2commerce_migrator_idmap left by the J2Store migrator. Legacy '
			. '#__j2store_* tables are reported separately and are never read by these tools. Read-only.';
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

		$prefix = $this->db->getPrefix();
		$tables  = [];
		$legacy  = [];
		$present = [];

		foreach ($this->j2AllTables() as $name) {
			if (str_starts_with($name, $prefix . 'j2store_')) {
				$legacy[] = '#__' . substr($name, \strlen($prefix));
				continue;
			}

			if (!str_starts_with($name, $prefix . 'j2commerce_')) {
				continue;
			}

			$short = substr($name, \strlen($prefix));
			$rows  = null;

			try {
				$rows = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->db->quoteName($name))->loadResult();
			} catch (\Throwable $e) {
				// Leave rows null; the table may be mid-migration.
			}

			$bare  = substr($short, \strlen('j2commerce_'));
			$entry = [
				'table'    => '#__' . $short,
				'rows'     => $rows,
				'readable' => !\in_array($short, self::J2_READ_DENYLIST, true),
			];

			if (!\in_array($bare, self::INSTALL_TABLES, true)) {
				$entry['not_in_core_install'] = self::REFERENCED_NOT_INSTALLED[$bare]
					?? 'Not created by the J2Commerce 6.6.5 core install SQL — most likely added by an app.';
			}

			$tables[] = $entry;
			$present[] = $bare;
		}

		$missing = array_values(array_diff(self::INSTALL_TABLES, $present));

		$response = [
			'ok'     => true,
			'count'  => \count($tables),
			'tables' => $tables,
		];

		if ($missing !== []) {
			$response['missing_core_tables'] = array_map(static fn (string $t): string => '#__j2commerce_' . $t, $missing);
			$response['missing_note'] = 'The 6.6.5 install SQL creates these and they are absent. Either this is '
				. 'an older J2Commerce, or an install/update did not finish. Reinstall pkg_j2commerce.';
		}

		if ($legacy !== []) {
			$response['legacy_j2store_tables'] = $legacy;
			$response['legacy_note'] = 'J2Store 4 tables. J2Commerce 6 does not read or migrate them; the '
				. 'separate J2Commerce migrator copies their data. They are outside these tools\' scope.';
		}

		return ToolResult::json($response);
	}
}
