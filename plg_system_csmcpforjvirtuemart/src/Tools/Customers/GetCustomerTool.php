<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Customers;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * One customer in full: vmuser row, Joomla account, every address, shopper
 * groups, and an order summary.
 */
final class GetCustomerTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'get_virtuemart_customer'; }

	public function getDescription(): string
	{
		return 'Return one VirtueMart customer in full: the #__virtuemart_vmusers row, the matching '
			. 'Joomla #__users account, every address row in #__virtuemart_userinfos (billing and '
			. 'shipping), the customer\'s shopper groups, and a summary of their orders. Requires '
			. 'user_id, which is the Joomla user id — virtuemart_user_id is the same value, not a '
			. 'separate sequence. '
			. 'THIS RETURNS FULL PERSONAL DATA: name, email, phone, and complete postal addresses. '
			. 'The address columns are read with SHOW COLUMNS at runtime. #__virtuemart_userinfos is not '
			. 'in install.sql and its column set is driven by #__virtuemart_userfields, which VirtueMart '
			. 'alters with real DDL from its userfields screen (models/userfields.php:300). Columns from '
			. 'deleted userfields are renamed to <name>_DELETED_<unixtime> rather than dropped '
			. '(helpers/vmtable.php:2748-2751) and are filtered out of this response; the count of them is '
			. 'reported, because a large number is a sign of a much-edited shop. '
			. 'address_type is "BT" for billing and "ST" for shipping. A customer may legitimately have '
			. 'no address rows at all if they registered but never checked out. '
			. 'There is no setter for customer records in this add-on. Editing a customer means editing '
			. 'the Joomla user account plus a table whose columns are shop-specific and can be altered by '
			. 'the userfields screen — and that same screen is an arbitrary-ALTER-TABLE primitive '
			. '(helpers/vmtable.php:2712), which is not something we expose over MCP. Use '
			. 'set_virtuemart_customer_shoppergroups for the one thing that is safely writable.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'user_id' => ['type' => 'integer', 'description' => 'Joomla user id (= virtuemart_user_id). Required.'],
			],
			'required' => ['user_id'],
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

		$id = $this->requirePositiveInt($arguments, 'user_id');

		$vmuser = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->vmTable('vmusers')))
				->where($this->db->quoteName('virtuemart_user_id') . ' = ' . $id)
		)->loadAssoc();

		$joomla = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('id'),
					$this->db->quoteName('name'),
					$this->db->quoteName('username'),
					$this->db->quoteName('email'),
					$this->db->quoteName('block'),
					$this->db->quoteName('registerDate'),
					$this->db->quoteName('lastvisitDate'),
				])
				->from($this->db->quoteName('#__users'))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();

		if (!\is_array($vmuser) && !\is_array($joomla)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No VirtueMart customer and no Joomla user with id ' . $id . '.',
			], true);
		}

		$addresses    = [];
		$zombieFields = 0;

		if ($this->vmTableExists('userinfos')) {
			$rows = $this->db->setQuery(
				$this->db->getQuery(true)
					->select('*')
					->from($this->db->quoteName($this->vmTable('userinfos')))
					->where($this->db->quoteName('virtuemart_user_id') . ' = ' . $id)
					->order($this->db->quoteName('address_type') . ' ASC')
			)->loadAssocList() ?: [];

			foreach ($rows as $row) {
				$clean = [];

				foreach ($row as $column => $value) {
					if (preg_match('/_DELETED_\d+$/', (string) $column) === 1) {
						$zombieFields++;

						continue;
					}

					$clean[$column] = $value;
				}

				$addresses[] = [
					'address_type'      => (string) ($row['address_type'] ?? ''),
					'address_type_name' => (string) ($row['address_type_name'] ?? ''),
					'row'               => $clean,
				];
			}
		}

		$groups = $this->vmTableExists('vmuser_shoppergroups')
			? $this->vmXrefIds('vmuser_shoppergroups', 'virtuemart_user_id', $id, 'virtuemart_shoppergroup_id')
			: [];

		$groupNames = [];

		if ($groups !== [] && $this->vmTableExists('shoppergroups')) {
			$rows = $this->db->setQuery(
				$this->db->getQuery(true)
					->select([
						$this->db->quoteName('virtuemart_shoppergroup_id'),
						$this->db->quoteName('shopper_group_name'),
					])
					->from($this->db->quoteName($this->vmTable('shoppergroups')))
					->whereIn($this->db->quoteName('virtuemart_shoppergroup_id'), $groups)
			)->loadAssocList() ?: [];

			foreach ($rows as $row) {
				$groupNames[(int) $row['virtuemart_shoppergroup_id']] = (string) $row['shopper_group_name'];
			}
		}

		$orders = [];

		if ($this->vmTableExists('orders')) {
			$orders = array_map(
				fn (array $row): array => $this->vmRedactRow($row),
				$this->db->setQuery(
					$this->db->getQuery(true)
						->select([
							$this->db->quoteName('virtuemart_order_id'),
							$this->db->quoteName('order_number'),
							$this->db->quoteName('order_status'),
							$this->db->quoteName('order_total'),
							$this->db->quoteName('paid'),
							$this->db->quoteName('created_on'),
						])
						->from($this->db->quoteName($this->vmTable('orders')))
						->where($this->db->quoteName('virtuemart_user_id') . ' = ' . $id)
						->order($this->db->quoteName('virtuemart_order_id') . ' DESC'),
					0,
					50
				)->loadAssocList() ?: []
			);
		}

		$response = [
			'ok'                  => true,
			'virtuemart_user_id'  => $id,
			'vmuser'              => \is_array($vmuser) ? $vmuser : null,
			'joomla_user'         => \is_array($joomla) ? $joomla : null,
			'addresses'           => $addresses,
			'shoppergroup_ids'    => $groups,
			'shoppergroup_names'  => $groupNames,
			'recent_orders'       => $orders,
			'order_count'         => \count($orders),
			'pii_note'            => 'This response contains full personal data: name, email, phone and '
				. 'postal addresses.',
		];

		if (!\is_array($vmuser)) {
			$response['vmuser_missing'] = 'There is a Joomla user ' . $id . ' but no '
				. '#__virtuemart_vmusers row. VirtueMart creates that row on first checkout or on the '
				. 'first save of the shopper form, so this is normal for an account that has never used '
				. 'the shop.';
		}

		if (!\is_array($joomla)) {
			$response['joomla_user_missing'] = 'There is a #__virtuemart_vmusers row but no Joomla user '
				. $id . '. virtuemart_user_id IS the Joomla user id and nothing cascades — VirtueMart '
				. 'declares no foreign keys — so this is what deleting a Joomla account leaves behind.';
		}

		if ($addresses === []) {
			$response['address_note'] = 'This customer has no rows in #__virtuemart_userinfos. That is '
				. 'normal for someone who registered but never checked out. It only matters on an ORDER, '
				. 'where a missing BT row breaks email, invoicing and the admin order screen.';
		}

		if ($zombieFields > 0) {
			$response['deleted_userfield_columns'] = $zombieFields;
			$response['deleted_userfield_note'] = 'The userinfos rows carry ' . $zombieFields
				. ' column(s) whose names end in _DELETED_<unixtime>, filtered out of this response. '
				. 'VirtueMart RENAMES a userfield column instead of dropping it when the field is deleted '
				. '(helpers/vmtable.php:2748-2751), so those hold data from fields the shop no longer '
				. 'uses.';
		}

		if (\is_array($vmuser) && (int) $vmuser['user_is_vendor'] === 1) {
			$response['vendor_note'] = 'This account is flagged as a vendor. vmAccess::isSuperVendor() '
				. 'looks exactly this row up (helpers/vmaccess.php:66-108); note that a manager with NO '
				. 'vmusers row falls back to vendor id 1 at :95.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}
}
