<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Products;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

/**
 * Turn an existing Joomla article into a J2Commerce product.
 *
 * This is exactly what J2Commerce's own "create product from article" action
 * does (`src/Controller/ProductController.php:147-277`): one product row and
 * one master variant, inserted directly, with the same column values. It does
 * not go through `ProductService::saveProduct()`, which zeroes every field it is
 * not given. The caller's price, SKU, stock and shipping data are then applied
 * to the fresh rows, stock through J2Commerce's own
 * `InventoryModel::saveInventoryItem()` so `onJ2CommerceAfterStockAdjust` fires.
 *
 * The article must already exist — create it with core `create_article`.
 * The product's name and page ARE the article; there is nowhere else to put them.
 */
final class CreateProductTool extends AbstractTool
{
	use J2commerceBootTrait;
	use ProductSupportTrait;

	/** Master-variant-only types. Variable types need option-generated child variants. */
	private const CREATABLE_TYPES = ['simple', 'configurable', 'downloadable'];

	public function getName(): string { return 'create_j2commerce_product'; }

	public function getDescription(): string
	{
		return 'Make an existing Joomla article into a J2Commerce product. In J2Commerce a product IS an '
			. 'article: the article title is the product name, the article is the product page, and the '
			. 'product rows hang off it by product_source=com_content + product_source_id. So create the '
			. 'article first (core create_article), then call this with its article_id. '
			. 'This does exactly what J2Commerce\'s own "create product from article" action does — inserts '
			. 'one product row and one master variant with the vendor\'s own defaults '
			. '(src/Controller/ProductController.php:211-277) — and then applies what you pass: sku '
			. '(default PROD-<id>, the vendor\'s own), price, stock, shipping, weight, dimensions, tax '
			. 'profile and manufacturer. Stock goes through J2Commerce\'s InventoryModel so its stock event '
			. 'fires. '
			. 'Types: simple, configurable or downloadable — all priced and stocked on the master variant. '
			. 'Variable and flexivariable products are REFUSED: their sellable items are child variants '
			. 'generated from options in the article editor, and building them by hand is how a catalogue '
			. 'ends up with variants J2Commerce cannot match to a selection. Create those in the editor. '
			. 'Configurable options and downloadable files are attached in the editor too. '
			. 'shipping defaults to true (the product needs shipping) except for downloadable, where it '
			. 'defaults to false. The vendor\'s own action defaults it to false for every type, which '
			. 'silently makes a physical product ship free; that default is not copied. '
			. 'Refused if the article does not exist or already has a product (the pair is UNIQUE).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'article_id'      => ['type' => 'integer', 'description' => 'Existing Joomla article id. Required.'],
				'product_type'    => ['type' => 'string', 'enum' => self::CREATABLE_TYPES, 'description' => 'Default simple.'],
				'sku'             => ['type' => 'string', 'description' => 'Default PROD-<product id>.'],
				'upc'             => ['type' => 'string'],
				'price'           => ['type' => 'number', 'description' => 'Base price, in the store currency, before tax.'],
				'manage_stock'    => ['type' => 'boolean', 'description' => 'Default false. Only variants with stock management on are ever debited by orders.'],
				'quantity'        => ['type' => 'integer', 'description' => 'Initial stock on hand. Setting it turns manage_stock on unless manage_stock is passed as false.'],
				'allow_backorder' => ['type' => 'string', 'enum' => ['no', 'allow', 'allow_and_notify']],
				'shipping'        => ['type' => 'boolean', 'description' => 'Whether the product needs shipping. Default true, false for downloadable.'],
				'weight'          => ['type' => 'number'],
				'weight_class_id' => ['type' => 'integer'],
				'length'          => ['type' => 'number'],
				'width'           => ['type' => 'number'],
				'height'          => ['type' => 'number'],
				'length_class_id' => ['type' => 'integer'],
				'taxprofile_id'   => ['type' => 'integer', 'description' => '0 = no tax. list_j2commerce_tax_profiles.'],
				'manufacturer_id' => ['type' => 'integer'],
				'enabled'         => ['type' => 'boolean', 'description' => 'Default true.'],
				'visibility'      => ['type' => 'boolean', 'description' => 'Shown in product listings. Default true.'],
			],
			'required' => ['article_id'],
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

