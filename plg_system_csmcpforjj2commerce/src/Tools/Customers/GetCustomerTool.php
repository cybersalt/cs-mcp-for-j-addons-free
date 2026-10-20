<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Customers;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

final class GetCustomerTool extends AbstractTool
{
	use J2commerceBootTrait;

	public function getName(): string { return 'get_j2commerce_customer'; }

	public function getDescription(): string
	{
		return 'Get one J2Commerce customer by email or Joomla user_id: every saved address (billing and '
			. 'shipping, with phone, company and tax number), the distinct billing addresses used on their orders '
			. '(guest checkout saves no address row, so for guests these are the only addresses), the linked Joomla user (name, username, blocked '
			. 'state, groups — which drive customer-group prices and coupon eligibility), every order with its '
			. 'number, status and total, paid order count and paid lifetime value, the coupons and vouchers they '
			. 'have redeemed, and any gift vouchers addressed to them (codes masked). '
			. 'A customer in J2Commerce is not a record: it is the address rows and orders that share an email, '
			. 'so a registered user who checked out as a guest under the same email appears here with both. '
			. 'Matching is case-insensitive. Stored payment profiles (saved cards) are only COUNTED, never read. '
			. 'Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'email'   => ['type' => 'string'],
				'user_id' => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->j2Installed()) {
			return $this->j2NotInstalledError();
		}

		$this->j2RequireAccess($actor, 'j2commerce.exportorders');

		$db     = $this->db;
		$email  = strtolower(trim((string) ($arguments['email'] ?? '')));
		$userId = (int) ($arguments['user_id'] ?? 0);
		$user   = null;

		if ($userId > 0) {
			$user = $db->setQuery(
				'SELECT id, name, username, email, block, registerDate, lastvisitDate FROM ' . $db->quoteName('#__users') . ' WHERE id = ' . $userId
			)->loadAssoc();

			if (!\is_array($user)) {
				return ToolResult::json(['ok' => false, 'error' => 'No Joomla user with id ' . $userId . '.'], true);
			}

			if ($email === '') {
				$email = strtolower((string) $user['email']);
			}
		} elseif ($email === '') {
			throw new \InvalidArgumentException('Pass email or user_id.');
		}

		$emailQ = $db->quote($email);

		$addresses = $db->setQuery(
			'SELECT a.*, c.country_name, z.zone_name FROM ' . $db->quoteName($this->j2Table('addresses')) . ' a'
			. ' LEFT JOIN ' . $db->quoteName($this->j2Table('countries')) . ' c ON c.j2commerce_country_id = a.country_id'
			. ' LEFT JOIN ' . $db->quoteName($this->j2Table('zones')) . ' z ON z.j2commerce_zone_id = a.zone_id'
			. ' WHERE LOWER(TRIM(a.email)) = ' . $emailQ . ($userId > 0 ? ' OR a.user_id = ' . $userId : '')
			. ' ORDER BY a.j2commerce_address_id'
		)->loadAssocList() ?: [];

		if ($user === null) {
			$linked = 0;

			foreach ($addresses as $a) {
				$linked = max($linked, (int) $a['user_id']);
			}

			if ($linked === 0) {
				$linked = (int) $db->setQuery(
					'SELECT MAX(user_id) FROM ' . $db->quoteName($this->j2Table('orders')) . ' WHERE LOWER(TRIM(user_email)) = ' . $emailQ
				)->loadResult();
			}

			if ($linked > 0) {
				$user = $db->setQuery(
					'SELECT id, name, username, email, block, registerDate, lastvisitDate FROM ' . $db->quoteName('#__users') . ' WHERE id = ' . $linked
				)->loadAssoc() ?: null;
				$userId = $linked;
			}
		}

		$orderWhere = 'LOWER(TRIM(o.user_email)) = ' . $emailQ . ($userId > 0 ? ' OR o.user_id = ' . $userId : '');
		$orders = $db->setQuery(
			'SELECT o.j2commerce_order_id, o.order_id, o.order_state_id, o.order_total, o.currency_code, o.created_on, o.user_id, o.user_email'
			. ' FROM ' . $db->quoteName($this->j2Table('orders')) . ' o WHERE ' . $orderWhere . ' ORDER BY o.created_on DESC'
		)->loadAssocList() ?: [];

