<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Pricing;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

final class ListProductPricesTool extends AbstractTool
{
	use J2commerceBootTrait;
	use PriceSupportTrait;

	public function getName(): string { return 'list_j2commerce_product_prices'; }

	public function getDescription(): string
	{
		return 'List J2Commerce advanced prices — the quantity-tier, customer-group and date-window prices in '
			. '#__j2commerce_product_prices — for one product (all its variants) or one variant, or with '
			. 'never_applies: true, every price row in the shop that can never take effect. '
			. 'How J2Commerce picks one (src/Helper/ProductHelper.php:3916-3985): among rows whose quantity '
			. 'window holds the cart quantity, whose date window holds now (UTC), and whose group is empty or '
			. 'one of the shopper\'s (always including 1, Public), the LOWEST wins — and only if it is lower '
			. 'than the variant\'s base price. So a tier priced above base is silently ignored, and '
			. 'customer_group_id 0 matches nobody. Each row is flagged with never_applies when either is '
			. 'true, or when its date window has ended. The base price itself is on the variant '
			. '(update_j2commerce_variant). Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'product_id'    => ['type' => 'integer'],
				'variant_id'    => ['type' => 'integer'],
				'never_applies' => ['type' => 'boolean', 'description' => 'Shop-wide: only rows that can never apply.'],
				'limit'         => ['type' => 'integer', 'description' => 'Default 100, max 200.'],
				'offset'        => ['type' => 'integer'],
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

		$this->j2RequireAccess($actor, 'j2commerce.viewproducts');

		$db    = $this->db;
		$where = [];

		if (!empty($arguments['variant_id'])) {
			$where[] = 'pp.variant_id = ' . (int) $arguments['variant_id'];
		} elseif (!empty($arguments['product_id'])) {
			$where[] = 'v.product_id = ' . (int) $arguments['product_id'];
		} elseif (!(bool) ($arguments['never_applies'] ?? false)) {
			throw new \InvalidArgumentException('Pass product_id, variant_id, or never_applies: true.');
		}

		$rows = $db->setQuery(
			'SELECT pp.*, v.price AS base_price, v.product_id, v.sku, v.is_master'
			. ' FROM ' . $db->quoteName($this->j2Table('product_prices')) . ' pp'
			. ' LEFT JOIN ' . $db->quoteName($this->j2Table('variants')) . ' v ON v.j2commerce_variant_id = pp.variant_id'
			. ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
			. ' ORDER BY pp.variant_id, pp.quantity_from, pp.price'
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$entry = $this->shapePrice($row, $row['base_price'] !== null ? (float) $row['base_price'] : null);
			$entry['product_id'] = $row['product_id'] !== null ? (int) $row['product_id'] : null;
			$entry['sku']        = $row['sku'];
			$entry['base_price'] = $row['base_price'] !== null ? (float) $row['base_price'] : null;

			if ($row['product_id'] === null) {
				$entry['never_applies'][] = 'Its variant no longer exists (orphaned price row).';
			}

			if ((bool) ($arguments['never_applies'] ?? false) && empty($entry['never_applies'])) {
				continue;
			}

			$out[] = $entry;
		}

		$total  = \count($out);
		$limit  = $this->j2Limit($arguments, 100);
		$offset = $this->j2Offset($arguments);

		return ToolResult::json([
			'ok'       => true,
			'total'    => $total,
			'showing'  => min($limit, max(0, $total - $offset)),
			'currency' => $this->j2StoreCurrency(),
			'prices'   => \array_slice($out, $offset, $limit),
			'rule'     => 'Lowest qualifying row wins, and only if below the base price. Dates compare in UTC.',
		]);
	}
}
