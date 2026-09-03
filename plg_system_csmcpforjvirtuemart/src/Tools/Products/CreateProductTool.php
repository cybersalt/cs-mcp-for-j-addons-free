<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Products;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Create a product, writing every row it needs to actually exist.
 *
 * The hard part of creating a VirtueMart product is not the base row. It is
 * that the base row alone is inert: no language row means no product, because
 * every read is an INNER JOIN (`helpers/vmtable.php:1065-1068`). This tool
 * therefore writes a language row for EVERY active language in one operation,
 * and refuses the whole thing if it cannot.
 *
 * The write order matters and mirrors `bindChecknStore()`
 * (`helpers/vmtable.php:2105-2135`): base row first so the autoincrement id
 * exists, then the language rows carrying that id, then the satellites, then
 * the `has_*` flags recomputed from what actually landed.
 */
final class CreateProductTool extends AbstractTool
{
	use VirtuemartBootTrait;
	use ProductWriteTrait;

	public function getName(): string { return 'create_virtuemart_product'; }

	public function getDescription(): string
	{
		return 'Create a VirtueMart product. Requires product_name. '
			. 'Writes, in one operation: the #__virtuemart_products base row, a row in the satellite '
			. 'language table for EVERY language in the shop\'s active_languages, the category / '
			. 'manufacturer / shopper-group assignments you supply, an optional first price row, and the '
			. 'has_* join hints recomputed from what actually landed. '
			. 'The language rows are the point. #__virtuemart_products has no product_name column at all; '
			. 'name, descriptions, meta fields and slug live in #__virtuemart_products_<langsuffix> and '
			. 'VirtueMart joins them with an INNER JOIN (helpers/vmtable.php:1065-1068). A base row '
			. 'without them is an invisible product — no listing, no search result, no error. By default '
			. 'the same text is written to every active language; pass `translations` to give each '
			. 'language its own. '
			. 'The slug is generated from product_name with VirtueMart\'s own transform '
			. '(helpers/vmtable.php:1754-1776) and made unique the way checkCreateUnique() would '
			. '(:1487-1523), because the slug column carries a UNIQUE KEY on the language table. '
			. 'published defaults to 0, matching the SQL column default (install.sql:842). Note '
			. 'VirtueMart\'s PHP table class defaults it to 1 instead (tables/products.php:93), so never '
			. 'rely on either — set it explicitly. '
			. 'PRICES: pass price to create one #__virtuemart_product_prices row for shopper group 0 '
			. '("everyone"). It must be a bare dot-decimal string. A value containing a comma is REFUSED, '
			. 'because VirtueMart\'s convertDec() (helpers/vmtable.php:489-501) replaces every comma with '
			. 'a dot and then calls floatval(), turning "1,234.56" into 1.234 — a thousandfold error, '
			. 'silently. Remember product_price is the BASE/COST price, not the shopper-facing figure. '
			. 'NOT SUPPORTED and never will be: uploading or attaching image files. CVE-2025-25229 and '
			. 'CVE-2025-25230 were an unrestricted-upload and CSRF-bypass pair in exactly VirtueMart\'s '
			. 'product-image path, and 4.8.0 was still hardening it. Link an existing '
			. '#__virtuemart_medias row instead. '
			. 'virtuemart_vendor_id is not settable. On a single-vendor shop VirtueMart overwrites it with '
			. '1 on every save regardless (helpers/vmtable.php:1526-1603); on a multi-vendor shop changing '
			. 'it is an ownership transfer the ACL can refuse mid-save.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'product_name'   => ['type' => 'string', 'description' => 'Required. Written to every active language unless overridden per-language in `translations`.'],
				'product_sku'    => ['type' => 'string', 'description' => 'Max 192 bytes. VirtueMart does not enforce uniqueness.'],
				'product_s_desc' => ['type' => 'string', 'description' => 'Short description. Goes to the language table.'],
				'product_desc'   => ['type' => 'string', 'description' => 'Long description (HTML). Goes to the language table.'],
				'metadesc'       => ['type' => 'string'],
				'metakey'        => ['type' => 'string'],
				'customtitle'    => ['type' => 'string', 'description' => 'Overrides the browser page title.'],
				'slug'           => ['type' => 'string', 'description' => 'Leave empty to generate from product_name. Normalised and de-duplicated either way.'],
				'translations'   => [
					'type'        => 'object',
					'description' => 'Per-language overrides, keyed by language tag, e.g. {"de-DE": {"product_name": "...", "product_desc": "..."}}. Any language not named here gets the top-level values.',
					'additionalProperties' => ['type' => 'object'],
				],
				'published'         => ['type' => 'boolean', 'description' => 'Default false, matching the SQL column default.'],
				'product_in_stock'  => ['type' => 'integer'],
				'product_gtin'      => ['type' => 'string', 'description' => 'Max 64 bytes.'],
				'product_mpn'       => ['type' => 'string', 'description' => 'Max 64 bytes.'],
				'product_url'       => ['type' => 'string', 'description' => 'Max 255 bytes.'],
				'product_weight'    => ['type' => 'string', 'description' => 'Bare dot-decimal string. Empty means NULL.'],
				'product_weight_uom' => ['type' => 'string'],
				'product_length'    => ['type' => 'string'],
				'product_width'     => ['type' => 'string'],
				'product_height'    => ['type' => 'string'],
				'product_lwh_uom'   => ['type' => 'string'],
				'product_unit'      => ['type' => 'string'],
				'product_packaging' => ['type' => 'string'],
				'product_parent_id' => ['type' => 'integer', 'description' => 'Set to make this a variant of an existing product. Must exist.'],
				'product_special'   => ['type' => 'boolean'],
				'product_discontinued' => ['type' => 'boolean'],
				'product_availability' => ['type' => 'string'],
				'product_available_date' => ['type' => 'string', 'description' => 'SQL datetime. Empty means NULL.'],
				'product_stockhandle' => ['type' => 'string'],
				'low_stock_notification' => ['type' => 'integer'],
				'product_canon_category_id' => ['type' => 'integer', 'description' => 'Canonical category when the product is in several.'],
				'intnotes'   => ['type' => 'string', 'description' => 'Internal notes, never shown to shoppers.'],
				'metarobot'  => ['type' => 'string'],
				'metaauthor' => ['type' => 'string'],
				'layout'     => ['type' => 'string'],
				'categories' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Category ids. A product with none is reachable by direct URL but appears in no listing.'],
				'manufacturers' => ['type' => 'array', 'items' => ['type' => 'integer']],
				'shoppergroups' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Restricts visibility to these shopper groups. Empty means visible to everyone.'],
				'price'          => ['type' => 'string', 'description' => 'Base price as a bare dot-decimal string, e.g. "19.99". Creates one price row for shopper group 0. Commas and spaces are refused.'],
				'price_currency_id' => ['type' => 'integer', 'description' => 'virtuemart_currency_id for the price row. Defaults to the vendor currency.'],
			],
			'required' => ['product_name'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->vmAdminBase() === null) {
			return $this->vmNotInstalledError();
		}