		if ($addresses === [] && $orders === [] && $user === null) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No J2Commerce addresses or orders, and no Joomla user, match ' . ($email !== '' ? $email : 'user ' . $userId) . '.',
			], true);
		}

		$statuses  = $this->j2StatusMap();
		$paidCount = 0;
		$paidValue = 0.0;
		$orderOut  = [];

		foreach ($orders as $o) {
			$sid  = (int) $o['order_state_id'];
			$type = $statuses[$sid]['type'] ?? null;
			$paid = \in_array((string) $type, self::J2_FINALIZED_TYPES, true);

			if ($paid) {
				$paidCount++;
				$paidValue += (float) $o['order_total'];
			}

			$orderOut[] = [
				'order_pk'     => (int) $o['j2commerce_order_id'],
				'order_number' => (string) $o['order_id'],
				'status'       => $statuses[$sid]['label'] ?? ('#' . $sid),
				'paid'         => $paid,
				'abandoned'    => $sid === 5,
				'total'        => $this->j2Money($o['order_total']),
				'currency'     => (string) $o['currency_code'],
				'created_on'   => (string) $o['created_on'],
				'as_guest'     => (int) $o['user_id'] === 0,
			];
		}

		$numbers = array_map(static fn (array $o): string => (string) $o['order_id'], $orders);
		$redeemed = [];

		if ($numbers !== []) {
			$redeemed = array_map(fn (array $d): array => [
				'order_number' => (string) $d['order_id'],
				'type'         => (string) $d['discount_type'],
				'code'         => (string) $d['discount_type'] === 'voucher' ? $this->j2MaskCode((string) $d['discount_code']) : (string) $d['discount_code'],
				'amount'       => $this->j2Money($d['discount_amount']),
			], $db->setQuery(
				'SELECT order_id, discount_type, discount_code, discount_amount FROM ' . $db->quoteName($this->j2Table('orderdiscounts'))
				. ' WHERE order_id IN (' . implode(',', array_map([$db, 'quote'], $numbers)) . ')'
			)->loadAssocList() ?: []);
		}

		// Guest checkout saves no address row, so the order is often the only place an
		// address exists. Distinct billing addresses across their orders.
		$orderAddresses = [];

		if ($numbers !== []) {
			foreach ($db->setQuery(
				'SELECT order_id, billing_first_name, billing_last_name, billing_company, billing_address_1, billing_address_2,'
				. ' billing_city, billing_zip, billing_zone_name, billing_country_name, billing_phone_1, billing_phone_2'
				. ' FROM ' . $db->quoteName($this->j2Table('orderinfos'))
				. ' WHERE order_id IN (' . implode(',', array_map([$db, 'quote'], $numbers)) . ')'
			)->loadAssocList() ?: [] as $i) {
				$addr = [
					'name'      => trim((string) $i['billing_first_name'] . ' ' . (string) $i['billing_last_name']),
					'company'   => (string) $i['billing_company'],
					'address_1' => (string) $i['billing_address_1'],
					'address_2' => (string) $i['billing_address_2'],
					'city'      => (string) $i['billing_city'],
					'zip'       => (string) $i['billing_zip'],
					'zone'      => (string) $i['billing_zone_name'],
					'country'   => (string) $i['billing_country_name'],
					'phone'     => (string) ($i['billing_phone_1'] ?: $i['billing_phone_2']),
				];
				$key = md5(json_encode($addr));

				$orderAddresses[$key] ??= $addr + ['first_used_on_order' => (string) $i['order_id']];
			}
		}

		$vouchers = array_map(fn (array $v): array => [
			'voucher_id' => (int) $v['j2commerce_voucher_id'],
			'code'       => $this->j2MaskCode((string) $v['voucher_code']),
			'value'      => $this->j2Money($v['voucher_value']),
			'valid_to'   => $v['valid_to'],
			'enabled'    => (int) $v['enabled'] === 1,
		], $db->setQuery(
			'SELECT j2commerce_voucher_id, voucher_code, voucher_value, valid_to, enabled FROM ' . $db->quoteName($this->j2Table('vouchers'))
			. ' WHERE LOWER(TRIM(email_to)) = ' . $emailQ . ($userId > 0 ? ' OR user_id = ' . $userId : '')
		)->loadAssocList() ?: []);

		$response = [
			'ok'             => true,
			'email'          => $email,
			'user'           => $user !== null ? [
				'id'             => (int) $user['id'],
				'name'           => (string) $user['name'],
				'username'       => (string) $user['username'],
				'email'          => (string) $user['email'],
				'blocked'        => (int) $user['block'] === 1,
				'registered'     => (string) $user['registerDate'],
				'last_visit'     => $user['lastvisitDate'],
				'groups'         => $this->groups((int) $user['id']),
			] : null,
			'guest'          => $user === null,
			'addresses'      => array_map(static fn (array $a): array => [
				'address_id' => (int) $a['j2commerce_address_id'],
				'type'       => (string) $a['type'],
				'name'       => trim((string) $a['first_name'] . ' ' . (string) $a['last_name']),
				'company'    => (string) $a['company'],
				'address_1'  => (string) $a['address_1'],
				'address_2'  => (string) $a['address_2'],
				'city'       => (string) $a['city'],
				'zip'        => (string) $a['zip'],
				'zone'       => $a['zone_name'],
				'country'    => $a['country_name'],
				'phone_1'    => (string) $a['phone_1'],
				'phone_2'    => (string) $a['phone_2'],
				'tax_number' => (string) $a['tax_number'],
				'email'      => (string) $a['email'],
				'user_id'    => (int) $a['user_id'],
			], $addresses),
			'billing_addresses_from_orders' => array_values($orderAddresses),
			'orders'         => $orderOut,
			'summary'        => [
				'orders'         => \count(array_filter($orderOut, static fn (array $o): bool => !$o['abandoned'])),
				'abandoned'      => \count(array_filter($orderOut, static fn (array $o): bool => $o['abandoned'])),
				'paid_orders'    => $paidCount,
				'lifetime_value' => round($paidValue, 5),
			],
			'discounts_redeemed' => $redeemed,
			'vouchers_addressed_to_them' => $vouchers,
		];

		if ($userId > 0 && $this->j2TableExists('paymentprofiles')) {
			$response['saved_payment_profiles'] = (int) $db->setQuery(
				'SELECT COUNT(*) FROM ' . $db->quoteName($this->j2Table('paymentprofiles')) . ' WHERE user_id = ' . $userId
			)->loadResult();
		}

		return ToolResult::json($response);
	}

	/** @return array<int,array{id:int,title:string}> */
	private function groups(int $userId): array
	{
		return array_map(static fn (array $g): array => ['id' => (int) $g['id'], 'title' => (string) $g['title']], $this->db->setQuery(
			'SELECT g.id, g.title FROM ' . $this->db->quoteName('#__user_usergroup_map') . ' m'
			. ' JOIN ' . $this->db->quoteName('#__usergroups') . ' g ON g.id = m.group_id WHERE m.user_id = ' . $userId
		)->loadAssocList() ?: []);
	}
}
