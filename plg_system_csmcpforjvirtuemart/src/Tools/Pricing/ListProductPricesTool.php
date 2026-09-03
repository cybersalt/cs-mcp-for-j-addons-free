<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Pricing;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * List price rows, with the caveat attached to every figure.
 *
 * `product_price` is the base/cost price. The number a shopper sees is computed
 * at read time by `calculationHelper` (`helpers/calculationh.php:404-498`) from
 * that figure plus the applicable `#__virtuemart_calcs` rules, currency
 * conversion and shopper group, and is stored nowhere. Reporting
 * `product_price` as "the price" is therefore wrong in almost every shop that
 * has tax configured, which is most of them.
 */
final class ListProductPricesTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'list_virtuemart_product_prices'; }

	public function getDescription(): string
	{
		return 'List rows from #__virtuemart_product_prices, optionally for one product. '
			. 'THE MOST IMPORTANT THING TO KNOW: product_price is the BASE/COST price, not the '
			. 'shopper-facing sales price. The figure a customer sees is computed at display time by '
			. 'calculationHelper (helpers/calculationh.php:404-498) from this base price plus every '
			. 'applicable Tax, VatTax, DBTax, DATax and Marge rule in #__virtuemart_calcs, plus currency '
			. 'conversion and the shopper\'s group. It is not stored anywhere and this add-on does not '
			. 'compute it. Never quote product_price to a customer as "the price". Use '
			. 'list_virtuemart_calc_rules to see what would be applied on top. '
			. 'MULTIPLE ROWS PER PRODUCT ARE NORMAL, not a fault. Each row is a combination of shopper '
			. 'group (virtuemart_shoppergroup_id, where 0 means everyone) and quantity tier '
			. '(price_quantity_start / price_quantity_end). '
			. 'override + product_override_price replace the calculated result outright rather than '
			. 'adjusting it. Note the two columns have different scales — product_price is '
			. 'decimal(15,6) and product_override_price is decimal(15,5) — so a value round-tripped '
			. 'between them loses a digit at the DB with no error. '
			. 'product_price_publish_up / _down schedule a price. A row whose window has closed is still '
			. 'returned here, flagged as expired, because a product whose only price row has expired '
			. 'displays as having no price at all. '
			. 'product_tax_id and product_discount_id are foreign keys into #__virtuemart_calcs with no '
			. 'constraint behind them; a dangling one is reported.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'product_id'      => ['type' => 'integer', 'description' => 'Only price rows for this product. Omit to list across the shop.'],
				'shoppergroup_id' => ['type' => 'integer', 'description' => 'Only rows for this shopper group. 0 means the "everyone" rows.'],
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

		if (!$this->vmTableExists('product_prices')) {
			return $this->vmMissingTableError('product_prices');
		}

		$limit  = $this->vmLimit($arguments);
		$offset = $this->vmOffset($arguments);

		$query = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName($this->vmTable('product_prices')))
			->order($this->db->quoteName('virtuemart_product_id') . ' ASC, '
				. $this->db->quoteName('virtuemart_shoppergroup_id') . ' ASC, '
				. $this->db->quoteName('price_quantity_start') . ' ASC');

		$count = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName($this->vmTable('product_prices')));

		if (\array_key_exists('product_id', $arguments)) {
			$clause = $this->db->quoteName('virtuemart_product_id') . ' = ' . (int) $arguments['product_id'];
			$query->where($clause);
			$count->where($clause);
		}

		if (\array_key_exists('shoppergroup_id', $arguments)) {
			$clause = $this->db->quoteName('virtuemart_shoppergroup_id') . ' = '
				. (int) $arguments['shoppergroup_id'];
			$query->where($clause);
			$count->where($clause);
		}

		$total = (int) $this->db->setQuery($count)->loadResult();
		$rows  = $this->db->setQuery($query, $offset, $limit)->loadAssocList() ?: [];

		$knownCalcs      = $this->knownCalcIds();
		$now             = time();
		$out             = [];
		$expired         = 0;
		$danglingCalcs   = 0;

		foreach ($rows as $row) {
			$entry = $row;

			$entry['virtuemart_product_price_id'] = (int) $row['virtuemart_product_price_id'];
			$entry['virtuemart_product_id']       = (int) $row['virtuemart_product_id'];
			$entry['virtuemart_shoppergroup_id']  = (int) $row['virtuemart_shoppergroup_id'];
			$entry['override']                    = (int) $row['override'] === 1;

			$notes = [];

			$up   = $this->timestamp((string) ($row['product_price_publish_up'] ?? ''));
			$down = $this->timestamp((string) ($row['product_price_publish_down'] ?? ''));

			if ($down !== null && $down < $now) {
				$expired++;
				$notes[] = 'This price window has CLOSED (product_price_publish_down is in the past). '
					. 'VirtueMart will not use this row. If it is the product\'s only price row, the '
					. 'product displays with no price and cannot be bought.';
			}

			if ($up !== null && $up > $now) {
				$notes[] = 'This price is scheduled and not yet active (product_price_publish_up is in '
					. 'the future).';
			}

			foreach (['product_tax_id', 'product_discount_id'] as $column) {
				$calcId = (int) ($row[$column] ?? 0);

				if ($calcId > 0 && !\in_array($calcId, $knownCalcs, true)) {
					$danglingCalcs++;
					$notes[] = $column . ' is ' . $calcId . ' but no such row exists in '
						. '#__virtuemart_calcs. VirtueMart declares no foreign keys, so this simply never '
						. 'matches and the rule is not applied.';
				}
			}

			if ((int) $row['override'] === 1) {
				$notes[] = 'override is set, so product_override_price REPLACES the calculated result '
					. 'rather than adjusting it. Note it is decimal(15,5) while product_price is '
					. 'decimal(15,6).';
			}

			if ($notes !== []) {
				$entry['notes'] = $notes;
			}

			$out[] = $entry;
		}

		$response = [
			'ok'      => true,
			'total'   => $total,
			'limit'   => $limit,
			'offset'  => $offset,
			'showing' => \count($out),
			'prices'  => $out,
			'price_meaning' => 'product_price is the BASE/COST price. The shopper-facing sales price is '
				. 'derived at display time by calculationHelper (helpers/calculationh.php:404-498) from '
				. 'this figure plus the applicable calc rules, currency conversion and shopper group, and '
				. 'is not stored anywhere.',
		];

		if ($expired > 0) {
			$response['expired_count'] = $expired;
			$response['expired_note']  = $expired . ' of the rows shown have a closed publish window. A '
				. 'product whose only price row has expired shows with no price and cannot be bought, and '
				. 'nothing in VirtueMart reports it.';
		}

		if ($danglingCalcs > 0) {
			$response['dangling_calc_references'] = $danglingCalcs;
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/** @return array<int,int> */
	private function knownCalcIds(): array
	{
		if (!$this->vmTableExists('calcs')) {
			return [];
		}

		return array_map('intval', $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('virtuemart_calc_id'))
				->from($this->db->quoteName($this->vmTable('calcs')))
		)->loadColumn() ?: []);
	}

	private function timestamp(string $value): ?int
	{
		if ($value === '' || str_starts_with($value, '0000-00-00')) {
			return null;
		}

		$time = strtotime($value);

		return $time === false ? null : $time;
	}
}
