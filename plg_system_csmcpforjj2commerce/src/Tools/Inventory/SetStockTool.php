<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Inventory;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

/**
 * Set or adjust stock through J2Commerce's own stock writers.
 *
 *   - adjust_by goes to `InventoryHelper::adjustStockAndAvailability()`
 *     (`src/Helper/InventoryHelper.php:1332`), a single atomic
 *     `quantity = quantity + delta` UPDATE that also keeps the availability flag
 *     in step — the same call the admin's order editing uses. Safe against a
 *     concurrent checkout.
 *   - quantity (an absolute count) goes to `InventoryModel::saveInventoryItem()`
 *     (`src/Model/InventoryModel.php:308`), the Inventory screen's own save,
 *     which upserts the stock row and writes manage_stock and availability.
 *
 * Both fire `onJ2CommerceAfterStockAdjust`, so any stock-sync app sees the change.
 */
final class SetStockTool extends AbstractTool
{
	use J2commerceBootTrait;

	public function getName(): string { return 'set_j2commerce_stock'; }

	public function getDescription(): string
	{
		return 'Set or adjust a J2Commerce variant\'s stock on hand. Pass variant_id (or product_id for a '
			. 'simple, configurable or downloadable product\'s master variant) and EITHER quantity (an '
			. 'absolute count, e.g. after a stocktake) OR adjust_by (a signed delta, e.g. +12 for a delivery, '
			. '-1 for a breakage). '
			. 'adjust_by uses J2Commerce\'s own InventoryHelper::adjustStockAndAvailability() — one atomic '
			. 'quantity = quantity + delta UPDATE, safe against a checkout happening at the same moment, which '
			. 'also flips availability to out-of-stock at zero (unless backorders are allowed) and back to '
			. 'in-stock above it. quantity uses the Inventory screen\'s own InventoryModel::saveInventoryItem(). '
			. 'Both fire J2Commerce\'s onJ2CommerceAfterStockAdjust event, so stock-sync apps see the change. '
			. 'Prefer quantity when you know the true count; a delta computed from a stale read can race an '
			. 'order. '
			. 'Stock only matters for variants with manage_stock on — orders never move the rest. Pass '
			. 'manage_stock: true to switch it on in the same call. A resulting negative count is refused '
			. 'unless allow_negative: true (J2Commerce itself stores negative stock as "oversold"). '
			. 'For variable products, set the CHILD variant shoppers buy; product_id is refused for those.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'variant_id'     => ['type' => 'integer'],
				'product_id'     => ['type' => 'integer', 'description' => 'Master variant of a simple-family product.'],
				'quantity'       => ['type' => 'integer', 'description' => 'Absolute stock on hand.'],
				'adjust_by'      => ['type' => 'integer', 'description' => 'Signed delta. Atomic.'],
				'manage_stock'   => ['type' => 'boolean', 'description' => 'Also switch stock management on or off.'],
				'allow_negative' => ['type' => 'boolean', 'description' => 'Permit a result below zero. Default false.'],
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

		$hasAbs   = \array_key_exists('quantity', $arguments);
		$hasDelta = \array_key_exists('adjust_by', $arguments);

		if ($hasAbs === $hasDelta && !\array_key_exists('manage_stock', $arguments)) {
			throw new \InvalidArgumentException('Pass exactly one of quantity or adjust_by (or only manage_stock).');
		}

		if ($hasAbs && $hasDelta) {
			throw new \InvalidArgumentException('Pass quantity OR adjust_by, not both. Nothing was written.');
		}

		if (!empty($arguments['variant_id'])) {
			$variantId = (int) $arguments['variant_id'];
		} elseif (!empty($arguments['product_id'])) {
			$type = (string) $db->setQuery(
				'SELECT product_type FROM ' . $db->quoteName($this->j2Table('products')) . ' WHERE j2commerce_product_id = ' . (int) $arguments['product_id']
			)->loadResult();

			if ($type === '') {
				return ToolResult::json(['ok' => false, 'error' => 'No product with id ' . (int) $arguments['product_id'] . '. Nothing was written.'], true);
			}

			if (\in_array($type, ['variable', 'flexivariable'], true)) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Product ' . (int) $arguments['product_id'] . ' is ' . $type . ': shoppers buy its child '
						. 'variants, so set stock on the child\'s variant_id (list_j2commerce_stock product_id: '
						. (int) $arguments['product_id'] . '). Nothing was written.',
				], true);
			}

			$variantId = (int) $db->setQuery(
				'SELECT MIN(j2commerce_variant_id) FROM ' . $db->quoteName($this->j2Table('variants'))
				. ' WHERE product_id = ' . (int) $arguments['product_id'] . ' AND is_master = 1'
			)->loadResult();
		} else {
			throw new \InvalidArgumentException('Pass variant_id or product_id.');
		}

		$variant = $db->setQuery(
			'SELECT v.j2commerce_variant_id, v.product_id, v.manage_stock, v.availability, v.allow_backorder, q.quantity'
			. ' FROM ' . $db->quoteName($this->j2Table('variants')) . ' v'
			. ' LEFT JOIN ' . $db->quoteName($this->j2Table('productquantities')) . ' q ON q.variant_id = v.j2commerce_variant_id'
			. ' WHERE v.j2commerce_variant_id = ' . $variantId
		)->loadAssoc();

		if (!\is_array($variant)) {
			return ToolResult::json(['ok' => false, 'error' => 'No variant with id ' . $variantId . '. Nothing was written.'], true);
		}

		$before      = $variant['quantity'] !== null ? (int) $variant['quantity'] : null;
		$manage      = \array_key_exists('manage_stock', $arguments) ? ((bool) $arguments['manage_stock'] ? 1 : 0) : (int) $variant['manage_stock'];
		$backorders  = (int) $variant['allow_backorder'] > 0;
		$allowNeg    = (bool) ($arguments['allow_negative'] ?? false) || $backorders;
		$target      = $hasAbs ? (int) $arguments['quantity'] : ($hasDelta ? ($before ?? 0) + (int) $arguments['adjust_by'] : $before);

		if ($target !== null && $target < 0 && !$allowNeg) {
			return ToolResult::json([
				'ok'               => false,
				'error'            => 'That leaves stock at ' . $target . '. Negative stock means oversold; refusing '
					. 'unless allow_negative: true (or the variant allows backorders). Nothing was written.',
				'current_quantity' => $before,
			], true);
		}

		$messages = [];

		if ($hasDelta) {
			$delta = (int) $arguments['adjust_by'];

			if ($manage !== (int) $variant['manage_stock']) {
				$db->setQuery(
					'UPDATE ' . $db->quoteName($this->j2Table('variants')) . ' SET manage_stock = ' . $manage
					. ' WHERE j2commerce_variant_id = ' . $variantId
				)->execute();
			}

			$this->j2LoadLanguage();

			[$result, $messages] = $this->j2Run(
				static fn () => \J2Commerce\Component\J2commerce\Administrator\Helper\InventoryHelper::adjustStockAndAvailability($variantId, $delta, $backorders)
			);

			$method = 'InventoryHelper::adjustStockAndAvailability (atomic)';
		} else {
			// Absolute (or manage_stock only): the Inventory screen's own save.
			$qty          = $hasAbs ? $target : null;
			$availability = ($manage === 0 || ($qty ?? $before ?? 0) > 0 || $backorders) ? 1 : 0;

			[$result, $messages] = $this->j2Run(
				fn () => $this->j2Model('Inventory')->saveInventoryItem((int) $variant['product_id'], $variantId, $qty, $manage, $availability)
			);

			if ($result === false) {
				return ToolResult::json([
					'ok'                  => false,
					'error'               => 'J2Commerce\'s InventoryModel reported a failure saving stock.',
					'j2commerce_messages' => $messages,
				], true);
			}

			$method = 'InventoryModel::saveInventoryItem';
		}

		$after = $db->setQuery(
			'SELECT v.manage_stock, v.availability, q.quantity FROM ' . $db->quoteName($this->j2Table('variants')) . ' v'
			. ' LEFT JOIN ' . $db->quoteName($this->j2Table('productquantities')) . ' q ON q.variant_id = v.j2commerce_variant_id'
			. ' WHERE v.j2commerce_variant_id = ' . $variantId
		)->loadAssoc();

		$response = [
			'ok'           => true,
			'variant_id'   => $variantId,
			'product_id'   => (int) $variant['product_id'],
			'via'          => $method,
			'quantity'     => ['before' => $before, 'after' => $after['quantity'] !== null ? (int) $after['quantity'] : null],
			'manage_stock' => (int) $after['manage_stock'] === 1,
			'availability' => (int) $after['availability'] === 1 ? 'in_stock' : 'out_of_stock',
		];

		if ($hasDelta && $before !== null && $after['quantity'] !== null && (int) $after['quantity'] !== $before + (int) $arguments['adjust_by']) {
			$response['concurrent_change'] = 'Stock moved by something else between the read and the update '
				. '(probably an order). The delta was still applied atomically to the live value.';
		}

		if ((int) $after['manage_stock'] !== 1) {
			$response['note'] = 'Stock management is OFF for this variant, so orders will not move this count and '
				. 'J2Commerce will not stop sales at zero. Pass manage_stock: true to change that.';
		}

		if ($messages !== []) {
			$response['j2commerce_messages'] = $messages;
		}

		return ToolResult::json($response);
	}
}
