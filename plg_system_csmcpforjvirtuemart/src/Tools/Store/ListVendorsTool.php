<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Store;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * List vendors, and say whether this shop is single- or multi-vendor.
 *
 * That distinction changes the meaning of `virtuemart_vendor_id` everywhere
 * else. On `multix = 'none'` — the default — `VmTable::setCheckVendorId()`
 * (`helpers/vmtable.php:1526-1603`) FORCES the column to 1 on every store,
 * whatever value was passed, so a vendor id in any other tool's output is
 * decoration. On a multi-vendor shop it is ownership, and a save that changes
 * it can be refused by the ACL mid-write, leaving the base row written and the
 * satellites not.
 */
final class ListVendorsTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'list_virtuemart_vendors'; }

	public function getDescription(): string
	{
		return 'List vendors from #__virtuemart_vendors, joined to the language satellite table, and '
			. 'report whether this shop is running in single- or multi-vendor mode. '
			. 'THAT MODE CHANGES WHAT virtuemart_vendor_id MEANS EVERYWHERE ELSE. On multix = "none" — '
			. 'the default — VmTable::setCheckVendorId() FORCES virtuemart_vendor_id to 1 on every store, '
			. 'whatever value was passed (helpers/vmtable.php:1526-1603), so the column is decoration. On '
			. 'a multi-vendor shop it is ownership: a non-admin storing a row belonging to another vendor '
			. 'is blocked with "Blocked storing of the object, you are not the owner" and check() returns '
			. 'false (:1567-1573) — except for orders, order_items and carts, which are exempted at '
			. ':1570. No tool in this add-on offers to set a vendor id, for exactly that reason. '
			. 'Vendors are translatable: vendor_name is on the base row but the store description, terms '
			. 'of service, legal information and the invoice letter CSS/header/footer are all in '
			. '#__virtuemart_vendors_<langsuffix> (tables/vendors.php:60). '
			. 'vendor_params is VirtueMart\'s pipe-parameter blob and is returned as a byte count rather '
			. 'than raw content — it holds store configuration a caller has no safe way to edit. '
			. 'Also reported: each vendor\'s currency, accepted currencies, product and order counts, and '
			. 'whether a #__virtuemart_vendor_users row links it to a Joomla account.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'language' => ['type' => 'string', 'description' => 'Language tag whose satellite table to join. Defaults to the shop language.'],
				'limit'    => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'   => ['type' => 'integer'],
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

		if (!$this->vmTableExists('vendors')) {
			return $this->vmMissingTableError('vendors');
		}

		$tag     = $this->vmResolveLangTag($arguments);

		if ($tag instanceof ToolResult) {
			return $tag;
		}

		$hasLang = $this->vmLangTableExists('vendors', $tag);
		$limit   = $this->vmLimit($arguments);
		$offset  = $this->vmOffset($arguments);

		$total = (int) $this->db->setQuery(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName($this->vmTable('vendors'))
		)->loadResult();

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->vmTable('vendors')))
				->order($this->db->quoteName('virtuemart_vendor_id') . ' ASC'),
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$multix = (string) $this->vmConfigGet('multix', 'none');

		$out = [];

		foreach ($rows as $row) {
			$id = (int) $row['virtuemart_vendor_id'];

			$entry = [
				'virtuemart_vendor_id'       => $id,
				'vendor_name'                => (string) $row['vendor_name'],
				'vendor_currency'            => (int) $row['vendor_currency'],
				'vendor_accepted_currencies' => (string) $row['vendor_accepted_currencies'],
				'vendor_params_bytes'        => \strlen((string) $row['vendor_params']),
				'created_on'                 => $row['created_on'],
				'modified_on'                => $row['modified_on'],
				'product_count'              => $this->countIn('products', 'virtuemart_vendor_id', $id),
				'order_count'                => $this->countIn('orders', 'virtuemart_vendor_id', $id),
			];

			if ($hasLang) {
				$langRow = $this->db->setQuery(
					$this->db->getQuery(true)
						->select('*')
						->from($this->db->quoteName($this->vmLangTable('vendors', $tag)))
						->where($this->db->quoteName('virtuemart_vendor_id') . ' = ' . $id)
				)->loadAssoc();

				$entry['translation'] = \is_array($langRow) ? $langRow : null;

				if (!\is_array($langRow)) {
					$entry['translation_warning'] = 'No row in ' . $this->vmLangTable('vendors', $tag)
						. '. The vendor\'s store description, terms of service and invoice letter '
						. 'templates are all in that table, so they are empty for ' . $tag . ' shoppers.';
				}
			}

			if ($this->vmTableExists('vendor_users')) {
				$entry['linked_joomla_user_ids'] = array_map('intval', $this->db->setQuery(
					$this->db->getQuery(true)
						->select($this->db->quoteName('virtuemart_user_id'))
						->from($this->db->quoteName($this->vmTable('vendor_users')))
						->where($this->db->quoteName('virtuemart_vendor_id') . ' = ' . $id)
				)->loadColumn() ?: []);
			}

			$out[] = $entry;
		}

		$response = [
			'ok'      => true,
			'total'   => $total,
			'limit'   => $limit,
			'offset'  => $offset,
			'showing' => \count($out),
			'vendors' => $out,
			'mode'    => [
				'multix'        => $multix,
				'multi_vendor'  => $multix !== 'none',
				'meaning'       => $multix === 'none'
					? 'Single-vendor. VirtueMart FORCES virtuemart_vendor_id to 1 on every store '
						. '(helpers/vmtable.php:1526-1603), so that column is decoration everywhere in the '
						. 'shop and no tool here offers to set it.'
					: 'Multi-vendor (multix = "' . $multix . '"). virtuemart_vendor_id is ownership. A '
						. 'non-admin storing a row owned by another vendor is refused mid-save '
						. '(helpers/vmtable.php:1567-1573), which can leave a base row written and its '
						. 'satellites not. No tool here offers to change it.',
			],
			'language' => ['tag' => $tag, 'table_exists' => $hasLang],
		];

		$defaultVendorOrders = $this->countIn('orders', 'virtuemart_vendor_id', 0);

		if ($defaultVendorOrders > 0) {
			$response['vendor_zero_orders'] = $defaultVendorOrders;
			$response['vendor_zero_note'] = $defaultVendorOrders . ' order(s) have '
				. 'virtuemart_vendor_id = 0, which matches no vendor row. The defaults are inconsistent '
				. 'in VirtueMart: products default to vendor 1 (install.sql:812) but orders default to 0 '
				. '(install.sql:552), so a raw INSERT that omits the column produces exactly this. Vendor '
				. 'email and invoice numbering both look the vendor up by that id.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	private function countIn(string $table, string $column, int $value): int
	{
		if (!$this->vmTableExists($table) || !\in_array($column, $this->vmColumns($table), true)) {
			return 0;
		}

		return (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName($this->vmTable($table)))
				->where($this->db->quoteName($column) . ' = ' . $value)
		)->loadResult();
	}
}
