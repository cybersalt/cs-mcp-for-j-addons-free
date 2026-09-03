<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Customers;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * List shop customers: `#__virtuemart_vmusers` joined to Joomla's `#__users`
 * and to the billing address row.
 *
 * `virtuemart_user_id` IS the Joomla `#__users.id` rather than an independent
 * sequence, and nothing cascades — deleting a Joomla user leaves the vmuser row
 * behind. Orphans are reported.
 */
final class ListCustomersTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'list_virtuemart_customers'; }

	public function getDescription(): string
	{
		return 'List VirtueMart customers from #__virtuemart_vmusers, joined to Joomla\'s #__users and to '
			. 'the customer\'s billing (BT) address row in #__virtuemart_userinfos. '
			. 'virtuemart_user_id IS the Joomla #__users.id — not a separate sequence — and there is no '
			. 'cascade in either direction, because VirtueMart declares no foreign keys anywhere. '
			. 'Deleting a Joomla user therefore leaves the vmuser row behind, and those orphans are '
			. 'reported with joomla_user_missing: true. '
			. 'THIS RETURNS PERSONAL DATA: names, email addresses, phone numbers and postal addresses. '
			. 'Treat the response accordingly. '
			. 'Filters: search (on name, username, email), is_vendor, shoppergroup_id, has_orders. '
			. 'Supports limit (default 50, max 200) and offset. '
			. 'The address columns are read with SHOW COLUMNS at runtime rather than assumed. '
			. '#__virtuemart_userinfos is NOT in install.sql — it is created by '
			. 'install_essential_data.sql:78 and then reshaped by VirtueMart\'s userfields screen, which '
			. 'issues real ALTER TABLE statements (models/userfields.php:300). Deleting a userfield does '
			. 'not drop its column: VmTable::_modifyColumn() renames it to <name>_DELETED_<unixtime> '
			. '(helpers/vmtable.php:2748-2751), so a mature shop carries zombie columns. Those are '
			. 'filtered out here. '
			. 'A customer with no BT address row is normal for someone who registered but never ordered; '
			. 'it is only a problem on an ORDER, where a missing BT row breaks email and invoicing.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'search'          => ['type' => 'string', 'description' => 'Substring match on Joomla name, username or email.'],
				'is_vendor'       => ['type' => 'boolean', 'description' => 'Only vendor accounts (user_is_vendor = 1), or only non-vendors.'],
				'shoppergroup_id' => ['type' => 'integer', 'description' => 'Only customers in this shopper group.'],
				'has_orders'      => ['type' => 'boolean', 'description' => 'true for customers with at least one order, false for those with none.'],
				'limit'           => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'          => ['type' => 'integer'],
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

		if (!$this->vmTableExists('vmusers')) {
			return $this->vmMissingTableError('vmusers');
		}

		$limit  = $this->vmLimit($arguments);
		$offset = $this->vmOffset($arguments);

		$hasUserinfos = $this->vmTableExists('userinfos');
		$userColumns  = $hasUserinfos ? $this->vmColumns('userinfos') : [];

		$from = ' FROM ' . $this->db->quoteName($this->vmTable('vmusers'), 'v')
			. ' LEFT JOIN ' . $this->db->quoteName('#__users', 'j')
			. ' ON ' . $this->db->quoteName('v.virtuemart_user_id') . ' = ' . $this->db->quoteName('j.id');

		if ($hasUserinfos) {
			$from .= ' LEFT JOIN ' . $this->db->quoteName($this->vmTable('userinfos'), 'u')
				. ' ON ' . $this->db->quoteName('v.virtuemart_user_id')
				. ' = ' . $this->db->quoteName('u.virtuemart_user_id')
				. ' AND ' . $this->db->quoteName('u.address_type') . ' = ' . $this->db->quote('BT');
		}

		$where = [];

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$like    = $this->db->quote('%' . $this->db->escape($search, true) . '%', false);
			$where[] = '(' . implode(' OR ', [
				$this->db->quoteName('j.name') . ' LIKE ' . $like,
				$this->db->quoteName('j.username') . ' LIKE ' . $like,
				$this->db->quoteName('j.email') . ' LIKE ' . $like,
			]) . ')';
		}

		if (\array_key_exists('is_vendor', $arguments)) {
			$where[] = $this->db->quoteName('v.user_is_vendor') . ' = ' . ((bool) $arguments['is_vendor'] ? 1 : 0);
		}

		if (\array_key_exists('shoppergroup_id', $arguments) && $this->vmTableExists('vmuser_shoppergroups')) {
			$where[] = $this->db->quoteName('v.virtuemart_user_id') . ' IN (SELECT '
				. $this->db->quoteName('virtuemart_user_id') . ' FROM '
				. $this->db->quoteName($this->vmTable('vmuser_shoppergroups')) . ' WHERE '
				. $this->db->quoteName('virtuemart_shoppergroup_id') . ' = '
				. (int) $arguments['shoppergroup_id'] . ')';
		}

		if (\array_key_exists('has_orders', $arguments) && $this->vmTableExists('orders')) {
			$sub = '(SELECT DISTINCT ' . $this->db->quoteName('virtuemart_user_id') . ' FROM '
				. $this->db->quoteName($this->vmTable('orders')) . ')';

			$where[] = $this->db->quoteName('v.virtuemart_user_id')
				. ((bool) $arguments['has_orders'] ? ' IN ' : ' NOT IN ') . $sub;
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		$total = (int) $this->db->setQuery('SELECT COUNT(*)' . $from . $whereSql)->loadResult();

		$select = [
			$this->db->quoteName('v.virtuemart_user_id'),
			$this->db->quoteName('v.virtuemart_vendor_id'),
			$this->db->quoteName('v.user_is_vendor'),
			$this->db->quoteName('v.customer_number'),
			$this->db->quoteName('v.agreed'),
			$this->db->quoteName('v.created_on'),
			$this->db->quoteName('j.id', 'joomla_id'),
			$this->db->quoteName('j.name'),
			$this->db->quoteName('j.username'),
			$this->db->quoteName('j.email'),
			$this->db->quoteName('j.block'),
			$this->db->quoteName('j.lastvisitDate'),
		];

		if ($hasUserinfos) {
			foreach (['first_name', 'last_name', 'company', 'city', 'zip', 'phone_1', 'virtuemart_country_id'] as $column) {
				if (\in_array($column, $userColumns, true)) {
					$select[] = $this->db->quoteName('u.' . $column);
				}
			}
		}

		$rows = $this->db->setQuery(
			'SELECT ' . implode(', ', $select) . $from . $whereSql
			. ' ORDER BY ' . $this->db->quoteName('v.virtuemart_user_id') . ' DESC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$ids         = array_map(static fn (array $r): int => (int) $r['virtuemart_user_id'], $rows);
		$orderCounts = $this->orderCounts($ids);
		$groups      = $this->shopperGroups($ids);

		$out     = [];
		$orphans = 0;

		foreach ($rows as $row) {
			$id = (int) $row['virtuemart_user_id'];

			$entry = [
				'virtuemart_user_id' => $id,
				'joomla_user_id'     => $id,
				'name'               => $row['name'],
				'username'           => $row['username'],
				'email'              => $row['email'],
				'blocked'            => (int) ($row['block'] ?? 0) === 1,
				'last_visit'         => $row['lastvisitDate'] ?? null,
				'user_is_vendor'     => (int) $row['user_is_vendor'] === 1,
				'customer_number'    => $row['customer_number'],
				'agreed'             => (int) $row['agreed'] === 1,
				'created_on'         => $row['created_on'],
				'shoppergroup_ids'   => $groups[$id] ?? [],
				'order_count'        => $orderCounts[$id] ?? 0,
			];

			if ($hasUserinfos) {
				$entry['billing'] = [
					'first_name'            => $row['first_name'] ?? null,
					'last_name'             => $row['last_name'] ?? null,
					'company'               => $row['company'] ?? null,
					'city'                  => $row['city'] ?? null,
					'zip'                   => $row['zip'] ?? null,
					'phone_1'               => $row['phone_1'] ?? null,
					'virtuemart_country_id' => isset($row['virtuemart_country_id']) ? (int) $row['virtuemart_country_id'] : null,
				];
			}

			if (($row['joomla_id'] ?? null) === null) {
				$orphans++;
				$entry['joomla_user_missing'] = true;
				$entry['joomla_user_note'] = 'There is no #__users row with id ' . $id . '. '
					. 'virtuemart_user_id IS the Joomla user id and nothing cascades, so this is what a '
					. 'deleted Joomla account leaves behind. The customer\'s orders survive too, because '
					. '#__virtuemart_order_userinfos stores its own copy of the address.';
			}

			$out[] = $entry;
		}

		$response = [
			'ok'        => true,
			'total'     => $total,
			'limit'     => $limit,
			'offset'    => $offset,
			'showing'   => \count($out),
			'customers' => $out,
			'pii_note'  => 'This response contains personal data: names, email addresses, phone numbers '
				. 'and partial postal addresses.',
			'identity_note' => 'virtuemart_user_id is the Joomla #__users.id. VirtueMart declares no '
				. 'foreign keys, so nothing cascades in either direction.',
		];

		if ($orphans > 0) {
			$response['orphaned_vmusers'] = $orphans;
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/**
	 * @param  array<int,int> $ids
	 * @return array<int,int>
	 */
	private function orderCounts(array $ids): array
	{
		if ($ids === [] || !$this->vmTableExists('orders')) {
			return [];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('virtuemart_user_id'),
					'COUNT(*) AS ' . $this->db->quoteName('total'),
				])
				->from($this->db->quoteName($this->vmTable('orders')))
				->whereIn($this->db->quoteName('virtuemart_user_id'), $ids)
				->group($this->db->quoteName('virtuemart_user_id'))
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$out[(int) $row['virtuemart_user_id']] = (int) $row['total'];
		}

		return $out;
	}

	/**
	 * @param  array<int,int> $ids
	 * @return array<int,array<int,int>>
	 */
	private function shopperGroups(array $ids): array
	{
		if ($ids === [] || !$this->vmTableExists('vmuser_shoppergroups')) {
			return [];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('virtuemart_user_id'),
					$this->db->quoteName('virtuemart_shoppergroup_id'),
				])
				->from($this->db->quoteName($this->vmTable('vmuser_shoppergroups')))
				->whereIn($this->db->quoteName('virtuemart_user_id'), $ids)
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$out[(int) $row['virtuemart_user_id']][] = (int) $row['virtuemart_shoppergroup_id'];
		}

		return $out;
	}
}
