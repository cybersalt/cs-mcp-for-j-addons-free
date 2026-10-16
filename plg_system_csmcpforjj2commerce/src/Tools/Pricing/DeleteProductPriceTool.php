<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Pricing;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

final class DeleteProductPriceTool extends AbstractTool
{
	use J2commerceBootTrait;
	use PriceSupportTrait;

	public function getName(): string { return 'delete_j2commerce_product_price'; }

	public function getDescription(): string
	{
		return 'Delete ONE J2Commerce advanced price row by price_id (from list_j2commerce_product_prices or '
			. 'get_j2commerce_product). Only that row goes; the variant\'s base price and its other tiers are '
			. 'untouched. Shoppers who qualified for it fall back to the next-lowest qualifying row, or the '
			. 'base price. Past orders are unaffected — order lines store the price actually charged.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => ['price_id' => ['type' => 'integer', 'description' => 'Required.']],
			'required' => ['price_id'],
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

		$priceId = $this->requirePositiveInt($arguments, 'price_id');
		$table   = $this->db->quoteName($this->j2Table('product_prices'));

		$row = $this->db->setQuery('SELECT * FROM ' . $table . ' WHERE j2commerce_productprice_id = ' . $priceId)->loadAssoc();

		if (!\is_array($row)) {
			return ToolResult::json(['ok' => false, 'error' => 'No advanced price with id ' . $priceId . '. Nothing was deleted.'], true);
		}

		$this->db->setQuery('DELETE FROM ' . $table . ' WHERE j2commerce_productprice_id = ' . $priceId)->execute();

		return ToolResult::json([
			'ok'      => true,
			'deleted' => $this->shapePrice($row, $this->variantBasePrice((int) $row['variant_id'])),
			'remaining_rows_for_variant' => (int) $this->db->setQuery(
				'SELECT COUNT(*) FROM ' . $table . ' WHERE variant_id = ' . (int) $row['variant_id']
			)->loadResult(),
		]);
	}
}