		// The vendor's own action takes core.create (ProductController.php:153-162).
		if (!$actor->authorise('core.create', 'com_j2commerce')) {
			throw new \RuntimeException('Creating a product requires core.create on com_j2commerce, as J2Commerce\'s own action does.');
		}

		$db        = $this->db;
		$articleId = $this->requirePositiveInt($arguments, 'article_id');
		$type      = (string) ($arguments['product_type'] ?? 'simple');

		if (!\in_array($type, self::CREATABLE_TYPES, true)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'product_type "' . $type . '" cannot be created here. Variable and flexivariable '
					. 'products sell child variants generated from options in the article editor. Nothing was '
					. 'written.',
			], true);
		}

		$article = $db->setQuery(
			$db->getQuery(true)
				->select(['id', 'title', 'state'])
				->from($db->quoteName('#__content'))
				->where($db->quoteName('id') . ' = ' . $articleId)
		)->loadAssoc();

		if (!\is_array($article)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No article with id ' . $articleId . '. Create it first with core create_article — its '
					. 'title becomes the product name. Nothing was written.',
			], true);
		}

		$existing = (int) $db->setQuery(
			$db->getQuery(true)
				->select($db->quoteName('j2commerce_product_id'))
				->from($db->quoteName($this->j2Table('products')))
				->where($db->quoteName('product_source') . ' = ' . $db->quote('com_content'))
				->where($db->quoteName('product_source_id') . ' = ' . $articleId)
		)->loadResult();

		if ($existing > 0) {
			return ToolResult::json([
				'ok'         => false,
				'error'      => 'Article ' . $articleId . ' is already product ' . $existing . '. An article can '
					. 'carry one product. Nothing was written.',
				'product_id' => $existing,
			], true);
		}

		$taxProfile   = (int) ($arguments['taxprofile_id'] ?? 0);
		$manufacturer = (int) ($arguments['manufacturer_id'] ?? 0);
		$this->requireTaxProfile($taxProfile);
		$this->requireManufacturer($manufacturer);

		if (isset($arguments['sku'])) {
			$this->j2RequireFits('sku', (string) $arguments['sku'], 255);
		}

		$hasQty      = \array_key_exists('quantity', $arguments);
		$manageStock = \array_key_exists('manage_stock', $arguments) ? (bool) $arguments['manage_stock'] : $hasQty;
		$backorder   = array_search((string) ($arguments['allow_backorder'] ?? 'no'), self::BACKORDER_LABELS, true);
		$shipping    = \array_key_exists('shipping', $arguments) ? (bool) $arguments['shipping'] : $type !== 'downloadable';
		$now         = $this->j2Now();
		$uid         = (int) $actor->id;

		// Column values as ProductController::createFromArticle() sets them.
		$product = (object) [
			'visibility'        => \array_key_exists('visibility', $arguments) ? ((bool) $arguments['visibility'] ? 1 : 0) : 1,
			'product_source'    => 'com_content',
			'product_source_id' => $articleId,
			'product_type'      => $type,
			'main_tag'          => '',
			'taxprofile_id'     => $taxProfile,
			'manufacturer_id'   => $manufacturer,
			'vendor_id'         => 0,
			'has_options'       => 0,
			'addtocart_text'    => 'COM_J2COMMERCE_ADD_TO_CART',
			'enabled'           => \array_key_exists('enabled', $arguments) ? ((bool) $arguments['enabled'] ? 1 : 0) : 1,
			'plugins'           => '',
			'params'            => '',
			'created_on'        => $now,
			'created_by'        => $uid,
			'modified_on'       => $now,
			'modified_by'       => $uid,
			'up_sells'          => '',
			'cross_sells'       => '',
			'productfilter_ids' => '',
			'hits'              => 0,
		];

		$db->transactionStart();

		try {
			$db->insertObject($this->j2Table('products'), $product, 'j2commerce_product_id');
			$productId = (int) $product->j2commerce_product_id;

			$variant = (object) [
				'product_id'                    => $productId,
				'sku'                           => isset($arguments['sku']) ? (string) $arguments['sku'] : 'PROD-' . $productId,
				'upc'                           => (string) ($arguments['upc'] ?? ''),
				'price'                         => (float) ($arguments['price'] ?? 0),
				'pricing_calculator'            => 'standard',
				'shipping'                      => $shipping ? 1 : 0,
				'params'                        => '',
				'length'                        => (float) ($arguments['length'] ?? 0),
				'width'                         => (float) ($arguments['width'] ?? 0),
				'height'                        => (float) ($arguments['height'] ?? 0),
				'length_class_id'               => (int) ($arguments['length_class_id'] ?? 0),
				'weight'                        => (float) ($arguments['weight'] ?? 0),
				'weight_class_id'               => (int) ($arguments['weight_class_id'] ?? 0),
				'manage_stock'                  => $manageStock ? 1 : 0,
				'quantity_restriction'          => 0,
				'min_out_qty'                   => 0,
				'use_store_config_min_out_qty'  => 1,
				'min_sale_qty'                  => 1,
				'use_store_config_min_sale_qty' => 1,
				'max_sale_qty'                  => 0,
				'use_store_config_max_sale_qty' => 1,
				'notify_qty'                    => 0,
				'use_store_config_notify_qty'   => 1,
				'availability'                  => 1,
				'sold'                          => 0,
				'allow_backorder'               => $backorder === false ? 0 : (int) $backorder,
				'isdefault_variant'             => 1,
				'is_master'                     => 1,
				'created_on'                    => $now,
				'created_by'                    => $uid,
				'modified_on'                   => $now,
				'modified_by'                   => $uid,
			];

			$db->insertObject($this->j2Table('variants'), $variant, 'j2commerce_variant_id');
			$variantId = (int) $variant->j2commerce_variant_id;

			$db->transactionCommit();
		} catch (\Throwable $e) {
			$db->transactionRollback();

			throw new \RuntimeException('Creating the product failed and was rolled back; nothing was kept. ' . $e->getMessage());
		}

		$messages = [];

		if ($hasQty) {
			$qty          = (int) $arguments['quantity'];
			$availability = (!$manageStock || $qty > 0 || (int) $variant->allow_backorder > 0) ? 1 : 0;

			[$ok, $messages] = $this->j2Run(
				fn () => $this->j2Model('Inventory')->saveInventoryItem($productId, $variantId, $qty, $manageStock ? 1 : 0, $availability)
			);

			if (!$ok) {
				$messages[] = ['type' => 'warning', 'message' => 'The product was created, but J2Commerce\'s '
					. 'InventoryModel reported failure saving the initial stock. Set it with set_j2commerce_stock.'];
			}
		}

		$response = [
			'ok'           => true,
			'product_id'   => $productId,
			'variant_id'   => $variantId,
			'name'         => (string) $article['title'],
			'product_type' => $type,
			'variants'     => $this->variantsFor($productId),
			'note'         => 'Product created on article ' . $articleId . ', exactly as J2Commerce\'s own '
				. '"create from article" action would, with your values applied. Product images, configurable '
				. 'options and downloadable files are added in the article editor\'s J2Commerce tab.',
		];

		if ((int) $article['state'] !== 1) {
			$response['article_note'] = 'The article is not published, so shoppers cannot reach this product yet. '
				. 'Publish it with core update_article.';
		}

		if (($collisions = $this->skuCollisions((string) $variant->sku, $variantId)) !== []) {
			$response['sku_warning'] = 'SKU "' . $variant->sku . '" is also used by product(s) '
				. implode(', ', array_unique($collisions)) . '. J2Commerce does not enforce unique SKUs; '
				. 'integrations that key on SKU will confuse them.';
		}

		if ((float) $variant->price === 0.0) {
			$response['price_warning'] = 'No price was given, so the product is priced at 0.';
		}

		if ($messages !== []) {
			$response['j2commerce_messages'] = $messages;
		}

		return ToolResult::json($response);
	}
}
