<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Pricing;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Delete a single price row, warning when it was the product's last one.
 */
final class DeleteProductPriceTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'delete_virtuemart_product_price'; }

	public function getDescription(): string
	{
		return 'Delete ONE row from #__virtuemart_product_prices by price_id. '
			. 'Only the named row is deleted; every other price row for the product is left alone. This '
			. 'is the counterpart to set_virtuemart_product_price and exists for the same reason — '
			. 'VirtueMart\'s own price handling deletes every row it was not given '
			. '(models/product.php:2895-2913), so a set-based operation is the dangerous shape here. '
			. 'The product\'s has_prices join hint is recomputed afterwards. If this was the product\'s '
			. 'LAST price row, the response says so plainly: unless the shop runs with prices disabled, '
			. 'that product now displays with no price and cannot be bought, and nothing in VirtueMart '
			. 'reports it. '
			. 'Removing a shopper-group-specific price row does not hide the product from that group — it '
			. 'makes them fall back to the general (shopper group 0) price, or to no price at all if '
			. 'there is not one.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'price_id' => ['type' => 'integer', 'description' => 'virtuemart_product_price_id. Required.'],
			],
			'required' => ['price_id'],
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

		$priceId = $this->requirePositiveInt($arguments, 'price_id');

		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->vmTable('product_prices')))
				->where($this->db->quoteName('virtuemart_product_price_id') . ' = ' . $priceId)
		)->loadAssoc();

		if (!\is_array($row)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No price row with virtuemart_product_price_id ' . $priceId . '. Nothing was '
					. 'deleted.',
			], true);
		}

		$productId = (int) $row['virtuemart_product_id'];

		$this->db->setQuery(
			$this->db->getQuery(true)
				->delete($this->db->quoteName($this->vmTable('product_prices')))
				->where($this->db->quoteName('virtuemart_product_price_id') . ' = ' . $priceId)
		)->execute();

		$remaining = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName($this->vmTable('product_prices')))
				->where($this->db->quoteName('virtuemart_product_id') . ' = ' . $productId)
		)->loadResult();

		$flag = $remaining > 0 ? 1 : 0;

		$update                        = new \stdClass();
		$update->virtuemart_product_id = $productId;
		$update->has_prices            = $flag;

		$this->db->updateObject($this->vmTable('products'), $update, 'virtuemart_product_id');

		$response = [
			'ok'                          => true,
			'deleted_price_id'            => $priceId,
			'virtuemart_product_id'       => $productId,
			'deleted_row'                 => $row,
			'remaining_price_rows'        => $remaining,
			'has_prices'                  => $flag,
			'other_price_rows_untouched'  => 'Only the named row was deleted.',
		];

		if ($remaining === 0) {
			$response['warning'] = 'That was the product\'s LAST price row. Unless the shop runs with '
				. 'prices disabled, product ' . $productId . ' now displays with no price and cannot be '
				. 'bought. VirtueMart does not report this anywhere. Use set_virtuemart_product_price to '
				. 'add one back.';
		}

		if ((int) $row['virtuemart_shoppergroup_id'] > 0) {
			$response['shoppergroup_note'] = 'This was a price specific to shopper group '
				. (int) $row['virtuemart_shoppergroup_id'] . '. Members of that group now fall back to the '
				. 'general (shopper group 0) price, or to no price if there is not one.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}
}