		foreach (['products', 'product_categories', 'product_prices'] as $table) {
			if (!$this->vmTableExists($table)) {
				return $this->vmMissingTableError($table);
			}
		}

		$tags = $this->vmActiveLangTags();

		foreach ($tags as $tag) {
			if (!$this->vmLangTableExists('products', $tag)) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Refusing to create a product: ' . $this->vmLangTable('products', $tag)
						. ' does not exist, but ' . $tag . ' is in this shop\'s active_languages. Writing '
						. 'the base row without a language row for every active language would produce a '
						. 'product that is invisible to some shoppers and reports no error anywhere.',
					'active_languages' => $tags,
					'resolution'       => 'Save the VirtueMart configuration once in the shop admin — '
						. 'models/config.php:541-546 creates missing language tables when the active list '
						. 'grows.',
				], true);
			}
		}

		// --- base row ---------------------------------------------------------
		$base    = [];
		$rejects = [];

		foreach ($arguments as $key => $value) {
			if (!\in_array($key, $this->productBaseWritable(), true)) {
				continue;
			}

			$coerced = $this->coerceBaseValue((string) $key, $value);

			if ($coerced instanceof ToolResult) {
				return $coerced;
			}

			$base[$key] = $coerced;
		}

		$base['published']            = $this->vmNormalisePublished($arguments['published'] ?? false) ?? 0;
		$base['virtuemart_vendor_id'] = $this->defaultVendorId();
		$base['product_parent_id']    = (int) ($arguments['product_parent_id'] ?? 0);

		$refusal = $this->assertBaseSane($base, 0);

		if ($refusal !== null) {
			return $refusal;
		}

		// --- language rows, validated BEFORE anything is written --------------
		$langPlan = [];

		foreach ($tags as $tag) {
			$supplied = $this->langInputFor($arguments, $tag);
			$built    = $this->buildLangRow($supplied, [], $this->vmLangTable('products', $tag), 0);

			if ($built instanceof ToolResult) {
				return $built;
			}

			$langPlan[$tag] = $built;
		}

		// --- price, validated before anything is written ----------------------
		$price = null;

		if (\array_key_exists('price', $arguments) && trim((string) $arguments['price']) !== '') {
			$price = $this->vmNormaliseMoney($arguments['price'], 'price');

			if ($price instanceof ToolResult) {
				return $price;
			}
		}

		// --- write ------------------------------------------------------------
		$now = $this->vmNow();

		$row              = new \stdClass();
		$row->created_on  = $now;
		$row->created_by  = (int) $actor->id;
		$row->modified_on = $now;
		$row->modified_by = (int) $actor->id;

		foreach ($base as $column => $value) {
			$row->{$column} = $value;
		}

		$this->db->insertObject($this->vmTable('products'), $row, 'virtuemart_product_id');

		$productId = (int) $row->virtuemart_product_id;

		if ($productId <= 0) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'The base row insert returned no id. Nothing further was written.',
			], true);
		}

		$slugNotes = [];

		foreach ($langPlan as $tag => $plan) {
			$this->writeLangRow($this->vmLangTable('products', (string) $tag), $productId, $plan['fields'], false);

			if ($plan['slug_note'] !== null) {
				$slugNotes[$tag] = $plan['slug_note'];
			}
		}

		$written = [];

		if (\array_key_exists('categories', $arguments)) {
			$written['categories'] = $this->vmReplaceXref('product_categories', 'virtuemart_product_id', $productId, 'virtuemart_category_id', (array) $arguments['categories'], true);
		}

		if (\array_key_exists('manufacturers', $arguments)) {
			$written['manufacturers'] = $this->vmReplaceXref('product_manufacturers', 'virtuemart_product_id', $productId, 'virtuemart_manufacturer_id', (array) $arguments['manufacturers']);
		}

		if (\array_key_exists('shoppergroups', $arguments)) {
			$written['shoppergroups'] = $this->vmReplaceXref('product_shoppergroups', 'virtuemart_product_id', $productId, 'virtuemart_shoppergroup_id', (array) $arguments['shoppergroups']);
		}

		if ($price !== null) {
			$priceRow                             = new \stdClass();
			$priceRow->virtuemart_product_id      = $productId;
			$priceRow->virtuemart_shoppergroup_id = 0;
			$priceRow->product_price              = $price;
			$priceRow->product_currency           = (int) ($arguments['price_currency_id'] ?? $this->vendorCurrencyId());
			$priceRow->product_tax_id             = 0;
			$priceRow->product_discount_id        = 0;
			$priceRow->override                   = 0;
			$priceRow->price_quantity_start       = 0;
			$priceRow->price_quantity_end         = 0;
			$priceRow->created_on                 = $now;
			$priceRow->created_by                 = (int) $actor->id;
			$priceRow->modified_on                = $now;
			$priceRow->modified_by                = (int) $actor->id;

			$this->db->insertObject($this->vmTable('product_prices'), $priceRow);

			$written['prices'] = 1;
		}

		$flags = $this->refreshHasFlags($productId);

		$response = [
			'ok'                    => true,
			'virtuemart_product_id' => $productId,
			'product_name'          => (string) $arguments['product_name'],
			'published'             => (int) $base['published'] === 1,
			'languages_written'     => array_keys($langPlan),
			'slugs'                 => array_map(
				static fn (array $plan): string => (string) $plan['fields']['slug'],
				$langPlan
			),
			'satellites_written'    => $written,
			'has_flags'             => $flags,
		];

		if ($slugNotes !== []) {
			$response['slug_notes'] = $slugNotes;
		}

		if ((int) $base['published'] !== 1) {
			$response['visibility_note'] = 'This product is UNPUBLISHED and will not appear in the shop. '
				. 'That is the default here because it matches the SQL column default '
				. '(install.sql:842). Use set_virtuemart_product_state to publish it.';
		}

		if (empty($arguments['categories'])) {
			$response['category_note'] = 'No categories were assigned, so this product appears in no '
				. 'listing. It is still reachable by direct URL. Use set_virtuemart_product_categories.';
		}

		if ($price === null) {
			$response['price_note'] = 'No price row was created. Unless the shop runs with prices '
				. 'disabled, the product shows without a price and cannot be bought. Use '
				. 'set_virtuemart_product_price.';
		}

		$response['direct_write_note'] = $this->vmDirectWriteNotice();
		$response['component']         = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/**
	 * The language payload for one tag: top-level values, overlaid with any
	 * per-language override.
	 *
	 * @return array<string,mixed>
	 */
	private function langInputFor(array $arguments, string $tag): array
	{
		$supplied = [];

		foreach ($this->productLangWritable() as $column) {
			if (\array_key_exists($column, $arguments)) {
				$supplied[$column] = $arguments[$column];
			}
		}

		$overrides = $arguments['translations'] ?? [];

		if (!\is_array($overrides)) {
			return $supplied;
		}

		foreach ($overrides as $overrideTag => $values) {
			if (strcasecmp((string) $overrideTag, $tag) !== 0 || !\is_array($values)) {
				continue;
			}

			foreach ($values as $column => $value) {
				if (\in_array((string) $column, $this->productLangWritable(), true)) {
					$supplied[(string) $column] = $value;
				}
			}
		}

		// A slug is per-language and must not be copied from another language,
		// where it would collide with the UNIQUE KEY on that table.
		if (\count($this->vmActiveLangTags()) > 1 && !isset($overrides[$tag]['slug'])) {
			unset($supplied['slug']);
		}

		return $supplied;
	}

	/**
	 * Vendor 1 on a single-vendor shop, which is what `multix = 'none'` (the
	 * default) forces anyway at `helpers/vmtable.php:1526-1603`.
	 */
	private function defaultVendorId(): int
	{
		$id = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('MIN(' . $this->db->quoteName('virtuemart_vendor_id') . ')')
				->from($this->db->quoteName($this->vmTable('vendors')))
		)->loadResult();

		return $id > 0 ? $id : 1;
	}

	private function vendorCurrencyId(): int
	{
		return (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('vendor_currency'))
				->from($this->db->quoteName($this->vmTable('vendors')))
				->where($this->db->quoteName('virtuemart_vendor_id') . ' = ' . $this->defaultVendorId())
		)->loadResult();
	}
}
