<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Pricing;

\defined('_JEXEC') or die;

/**
 * How J2Commerce picks an advanced price (`src/Helper/ProductHelper.php:3916-3985`):
 *
 *   - rows for the variant whose quantity window contains the cart quantity
 *     (quantity_from/quantity_to of NULL or 0 = unbounded),
 *   - whose date window contains NOW in UTC (NULL or zero date = unbounded),
 *   - whose customer_group_id is NULL or one of the shopper's groups — and the
 *     shopper's groups always include 1 (Public),
 *   - the LOWEST such price wins,
 *   - and it is used ONLY if it is lower than the variant's base price
 *     (`:3889`). A tier price above the base price is silently ignored.
 *
 * Two consequences the tools enforce: `customer_group_id = 0` matches nobody
 * (there is no group 0), and a price at or above base never shows.
 */
trait PriceSupportTrait
{
	protected function shapePrice(array $p, ?float $base = null): array
	{
		$price = (float) $p['price'];
		$out   = [
			'price_id'          => (int) $p['j2commerce_productprice_id'],
			'variant_id'        => (int) $p['variant_id'],
			'price'             => round($price, 5),
			'quantity_from'     => $p['quantity_from'] !== null ? (float) $p['quantity_from'] : null,
			'quantity_to'       => $p['quantity_to'] !== null ? (float) $p['quantity_to'] : null,
			'date_from'         => $this->cleanDate($p['date_from']),
			'date_to'           => $this->cleanDate($p['date_to']),
			'customer_group_id' => $p['customer_group_id'] !== null ? (int) $p['customer_group_id'] : null,
		];

		$problems = [];

		if ($base !== null && $price >= $base) {
			$problems[] = 'At or above the base price (' . $base . '), so J2Commerce never applies it.';
		}

		if ($p['customer_group_id'] !== null && (int) $p['customer_group_id'] === 0) {
			$problems[] = 'customer_group_id is 0, which is no Joomla group, so it matches no shopper.';
		}

		$to = $this->cleanDate($p['date_to']);

		if ($to !== null && $to < gmdate('Y-m-d H:i:s')) {
			$problems[] = 'Expired: date_to (' . $to . ' UTC) is in the past.';
		}

		if ($problems !== []) {
			$out['never_applies'] = $problems;
		}

		return $out;
	}

	protected function cleanDate(mixed $value): ?string
	{
		$value = (string) $value;

		return $value === '' || str_starts_with($value, '0000-00-00') ? null : $value;
	}

	protected function variantBasePrice(int $variantId): ?float
	{
		$price = $this->db->setQuery(
			'SELECT price FROM ' . $this->db->quoteName($this->j2Table('variants')) . ' WHERE j2commerce_variant_id = ' . $variantId
		)->loadResult();

		return $price === null ? null : (float) $price;
	}

	protected function requireGroup(int $groupId): string
	{
		$title = $this->db->setQuery(
			'SELECT title FROM ' . $this->db->quoteName('#__usergroups') . ' WHERE id = ' . $groupId
		)->loadResult();

		if ($title === null) {
			throw new \InvalidArgumentException('No Joomla user group with id ' . $groupId . '. Nothing was written.');
		}

		return (string) $title;
	}

	/** Accept "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS", interpreted as UTC, as J2Commerce compares them. */
	protected function parseUtc(string $field, mixed $value): ?string
	{
		$value = trim((string) $value);

		if ($value === '') {
			return null;
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			$value .= $field === 'date_to' ? ' 23:59:59' : ' 00:00:00';
		}

		$dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));

		if ($dt === false || $dt->format('Y-m-d H:i:s') !== $value) {
			throw new \InvalidArgumentException($field . ' must be YYYY-MM-DD or YYYY-MM-DD HH:MM:SS (UTC). Got "' . $value . '".');
		}

		return $value;
	}
}
