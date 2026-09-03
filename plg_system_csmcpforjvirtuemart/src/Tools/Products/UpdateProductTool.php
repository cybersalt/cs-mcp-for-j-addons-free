<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Products;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Update a product by reading its whole current state, merging the caller's
 * deltas onto it, and writing the merged whole back.
 *
 * This is the tool the entire add-on is shaped around. `ProductModel::store()`
 * interprets its `$data` argument as a complete admin form POST, so every
 * satellite the caller failed to mention is DELETED:
 *
 *   - no `mprices`                    -> every price row deleted
 *     (`models/product.php:2895-2913`)
 *   - no `categories`                 -> every category assignment deleted
 *     (`:2939-2960` via `helpers/vmtablexarray.php:226-237`)
 *   - no `virtuemart_shoppergroup_id` -> every shopper-group restriction deleted
 *     (`:2935`, unconditional, no sentinel)
 *   - no `virtuemart_manufacturer_id` -> every manufacturer link deleted (`:2937`)
 *   - no `field`                      -> every custom-field value deleted, with
 *     `plgVmOnCustomfieldRemove` firing per row
 *     (`models/customfields.php:1590-1599`)
 *
 * The category case is the nastiest, because the guard at
 * `models/product.php:2940` reads "empty means yes, store categories" — i.e.
 * store the empty set, i.e. delete them all. The only escape is the literal
 * sentinel `$data['categories'][0] === "-2"`.
 *
 * This tool never touches a satellite the caller did not name. Satellite
 * changes have their own dedicated tools precisely so that the intent is always
 * explicit.
 */
final class UpdateProductTool extends AbstractTool
{
	use VirtuemartBootTrait;
	use ProductWriteTrait;

	public function getName(): string { return 'update_virtuemart_product'; }

	public function getDescription(): string
	{
		return 'Update an existing VirtueMart product. Requires id (virtuemart_product_id). '
			. 'READ-MODIFY-WRITE: the product\'s complete current state is loaded first, your supplied '
			. 'fields are merged onto it, and the merged whole is written back. Columns you do not name '
			. 'keep their value. Satellites you do not name are NOT TOUCHED — no price row, category '
			. 'assignment, manufacturer link, shopper group or custom field value is ever deleted by this '
			. 'tool. '
			. 'That is the reason this add-on does not call VirtueMart\'s own ProductModel::store(). That '
			. 'method treats its input as a complete admin form POST and DELETES every satellite absent '
			. 'from it: prices at models/product.php:2895-2913, categories at :2939-2960 (where an EMPTY '
			. 'categories array means "store the empty set", i.e. delete them all — the only escape is the '
			. 'literal sentinel "-2"), shopper groups at :2935 and manufacturers at :2937 with no guard at '
			. 'all, and custom fields at models/customfields.php:1590-1599. A partial call to that model '
			. 'is a data-loss event that reports success. '
			. 'Language fields (product_name, product_s_desc, product_desc, metadesc, metakey, '
			. 'customtitle, slug) are written to the language given by `language`, defaulting to the shop '
			. 'language. They do NOT propagate to other languages — use set_virtuemart_product_translation '
			. 'for those, or get_virtuemart_product to see which are missing. '
			. 'Changing product_name does NOT regenerate the slug. VirtueMart only does that when its '
			. 'slugHandling config key says so (models/product.php:2779-2787), and silently changing a '
			. 'live URL breaks inbound links. Pass slug: "" explicitly to force regeneration. '
			. 'The has_* join hints are recomputed from the real satellite rows on every call, so this '
			. 'tool also repairs a product whose flags had drifted — a product with has_prices = 0 shows '
			. 'no price even when price rows exist, because VirtueMart skips the join. '
			. 'Not settable: virtuemart_vendor_id (forced to 1 on a single-vendor shop by '
			. 'helpers/vmtable.php:1526-1603, an ACL-refusable ownership transfer otherwise), '
			. 'product_ordered / product_sales (maintained by order status handling), hits, and the has_* '
			. 'flags themselves.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'             => ['type' => 'integer', 'description' => 'virtuemart_product_id. Required.'],
				'language'       => ['type' => 'string', 'description' => 'Which language satellite table the language fields are written to. Defaults to the shop language. Must be one of active_languages.'],
				'product_name'   => ['type' => 'string'],
				'product_s_desc' => ['type' => 'string'],
				'product_desc'   => ['type' => 'string'],
				'metadesc'       => ['type' => 'string'],
				'metakey'        => ['type' => 'string'],
				'customtitle'    => ['type' => 'string'],
				'slug'           => ['type' => 'string', 'description' => 'Pass "" to regenerate from product_name. Otherwise normalised and de-duplicated. Changing a live slug breaks existing inbound links.'],
				'product_sku'    => ['type' => 'string'],
				'product_gtin'   => ['type' => 'string'],
				'product_mpn'    => ['type' => 'string'],
				'product_url'    => ['type' => 'string'],
				'product_weight' => ['type' => 'string', 'description' => 'Bare dot-decimal string. "" stores NULL.'],
				'product_weight_uom' => ['type' => 'string'],
				'product_length' => ['type' => 'string'],
				'product_width'  => ['type' => 'string'],
				'product_height' => ['type' => 'string'],
				'product_lwh_uom' => ['type' => 'string'],
				'product_unit'   => ['type' => 'string'],
				'product_packaging' => ['type' => 'string'],
				'product_in_stock' => ['type' => 'integer', 'description' => 'Prefer set_virtuemart_product_stock, which explains the stock/ordered/sales interaction.'],
				'product_stockhandle' => ['type' => 'string'],
				'low_stock_notification' => ['type' => 'integer'],
				'product_availability' => ['type' => 'string'],
				'product_available_date' => ['type' => 'string', 'description' => 'SQL datetime. "" stores NULL.'],
				'product_special' => ['type' => 'boolean'],
				'product_discontinued' => ['type' => 'boolean'],
				'product_parent_id' => ['type' => 'integer', 'description' => 'Must exist and cannot be this product\'s own id.'],
				'product_canon_category_id' => ['type' => 'integer'],
				'pordering'  => ['type' => 'integer', 'description' => 'Ordering of variants under a parent.'],
				'intnotes'   => ['type' => 'string'],
				'metarobot'  => ['type' => 'string'],
				'metaauthor' => ['type' => 'string'],
				'layout'     => ['type' => 'string'],
				'published'  => ['type' => 'boolean'],
			],
			'required' => ['id'],
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

