<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Pricing;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

/**
 * Create or update ONE advanced-price row. Row at a time on purpose: the
 * product editor rewrites a variant's whole price set from its form, and a
 * caller who means "add a tier" should not be able to lose the others.
 */
final class SetProductPriceTool extends AbstractTool
{
	use J2commerceBootTrait;
	use PriceSupportTrait;

	public function getName(): string { return 'set_j2commerce_product_price'; }

	public function getDescription(): string
	{
		return 'Create or update ONE J2Commerce advanced price row (quantity tier, customer-group price or '
			. 'date-window sale price). To create, pass variant_id and price plus any of quantity_from, '
			. 'quantity_to, date_from, date_to and customer_group_id. To update, pass price_id and only the '
			. 'fields to change. One row per call, so adding a tier can never disturb the others. '
			. 'Rules enforced because J2Commerce would otherwise store the row and silently never use it '
			. '(src/Helper/ProductHelper.php:3889, 3916-3985): the price must be LOWER than the variant\'s '
			. 'base price — J2Commerce only ever applies an advanced price that undercuts base; '
			. 'customer_group_id must be an existing Joomla group, and omitting it means every shopper (0 '
			. 'is refused: it matches nobody); quantity_to must not be below quantity_from (0 or omitted = '
			. 'no bound); date_to must not be before date_from. Dates are YYYY-MM-DD or YYYY-MM-DD HH:MM:SS '
			. 'in UTC, which is how J2Commerce compares them; a bare date_to means the end of that day. '
			. 'To clear a bound on update, pass it as 0 (quantities) or an empty string (dates). '
			. 'For variable products, prices belong to the CHILD variant being sold.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'price_id'          => ['type' => 'integer', 'description' => 'Update this row. Omit to create.'],
				'variant_id'        => ['type' => 'integer', 'description' => 'Required to create.'],
				'price'             => ['type' => 'number', 'description' => 'Required to create. Must be below the variant base price.'],
				'quantity_from'     => ['type' => 'number', 'description' => 'Minimum cart quantity. 0 = no lower bound.'],
				'quantity_to'       => ['type' => 'number', 'description' => 'Maximum cart quantity. 0 = no upper bound.'],
				'date_from'         => ['type' => 'string', 'description' => 'UTC. Empty = no start.'],
				'date_to'           => ['type' => 'string', 'description' => 'UTC. Empty = no end.'],
				'customer_group_id' => ['type' => 'integer', 'description' => 'Joomla user group. Omit for all shoppers.'],
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

		$db       = $this->db;
		$priceId  = (int) ($arguments['price_id'] ?? 0);
		$existing = null;

		if ($priceId > 0) {
			$existing = $db->setQuery(
				'SELECT * FROM ' . $db->quoteName($this->j2Table('product_prices')) . ' WHERE j2commerce_productprice_id = ' . $priceId
			)->loadAssoc();

			if (!\is_array($existing)) {
				return ToolResult::json(['ok' => false, 'error' => 'No advanced price with id ' . $priceId . '. Nothing was written.'], true);
			}

			if (isset($arguments['variant_id']) && (int) $arguments['variant_id'] !== (int) $existing['variant_id']) {
				throw new \InvalidArgumentException('A price row cannot be moved to another variant. Delete it and create a new one.');
			}
		}

		$variantId = $existing !== null ? (int) $existing['variant_id'] : $this->requirePositiveInt($arguments, 'variant_id');
		$base      = $this->variantBasePrice($variantId);

		if ($base === null) {
			return ToolResult::json(['ok' => false, 'error' => 'No variant with id ' . $variantId . '. Nothing was written.'], true);
		}

		if ($existing === null && !\array_key_exists('price', $arguments)) {
			throw new \InvalidArgumentException('price is required to create an advanced price.');
		}

		$row = [
			'price'             => \array_key_exists('price', $arguments) ? (float) $arguments['price'] : (float) $existing['price'],
			'quantity_from'     => \array_key_exists('quantity_from', $arguments) ? (float) $arguments['quantity_from'] : ($existing !== null ? $existing['quantity_from'] : null),
			'quantity_to'       => \array_key_exists('quantity_to', $arguments) ? (float) $arguments['quantity_to'] : ($existing !== null ? $existing['quantity_to'] : null),
			'date_from'         => \array_key_exists('date_from', $arguments) ? $this->parseUtc('date_from', $arguments['date_from']) : ($existing !== null ? $this->cleanDate($existing['date_from']) : null),
			'date_to'           => \array_key_exists('date_to', $arguments) ? $this->parseUtc('date_to', $arguments['date_to']) : ($existing !== null ? $this->cleanDate($existing['date_to']) : null),
			'customer_group_id' => \array_key_exists('customer_group_id', $arguments) ? (int) $arguments['customer_group_id'] : ($existing !== null ? $existing['customer_group_id'] : null),
		];

		if ($row['price'] < 0) {
			throw new \InvalidArgumentException('price cannot be negative. Nothing was written.');
		}

		if ($row['price'] >= $base) {
			return ToolResult::json([
				'ok'         => false,
				'error'      => 'An advanced price of ' . $row['price'] . ' is not below this variant\'s base price of '
					. $base . '. J2Commerce only applies an advanced price that undercuts base '
					. '(src/Helper/ProductHelper.php:3889), so this row would be stored and never used. Nothing '
					. 'was written. To raise the price for everyone, change the base price with '
					. 'update_j2commerce_variant.',
				'base_price' => $base,
			], true);
		}

		$groupTitle = null;

		if ($row['customer_group_id'] !== null) {
			if ((int) $row['customer_group_id'] === 0) {
				throw new \InvalidArgumentException('customer_group_id 0 is not a Joomla group and would match no '
					. 'shopper' . ($existing !== null && !\array_key_exists('customer_group_id', $arguments)
						? ' — and this existing row already holds 0, so it has never applied to anyone'
						: '') . '. For every shopper pass customer_group_id: 1 (Public, which J2Commerce adds to '
					. 'every shopper\'s groups) or, when creating, omit it. Nothing was written.');
			}

			$groupTitle = $this->requireGroup((int) $row['customer_group_id']);
		}

		$qFrom = (float) ($row['quantity_from'] ?? 0);
		$qTo   = (float) ($row['quantity_to'] ?? 0);

		if ($qFrom < 0 || $qTo < 0) {
			throw new \InvalidArgumentException('Quantities cannot be negative.');
		}

		if ($qTo > 0 && $qTo < $qFrom) {
			throw new \InvalidArgumentException('quantity_to (' . $qTo . ') is below quantity_from (' . $qFrom . '), '
				. 'so no cart quantity could ever match. Nothing was written.');
		}

		if ($row['date_from'] !== null && $row['date_to'] !== null && $row['date_to'] < $row['date_from']) {
			throw new \InvalidArgumentException('date_to is before date_from, so the window is empty. Nothing was written.');
		}

		$values = [
			'variant_id'        => $variantId,
			'price'             => $row['price'],
			'quantity_from'     => $row['quantity_from'],
			'quantity_to'       => $row['quantity_to'],
			'date_from'         => $row['date_from'],
			'date_to'           => $row['date_to'],
			'customer_group_id' => $row['customer_group_id'] !== null ? (int) $row['customer_group_id'] : null,
		];

		$obj = (object) $values;

		if ($existing === null) {
			$obj->params = '';
			$db->insertObject($this->j2Table('product_prices'), $obj, 'j2commerce_productprice_id');
			$priceId = (int) $obj->j2commerce_productprice_id;
		} else {
			$obj->j2commerce_productprice_id = $priceId;
			$db->updateObject($this->j2Table('product_prices'), $obj, 'j2commerce_productprice_id', true);
		}

		$saved = $db->setQuery(
			'SELECT * FROM ' . $db->quoteName($this->j2Table('product_prices')) . ' WHERE j2commerce_productprice_id = ' . $priceId
		)->loadAssoc();

		$response = [
			'ok'         => true,
			'action'     => $existing === null ? 'created' : 'updated',
			'price'      => $this->shapePrice($saved, $base),
			'base_price' => $base,
			'applies_to' => ($groupTitle !== null ? 'members of "' . $groupTitle . '"' : 'every shopper')
				. ($qFrom > 0 || $qTo > 0 ? ', cart quantity ' . ($qFrom > 0 ? $qFrom : 1) . ($qTo > 0 ? '-' . $qTo : '+') : '')
				. ($row['date_from'] !== null || $row['date_to'] !== null
					? ', ' . ($row['date_from'] ?? 'now') . ' to ' . ($row['date_to'] ?? 'open-ended') . ' UTC' : ''),
		];

		if ($existing !== null) {
			$response['before'] = $this->shapePrice($existing, $base);
		}

		return ToolResult::json($response);
	}
}
