<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Customers;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Change which shopper groups a customer belongs to.
 *
 * The only customer field this add-on writes. Everything else about a customer
 * lives either in Joomla's own user table (which the core MCP tools already
 * handle) or in `#__virtuemart_userinfos`, whose column set is defined by the
 * userfields screen — and that screen is an arbitrary-`ALTER TABLE` primitive
 * (`helpers/vmtable.php:2712`) we deliberately do not go near.
 */
final class SetCustomerShoppergroupsTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'set_virtuemart_customer_shoppergroups'; }

	public function getDescription(): string
	{
		return 'Set which shopper groups a customer belongs to, writing '
			. '#__virtuemart_vmuser_shoppergroups. Requires user_id (the Joomla user id, which is the '
			. 'same value as virtuemart_user_id) and one of shoppergroup_ids (replace the whole set), '
			. 'add, or remove. '
			. 'THIS CHANGES WHAT THE CUSTOMER PAYS AND WHAT THEY CAN SEE. Shopper groups drive '
			. 'per-group price rows in #__virtuemart_product_prices, product visibility restrictions in '
			. '#__virtuemart_product_shoppergroups, and which payment and shipment methods appear at '
			. 'checkout. Moving someone between groups can change every price they see and can hide or '
			. 'reveal whole sections of the catalogue. The response reports how many price rows and '
			. 'restricted products each affected group carries, so the blast radius is visible. '
			. 'Group ids are validated before anything is written — VirtueMart declares no foreign keys, '
			. 'so an invalid id would insert cleanly and simply never match. '
			. 'Existing ORDERS are not affected. #__virtuemart_orders stores the shopper\'s groups at the '
			. 'time of purchase in its own user_shoppergroups column, so historical pricing stays '
			. 'correct. '
			. 'This is the only customer field this add-on writes. Everything else lives either in '
			. 'Joomla\'s user table — use the core MCP user tools — or in #__virtuemart_userinfos, whose '
			. 'columns are defined by VirtueMart\'s userfields screen. That screen issues real ALTER '
			. 'TABLE statements (helpers/vmtable.php:2712, guarded only by an ACL check), which is an '
			. 'arbitrary-DDL primitive and is not something we expose over MCP.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'user_id'          => ['type' => 'integer', 'description' => 'Joomla user id (= virtuemart_user_id). Required.'],
				'shoppergroup_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Replace the whole set with these. An empty array removes the customer from every group.'],
				'add'              => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Add these to the existing set.'],
				'remove'           => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Remove these from the existing set.'],
			],
			'required' => ['user_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->vmAdminBase() === null) {
			return $this->vmNotInstalledError();
		}

		foreach (['vmuser_shoppergroups', 'shoppergroups'] as $table) {
			if (!$this->vmTableExists($table)) {
				return $this->vmMissingTableError($table);
			}
		}

		$userId = $this->requirePositiveInt($arguments, 'user_id');

		$vmuserExists = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName($this->vmTable('vmusers')))
				->where($this->db->quoteName('virtuemart_user_id') . ' = ' . $userId)
		)->loadResult();

		if ($vmuserExists === 0) {
			$joomlaExists = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select('COUNT(*)')
					->from($this->db->quoteName('#__users'))
					->where($this->db->quoteName('id') . ' = ' . $userId)
			)->loadResult();

			return ToolResult::json([
				'ok'    => false,
				'error' => $joomlaExists > 0
					? 'Joomla user ' . $userId . ' exists but has no #__virtuemart_vmusers row, so '
						. 'VirtueMart does not yet consider them a shop customer. Shopper-group rows for a '
						. 'user with no vmuser row are not read anywhere. Refusing; nothing was written.'
					: 'No user with id ' . $userId . ' in either #__users or #__virtuemart_vmusers. '
						. 'Nothing was written.',
				'resolution' => $joomlaExists > 0
					? 'The vmuser row is created on the customer\'s first checkout, or when their shopper '
						. 'form is saved once in the VirtueMart admin.'
					: null,
			], true);
		}

		$current = $this->vmXrefIds('vmuser_shoppergroups', 'virtuemart_user_id', $userId, 'virtuemart_shoppergroup_id');

		$hasReplace = \array_key_exists('shoppergroup_ids', $arguments);
		$hasAdd     = \array_key_exists('add', $arguments);
		$hasRemove  = \array_key_exists('remove', $arguments);

		if (!$hasReplace && !$hasAdd && !$hasRemove) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Supply one of shoppergroup_ids, add or remove.',
				'current_shoppergroup_ids' => $current,
			], true);
		}

		if ($hasReplace && ($hasAdd || $hasRemove)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'shoppergroup_ids replaces the whole set and cannot be combined with add or '
					. 'remove. Nothing was written.',
			], true);
		}

		$target = $hasReplace ? $this->cleanIds((array) $arguments['shoppergroup_ids']) : $current;

		if ($hasAdd) {
			foreach ($this->cleanIds((array) $arguments['add']) as $id) {
				if (!\in_array($id, $target, true)) {
					$target[] = $id;
				}
			}
		}

		if ($hasRemove) {
			$target = array_values(array_diff($target, $this->cleanIds((array) $arguments['remove'])));
		}

		if ($target !== []) {
			$known = array_map('intval', $this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName('virtuemart_shoppergroup_id'))
					->from($this->db->quoteName($this->vmTable('shoppergroups')))
					->whereIn($this->db->quoteName('virtuemart_shoppergroup_id'), $target)
			)->loadColumn() ?: []);

			$unknown = array_values(array_diff($target, $known));

			if ($unknown !== []) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'These shopper group ids do not exist: ' . implode(', ', $unknown)
						. '. VirtueMart declares no foreign keys, so they would have inserted cleanly and '
						. 'never matched anything. Refusing; nothing was written.',
				], true);
			}
		}

		if ($target === $current) {
			return ToolResult::json([
				'ok'               => true,
				'changed'          => false,
				'shoppergroup_ids' => $current,
				'note'             => 'The resulting set is identical to the current one. Nothing was '
					. 'written.',
			]);
		}

		$this->vmReplaceXref('vmuser_shoppergroups', 'virtuemart_user_id', $userId, 'virtuemart_shoppergroup_id', $target);

		$affected = array_values(array_unique(array_merge(
			array_diff($current, $target),
			array_diff($target, $current)
		)));

		$response = [
			'ok'                        => true,
			'virtuemart_user_id'        => $userId,
			'previous_shoppergroup_ids' => $current,
			'shoppergroup_ids'          => $target,
			'impact'                    => $this->impact($affected),
			'orders_note'               => 'Existing orders are unaffected. #__virtuemart_orders stores '
				. 'the shopper\'s groups at purchase time in its own user_shoppergroups column, so '
				. 'historical pricing stays correct.',
			'pricing_note'              => 'This customer may now see different prices and a different '
				. 'set of products. Shopper groups drive per-group price rows, product visibility '
				. 'restrictions and the payment and shipment methods offered at checkout.',
		];

		if ($target === []) {
			$response['warning'] = 'This customer is now in NO shopper group. They fall back to the '
				. 'shop\'s default group behaviour, which usually means the general (shopper group 0) '
				. 'prices and no group-restricted products.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/**
	 * How much each added or removed group actually controls.
	 *
	 * @param  array<int,int> $groupIds
	 * @return array<int,array<string,mixed>>
	 */
	private function impact(array $groupIds): array
	{
		$out = [];

		foreach ($groupIds as $groupId) {
			$out[] = [
				'virtuemart_shoppergroup_id' => $groupId,
				'price_rows'                 => $this->countIn('product_prices', $groupId),
				'products_restricted_to_it'  => $this->countIn('product_shoppergroups', $groupId),
				'payment_methods'            => $this->countIn('paymentmethod_shoppergroups', $groupId),
				'shipment_methods'           => $this->countIn('shipmentmethod_shoppergroups', $groupId),
			];
		}

		return $out;
	}

	private function countIn(string $table, int $groupId): int
	{
		if (!$this->vmTableExists($table)
			|| !\in_array('virtuemart_shoppergroup_id', $this->vmColumns($table), true)) {
			return 0;
		}

		return (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName($this->vmTable($table)))
				->where($this->db->quoteName('virtuemart_shoppergroup_id') . ' = ' . $groupId)
		)->loadResult();
	}

	/** @return array<int,int> */
	private function cleanIds(array $raw): array
	{
		$out = [];

		foreach ($raw as $value) {
			$id = (int) $value;

			if ($id > 0 && !\in_array($id, $out, true)) {
				$out[] = $id;
			}
		}

		return $out;
	}
}
