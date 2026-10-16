<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Coupons;

\defined('_JEXEC') or die;

/**
 * Validation for coupon writes, and the columns they may touch.
 *
 * Written as targeted SQL against the real columns rather than through
 * `CouponModel::save()`. That model reads the request (`task`, `id`) and
 * defaults an empty value_type to one its own maths ignores.
 *
 * Two coupon settings are deliberately NOT offered: user group and "limit to X
 * items". J2Commerce's form, table and model all handle `user_group` and
 * `max_quantity` (`src/Table/CouponTable.php:56-70`, `:216-229`;
 * `src/Model/CouponModel.php:247`, `:992`), but no 6.6.5 install or update
 * script creates those columns, and Joomla's database driver silently drops
 * properties with no column. Verified in the admin UI on 6.6.5: the save
 * reports success and the restriction is never stored. Offering them here
 * would repeat that lie.
 */
trait CouponWriteTrait
{
	protected function couponSchemaProps(): array
	{
		return [
			'name'              => ['type' => 'string', 'description' => 'Internal name.'],
			'code'              => ['type' => 'string', 'description' => 'What shoppers type. Stored uppercased, unique.'],
			'value'             => ['type' => 'number', 'description' => 'Amount, or percentage for the percentage types.'],
			'value_type'        => ['type' => 'string', 'enum' => self::J2_COUPON_VALUE_TYPES, 'description' => 'fixed_cart: amount off the cart. fixed_product: amount off each qualifying item. percentage_cart / percentage_product: percent off.'],
			'max_value'         => ['type' => 'number', 'description' => 'Cap on the discount a percentage coupon can give. 0 = no cap.'],
			'free_shipping'     => ['type' => 'boolean'],
			'enabled'           => ['type' => 'boolean'],
			'valid_from'        => ['type' => 'string', 'description' => 'UTC, YYYY-MM-DD or YYYY-MM-DD HH:MM:SS. Empty = no start.'],
			'valid_to'          => ['type' => 'string', 'description' => 'UTC. A bare date means the end of that day. Empty = no end.'],
			'max_uses'          => ['type' => 'integer', 'description' => 'Across all shoppers. 0 = unlimited.'],
			'max_customer_uses' => ['type' => 'integer', 'description' => 'Per shopper. 0 = unlimited.'],
			'logged_in_only'    => ['type' => 'boolean'],
			'min_subtotal'      => ['type' => 'number', 'description' => 'Cart subtotal required. 0 = none.'],
			'product_ids'       => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Only these products. Empty = all. Replaces the list.'],
			'category_ids'      => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Only products in these article categories. Replaces the list.'],
			'manufacturer_ids'  => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Only these manufacturers. Replaces the list.'],
			'user_ids'          => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Only these Joomla users. Replaces the list.'],
		];
	}

