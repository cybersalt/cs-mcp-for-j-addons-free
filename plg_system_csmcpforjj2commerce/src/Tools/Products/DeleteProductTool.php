<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Products;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

/**
 * Delete a product's J2Commerce data through J2Commerce's own delete path,
 * `ProductService::deleteProduct()` (`src/Service/ProductService.php:251-280`):
 * the type behaviour's `onBeforeDelete()` and then `ProductTable::delete()`,
 * which cascades through variants, stock rows, prices, option values, images,
 * files, filters, the price index and cart items
 * (`src/Table/ProductTable.php:195-274`). The article is not touched.
 */
final class DeleteProductTool extends AbstractTool
{
	use J2commerceBootTrait;
	use ProductSupportTrait;

	public function getName(): string { return 'delete_j2commerce_product'; }

	public function getDescription(): string
	{
		return 'Delete a J2Commerce product\'s commerce data, through J2Commerce\'s own delete path '
			. '(ProductService::deleteProduct, which cascades through the product\'s variants, stock rows, '
			. 'tier prices, options and option values, images, downloadable-file records, filters, price '
			. 'index and any cart items holding it). The Joomla ARTICLE is left exactly as it is — delete or '
			. 'trash it separately with core tools if the page should go too. '
			. 'Call it first without confirm to see exactly what would be removed; pass confirm: true to '
			. 'delete. Past orders are not affected: an order line stores its own name, SKU and price, so '
			. 'order history survives, but its product_id will no longer resolve to a product. '
			. 'Typical use: clearing orphaned products whose article was deleted (list_j2commerce_products '
			. 'orphaned: true). To take a product off sale without losing it, use update_j2commerce_product '
			. 'enabled: false instead.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'product_id' => ['type' => 'integer', 'description' => 'Required.'],
				'confirm'    => ['type' => 'boolean', 'description' => 'Must be true to delete. Without it the call only reports what would go.'],
			],
			'required' => ['product_id'],
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

		if (!$actor->authorise('core.delete', 'com_j2commerce')) {
			throw new \RuntimeException('Deleting a product requires core.delete on com_j2commerce.');
		}

		$productId = $this->requirePositiveInt($arguments, 'product_id');
		$product   = $this->requireProduct($productId);
		$article   = $this->articleFor($product);
		$db        = $this->db;

		$variantIds = array_map('intval', $db->setQuery(
			'SELECT j2commerce_variant_id FROM ' . $db->quoteName($this->j2Table('variants')) . ' WHERE product_id = ' . $productId
		)->loadColumn() ?: []);

		$inVariants = $variantIds === [] ? '0' : implode(',', $variantIds);

		$count = fn (string $table, string $where): int => $this->j2TableExists($table)
			? (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName($this->j2Table($table)) . ' WHERE ' . $where)->loadResult()
			: 0;

		$impact = [
			'variants'          => \count($variantIds),
			'stock_rows'        => $count('productquantities', 'variant_id IN (' . $inVariants . ')'),
			'advanced_prices'   => $count('product_prices', 'variant_id IN (' . $inVariants . ')'),
			'product_options'   => $count('product_options', 'product_id = ' . $productId),
			'images'            => $count('productimages', 'product_id = ' . $productId),
			'files'             => $count('productfiles', 'product_id = ' . $productId),
			'filters'           => $count('product_filters', 'product_id = ' . $productId),
			'cart_items'        => $count('cartitems', 'product_id = ' . $productId),
		];

		$orderLines = $count('orderitems', 'product_id = ' . $productId);
		$stockOnHand = (int) $db->setQuery(
			'SELECT COALESCE(SUM(quantity), 0) FROM ' . $db->quoteName($this->j2Table('productquantities'))
			. ' WHERE variant_id IN (' . $inVariants . ')'
		)->loadResult();

		$preview = [
			'product_id'      => $productId,
			'name'            => $article['title'] ?? null,
			'product_type'    => (string) $product['product_type'],
			'article'         => $article !== null ? ['id' => (int) $article['id'], 'state' => (int) $article['state'], 'kept' => true] : null,
			'will_delete'     => $impact,
			'stock_on_hand'   => $stockOnHand,
			'past_order_lines' => $orderLines,
		];

		if (!(bool) ($arguments['confirm'] ?? false)) {
			return ToolResult::json([
				'ok'      => true,
				'dry_run' => true,
				'preview' => $preview,
				'note'    => 'Nothing was deleted. Call again with confirm: true to delete. '
					. ($orderLines > 0 ? $orderLines . ' past order line(s) reference this product; they keep their '
						. 'own name, SKU and price but will no longer link to a product. ' : '')
					. ($impact['cart_items'] > 0 ? $impact['cart_items'] . ' item(s) in live shopper carts will vanish. ' : '')
					. 'To take it off sale reversibly, use update_j2commerce_product enabled: false.',
			]);
		}

		$this->j2LoadLanguage();

		[$ok, $messages, $stray] = $this->j2Run(static function () use ($productId) {
			$service = new \J2Commerce\Component\J2commerce\Administrator\Service\ProductService();

			return $service->deleteProduct($productId);
		});

		$stillThere = $this->productRow($productId) !== null;

		if (!$ok || $stillThere) {
			return ToolResult::json([
				'ok'                  => false,
				'error'               => 'J2Commerce did not delete product ' . $productId . '.',
				'product_still_exists' => $stillThere,
				'j2commerce_messages' => $messages,
			], true);
		}

		$leftover = array_sum([
			$count('variants', 'product_id = ' . $productId),
			$count('productquantities', 'variant_id IN (' . $inVariants . ')'),
			$count('product_prices', 'variant_id IN (' . $inVariants . ')'),
		]);

		$response = [
			'ok'       => true,
			'deleted'  => $preview,
			'verified' => $leftover === 0
				? 'Product row, variants, stock rows and advanced prices are all gone.'
				: $leftover . ' variant, stock or price row(s) survived the vendor cascade. Run check_j2commerce_health.',
			'note'     => 'The article was not touched' . ($article !== null ? ' (article ' . (int) $article['id'] . ' still exists)' : '') . '.',
		];

		if ($messages !== []) {
			$response['j2commerce_messages'] = $messages;
		}

		if ($stray !== '') {
			$response['plugin_output'] = mb_substr($stray, 0, 500);
		}

		return ToolResult::json($response);
	}
}
