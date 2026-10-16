<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Coupons;

\defined('_JEXEC') or die;

/**
 * Coupons are one flat table, and the rules that make one work or silently
 * not work are all in how its columns are read.
 *
 *   - Usage is NEVER stored. It is counted from `#__j2commerce_orderdiscounts`
 *     rows (`discount_type = 'coupon'`, `discount_entity_id` = the coupon) on
 *     orders in a "placed" status: the store's `clear_cart_states` if set, else
 *     the lifecycle types complete, approved, shipped, delivered and open
 *     (`src/Model/CouponModel.php:589-646`, `src/Helper/OrderStatusHelper.php:66-74`).
 *   - `value_type` must be one of four strings, or the discount maths returns 0
 *     — and `CouponTable::check()` defaults an empty one to 'percentage', which
 *     is not one of them (`src/Table/CouponTable.php:189-191`).
 *   - Restrictions are comma-separated id lists; `products` and
 *     `product_category` are varchar(255), so a long list truncates.
 *   - `coupon_code` is UNIQUE and uppercased on save (`CouponTable.php:134`).
 */
trait CouponSupportTrait
{
	protected function couponPlacedStatusIds(): array
	{
		$params = $this->j2RawParams();
		$states = array_values(array_filter(array_map('intval', (array) ($params['clear_cart_states'] ?? []))));

		if ($states !== []) {
			return $states;
		}

		$placed = [...self::J2_FINALIZED_TYPES, 'open'];

		return array_keys(array_filter($this->j2StatusMap(), static fn (array $s): bool => \in_array((string) $s['type'], $placed, true)));
	}

	/** @return array<int,int> coupon id => redemptions on placed orders */
	protected function couponUsage(array $couponIds): array
	{
		if ($couponIds === []) {
			return [];
		}

		$placed = $this->couponPlacedStatusIds();
		$db     = $this->db;

		$rows = $db->setQuery(
			'SELECT od.discount_entity_id AS id, COUNT(*) AS n FROM ' . $db->quoteName($this->j2Table('orderdiscounts')) . ' od'
			. ' LEFT JOIN ' . $db->quoteName($this->j2Table('orders')) . ' o ON o.order_id = od.order_id'
			. ' WHERE od.discount_type = ' . $db->quote('coupon')
			. ' AND od.discount_entity_id IN (' . implode(',', array_map('intval', $couponIds)) . ')'
			. ($placed !== [] ? ' AND o.order_state_id IN (' . implode(',', $placed) . ')' : '')
			. ' GROUP BY od.discount_entity_id'
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $r) {
			$out[(int) $r['id']] = (int) $r['n'];
		}

		return $out;
	}

	protected function shapeCoupon(array $c, int $uses): array
	{
		$now     = gmdate('Y-m-d H:i:s');
		$from    = $this->couponDate($c['valid_from']);
		$to      = $this->couponDate($c['valid_to']);
		$max     = (int) $c['max_uses'];
		$type    = (string) $c['value_type'];
		$reasons = [];

		if ((int) $c['enabled'] !== 1) {
			$reasons[] = 'disabled';
		}

		if (!\in_array($type, self::J2_COUPON_VALUE_TYPES, true)) {
			$reasons[] = 'value_type "' . $type . '" is not one J2Commerce applies, so it discounts nothing';
		}

		if ($from !== null && $from > $now) {
			$reasons[] = 'not started (valid_from ' . $from . ' UTC)';
		}

		if ($to !== null && $to < $now) {
			$reasons[] = 'expired (valid_to ' . $to . ' UTC)';
		}

		if ($max > 0 && $uses >= $max) {
			$reasons[] = 'usage limit reached (' . $uses . '/' . $max . ')';
		}

		if ((float) $c['value'] <= 0 && (int) $c['free_shipping'] !== 1) {
			$reasons[] = 'value is 0 and it gives no free shipping';
		}

		return [
			'coupon_id'         => (int) $c['j2commerce_coupon_id'],
			'name'              => (string) $c['coupon_name'],
			'code'              => (string) $c['coupon_code'],
			'enabled'           => (int) $c['enabled'] === 1,
			'value'             => (float) $c['value'],
			'value_type'        => $type,
			'max_value'         => (string) $c['max_value'] !== '' ? (float) $c['max_value'] : null,
			'free_shipping'     => (int) $c['free_shipping'] === 1,
			'valid_from'        => $from,
			'valid_to'          => $to,
			'max_uses'          => $max,
			'max_customer_uses' => (int) $c['max_customer_uses'],
			'uses'              => $uses,
			'logged_in_only'    => (int) $c['logged'] === 1,
			'min_subtotal'      => (string) $c['min_subtotal'] !== '' ? (float) $c['min_subtotal'] : null,
			'restrictions'      => [
				'product_ids'      => $this->csvIds((string) $c['products']),
				'category_ids'     => $this->csvIds((string) $c['product_category']),
				'manufacturer_ids' => $this->csvIds((string) $c['brand_ids']),
				'user_ids'         => $this->csvIds((string) $c['users']),
			],
			'usable_now'        => $reasons === [],
			'not_usable_because' => $reasons,
		];
	}

	protected function couponDate(mixed $v): ?string
	{
		$v = (string) $v;

		return $v === '' || str_starts_with($v, '0000-00-00') ? null : $v;
	}

	protected function csvIds(string $csv): array
	{
		return array_values(array_filter(array_map('intval', explode(',', $csv))));
	}
}
