<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Products;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * List products, joining the language table with a LEFT JOIN on purpose.
 *
 * VirtueMart itself joins `#__virtuemart_products` to
 * `#__virtuemart_products_<lang>` with an INNER JOIN
 * (`helpers/vmtable.php:1065-1068`). A product whose language row is missing is
 * therefore not "untranslated" — it is absent from every listing, every
 * category page and every search result, with no error logged anywhere. On a
 * single-language shop there is not even a fallback pass, because
 * `helpers/vmtable.php:1184` requires `langCount > 1`.
 *
 * A diagnostic tool that copied that INNER JOIN would hide precisely the rows a
 * user is looking for. So this one LEFT JOINs and reports `product_name: null`
 * with an explicit `invisible` flag instead. check_virtuemart_language_tables
 * turns the same idea into a shop-wide audit.
 */
final class ListProductsTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'list_virtuemart_products'; }

	public function getDescription(): string
	{
		return 'List VirtueMart products from #__virtuemart_products, joined to the language satellite '
			. 'table for the requested language. '
			. 'CRITICAL STRUCTURAL FACT: #__virtuemart_products has NO product_name column. The name, both '
			. 'descriptions, the meta fields and the slug all live in #__virtuemart_products_<langsuffix> '
			. '(en-GB becomes en_gb), and VirtueMart reads them back with an INNER JOIN '
			. '(helpers/vmtable.php:1065-1068). This tool uses a LEFT JOIN instead, so a product with no '
			. 'language row appears here with product_name null and invisible: true rather than silently '
			. 'vanishing the way it does everywhere in the shop. Set only_invisible: true to list just '
			. 'those. '
			. 'Filters: search (matches product_name in the chosen language, product_sku, product_gtin and '
			. 'product_mpn), published, category_id, manufacturer_id, parent_id (0 lists only standalone '
			. 'and parent products; a positive id lists that product\'s variants), language. '
			. 'Supports limit (default 50, max 200) and offset. '
			. 'PRICES ARE NOT RETURNED and deliberately so. #__virtuemart_product_prices.product_price is '
			. 'the base/cost price, not the figure a shopper sees: the sales price is computed at read '
			. 'time by calculationHelper (helpers/calculationh.php:404-498) from the base price plus the '
			. 'applicable tax, discount and margin rules, currency conversion and shopper group. Use '
			. 'list_virtuemart_product_prices, which says so alongside every figure. '
			. 'The has_categories / has_prices / has_medias / has_shoppergroups / has_manufacturers columns '
			. 'are denormalised join hints, not facts. When one is 0 VirtueMart skips that join in its '
			. 'listing queries even if the rows exist, so a product with has_prices = 0 displays with no '
			. 'price at all. Any row whose flags disagree with its actual satellite rows is reported with '
			. 'a flag_mismatch note.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'search'          => ['type' => 'string', 'description' => 'Case-insensitive substring match on product_name (in the chosen language), product_sku, product_gtin or product_mpn.'],
				'published'       => ['type' => 'boolean', 'description' => 'true for published products only, false for unpublished only. Omit for both.'],
				'category_id'     => ['type' => 'integer', 'description' => 'Only products assigned to this category via #__virtuemart_product_categories.'],
				'manufacturer_id' => ['type' => 'integer', 'description' => 'Only products assigned to this manufacturer.'],
				'parent_id'       => ['type' => 'integer', 'description' => 'product_parent_id filter. 0 lists standalone and parent products only; a positive id lists that product\'s variants.'],
				'language'        => ['type' => 'string', 'description' => 'Language tag whose satellite table to join, e.g. "en-GB". Defaults to the shop language. Must be one of the shop\'s active_languages.'],
				'only_invisible'  => ['type' => 'boolean', 'description' => 'Return ONLY products with no row in the chosen language table — the ones VirtueMart hides without saying so.'],
				'limit'           => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'          => ['type' => 'integer', 'description' => 'Rows to skip.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->vmAdminBase() === null) {
			return $this->vmNotInstalledError();
		}

		if (!$this->vmTableExists('products')) {
			return $this->vmMissingTableError('products');
		}

		$tag = $this->vmResolveLangTag($arguments);

		if ($tag instanceof ToolResult) {
			return $tag;
		}

		if (!$this->vmLangTableExists('products', $tag)) {
			return $this->vmMissingTableError('products_' . $this->vmLangSuffix($tag));
		}

		$products  = $this->db->quoteName($this->vmTable('products'), 'p');
		$langTable = $this->db->quoteName($this->vmLangTable('products', $tag), 'pl');
		$limit     = $this->vmLimit($arguments);
		$offset    = $this->vmOffset($arguments);

		$where = [];

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$like    = $this->db->quote('%' . $this->db->escape($search, true) . '%', false);
			$where[] = '(' . implode(' OR ', [
				$this->db->quoteName('pl.product_name') . ' LIKE ' . $like,
				$this->db->quoteName('p.product_sku') . ' LIKE ' . $like,
				$this->db->quoteName('p.product_gtin') . ' LIKE ' . $like,
				$this->db->quoteName('p.product_mpn') . ' LIKE ' . $like,
			]) . ')';
		}

		if (\array_key_exists('published', $arguments)) {
			$where[] = $this->db->quoteName('p.published') . ' = ' . ((bool) $arguments['published'] ? 1 : 0);
		}

		if (\array_key_exists('parent_id', $arguments)) {
			$where[] = $this->db->quoteName('p.product_parent_id') . ' = ' . (int) $arguments['parent_id'];
		}

		if (\array_key_exists('category_id', $arguments)) {
			$where[] = $this->db->quoteName('p.virtuemart_product_id') . ' IN (SELECT '
				. $this->db->quoteName('virtuemart_product_id') . ' FROM '
				. $this->db->quoteName($this->vmTable('product_categories')) . ' WHERE '
				. $this->db->quoteName('virtuemart_category_id') . ' = ' . (int) $arguments['category_id'] . ')';
		}

		if (\array_key_exists('manufacturer_id', $arguments)) {
			$where[] = $this->db->quoteName('p.virtuemart_product_id') . ' IN (SELECT '
				. $this->db->quoteName('virtuemart_product_id') . ' FROM '
				. $this->db->quoteName($this->vmTable('product_manufacturers')) . ' WHERE '
				. $this->db->quoteName('virtuemart_manufacturer_id') . ' = ' . (int) $arguments['manufacturer_id'] . ')';
		}

		if ((bool) ($arguments['only_invisible'] ?? false)) {
			$where[] = $this->db->quoteName('pl.virtuemart_product_id') . ' IS NULL';
		}

		$from = ' FROM ' . $products . ' LEFT JOIN ' . $langTable
			. ' ON ' . $this->db->quoteName('p.virtuemart_product_id')
			. ' = ' . $this->db->quoteName('pl.virtuemart_product_id');

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		$total = (int) $this->db->setQuery(
			'SELECT COUNT(*)' . $from . $whereSql
		)->loadResult();

		$select = [
			$this->db->quoteName('p.virtuemart_product_id'),
			$this->db->quoteName('p.product_parent_id'),
			$this->db->quoteName('p.virtuemart_vendor_id'),
			$this->db->quoteName('p.product_sku'),
			$this->db->quoteName('p.product_gtin'),
			$this->db->quoteName('p.product_mpn'),
			$this->db->quoteName('p.product_in_stock'),
			$this->db->quoteName('p.product_ordered'),
			$this->db->quoteName('p.product_sales'),
			$this->db->quoteName('p.product_special'),
			$this->db->quoteName('p.product_discontinued'),
			$this->db->quoteName('p.product_canon_category_id'),
			$this->db->quoteName('p.published'),
			$this->db->quoteName('p.hits'),
			$this->db->quoteName('p.created_on'),
			$this->db->quoteName('p.modified_on'),
			$this->db->quoteName('p.has_categories'),
			$this->db->quoteName('p.has_prices'),
			$this->db->quoteName('p.has_medias'),
			$this->db->quoteName('p.has_shoppergroups'),
			$this->db->quoteName('p.has_manufacturers'),
			$this->db->quoteName('pl.product_name'),
			$this->db->quoteName('pl.slug'),
		];

		$rows = $this->db->setQuery(
			'SELECT ' . implode(', ', $select) . $from . $whereSql
			. ' ORDER BY ' . $this->db->quoteName('p.virtuemart_product_id') . ' DESC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$out         = [];
		$invisible   = 0;
		$mismatched  = 0;

		foreach ($rows as $row) {
			$id      = (int) $row['virtuemart_product_id'];
			$hasLang = $row['product_name'] !== null;

			$entry = [
				'virtuemart_product_id'  => $id,
				'product_name'           => $hasLang ? (string) $row['product_name'] : null,
				'slug'                   => $hasLang ? (string) $row['slug'] : null,
				'product_sku'            => (string) $row['product_sku'],
				'published'              => (int) $row['published'] === 1,
				'product_parent_id'      => (int) $row['product_parent_id'],
				'is_variant'             => (int) $row['product_parent_id'] > 0,
				'product_in_stock'       => (int) $row['product_in_stock'],
				'product_ordered'        => (int) $row['product_ordered'],
				'product_sales'          => (int) $row['product_sales'],
				'product_special'        => (int) $row['product_special'] === 1,
				'product_discontinued'   => (int) $row['product_discontinued'] === 1,
				'virtuemart_vendor_id'   => (int) $row['virtuemart_vendor_id'],
				'hits'                   => (int) $row['hits'],
				'created_on'             => $row['created_on'],
				'modified_on'            => $row['modified_on'],
				'language'               => $tag,
			];

			if (!$hasLang) {
				$invisible++;
				$entry['invisible'] = true;
				$entry['invisible_reason'] = 'There is no row in ' . $this->vmLangTable('products', $tag)
					. ' for this product id. VirtueMart joins that table with an INNER JOIN '
					. '(helpers/vmtable.php:1065-1068), so this product does not appear anywhere in the '
					. 'shop for shoppers on ' . $tag . ' — not in listings, not in search, not on a '
					. 'category page — and nothing logs it. Fix it with '
					. 'set_virtuemart_product_translation.';
			}

			if ($hasLang && trim((string) $row['slug']) === '') {
				$entry['slug_warning'] = 'The language row exists but slug is empty. SEF URLs for this '
					. 'product will 404. VirtueMart derives the slug from product_name in check() '
					. '(helpers/vmtable.php:1737-1744) and refuses the whole save when both are empty.';
			}

			$flagIssues = $this->flagMismatches($id, $row);

			if ($flagIssues !== []) {
				$mismatched++;
				$entry['flag_mismatch'] = $flagIssues;
			}

			$out[] = $entry;
		}

		$response = [
			'ok'       => true,
			'total'    => $total,
			'limit'    => $limit,
			'offset'   => $offset,
			'showing'  => \count($out),
			'language' => [
				'tag'              => $tag,
				'suffix'           => $this->vmLangSuffix($tag),
				'table'            => $this->vmLangTable('products', $tag),
				'active_languages' => $this->vmActiveLangTags(),
			],
			'products' => $out,
			'join_note' => 'This listing uses a LEFT JOIN. VirtueMart uses an INNER JOIN, so any row here '
				. 'with invisible: true is one the shop itself does not show and does not report.',
		];

		if ($invisible > 0) {
			$response['invisible_count'] = $invisible;
			$response['warning'] = $invisible . ' of the products in this page of results have no '
				. $tag . ' language row and are invisible to shoppers. Run '
				. 'check_virtuemart_language_tables for a shop-wide count.';
		}

		if ($mismatched > 0) {
			$response['flag_mismatch_count'] = $mismatched;
			$response['flag_mismatch_note'] = 'The has_* columns are denormalised join hints recomputed on '
				. 'every save at models/product.php:2765-2776. When one reads 0 but satellite rows exist, '
				. 'VirtueMart skips that join and the data is present but never displayed. Re-saving the '
				. 'product through this add-on\'s update_virtuemart_product recomputes them.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/**
	 * Cross-check the denormalised flags against the real satellite counts.
	 *
	 * @param  array<string,mixed> $row
	 * @return array<int,string>
	 */
	private function flagMismatches(int $productId, array $row): array
	{
		$map = [
			'has_categories'    => ['product_categories', 'virtuemart_product_id'],
			'has_prices'        => ['product_prices', 'virtuemart_product_id'],
			'has_medias'        => ['product_medias', 'virtuemart_product_id'],
			'has_shoppergroups' => ['product_shoppergroups', 'virtuemart_product_id'],
			'has_manufacturers' => ['product_manufacturers', 'virtuemart_product_id'],
		];

		$issues = [];

		foreach ($map as $flag => [$table, $column]) {
			if (!$this->vmTableExists($table)) {
				continue;
			}

			$count = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select('COUNT(*)')
					->from($this->db->quoteName($this->vmTable($table)))
					->where($this->db->quoteName($column) . ' = ' . $productId)
			)->loadResult();

			$claims = (int) ($row[$flag] ?? 0) === 1;

			if ($claims && $count === 0) {
				$issues[] = $flag . ' is 1 but there are no rows in #__virtuemart_' . $table . '.';
			}

			if (!$claims && $count > 0) {
				$issues[] = $flag . ' is 0 but #__virtuemart_' . $table . ' holds ' . $count
					. ' row(s). VirtueMart will not join them, so this data is invisible in the shop.';
			}
		}

		return $issues;
	}
}
