<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Products;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

/**
 * Price, SKU, physical data and stock RULES for one variant, as a targeted
 * UPDATE. Stock QUANTITY is set_j2commerce_stock, which goes through the
 * vendor's atomic adjuster.
 */
final class UpdateVariantTool extends AbstractTool
{
	use J2commerceBootTrait;
	use ProductSupportTrait;

	private const DECIMAL_FIELDS = ['price', 'weight', 'length', 'width', 'height'];

	private const STORE_DEFAULTABLE = ['min_sale_qty', 'max_sale_qty', 'notify_qty'];

	public function getName(): string { return 'update_j2commerce_variant'; }

	public function getDescription(): string
	{
		return 'Change one J2Commerce variant: price, sku, upc, shipping (needs shipping or not), weight, '
			. 'weight_class_id, length, width, height, length_class_id, manage_stock, allow_backorder, and the '
			. 'sale limits min_sale_qty, max_sale_qty and notify_qty. Pass variant_id, or product_id to edit '
			. 'that product\'s master variant (where simple, configurable and downloadable products keep '
			. 'their price and SKU). Only the fields you pass are written, as a targeted UPDATE. '
			. 'For variable and flexivariable products, edit the CHILD variants — get_j2commerce_product lists '
			. 'them — because shoppers buy those; passing product_id for such a product is refused. After a '
			. 'child price change the product\'s price index (#__j2commerce_productprice_index) is rebuilt '
			. 'with the same computation as J2Commerce\'s Variable::runIndexes(); J2Commerce itself only '
			. 'rebuilds it on a full article save. '
			. 'Sale limits: pass a number to set a variant-specific limit, or list the field in '
			. 'use_store_default to inherit the store setting again. '
			. 'Stock on hand is NOT set here: use set_j2commerce_stock. Turning manage_stock on for a variant '
			. 'with no stock row means J2Commerce treats it as zero in stock. '
			. 'Advanced (tier, group, date) prices are separate rows: set_j2commerce_product_price.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'variant_id'        => ['type' => 'integer'],
				'product_id'        => ['type' => 'integer', 'description' => 'Alternative to variant_id: the product\'s master variant.'],
				'price'             => ['type' => 'number'],
				'sku'               => ['type' => 'string'],
				'upc'               => ['type' => 'string'],
				'shipping'          => ['type' => 'boolean', 'description' => 'true = the item needs shipping.'],
				'weight'            => ['type' => 'number'],
				'weight_class_id'   => ['type' => 'integer'],
				'length'            => ['type' => 'number'],
				'width'             => ['type' => 'number'],
				'height'            => ['type' => 'number'],
				'length_class_id'   => ['type' => 'integer'],
				'manage_stock'      => ['type' => 'boolean'],
				'allow_backorder'   => ['type' => 'string', 'enum' => ['no', 'allow', 'allow_and_notify']],
				'min_sale_qty'      => ['type' => 'number'],
				'max_sale_qty'      => ['type' => 'number', 'description' => '0 = no maximum.'],
				'notify_qty'        => ['type' => 'number', 'description' => 'Low-stock threshold.'],
				'use_store_default' => [
					'type'  => 'array',
					'items' => ['type' => 'string', 'enum' => self::STORE_DEFAULTABLE],
					'description' => 'Sale-limit fields to switch back to the store-wide setting.',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->j2Installed()) {
			return $this->j2NotInstalledError();
		}

		$this->j2RequireAccess($actor, 'j2commerce.editproducts');

		$db = $this->db;

		if (!empty($arguments['variant_id'])) {
			$variantId = (int) $arguments['variant_id'];
		} elseif (!empty($arguments['product_id'])) {
			$product = $this->requireProduct((int) $arguments['product_id']);

			if (\in_array((string) $product['product_type'], self::VARIANT_TYPES, true)) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Product ' . (int) $arguments['product_id'] . ' is ' . $product['product_type']
						. ': shoppers buy its child variants, so editing the master variant would change '
						. 'nothing they see. Pass the child\'s variant_id (get_j2commerce_product lists them). '
						. 'Nothing was written.',
				], true);
			}

			$variantId = $this->masterVariantId((int) $arguments['product_id']);

			if ($variantId === null) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Product ' . (int) $arguments['product_id'] . ' has no master variant, so it '
						. 'cannot be priced. Repair it from J2Commerce\'s Database Health screen. Nothing was written.',
				], true);
			}
		} else {
			throw new \InvalidArgumentException('Pass variant_id or product_id.');
		}

		$row = $db->setQuery(
			'SELECT * FROM ' . $db->quoteName($this->j2Table('variants')) . ' WHERE j2commerce_variant_id = ' . $variantId
		)->loadAssoc();

		if (!\is_array($row)) {
			return ToolResult::json(['ok' => false, 'error' => 'No variant with id ' . $variantId . '. Nothing was written.'], true);
		}

		$productId = (int) $row['product_id'];
		$product   = $this->requireProduct($productId);
		$sets      = [];
		$changes   = [];

		$apply = function (string $column, string|int|float $value) use (&$sets, &$changes, $row, $db): void {
			$before = $row[$column];

			if (\is_float($value) ? abs((float) $before - $value) < 0.000001 : (string) $before === (string) $value) {
				return;
			}

			$sets[]           = $db->quoteName($column) . ' = ' . (\is_int($value) ? $value : $db->quote((string) $value));
			$changes[$column] = ['before' => $before, 'after' => $value];
		};

		foreach (self::DECIMAL_FIELDS as $field) {
			if (\array_key_exists($field, $arguments)) {
				$value = (float) $arguments[$field];

				if ($value < 0) {
					throw new \InvalidArgumentException($field . ' cannot be negative. Nothing was written.');
				}

				$apply($field, $value);
			}
		}

		foreach (['sku', 'upc'] as $field) {
			if (\array_key_exists($field, $arguments)) {
				$value = (string) $arguments[$field];
				$this->j2RequireFits($field, $value, 255);
				$apply($field, $value);
			}
		}

		foreach (['weight_class_id', 'length_class_id'] as $field) {
			if (\array_key_exists($field, $arguments)) {
				$apply($field, (int) $arguments[$field]);
			}
		}

		foreach (['shipping', 'manage_stock'] as $flag) {
			if (\array_key_exists($flag, $arguments)) {
				$apply($flag, (bool) $arguments[$flag] ? 1 : 0);
			}
		}

		if (\array_key_exists('allow_backorder', $arguments)) {
			$code = array_search((string) $arguments['allow_backorder'], self::BACKORDER_LABELS, true);

			if ($code === false) {
				throw new \InvalidArgumentException('allow_backorder must be no, allow or allow_and_notify.');
			}

			$apply('allow_backorder', (int) $code);
		}

		$inherit = array_map('strval', (array) ($arguments['use_store_default'] ?? []));

		foreach (self::STORE_DEFAULTABLE as $field) {
			$flag = 'use_store_config_' . $field;

			if (\in_array($field, $inherit, true)) {
				if (\array_key_exists($field, $arguments)) {
					throw new \InvalidArgumentException($field . ' was given a value AND listed in use_store_default. Pick one.');
				}

				$apply($flag, 1);
				continue;
			}

			if (\array_key_exists($field, $arguments)) {
				$value = (float) $arguments[$field];

				if ($value < 0) {
					throw new \InvalidArgumentException($field . ' cannot be negative.');
				}

				$apply($field, $value);
				$apply($flag, 0);
			}
		}

		if (isset($changes['min_sale_qty']) || isset($changes['max_sale_qty'])) {
			$min = (float) ($changes['min_sale_qty']['after'] ?? $row['min_sale_qty']);
			$max = (float) ($changes['max_sale_qty']['after'] ?? $row['max_sale_qty']);

			if ($max > 0 && $min > $max) {
				throw new \InvalidArgumentException('min_sale_qty (' . $min . ') would exceed max_sale_qty (' . $max . '), '
					. 'so nobody could add it to the cart. Nothing was written.');
			}
		}

		if ($sets === []) {
			return ToolResult::json([
				'ok'      => true,
				'changed' => [],
				'note'    => 'Every field passed already holds that value. Nothing was written.',
			]);
		}

		$db->setQuery(
			'UPDATE ' . $db->quoteName($this->j2Table('variants'))
			. ' SET ' . implode(', ', $sets)
			. ', modified_on = ' . $db->quote($this->j2Now()) . ', modified_by = ' . (int) $actor->id
			. ' WHERE j2commerce_variant_id = ' . $variantId
		)->execute();

		$response = [
			'ok'         => true,
			'variant_id' => $variantId,
			'product_id' => $productId,
			'is_master'  => (int) $row['is_master'] === 1,
			'changed'    => $changes,
		];

		if (isset($changes['price']) && (int) $row['is_master'] === 0) {
			$index = $this->rebuildPriceIndex($productId, (string) $product['product_type']);

			if ($index !== null) {
				$response['price_index_rebuilt'] = $index;
			}
		}

		if (isset($changes['sku']) && ($collisions = $this->skuCollisions((string) $changes['sku']['after'], $variantId)) !== []) {
			$response['sku_warning'] = 'SKU "' . $changes['sku']['after'] . '" is also used by product(s) '
				. implode(', ', array_unique($collisions)) . '. J2Commerce does not enforce unique SKUs.';
		}

		if (isset($changes['manage_stock']) && (int) $changes['manage_stock']['after'] === 1) {
			$hasRow = (int) $db->setQuery(
				'SELECT COUNT(*) FROM ' . $db->quoteName($this->j2Table('productquantities')) . ' WHERE variant_id = ' . $variantId
			)->loadResult();

			if ($hasRow === 0) {
				$response['stock_warning'] = 'Stock management is now on but this variant has no stock row, so '
					. 'J2Commerce treats it as zero on hand. Set the count with set_j2commerce_stock.';
			}
		}

		$response['variant'] = array_values(array_filter(
			$this->variantsFor($productId),
			static fn (array $v): bool => $v['variant_id'] === $variantId
		))[0] ?? null;

		$response['untouched'] = 'Only the columns in changed were written. The product row, other variants, '
			. 'stock quantities, advanced prices and the article were not touched.';

		return ToolResult::json($response);
	}
}
