<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Store;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

/**
 * Shipping in J2Commerce is two layers. The "methods" a shopper chooses
 * between are `shipping_*` plugins in the `j2commerce` group
 * (`src/Model/ShippingmethodsModel.php:115-127`). `#__j2commerce_shippingmethods`
 * and `#__j2commerce_shippingrates` belong to ONE of them, `shipping_standard`,
 * which uses them for its own configurable methods and rate tables
 * (`plg_j2commerce_shipping_standard/src/Extension/ShippingStandard.php:68-76`).
 */
final class ListShippingMethodsTool extends AbstractTool
{
	use J2commerceBootTrait;

	private const SECRET_PATTERN = '/secret|pass|signature|key|token|client_id|user(name)?$|login|account|private|cert|hash|salt|webhook|api_/i';

	private const STANDARD_TYPES = [
		0 => 'per order, flat',
		1 => 'per order, by quantity',
		2 => 'per order, by price',
		3 => 'per item, flat',
		4 => 'per item, by weight',
		5 => 'per order, by weight',
		6 => 'per item, percentage',
	];

	public function getName(): string { return 'list_j2commerce_shipping_methods'; }

	public function getDescription(): string
	{
		return 'List J2Commerce shipping. Two layers: the shipping PLUGINS in the j2commerce group (core ships '
			. 'shipping_free and shipping_standard; carrier integrations are paid apps), each with enabled state '
			. 'and settings — carrier credentials withheld and reported only as set or empty — and, for '
			. 'shipping_standard, its own configured methods from #__j2commerce_shippingmethods: name, '
			. 'published, calculation type (per order flat, by quantity, by price, by weight; per item flat, by '
			. 'weight, percentage), tax profile, subtotal window, and every rate row with its geozone, weight '
			. 'band, price and handling fee. '
			. 'Flags: a rate on a geozone with no rules (never matches), a published standard method with no '
			. 'rates, overlapping weight bands, and a shop with no enabled shipping when products need shipping. '
			. 'Read-only. Edit rates in the J2Commerce admin: deleting a standard method there also deletes its rates.';
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

		$db       = $this->db;
		$warnings = [];

		$plugins = array_map(function (array $r): array {
			$params   = json_decode((string) $r['params'], true) ?: [];
			$shown    = [];
			$withheld = [];

			foreach ($params as $key => $value) {
				if (preg_match(self::SECRET_PATTERN, (string) $key) === 1) {
					$withheld[$key] = (\is_scalar($value) && (string) $value !== '') ? 'set' : 'empty';
				} else {
					$shown[$key] = $value;
				}
			}

			return [
				'extension_id' => (int) $r['extension_id'],
				'element'      => (string) $r['element'],
				'enabled'      => (int) $r['enabled'] === 1,
				'ordering'     => (int) $r['ordering'],
				'settings'     => $shown,
				'credentials'  => $withheld,
			];
		}, $db->setQuery(
			'SELECT extension_id, element, enabled, ordering, params FROM ' . $db->quoteName('#__extensions')
			. ' WHERE type = ' . $db->quote('plugin') . ' AND folder = ' . $db->quote('j2commerce')
			. ' AND element LIKE ' . $db->quote('shipping\_%') . ' ORDER BY ordering, element'
		)->loadAssocList() ?: []);

		$geoNames  = $db->setQuery('SELECT j2commerce_geozone_id, geozone_name FROM ' . $db->quoteName($this->j2Table('geozones')))->loadAssocList('j2commerce_geozone_id') ?: [];
		$geoRuled  = array_map('intval', $db->setQuery('SELECT DISTINCT geozone_id FROM ' . $db->quoteName($this->j2Table('geozonerules')))->loadColumn() ?: []);
		$standard  = [];

		if ($this->j2TableExists('shippingmethods')) {
			$rates = [];

			foreach ($db->setQuery(
				'SELECT * FROM ' . $db->quoteName($this->j2Table('shippingrates')) . ' ORDER BY shipping_method_id, geozone_id, shipping_rate_weight_start'
			)->loadAssocList() ?: [] as $rate) {
				$rates[(int) $rate['shipping_method_id']][] = $rate;
			}

			foreach ($db->setQuery(
				'SELECT * FROM ' . $db->quoteName($this->j2Table('shippingmethods')) . ' ORDER BY j2commerce_shippingmethod_id'
			)->loadAssocList() ?: [] as $m) {
				$mid   = (int) $m['j2commerce_shippingmethod_id'];
				$type  = (int) $m['shipping_method_type'];
				$entry = [
					'shippingmethod_id' => $mid,
					'name'              => (string) $m['shipping_method_name'],
					'published'         => (int) $m['published'] === 1,
					'type'              => self::STANDARD_TYPES[$type] ?? ('type ' . $type),
					'taxprofile_id'     => (int) $m['tax_class_id'],
					'subtotal_minimum'  => (float) $m['subtotal_minimum'],
					'subtotal_maximum'  => (float) $m['subtotal_maximum'],
					'rates'             => [],
				];

				$bands = [];

				foreach ($rates[$mid] ?? [] as $rate) {
					$gid   = (int) $rate['geozone_id'];
					$start = (float) $rate['shipping_rate_weight_start'];
					$end   = (float) $rate['shipping_rate_weight_end'];
					$re    = [
						'shippingrate_id' => (int) $rate['j2commerce_shippingrate_id'],
						'geozone_id'      => $gid,
						'geozone'         => $geoNames[$gid]['geozone_name'] ?? null,
						'weight_start'    => $start,
						'weight_end'      => $end,
						'price'           => (float) $rate['shipping_rate_price'],
						'handling'        => (float) $rate['shipping_rate_handling'],
					];

					if (!isset($geoNames[$gid])) {
						$re['warning'] = 'Geozone ' . $gid . ' does not exist; this rate never applies.';
					} elseif (!\in_array($gid, $geoRuled, true)) {
						$re['warning'] = 'Geozone "' . $geoNames[$gid]['geozone_name'] . '" has no rules; this rate never applies.';
					}

					foreach ($bands[$gid] ?? [] as [$s, $e]) {
						if ($end > 0 && $e > 0 && $start < $e && $s < $end) {
							$re['overlap'] = 'Weight band overlaps another rate for the same geozone, so one weight matches two rates. Usually a typo in a band boundary.';
						}
					}

					$bands[$gid][] = [$start, $end];
					$entry['rates'][] = $re;
				}

				if ($entry['published'] && $entry['rates'] === []) {
					$entry['warning'] = 'Published but has no rates, so it never offers a price.';
				}

				$standard[] = $entry;
			}
		}

		$enabled = array_filter($plugins, static fn (array $p): bool => $p['enabled']);

		if ($enabled === []) {
			$shippable = (int) $db->setQuery(
				'SELECT COUNT(*) FROM ' . $db->quoteName($this->j2Table('variants')) . ' v JOIN ' . $db->quoteName($this->j2Table('products'))
				. ' p ON p.j2commerce_product_id = v.product_id WHERE p.enabled = 1 AND v.shipping = 1'
			)->loadResult();

			if ($shippable > 0) {
				$warnings[] = 'No shipping plugin is enabled, but ' . $shippable . ' variant(s) of enabled products need shipping.';
			}
		}

		$response = [
			'ok'                => true,
			'shipping_plugins'  => array_values($plugins),
			'standard_methods'  => $standard,
			'shipping_mandatory' => (string) ($this->j2RawParams()['shipping_mandatory'] ?? '0') === '1',
		];

		if ($warnings !== []) {
			$response['warnings'] = $warnings;
		}

		return ToolResult::json($response);
	}
}
