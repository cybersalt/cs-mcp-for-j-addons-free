<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Customers;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Shopper groups, with the counts that show what each one actually controls.
 *
 * A shopper group is three things at once: a price segment, a visibility
 * restriction and a payment/shipment filter. The counts make that concrete.
 */
final class ListShoppergroupsTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'list_virtuemart_shoppergroups'; }

	public function getDescription(): string
	{
		return 'List shopper groups from #__virtuemart_shoppergroups, with the counts that show what each '
			. 'one actually controls: how many customers are in it, how many products are RESTRICTED to '
			. 'it, how many price rows are specific to it, and how many payment and shipment methods are '
			. 'limited to it. '
			. 'A shopper group does three different jobs at once and it is easy to reason about only one '
			. 'of them. It segments PRICING (#__virtuemart_product_prices rows carry a '
			. 'virtuemart_shoppergroup_id, where 0 means everyone). It restricts VISIBILITY '
			. '(#__virtuemart_product_shoppergroups — a product with NO rows there is visible to '
			. 'everyone; adding one hides it from everybody outside the group, including guests). And it '
			. 'filters CHECKOUT (#__virtuemart_paymentmethod_shoppergroups and '
			. '#__virtuemart_shipmentmethod_shoppergroups). '
			. 'The `default` column marks the group anonymous or newly registered shoppers fall into. '
			. 'sgrp_additional marks a group that is added on top of a shopper\'s main group rather than '
			. 'replacing it. '
			. 'price_display is a blob holding a serialised per-group price display configuration, only '
			. 'meaningful when custom_price_display is 1. Its size is reported rather than its contents; '
			. 'it is VirtueMart-internal and not useful raw. '
			. 'This is read-only. Group membership is writable through '
			. 'set_virtuemart_customer_shoppergroups.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'published' => ['type' => 'boolean'],
				'limit'     => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'    => ['type' => 'integer'],
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

		if (!$this->vmTableExists('shoppergroups')) {
			return $this->vmMissingTableError('shoppergroups');
		}

		$limit  = $this->vmLimit($arguments);
		$offset = $this->vmOffset($arguments);
		$table  = $this->db->quoteName($this->vmTable('shoppergroups'));

		$whereSql = '';

		if (\array_key_exists('published', $arguments)) {
			$whereSql = ' WHERE ' . $this->db->quoteName('published') . ' = '
				. ((bool) $arguments['published'] ? 1 : 0);
		}

		$total = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $table . $whereSql)->loadResult();

		$rows = $this->db->setQuery(
			'SELECT * FROM ' . $table . $whereSql
			. ' ORDER BY ' . $this->db->quoteName('ordering') . ' ASC, '
			. $this->db->quoteName('virtuemart_shoppergroup_id') . ' ASC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$ids = array_map(static fn (array $r): int => (int) $r['virtuemart_shoppergroup_id'], $rows);

		$customers = $this->countBy('vmuser_shoppergroups', $ids);
		$products  = $this->countBy('product_shoppergroups', $ids);
		$prices    = $this->countBy('product_prices', $ids);
		$payments  = $this->countBy('paymentmethod_shoppergroups', $ids);
		$shipments = $this->countBy('shipmentmethod_shoppergroups', $ids);

		$out      = [];
		$defaults = 0;

		foreach ($rows as $row) {
			$id = (int) $row['virtuemart_shoppergroup_id'];

			$entry = [
				'virtuemart_shoppergroup_id' => $id,
				'shopper_group_name'         => (string) $row['shopper_group_name'],
				'shopper_group_desc'         => (string) $row['shopper_group_desc'],
				'default'                    => (int) $row['default'] === 1,
				'sgrp_additional'            => (int) $row['sgrp_additional'] === 1,
				'custom_price_display'       => (int) $row['custom_price_display'] === 1,
				'price_display_bytes'        => \strlen((string) ($row['price_display'] ?? '')),
				'published'                  => (int) $row['published'] === 1,
				'shared'                     => (int) $row['shared'] === 1,
				'ordering'                   => (int) $row['ordering'],
				'usage'                      => [
					'customers'          => $customers[$id] ?? 0,
					'products_restricted_to_it' => $products[$id] ?? 0,
					'price_rows'         => $prices[$id] ?? 0,
					'payment_methods'    => $payments[$id] ?? 0,
					'shipment_methods'   => $shipments[$id] ?? 0,
				],
			];

			if ((int) $row['default'] === 1) {
				$defaults++;
				$entry['default_note'] = 'This is the default group — where anonymous and newly '
					. 'registered shoppers land.';
			}

			if (($products[$id] ?? 0) > 0) {
				$entry['visibility_note'] = ($products[$id]) . ' product(s) are RESTRICTED to this group, '
					. 'meaning every shopper outside it cannot see them anywhere in the shop.';
			}

			if ((int) $row['sgrp_additional'] === 1) {
				$entry['additional_note'] = 'sgrp_additional is set, so this group is added on top of a '
					. 'shopper\'s main group rather than replacing it.';
			}

			$out[] = $entry;
		}

		$response = [
			'ok'            => true,
			'total'         => $total,
			'limit'         => $limit,
			'offset'        => $offset,
			'showing'       => \count($out),
			'shoppergroups' => $out,
			'roles_note'    => 'A shopper group segments pricing, restricts product visibility and '
				. 'filters payment and shipment methods — three jobs at once. The counts above show which '
				. 'of them each group is actually doing on this shop.',
			'visibility_note' => 'In #__virtuemart_product_shoppergroups an EMPTY set means visible to '
				. 'everyone. Adding a group is a restriction, not a grant.',
		];

		if ($defaults > 1) {
			$response['warning'] = $defaults . ' groups are flagged as default. VirtueMart expects one, '
				. 'and which of them anonymous shoppers land in becomes a matter of query order.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/**
	 * @param  array<int,int> $ids
	 * @return array<int,int>
	 */
	private function countBy(string $table, array $ids): array
	{
		if ($ids === [] || !$this->vmTableExists($table)) {
			return [];
		}

		if (!\in_array('virtuemart_shoppergroup_id', $this->vmColumns($table), true)) {
			return [];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('virtuemart_shoppergroup_id'),
					'COUNT(*) AS ' . $this->db->quoteName('total'),
				])
				->from($this->db->quoteName($this->vmTable($table)))
				->whereIn($this->db->quoteName('virtuemart_shoppergroup_id'), $ids)
				->group($this->db->quoteName('virtuemart_shoppergroup_id'))
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$out[(int) $row['virtuemart_shoppergroup_id']] = (int) $row['total'];
		}

		return $out;
	}
}