	/**
	 * Map arguments onto columns, validating each.
	 *
	 * @return array<string,mixed> column => value, only for the keys supplied
	 */
	protected function couponColumns(array $a, ?array $existing): array
	{
		$db  = $this->db;
		$out = [];

		if (\array_key_exists('name', $a)) {
			$name = trim((string) $a['name']);
			$this->j2RequireFits('name', $name, 255);
			$out['coupon_name'] = $name;
		}

		if (\array_key_exists('code', $a)) {
			$code = strtoupper(trim((string) $a['code']));

			if ($code === '' || preg_match('/\s/', $code) === 1) {
				throw new \InvalidArgumentException('code must be non-empty and contain no spaces.');
			}

			$this->j2RequireFits('code', $code, 255);

			$clash = (int) $db->setQuery(
				'SELECT j2commerce_coupon_id FROM ' . $db->quoteName($this->j2Table('coupons'))
				. ' WHERE UPPER(coupon_code) = ' . $db->quote($code)
				. ($existing !== null ? ' AND j2commerce_coupon_id <> ' . (int) $existing['j2commerce_coupon_id'] : '')
			)->loadResult();

			if ($clash > 0) {
				throw new \InvalidArgumentException('Coupon code ' . $code . ' is already used by coupon ' . $clash . '. Codes are unique. Nothing was written.');
			}

			$out['coupon_code'] = $code;
		}

		$type = \array_key_exists('value_type', $a) ? (string) $a['value_type'] : ($existing['value_type'] ?? null);

		if (\array_key_exists('value_type', $a) && !\in_array($type, self::J2_COUPON_VALUE_TYPES, true)) {
			throw new \InvalidArgumentException('value_type must be one of ' . implode(', ', self::J2_COUPON_VALUE_TYPES) . '.');
		}

		if (\array_key_exists('value_type', $a)) {
			$out['value_type'] = $type;
		}

		if (\array_key_exists('value', $a)) {
			$value = (float) $a['value'];

			if ($value < 0) {
				throw new \InvalidArgumentException('value cannot be negative.');
			}

			$out['value'] = $value;
		}

		$value = (float) ($out['value'] ?? $existing['value'] ?? 0);

		if (\is_string($type) && str_starts_with($type, 'percentage') && $value > 100) {
			throw new \InvalidArgumentException('A percentage coupon cannot exceed 100 (got ' . $value . '). Nothing was written.');
		}

		foreach (['max_value' => 'max_value', 'min_subtotal' => 'min_subtotal'] as $arg => $col) {
			if (\array_key_exists($arg, $a)) {
				$v = (float) $a[$arg];

				if ($v < 0) {
					throw new \InvalidArgumentException($arg . ' cannot be negative.');
				}

				// Stored as varchar; '' means none, as the vendor stores it.
				$out[$col] = $v > 0 ? (string) $v : '';
			}
		}

		foreach (['free_shipping' => 'free_shipping', 'enabled' => 'enabled', 'logged_in_only' => 'logged'] as $arg => $col) {
			if (\array_key_exists($arg, $a)) {
				$out[$col] = (bool) $a[$arg] ? 1 : 0;
			}
		}

		foreach (['max_uses', 'max_customer_uses'] as $col) {
			if (\array_key_exists($col, $a)) {
				$out[$col] = max(0, (int) $a[$col]);
			}
		}

		foreach (['valid_from', 'valid_to'] as $col) {
			if (\array_key_exists($col, $a)) {
				$out[$col] = $this->couponUtc($col, $a[$col]);
			}
		}

		$from = \array_key_exists('valid_from', $out) ? $out['valid_from'] : ($existing !== null ? $this->couponDate($existing['valid_from']) : null);
		$to   = \array_key_exists('valid_to', $out) ? $out['valid_to'] : ($existing !== null ? $this->couponDate($existing['valid_to']) : null);

		if ($from !== null && $to !== null && $to < $from) {
			throw new \InvalidArgumentException('valid_to is before valid_from. Nothing was written.');
		}

		$lists = [
			'product_ids'      => ['products', 255, 'SELECT j2commerce_product_id FROM ' . $db->quoteName($this->j2Table('products')) . ' WHERE j2commerce_product_id IN (%s)'],
			'category_ids'     => ['product_category', 255, 'SELECT id FROM ' . $db->quoteName('#__categories') . ' WHERE id IN (%s)'],
			'manufacturer_ids' => ['brand_ids', 65535, 'SELECT j2commerce_manufacturer_id FROM ' . $db->quoteName($this->j2Table('manufacturers')) . ' WHERE j2commerce_manufacturer_id IN (%s)'],
			'user_ids'         => ['users', 65535, 'SELECT id FROM ' . $db->quoteName('#__users') . ' WHERE id IN (%s)'],
		];

		foreach ($lists as $arg => [$col, $max, $sql]) {
			if (!\array_key_exists($arg, $a)) {
				continue;
			}

			$ids = array_values(array_unique(array_filter(array_map('intval', (array) $a[$arg]), static fn (int $i): bool => $i > 0)));

			if ($ids !== []) {
				$found   = array_map('intval', $db->setQuery(\sprintf($sql, implode(',', $ids)))->loadColumn() ?: []);
				$missing = array_values(array_diff($ids, $found));

				if ($missing !== []) {
					throw new \InvalidArgumentException($arg . ' contains id(s) that do not exist: ' . implode(', ', $missing) . '. Nothing was written.');
				}
			}

			$csv = implode(',', $ids);

			if (\strlen($csv) > $max) {
				throw new \InvalidArgumentException($arg . ' is ' . \strlen($csv) . ' characters as a list; the ' . $col
					. ' column holds ' . $max . '. MySQL would truncate it mid-id and the coupon would silently apply '
					. 'to the wrong items. Use category_ids or manufacturer_ids to cover them instead. Nothing was written.');
			}

			$out[$col] = $csv;
		}

		return $out;
	}

	protected function couponUtc(string $field, mixed $value): ?string
	{
		$value = trim((string) $value);

		if ($value === '') {
			return null;
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			$value .= $field === 'valid_to' ? ' 23:59:59' : ' 00:00:00';
		}

		$dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));

		if ($dt === false || $dt->format('Y-m-d H:i:s') !== $value) {
			throw new \InvalidArgumentException($field . ' must be YYYY-MM-DD or YYYY-MM-DD HH:MM:SS (UTC).');
		}

		return $value;
	}
}
