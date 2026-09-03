<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Products;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;

/**
 * Shared write machinery for the product tools.
 *
 * Everything here exists to reproduce, faithfully, the parts of
 * `VirtueMartModelProduct::store()` that are genuinely necessary — the language
 * row, the slug, the `has_*` flags — while reproducing none of the parts that
 * destroy data.
 *
 * The column widths are read from the live schema rather than hardcoded,
 * because the language tables' columns are GENERATED at runtime by
 * `helpers/tableupdater.php:95-201` from each table's `getTranslatableFields()`
 * and from config keys (`dbnamesize`, `dbmetasize`, `dbslugsize`,
 * `dbpsdescsize`). The shipped defaults are 400/400/255/2000, but a shop can
 * change them and then the constants would be wrong in the dangerous
 * direction.
 */
trait ProductWriteTrait
{
	/**
	 * Columns on `#__virtuemart_products` a caller may set.
	 *
	 * Deliberately absent:
	 *   - `virtuemart_vendor_id` — on a single-vendor shop VirtueMart forces it
	 *     to 1 on every save regardless (`helpers/vmtable.php:1526-1603`), and
	 *     on a multi-vendor shop changing it is an ownership transfer the ACL
	 *     may refuse mid-save, leaving the base row written and the satellites
	 *     not. There is no good outcome, so it is not offered.
	 *   - `product_ordered`, `product_sales` — maintained by the order status
	 *     machinery (`models/product.php:3508-3565`). Hand-editing them
	 *     desynchronises stock reporting.
	 *   - `hits`, the `has_*` flags, and every created/modified/locked column —
	 *     derived or bookkeeping.
	 *
	 * @return array<int,string>
	 */
	protected function productBaseWritable(): array
	{
		return [
			'product_parent_id',
			'product_sku',
			'product_gtin',
			'product_mpn',
			'product_weight',
			'product_weight_uom',
			'product_length',
			'product_width',
			'product_height',
			'product_lwh_uom',
			'product_url',
			'product_in_stock',
			'product_stockhandle',
			'low_stock_notification',
			'product_available_date',
			'product_availability',
			'product_special',
			'product_discontinued',
			'product_unit',
			'product_packaging',
			'product_canon_category_id',
			'intnotes',
			'metarobot',
			'metaauthor',
			'layout',
			'published',
			'pordering',
		];
	}

	/**
	 * Columns on `#__virtuemart_products_<lang>` a caller may set.
	 *
	 * `tables/products.php:116` declares
	 * `setTranslatable(['product_name','product_s_desc','product_desc','metadesc','metakey','customtitle'])`
	 * and `setTranslatable()` force-appends `slug`
	 * (`helpers/vmtable.php:370`).
	 *
	 * @return array<int,string>
	 */
	protected function productLangWritable(): array
	{
		return [
			'product_name',
			'product_s_desc',
			'product_desc',
			'metadesc',
			'metakey',
			'customtitle',
			'slug',
		];
	}

	/** Decimal columns where an empty string must become NULL, not 0. */
	protected function productDecimalColumns(): array
	{
		return [
			'product_weight',
			'product_length',
			'product_width',
			'product_height',
			'product_packaging',
		];
	}

	/**
	 * Maximum byte length of a live column, or null when it has no limit.
	 *
	 * Parsed from the driver's own type string so a shop that raised
	 * `dbnamesize` gets the real ceiling rather than the shipped default.
	 */
	protected function columnLimit(string $table, string $column): ?int
	{
		$columns = $this->db->getTableColumns($table, true) ?: [];

		if (!isset($columns[$column])) {
			return null;
		}

		$type = strtolower((string) ($columns[$column]->Type ?? ''));

		if (preg_match('/^(var)?char\((\d+)\)/', $type, $m) === 1) {
			return (int) $m[2];
		}

		return match (true) {
			str_starts_with($type, 'tinytext')   => 255,
			str_starts_with($type, 'mediumtext') => 16777215,
			str_starts_with($type, 'longtext')   => 4294967295,
			str_starts_with($type, 'text')       => 65535,
			default                              => null,
		};
	}

