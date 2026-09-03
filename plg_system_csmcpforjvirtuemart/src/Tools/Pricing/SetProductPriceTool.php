<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Pricing;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Create or update ONE price row.
 *
 * The deliberate design choice here is that this tool operates on a single row
 * and never on the set. `ProductModel::store()` handles prices by loading the
 * existing row ids, writing whatever is in `$data['mprices']`, and then
 * DELETING every id it did not match (`models/product.php:2814-2913`). Any
 * caller with a partial view of a product's price rows who went through that
 * path would destroy the rest. Addressing a single row by id — or by its
 * (product, shopper group, quantity tier) identity — removes the possibility.
 */
final class SetProductPriceTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'set_virtuemart_product_price'; }

	public function getDescription(): string
	{
		return 'Create or update ONE row in #__virtuemart_product_prices. Requires product_id and price. '
			. 'Target the row either by price_id, or by the combination of shoppergroup_id (default 0 = '
			. 'everyone) and price_quantity_start / price_quantity_end (both default 0 = no tier); an '
			. 'existing row matching that combination is updated, otherwise a new one is created. '
			. 'This tool never touches any other price row. That matters because VirtueMart\'s own price '
			. 'handling is a replace-set: ProductModel::store() reads the product\'s existing price row '
			. 'ids, writes whatever is in $data[\'mprices\'], and then DELETES every id it did not match '
			. '(models/product.php:2814-2913). A caller that knew about one price row and went through '
			. 'that path would delete all the others. '
			. 'PRICE FORMAT: a bare dot-decimal string such as "19.99". A value containing a comma or a '
			. 'space is REFUSED, not cleaned up. VirtueMart\'s convertDec() '
			. '(helpers/vmtable.php:489-501) does floatval(str_replace(",", ".", $v)), so "1,234.56" '
			. 'becomes "1.234.56" and floatval stops at the second dot: the price is stored as 1.234, a '
			. 'thousandfold error with no warning. Guessing which convention was meant is not something '
			. 'this tool will do. '
			. 'product_price is the BASE/COST price. The shopper-facing figure is calculated at display '
			. 'time from it plus the applicable calc rules (helpers/calculationh.php:404-498). Setting '
			. 'this to the price you want customers to pay will be wrong wherever tax rules apply. '
			. 'product_price is decimal(15,6) and product_override_price is decimal(15,5), so extra '
			. 'precision is truncated at the DB with no error. '
			. 'The product\'s has_prices join hint is recomputed afterwards — when it reads 0 VirtueMart '
			. 'skips the price join entirely and the product shows with no price even though rows exist '
			. '(models/product.php:2765-2776).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'product_id'      => ['type' => 'integer', 'description' => 'virtuemart_product_id. Required.'],
				'price'           => ['type' => 'string', 'description' => 'Base price as a bare dot-decimal string, e.g. "19.99". Commas and spaces are refused. Required.'],
				'price_id'        => ['type' => 'integer', 'description' => 'virtuemart_product_price_id to update. Omit to match by shopper group and quantity tier instead.'],
				'shoppergroup_id' => ['type' => 'integer', 'description' => 'Default 0, meaning the price applies to everyone.'],
				'currency_id'     => ['type' => 'integer', 'description' => 'virtuemart_currency_id. Defaults to the vendor currency on create, unchanged on update.'],
				'tax_id'          => ['type' => 'integer', 'description' => 'virtuemart_calc_id of a Tax rule, or 0 for none.'],
				'discount_id'     => ['type' => 'integer', 'description' => 'virtuemart_calc_id of a Discount rule, or 0 for none.'],
				'override'        => ['type' => 'boolean', 'description' => 'When true, override_price REPLACES the calculated result instead of adjusting it.'],
				'override_price'  => ['type' => 'string', 'description' => 'Bare dot-decimal string. Only meaningful with override: true. Column is decimal(15,5).'],
				'price_quantity_start' => ['type' => 'integer', 'description' => 'Lower bound of the quantity tier. 0 for no tier.'],
				'price_quantity_end'   => ['type' => 'integer', 'description' => 'Upper bound of the quantity tier. 0 for no tier.'],
				'publish_up'      => ['type' => 'string', 'description' => 'SQL datetime from which this price applies. "" clears it.'],
				'publish_down'    => ['type' => 'string', 'description' => 'SQL datetime after which it stops. "" clears it. A closed window means the product shows no price.'],
			],
			'required' => ['product_id', 'price'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->vmAdminBase() === null) {
			return $this->vmNotInstalledError();
		}

		if (!$this->vmTableExists('product_prices')) {
			return $this->vmMissingTableError('product_prices');
		}

		$productId = $this->requirePositiveInt($arguments, 'product_id');

		$productExists = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName($this->vmTable('products')))
				->where($this->db->quoteName('virtuemart_product_id') . ' = ' . $productId)
		)->loadResult();

		if ($productExists === 0) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No product with virtuemart_product_id ' . $productId . '. A price row for a '
					. 'product that does not exist would never be read. Refusing; nothing was written.',
			], true);
		}

		$price = $this->vmNormaliseMoney($arguments['price'] ?? '', 'price');

		if ($price instanceof ToolResult) {
			return $price;
		}

		$overridePrice = null;

		if (\array_key_exists('override_price', $arguments)) {
			$overridePrice = $this->vmNormaliseMoney($arguments['override_price'], 'override_price');

			if ($overridePrice instanceof ToolResult) {
				return $overridePrice;
			}
		}

		$shoppergroupId = (int) ($arguments['shoppergroup_id'] ?? 0);
		$qtyStart       = (int) ($arguments['price_quantity_start'] ?? 0);
		$qtyEnd         = (int) ($arguments['price_quantity_end'] ?? 0);

		if ($qtyEnd > 0 && $qtyStart > $qtyEnd) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'price_quantity_start (' . $qtyStart . ') is greater than price_quantity_end ('
					. $qtyEnd . '), which describes an empty tier that can never match a basket. '
					. 'Refusing; nothing was written.',
			], true);
		}

		if ($shoppergroupId > 0 && $this->vmTableExists('shoppergroups')) {
			$exists = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select('COUNT(*)')
					->from($this->db->quoteName($this->vmTable('shoppergroups')))
					->where($this->db->quoteName('virtuemart_shoppergroup_id') . ' = ' . $shoppergroupId)
			)->loadResult();

			if ($exists === 0) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'shoppergroup_id ' . $shoppergroupId . ' does not exist, so this price '
						. 'would never apply to anyone. Refusing; nothing was written.',
				], true);
			}
		}

		foreach (['tax_id' => 'product_tax_id', 'discount_id' => 'product_discount_id'] as $arg => $column) {
			if (!\array_key_exists($arg, $arguments) || (int) $arguments[$arg] === 0) {
				continue;
			}

			$refusal = $this->assertCalcExists((int) $arguments[$arg], $arg);

			if ($refusal !== null) {
				return $refusal;
			}
		}

		// --- locate the row ---------------------------------------------------
		$existing = null;

		if (\array_key_exists('price_id', $arguments) && (int) $arguments['price_id'] > 0) {
			$existing = $this->db->setQuery(
				$this->db->getQuery(true)
					->select('*')
					->from($this->db->quoteName($this->vmTable('product_prices')))
					->where($this->db->quoteName('virtuemart_product_price_id') . ' = ' . (int) $arguments['price_id'])
			)->loadAssoc();

			if (!\is_array($existing)) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'No price row with virtuemart_product_price_id ' . (int) $arguments['price_id']
						. '. Nothing was written.',
				], true);
			}

			if ((int) $existing['virtuemart_product_id'] !== $productId) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Price row ' . (int) $arguments['price_id'] . ' belongs to product '
						. (int) $existing['virtuemart_product_id'] . ', not ' . $productId
						. '. Refusing; nothing was written.',
				], true);
			}
		} else {
			$existing = $this->db->setQuery(
				$this->db->getQuery(true)
					->select('*')
					->from($this->db->quoteName($this->vmTable('product_prices')))
					->where($this->db->quoteName('virtuemart_product_id') . ' = ' . $productId)
					->where($this->db->quoteName('virtuemart_shoppergroup_id') . ' = ' . $shoppergroupId)
					->where($this->db->quoteName('price_quantity_start') . ' = ' . $qtyStart)
					->where($this->db->quoteName('price_quantity_end') . ' = ' . $qtyEnd)
			)->loadAssoc();

			$existing = \is_array($existing) ? $existing : null;
		}

		// --- build the row: merge onto the whole current state -----------------
		$writable = [
			'virtuemart_shoppergroup_id',
			'product_price',
			'override',
			'product_override_price',
			'product_tax_id',
			'product_discount_id',
			'product_currency',
			'product_price_publish_up',
			'product_price_publish_down',
			'price_quantity_start',
			'price_quantity_end',
		];

		$deltas = [
			'virtuemart_shoppergroup_id' => $shoppergroupId,
			'product_price'              => $price,
			'price_quantity_start'       => $qtyStart,
			'price_quantity_end'         => $qtyEnd,
		];

		if (\array_key_exists('override', $arguments)) {
			$deltas['override'] = (bool) $arguments['override'] ? 1 : 0;
		}

		if ($overridePrice !== null) {
			$deltas['product_override_price'] = $overridePrice;
		}

		if (\array_key_exists('tax_id', $arguments)) {
			$deltas['product_tax_id'] = (int) $arguments['tax_id'];
		}

		if (\array_key_exists('discount_id', $arguments)) {
			$deltas['product_discount_id'] = (int) $arguments['discount_id'];
		}

		if (\array_key_exists('currency_id', $arguments)) {
			$deltas['product_currency'] = (int) $arguments['currency_id'];
		}

		foreach (['publish_up' => 'product_price_publish_up', 'publish_down' => 'product_price_publish_down'] as $arg => $column) {
			if (\array_key_exists($arg, $arguments)) {
				$value            = trim((string) $arguments[$arg]);
				$deltas[$column]  = $value === '' ? null : $value;
			}
		}

		$now = $this->vmNow();

		if ($existing === null) {
			$base = [
				'virtuemart_product_id'      => $productId,
				'virtuemart_shoppergroup_id' => 0,
				'product_price'              => '0',
				'override'                   => 0,
				'product_override_price'     => null,
				'product_tax_id'             => 0,
				'product_discount_id'        => 0,
				'product_currency'           => $this->vendorCurrencyId(),
				'product_price_publish_up'   => null,
				'product_price_publish_down' => null,
				'price_quantity_start'       => 0,
				'price_quantity_end'         => 0,
			];

			$merged = $this->vmMergeForWrite($base, $deltas, $writable);

			$row              = new \stdClass();
			$row->created_on  = $now;
			$row->created_by  = (int) $actor->id;
			$row->modified_on = $now;
			$row->modified_by = (int) $actor->id;

			foreach ($merged['data'] as $column => $value) {
				$row->{$column} = $value;
			}

			$this->db->insertObject($this->vmTable('product_prices'), $row, 'virtuemart_product_price_id');

			$priceId = (int) $row->virtuemart_product_price_id;
			$created = true;
			$changed = $merged['changed'];
		} else {
			$merged = $this->vmMergeForWrite($existing, $deltas, $writable);

			if ($merged['changed'] === []) {
				return ToolResult::json([
					'ok'      => true,
					'created' => false,
					'changed' => [],
					'virtuemart_product_price_id' => (int) $existing['virtuemart_product_price_id'],
					'note'    => 'Every supplied value already matches this price row. Nothing was written.',
				]);
			}

			$row                              = new \stdClass();
			$row->virtuemart_product_price_id = (int) $existing['virtuemart_product_price_id'];
			$row->modified_on                 = $now;
			$row->modified_by                 = (int) $actor->id;

			foreach ($writable as $column) {
				$row->{$column} = $merged['data'][$column] ?? null;
			}

			$this->db->updateObject($this->vmTable('product_prices'), $row, 'virtuemart_product_price_id', true);

			$priceId = (int) $existing['virtuemart_product_price_id'];
			$created = false;
			$changed = $merged['changed'];
		}

		$hasPrices = $this->refreshHasPrices($productId);

		$response = [
			'ok'                          => true,
			'virtuemart_product_price_id' => $priceId,
			'virtuemart_product_id'       => $productId,
			'created'                     => $created,
			'changed'                     => $changed,
			'product_price'               => $price,
			'shoppergroup_id'             => $shoppergroupId,
			'quantity_tier'               => ['start' => $qtyStart, 'end' => $qtyEnd],
			'has_prices'                  => $hasPrices,
			'other_price_rows_untouched'  => 'Only this row was written. VirtueMart\'s own price handling '
				. 'is a replace-set that deletes every row it was not given (models/product.php:2895-2913); '
				. 'this tool is deliberately row-at-a-time so that cannot happen.',
			'price_meaning' => 'product_price is the BASE/COST price. The shopper pays the result of '
				. 'calculationHelper applying the tax, discount and margin rules on top '
				. '(helpers/calculationh.php:404-498), which is not stored anywhere and is not returned '
				. 'here.',
		];

		if (\array_key_exists('publish_down', $arguments)) {
			$down = trim((string) $arguments['publish_down']);

			if ($down !== '' && strtotime($down) !== false && strtotime($down) < time()) {
				$response['warning'] = 'publish_down is in the past, so this price is already expired and '
					. 'VirtueMart will not use it. If it is the product\'s only price row, the product now '
					. 'displays with no price and cannot be bought.';
			}
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	private function assertCalcExists(int $calcId, string $label): ?ToolResult
	{
		if (!$this->vmTableExists('calcs')) {
			return null;
		}

		$exists = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName($this->vmTable('calcs')))
				->where($this->db->quoteName('virtuemart_calc_id') . ' = ' . $calcId)
		)->loadResult();

		if ($exists > 0) {
			return null;
		}

		return ToolResult::json([
			'ok'    => false,
			'error' => $label . ' ' . $calcId . ' does not exist in #__virtuemart_calcs. VirtueMart has '
				. 'no foreign keys, so this would have stored cleanly and simply never applied. Refusing; '
				. 'nothing was written. Use list_virtuemart_calc_rules to find the right id.',
		], true);
	}

	private function refreshHasPrices(int $productId): int
	{
		$count = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName($this->vmTable('product_prices')))
				->where($this->db->quoteName('virtuemart_product_id') . ' = ' . $productId)
		)->loadResult();

		$flag = $count > 0 ? 1 : 0;

		$row                        = new \stdClass();
		$row->virtuemart_product_id = $productId;
		$row->has_prices            = $flag;

		$this->db->updateObject($this->vmTable('products'), $row, 'virtuemart_product_id');

		return $flag;
	}

	private function vendorCurrencyId(): int
	{
		return (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('vendor_currency'))
				->from($this->db->quoteName($this->vmTable('vendors')))
				->order($this->db->quoteName('virtuemart_vendor_id') . ' ASC')
		)->loadResult();
	}
}
