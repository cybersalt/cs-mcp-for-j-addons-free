<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Products;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

/**
 * Change product-row settings with a targeted UPDATE of the named columns.
 *
 * Deliberately not `ProductService::saveProduct()`: given only the fields the
 * caller wants changed, every Simple / Variable / Flexivariable `onBeforeSave`
 * forces the absent ones to 0 or '' — price, dimensions, weight, tax profile,
 * manufacturer, vendor, sale limits — and resets cross- and up-sells
 * (`src/Model/Behavior/Simple.php:100-166`). Changing a product's manufacturer
 * that way would also zero its price.
 */
final class UpdateProductTool extends AbstractTool
{
	use J2commerceBootTrait;
	use ProductSupportTrait;

	public function getName(): string { return 'update_j2commerce_product'; }

	public function getDescription(): string
	{
		return 'Change product-level J2Commerce settings: enabled, visibility (shown in product listings), '
			. 'taxprofile_id, manufacturer_id, addtocart_text, up_sells and cross_sells (lists of product '
			. 'ids). Only the fields you pass are written, as a targeted UPDATE; every other field and every '
			. 'variant, price, option, image and stock row is untouched. '
			. 'This is NOT how J2Commerce saves products, on purpose: its ProductService treats a save as a '
			. 'complete form and forces every absent field to 0 — so changing just the manufacturer through '
			. 'it would also zero the price, weight, dimensions and tax profile '
			. '(src/Model/Behavior/Simple.php:100-166). '
			. 'Price, SKU, weight, dimensions, shipping and stock rules live on the VARIANT: use '
			. 'update_j2commerce_variant. Stock on hand: set_j2commerce_stock. The product name and page are '
			. 'the Joomla article: use core update_article. '
			. 'enabled is independent of the article\'s publish state. When the content plugin\'s '
			. 'mirror_state option is on (the default), publishing or trashing the article later overwrites '
			. 'enabled to match.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'product_id'      => ['type' => 'integer', 'description' => 'Required.'],
				'enabled'         => ['type' => 'boolean'],
				'visibility'      => ['type' => 'boolean', 'description' => 'Shown in product list views.'],
				'taxprofile_id'   => ['type' => 'integer', 'description' => '0 = untaxed.'],
				'manufacturer_id' => ['type' => 'integer', 'description' => '0 = none.'],
				'addtocart_text'  => ['type' => 'string', 'description' => 'Button label or language key.'],
				'up_sells'        => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Replaces the whole up-sell list.'],
				'cross_sells'     => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Replaces the whole cross-sell list.'],
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

		$productId = $this->requirePositiveInt($arguments, 'product_id');
		$product   = $this->requireProduct($productId);
		$db        = $this->db;

		$sets    = [];
		$changes = [];

		$apply = function (string $column, mixed $value, mixed $before) use (&$sets, &$changes, $db): void {
			if ((string) $before === (string) $value) {
				return;
			}

			$sets[]           = $db->quoteName($column) . ' = ' . (\is_int($value) ? $value : $db->quote((string) $value));
			$changes[$column] = ['before' => $before, 'after' => $value];
		};

		foreach (['enabled', 'visibility'] as $flag) {
			if (\array_key_exists($flag, $arguments)) {
				$apply($flag, (bool) $arguments[$flag] ? 1 : 0, (int) $product[$flag]);
			}
		}

		if (\array_key_exists('taxprofile_id', $arguments)) {
			$this->requireTaxProfile((int) $arguments['taxprofile_id']);
			$apply('taxprofile_id', (int) $arguments['taxprofile_id'], (int) $product['taxprofile_id']);
		}

		if (\array_key_exists('manufacturer_id', $arguments)) {
			$this->requireManufacturer((int) $arguments['manufacturer_id']);
			$apply('manufacturer_id', (int) $arguments['manufacturer_id'], (int) $product['manufacturer_id']);
		}

		if (\array_key_exists('addtocart_text', $arguments)) {
			$text = (string) $arguments['addtocart_text'];
			$this->j2RequireFits('addtocart_text', $text, 255);
			$apply('addtocart_text', $text, (string) $product['addtocart_text']);
		}

		foreach (['up_sells', 'cross_sells'] as $list) {
			if (!\array_key_exists($list, $arguments)) {
				continue;
			}

			$csv = $this->idCsv($arguments[$list]);
			$this->j2RequireFits($list, $csv, 255);

			if ($csv !== '') {
				$ids     = array_map('intval', explode(',', $csv));
				$known   = array_map('intval', $db->setQuery(
					'SELECT j2commerce_product_id FROM ' . $db->quoteName($this->j2Table('products'))
					. ' WHERE j2commerce_product_id IN (' . $csv . ')'
				)->loadColumn() ?: []);
				$missing = array_values(array_diff($ids, $known));

				if ($missing !== []) {
					throw new \InvalidArgumentException($list . ' names product id(s) that do not exist: '
						. implode(', ', $missing) . '. Nothing was written.');
				}

				if (\in_array($productId, $ids, true)) {
					throw new \InvalidArgumentException($list . ' includes the product itself. Nothing was written.');
				}
			}

			$apply($list, $csv, (string) $product[$list]);
		}

		if ($sets === []) {
			return ToolResult::json([
				'ok'      => true,
				'changed' => [],
				'note'    => 'Nothing to change: every field passed already holds that value. Nothing was written.',
			]);
		}

		$db->setQuery(
			'UPDATE ' . $db->quoteName($this->j2Table('products'))
			. ' SET ' . implode(', ', $sets)
			. ', modified_on = ' . $db->quote($this->j2Now()) . ', modified_by = ' . (int) $actor->id
			. ' WHERE j2commerce_product_id = ' . $productId
		)->execute();

		$after = $this->productRow($productId) ?? [];

		$response = [
			'ok'         => true,
			'product_id' => $productId,
			'changed'    => $changes,
			'verified'   => array_map(
				static fn (string $col): bool => (string) ($after[$col] ?? '') === (string) $changes[$col]['after'],
				array_combine(array_keys($changes), array_keys($changes))
			),
			'untouched'  => 'Only the columns above were written. Variants, prices, stock, options, images and '
				. 'the article were not read or written.',
		];

		if (isset($changes['enabled']) && ($article = $this->articleFor($product)) !== null && (int) $article['state'] !== 1 && (int) $changes['enabled']['after'] === 1) {
			$response['article_note'] = 'The product is enabled but its article is not published, so shoppers '
				. 'still cannot reach it.';
		}

		return ToolResult::json($response);
	}
}