	/**
	 * Refuse any language value that would not fit its generated column.
	 *
	 * VirtueMart truncates instead, silently, in `checkByShowColumns()`
	 * (`helpers/vmtable.php:1829`). A description cut at an arbitrary byte is a
	 * worse outcome than a rejected write, and the caller cannot tell it
	 * happened.
	 *
	 * @param array<string,mixed> $fields
	 */
	protected function assertLangFits(array $fields, string $langTable): ?ToolResult
	{
		foreach ($fields as $column => $value) {
			if (!\is_string($value)) {
				continue;
			}

			$limit = $this->columnLimit($langTable, (string) $column);

			if ($limit === null) {
				continue;
			}

			$refusal = $this->vmAssertFits($value, $column . ' (in ' . $langTable . ')', $limit);

			if ($refusal !== null) {
				return $refusal;
			}
		}

		return null;
	}

	/**
	 * Build a complete language row, generating the slug the way VirtueMart
	 * would if the caller did not supply one.
	 *
	 * `check()` refuses the entire save when both slug and product_name are
	 * empty (`helpers/vmtable.php:1741-1744`), so the same is enforced here —
	 * except that we return a clear refusal rather than a `false` that a caller
	 * would have to guess the meaning of.
	 *
	 * @param  array<string,mixed> $supplied
	 * @param  array<string,mixed> $existing Current row, or [] when creating.
	 * @return array{fields:array<string,mixed>,slug_note:?string}|ToolResult
	 */
	protected function buildLangRow(array $supplied, array $existing, string $langTable, int $productId): array|ToolResult
	{
		$fields = [];

		foreach ($this->productLangWritable() as $column) {
			if (\array_key_exists($column, $supplied)) {
				$fields[$column] = (string) $supplied[$column];
			} elseif (\array_key_exists($column, $existing)) {
				$fields[$column] = (string) $existing[$column];
			} else {
				$fields[$column] = '';
			}
		}

		if (trim($fields['product_name']) === '') {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'product_name is empty for ' . $langTable . '. VirtueMart declares it an '
					. 'obligatory key (tables/products.php:115) and its own check() aborts the whole save '
					. 'when both product_name and slug are empty (helpers/vmtable.php:1741-1744). A '
					. 'nameless product is also unfindable in the admin listing. Refusing; nothing was '
					. 'written.',
			], true);
		}

		$slugNote = null;
		$slugIn   = trim($fields['slug']);

		if ($slugIn === '') {
			$fields['slug'] = $this->vmSlugify($fields['product_name']);
			$slugNote       = 'slug was generated from product_name using VirtueMart\'s own transform '
				. '(helpers/vmtable.php:1754-1776).';
		} else {
			$fields['slug'] = $this->vmSlugify($slugIn);

			if ($fields['slug'] !== $slugIn) {
				$slugNote = 'The supplied slug was normalised from "' . $slugIn . '" to "'
					. $fields['slug'] . '" by VirtueMart\'s own transform.';
			}
		}

		if ($fields['slug'] === '') {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'The slug reduced to an empty string. VirtueMart\'s filter keeps only word '
					. 'characters plus - , _ and | , so a name consisting entirely of other characters '
					. 'produces nothing usable and SEF routing would 404. Supply an explicit slug. '
					. 'Refusing; nothing was written.',
			], true);
		}

		$unique = $this->vmUniqueSlug($fields['slug'], $langTable, 'virtuemart_product_id', $productId);

		if (!$unique['unique']) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Could not find a unique slug after 40 attempts, matching the limit in '
					. 'checkCreateUnique() (helpers/vmtable.php:1487-1523). The slug column carries a '
					. 'UNIQUE KEY on ' . $langTable . ', so writing anyway would be a hard insert failure. '
					. 'Refusing; nothing was written.',
			], true);
		}

		if ($unique['slug'] !== $fields['slug']) {
			$slugNote = ($slugNote === null ? '' : $slugNote . ' ')
				. 'It collided with an existing row and was made unique as "' . $unique['slug']
				. '", the same way checkCreateUnique() would.';
			$fields['slug'] = $unique['slug'];
		}

		$refusal = $this->assertLangFits($fields, $langTable);

		if ($refusal !== null) {
			return $refusal;
		}

		return ['fields' => $fields, 'slug_note' => $slugNote];
	}

	/**
	 * Insert or update a product's row in one language satellite table.
	 *
	 * @param array<string,mixed> $fields
	 */
	protected function writeLangRow(string $langTable, int $productId, array $fields, bool $rowExists): void
	{
		$object                        = new \stdClass();
		$object->virtuemart_product_id = $productId;

		foreach ($fields as $column => $value) {
			$object->{$column} = $value;
		}

		if ($rowExists) {
			$this->db->updateObject($langTable, $object, 'virtuemart_product_id');

			return;
		}

		$this->db->insertObject($langTable, $object);
	}

	/**
	 * Coerce a base-row value into what the column expects.
	 *
	 * Decimal columns get NULL for an empty string, mirroring
	 * `models/product.php:2743-2754`. Note that VirtueMart cannot distinguish
	 * "zero" from "unset" there — its `!empty()` test turns both into NULL —
	 * whereas writing directly we can and do store an explicit 0.
	 *
	 * @return mixed|ToolResult
	 */
	protected function coerceBaseValue(string $column, mixed $value): mixed
	{
		if (\in_array($column, $this->productDecimalColumns(), true)) {
			if ($value === null || trim((string) $value) === '') {
				return null;
			}

			return $this->vmNormaliseMoney($value, $column);
		}

		return match ($column) {
			'product_parent_id', 'product_in_stock', 'low_stock_notification',
			'product_canon_category_id', 'pordering' => (int) $value,
			'product_special', 'product_discontinued' => (bool) $value ? 1 : 0,
			'published'                               => $this->vmNormalisePublished($value) ?? 0,
			'product_available_date'                  => trim((string) $value) === '' ? null : (string) $value,
			default                                   => (string) $value,
		};
	}

	/**
	 * Refuse a base-row write that would break a structural invariant.
	 *
	 * @param array<string,mixed> $data
	 */
	protected function assertBaseSane(array $data, int $productId): ?ToolResult
	{
		$parent = (int) ($data['product_parent_id'] ?? 0);

		if ($productId > 0 && $parent === $productId) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'product_parent_id cannot be the product\'s own id. VirtueMart guards against '
					. 'this at models/product.php:2761-2763 because a self-parenting product makes the '
					. 'variant walk infinite. Refusing; nothing was written.',
			], true);
		}

		if ($parent > 0) {
			$exists = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select('COUNT(*)')
					->from($this->db->quoteName($this->vmTable('products')))
					->where($this->db->quoteName('virtuemart_product_id') . ' = ' . $parent)
			)->loadResult();

			if ($exists === 0) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'product_parent_id ' . $parent . ' does not exist. Refusing; nothing was '
						. 'written.',
				], true);
			}
		}

		foreach (['product_sku' => 192, 'product_gtin' => 64, 'product_mpn' => 64, 'product_url' => 255] as $column => $limit) {
			if (!\array_key_exists($column, $data)) {
				continue;
			}

			$refusal = $this->vmAssertFits((string) $data[$column], $column, $limit);

			if ($refusal !== null) {
				return $refusal;
			}
		}

		return null;
	}

	/**
	 * Recompute and persist the five denormalised join hints from live rows.
	 *
	 * @return array<string,int>
	 */
	protected function refreshHasFlags(int $productId): array
	{
		$satellites = [
			'categories'    => $this->vmXrefIds('product_categories', 'virtuemart_product_id', $productId, 'virtuemart_category_id'),
			'manufacturers' => $this->vmXrefIds('product_manufacturers', 'virtuemart_product_id', $productId, 'virtuemart_manufacturer_id'),
			'shoppergroups' => $this->vmXrefIds('product_shoppergroups', 'virtuemart_product_id', $productId, 'virtuemart_shoppergroup_id'),
			'medias'        => $this->vmXrefIds('product_medias', 'virtuemart_product_id', $productId, 'virtuemart_media_id'),
			'prices'        => $this->vmProductPrices($productId),
		];

		$flags = $this->vmHasFlags($satellites);

		$object                        = new \stdClass();
		$object->virtuemart_product_id = $productId;

		foreach ($flags as $flag => $value) {
			$object->{$flag} = $value;
		}

		$this->db->updateObject($this->vmTable('products'), $object, 'virtuemart_product_id');

		return $flags;
	}
}