		$id  = $this->requirePositiveInt($arguments, 'id');
		$tag = $this->vmResolveLangTag($arguments);

		if ($tag instanceof ToolResult) {
			return $tag;
		}

		$state = $this->vmReadProductState($id);

		if ($state === null) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No product with virtuemart_product_id ' . $id . '. Nothing was written.',
			], true);
		}

		$langTable = $this->vmLangTable('products', $tag);

		if (!$this->vmLangTableExists('products', $tag)) {
			return $this->vmMissingTableError('products_' . $this->vmLangSuffix($tag));
		}

		// --- base row: merge deltas onto the WHOLE current row ----------------
		$deltas = [];

		foreach ($this->productBaseWritable() as $column) {
			if (!\array_key_exists($column, $arguments)) {
				continue;
			}

			$coerced = $this->coerceBaseValue($column, $arguments[$column]);

			if ($coerced instanceof ToolResult) {
				return $coerced;
			}

			$deltas[$column] = $coerced;
		}

		$merged = $this->vmMergeForWrite($state['base'], $deltas, $this->productBaseWritable());

		$refusal = $this->assertBaseSane($merged['data'], $id);

		if ($refusal !== null) {
			return $refusal;
		}

		// --- language row: same discipline, on the language table -------------
		$langSupplied = [];

		foreach ($this->productLangWritable() as $column) {
			if (\array_key_exists($column, $arguments)) {
				$langSupplied[$column] = $arguments[$column];
			}
		}

		$existingLang = $state['translations'][$tag]['row'] ?? null;
		$langExists   = \is_array($existingLang);

		$langChanged = [];
		$slugNote    = null;
		$langFields  = null;

		if ($langSupplied !== [] || !$langExists) {
			if (!$langExists && $langSupplied === []) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Product ' . $id . ' has no row in ' . $langTable . ' and no language '
						. 'fields were supplied, so one cannot be created. This product is currently '
						. 'invisible to shoppers using ' . $tag . ' — the read join is INNER '
						. '(helpers/vmtable.php:1065-1068). Supply at least product_name, or use '
						. 'set_virtuemart_product_translation.',
				], true);
			}

			$built = $this->buildLangRow($langSupplied, \is_array($existingLang) ? $existingLang : [], $langTable, $id);

			if ($built instanceof ToolResult) {
				return $built;
			}

			$langFields = $built['fields'];
			$slugNote   = $built['slug_note'];

			foreach ($langFields as $column => $value) {
				$before = $existingLang[$column] ?? null;

				if ((string) $value !== (string) $before) {
					$langChanged[] = $column;
				}
			}
		}

		if ($merged['changed'] === [] && $langChanged === []) {
			return ToolResult::json([
				'ok'      => true,
				'changed' => [],
				'note'    => 'Nothing to do: every supplied value already matches what is stored. No row '
					. 'was written and modified_on was not stamped, because marking a product as changed '
					. 'when it was not is worse than doing nothing.',
				'virtuemart_product_id' => $id,
			]);
		}

		// --- write ------------------------------------------------------------
		$now = $this->vmNow();

		if ($merged['changed'] !== []) {
			$row                        = new \stdClass();
			$row->virtuemart_product_id = $id;
			$row->modified_on           = $now;
			$row->modified_by           = (int) $actor->id;

			foreach ($this->productBaseWritable() as $column) {
				if (\array_key_exists($column, $merged['data'])) {
					$row->{$column} = $merged['data'][$column];
				}
			}

			$this->db->updateObject($this->vmTable('products'), $row, 'virtuemart_product_id', true);
		}

		if ($langFields !== null && ($langChanged !== [] || !$langExists)) {
			$this->writeLangRow($langTable, $id, $langFields, $langExists);
		}

		$flags = $this->refreshHasFlags($id);

		$response = [
			'ok'                    => true,
			'virtuemart_product_id' => $id,
			'language'              => ['tag' => $tag, 'table' => $langTable],
			'changed_base_columns'  => $merged['changed'],
			'changed_lang_columns'  => $langChanged,
			'has_flags'             => $flags,
			'modified_on'           => $merged['changed'] === [] ? null : $now,
			'satellites_untouched'  => 'No price, category, manufacturer, shopper group, media link or '
				. 'custom field row was read, written or deleted by this call. Use the dedicated tools for '
				. 'those.',
		];

		if ($merged['rejected'] !== []) {
			$response['rejected_columns'] = $merged['rejected'];
			$response['rejected_note']    = 'These were not applied because they are not caller-writable. '
				. 'They are reported rather than ignored so you do not believe a value was stored when it '
				. 'was not.';
		}

		if ($slugNote !== null) {
			$response['slug_note'] = $slugNote;
		}

		if (\array_key_exists('product_name', $arguments) && !\array_key_exists('slug', $arguments)) {
			$response['slug_unchanged_note'] = 'product_name changed but the slug was left alone. '
				. 'VirtueMart only regenerates a slug when its slugHandling config key is set to '
				. '"changed" or "always" (models/product.php:2779-2787); rewriting a live URL silently '
				. 'breaks inbound links and search results. Pass slug: "" to regenerate deliberately.';
		}

		if (!$langExists && $langFields !== null) {
			$response['language_row_created'] = 'Product ' . $id . ' had no row in ' . $langTable
				. ' and one was created. It was invisible to ' . $tag . ' shoppers until now.';
		}

		$missing = [];

		foreach ($state['translations'] as $otherTag => $info) {
			if ($otherTag !== $tag && !$info['exists']) {
				$missing[] = (string) $otherTag;
			}
		}

		if ($missing !== []) {
			$response['other_languages_missing'] = $missing;
			$response['other_languages_note'] = 'This product still has no language row for: '
				. implode(', ', $missing) . '. Shoppers on those languages cannot see it at all. Use '
				. 'set_virtuemart_product_translation.';
		}

		if (\array_key_exists('published', $arguments)) {
			$response['published_note'] = 'published was written directly. Note that when a save goes '
				. 'through VirtueMart\'s own model, a user lacking the vm.product.edit.state permission '
				. 'has their published value silently overwritten (models/product.php:2734-2740) — so the '
				. 'component itself can report a state it did not store. This tool did store it.';
		}

		$response['direct_write_note'] = $this->vmDirectWriteNotice();
		$response['component']         = $this->vmComponentNotice();

		return ToolResult::json($response);
	}
}
