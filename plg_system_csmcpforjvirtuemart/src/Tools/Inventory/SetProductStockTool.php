<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Inventory;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Set or adjust `product_in_stock` with a targeted UPDATE.
 *
 * Another textbook case for direct SQL: routing a stock correction through
 * `ProductModel::store()` would delete every price row, category assignment,
 * manufacturer link, shopper group and custom field the caller failed to
 * mention. VirtueMart has its own narrow path for this too
 * (`ProductModel::updateStockInDB()`, `models/product.php:3508`, a single
 * UPDATE at `:3545`), which is the same shape as what happens here.
 *
 * `product_ordered` is deliberately NOT settable. It is derived from live order
 * state and hand-editing it makes the shop's oversold detection lie.
 */
final class SetProductStockTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'set_virtuemart_product_stock'; }

	public function getDescription(): string
	{
		return 'Set or adjust a product\'s stock level. Requires product_id, plus either in_stock (an '
			. 'absolute value) or adjust_by (a signed delta). '
			. 'This is a targeted UPDATE on product_in_stock and, optionally, low_stock_notification. '
			. 'Nothing else about the product is read or written — no price row, category assignment, '
			. 'manufacturer link, shopper group or custom field. That is the whole reason it is a '
			. 'separate tool: a stock correction routed through VirtueMart\'s ProductModel::store() would '
			. 'delete every satellite the caller did not happen to include '
			. '(models/product.php:2895-2960). VirtueMart has an equally narrow path of its own for the '
			. 'same reason — updateStockInDB(), models/product.php:3508, a single UPDATE at :3545. '
			. 'adjust_by is applied against the CURRENT value read in the same call. It is not atomic '
			. 'against concurrent orders, so on a busy shop a delta can race a checkout. Prefer in_stock '
			. 'when you know the true figure, for example after a stock count. '
			. 'product_ordered is NOT settable here. It is derived from live order state — VirtueMart '
			. 'increments it when an order enters a status whose order_stock_handle is R and decrements '
			. 'it on the way out (models/orders.php:2067-2074) — so hand-editing it makes every oversold '
			. 'calculation in the shop wrong. If it looks incorrect, the cause is almost always '
			. 'handleStockAfterStatusChangedPerProduct silently skipping a stock adjustment because an '
			. 'order status code was not a published orderstates row (models/orders.php:2035-2038). '
			. 'product_sales is not settable either: it is a lifetime counter that VirtueMart moves in '
			. 'the OPPOSITE direction to product_in_stock (models/product.php:3533-3539). '
			. 'No low-stock email is sent. VirtueMart only sends those from within its own stock update '
			. '(models/product.php:3554-3565), which this deliberately does not call.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'product_id'             => ['type' => 'integer', 'description' => 'virtuemart_product_id. Required.'],
				'in_stock'               => ['type' => 'integer', 'description' => 'Absolute new value for product_in_stock.'],
				'adjust_by'              => ['type' => 'integer', 'description' => 'Signed delta applied to the current value. Not atomic against concurrent orders.'],
				'low_stock_notification' => ['type' => 'integer', 'description' => 'Threshold at which VirtueMart emails a low-stock warning. 0 means no threshold, not "warn at zero".'],
				'allow_negative'         => ['type' => 'boolean', 'description' => 'Permit a resulting stock level below zero. Default false, and the call refuses otherwise.'],
			],
			'required' => ['product_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->vmAdminBase() === null) {
			return $this->vmNotInstalledError();
		}

		if (!$this->vmTableExists('products')) {
			return $this->vmMissingTableError('products');
		}

		$productId = $this->requirePositiveInt($arguments, 'product_id');

		$current = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('virtuemart_product_id'),
					$this->db->quoteName('product_in_stock'),
					$this->db->quoteName('product_ordered'),
					$this->db->quoteName('product_sales'),
					$this->db->quoteName('low_stock_notification'),
					$this->db->quoteName('product_parent_id'),
				])
				->from($this->db->quoteName($this->vmTable('products')))
				->where($this->db->quoteName('virtuemart_product_id') . ' = ' . $productId)
		)->loadAssoc();

		if (!\is_array($current)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No product with virtuemart_product_id ' . $productId . '. Nothing was written.',
			], true);
		}

		$hasAbsolute = \array_key_exists('in_stock', $arguments);
		$hasDelta    = \array_key_exists('adjust_by', $arguments);
		$hasLow      = \array_key_exists('low_stock_notification', $arguments);

		if ($hasAbsolute && $hasDelta) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Supply either in_stock or adjust_by, not both. Nothing was written.',
			], true);
		}

		if (!$hasAbsolute && !$hasDelta && !$hasLow) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Nothing to do. Supply in_stock, adjust_by or low_stock_notification.',
				'current' => [
					'product_in_stock'       => (int) $current['product_in_stock'],
					'product_ordered'        => (int) $current['product_ordered'],
					'available'              => (int) $current['product_in_stock'] - (int) $current['product_ordered'],
					'low_stock_notification' => (int) $current['low_stock_notification'],
				],
			], true);
		}

		$before = (int) $current['product_in_stock'];
		$after  = $before;

		if ($hasAbsolute) {
			$after = (int) $arguments['in_stock'];
		} elseif ($hasDelta) {
			$after = $before + (int) $arguments['adjust_by'];
		}

		if ($after < 0 && !(bool) ($arguments['allow_negative'] ?? false)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'That would set product_in_stock to ' . $after . '. Negative stock is legal in '
					. 'the schema but almost always means a delta was applied twice or against the wrong '
					. 'product. Refusing; nothing was written.',
				'current_in_stock' => $before,
				'resolution'       => 'Pass allow_negative: true if a negative level is genuinely what '
					. 'you want, for example to record a backorder.',
			], true);
		}

		$sets    = [];
		$changed = [];

		if ($after !== $before) {
			$sets[]    = $this->db->quoteName('product_in_stock') . ' = ' . $after;
			$changed[] = 'product_in_stock';
		}

		$lowBefore = (int) $current['low_stock_notification'];
		$lowAfter  = $lowBefore;

		if ($hasLow) {
			$lowAfter = max(0, (int) $arguments['low_stock_notification']);

			if ($lowAfter !== $lowBefore) {
				$sets[]    = $this->db->quoteName('low_stock_notification') . ' = ' . $lowAfter;
				$changed[] = 'low_stock_notification';
			}
		}

		if ($changed === []) {
			return ToolResult::json([
				'ok'      => true,
				'changed' => [],
				'note'    => 'The stored values already match what was supplied. Nothing was written and '
					. 'modified_on was not stamped.',
				'virtuemart_product_id' => $productId,
				'product_in_stock'      => $before,
			]);
		}

		$now = $this->vmNow();

		$this->db->setQuery(
			'UPDATE ' . $this->db->quoteName($this->vmTable('products'))
			. ' SET ' . implode(', ', $sets)
			. ', ' . $this->db->quoteName('modified_on') . ' = ' . $this->db->quote($now)
			. ', ' . $this->db->quoteName('modified_by') . ' = ' . (int) $actor->id
			. ' WHERE ' . $this->db->quoteName('virtuemart_product_id') . ' = ' . $productId
		)->execute();

		$ordered   = (int) $current['product_ordered'];
		$available = $after - $ordered;

		$response = [
			'ok'                     => true,
			'virtuemart_product_id'  => $productId,
			'changed'                => $changed,
			'product_in_stock'       => ['before' => $before, 'after' => $after],
			'low_stock_notification' => ['before' => $lowBefore, 'after' => $lowAfter],
			'product_ordered'        => $ordered,
			'available'              => $available,
			'modified_on'            => $now,
			'untouched'              => 'product_ordered, product_sales and every satellite (prices, '
				. 'categories, manufacturers, shopper groups, custom fields) were not read or written.',
		];

		if ($available < 0) {
			$response['oversold_warning'] = 'product_ordered (' . $ordered . ') now exceeds '
				. 'product_in_stock (' . $after . '), so this product is oversold by ' . abs($available)
				. '. VirtueMart does not surface this anywhere.';
		}

		if ($lowAfter > 0 && $after <= $lowAfter) {
			$response['low_stock_warning'] = 'Stock is at or below the low_stock_notification threshold '
				. 'of ' . $lowAfter . '. No email was sent: VirtueMart only sends low-stock warnings from '
				. 'inside its own updateStockInDB() (models/product.php:3554-3565), which this tool does '
				. 'not call.';
		}

		if ((int) $current['product_parent_id'] === 0) {
			$variants = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select('COUNT(*)')
					->from($this->db->quoteName($this->vmTable('products')))
					->where($this->db->quoteName('product_parent_id') . ' = ' . $productId)
			)->loadResult();

			if ($variants > 0) {
				$response['variant_note'] = 'This product has ' . $variants . ' variant(s), each of '
					. 'which carries its own product_in_stock. Setting stock on the parent normally has '
					. 'no effect on what shoppers can buy, unless the shop uses shared_stock — in which '
					. 'case VirtueMart writes the PARENT when a variant sells '
					. '(models/product.php:3518-3523) and this is the right row after all.';
			}
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}
}
