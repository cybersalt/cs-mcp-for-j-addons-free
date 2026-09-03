<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Products;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Return a product's complete state: base row, every language row, and every
 * satellite set.
 *
 * This is the read half of the read-modify-write contract. A caller that means
 * to change one field should fetch this first, so that whatever it sends back
 * to update_virtuemart_product describes a whole product rather than a
 * fragment. VirtueMart's own `ProductModel::store()` interprets a fragment as
 * "the user deleted everything they did not mention".
 *
 * It also runs the eight-point "will this actually appear in the shop?"
 * checklist, because a product can be perfectly well-formed in the base table
 * and still be invisible for any of five separate reasons.
 */
final class GetProductTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'get_virtuemart_product'; }

	public function getDescription(): string
	{
		return 'Return one VirtueMart product in full: the #__virtuemart_products base row, its row in '
			. 'EVERY active language satellite table, and all satellites — categories, manufacturers, '
			. 'shopper groups, media links, price rows, custom field values and variant (child) product '
			. 'ids. '
			. 'Use this before update_virtuemart_product. VirtueMart\'s own product model treats its input '
			. 'as a complete admin form POST, so anything omitted is read as a deletion; this add-on '
			. 'avoids that by merging your changes onto the whole current state, and this tool is how you '
			. 'see that state. '
			. 'The response includes a storefront_visibility block: the ordered checklist of everything '
			. 'that has to be true for a product to appear in the shop — a language row for the shopper\'s '
			. 'language (the join is INNER, helpers/vmtable.php:1065-1068), a non-empty unique slug, '
			. 'published = 1, at least one published category, at least one price row, and the has_* join '
			. 'hints agreeing with reality. Each failing item says what to do about it. '
			. 'Price rows are returned raw. product_price is the BASE/COST price, not the shopper-facing '
			. 'figure: the sales price is computed at read time by calculationHelper '
			. '(helpers/calculationh.php:404-498) from this figure plus the applicable tax, discount and '
			. 'margin rules, currency conversion and shopper group. Never quote product_price to a '
			. 'customer as "the price". '
			. 'Custom field rows are returned as stored. Note that their disabler and override columns are '
			. 'NOT booleans despite the names — models/customfields.php:1378-1384 puts a parent row\'s '
			. 'virtuemart_customfield_id in them, so treat them as nullable foreign keys.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'                 => ['type' => 'integer', 'description' => 'virtuemart_product_id. Required unless sku is given.'],
				'sku'                => ['type' => 'string', 'description' => 'Exact product_sku, as an alternative to id. Note VirtueMart does not enforce SKU uniqueness; if several match, this refuses rather than guessing.'],
				'include_customfields' => ['type' => 'boolean', 'description' => 'Include #__virtuemart_product_customfields rows. Default true.'],
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

		$id = (int) ($arguments['id'] ?? 0);

		if ($id <= 0) {
			$sku = trim((string) ($arguments['sku'] ?? ''));

			if ($sku === '') {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Supply either id (virtuemart_product_id) or sku.',
				], true);
			}

			$matches = $this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName('virtuemart_product_id'))
					->from($this->db->quoteName($this->vmTable('products')))
					->where($this->db->quoteName('product_sku') . ' = ' . $this->db->quote($sku))
			)->loadColumn() ?: [];

			if ($matches === []) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'No product with product_sku ' . $sku . '.',
				], true);
			}

			if (\count($matches) > 1) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'product_sku ' . $sku . ' matches ' . \count($matches) . ' products. '
						. 'VirtueMart puts no unique index on product_sku, so duplicates are legal and this '
						. 'tool will not guess which one you meant.',
					'matching_ids' => array_map('intval', $matches),
				], true);
			}

			$id = (int) $matches[0];
		}

		$state = $this->vmReadProductState($id);

		if ($state === null) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No product with virtuemart_product_id ' . $id . '.',
			], true);
		}

		$base = $state['base'];

		$translations = [];

		foreach ($state['translations'] as $tag => $info) {
			$translations[$tag] = [
				'table'  => $this->vmLangTable('products', (string) $tag),
				'exists' => $info['exists'],
				'row'    => $info['row'],
			];

			if ($info['table_missing']) {
				$translations[$tag]['error'] = 'The satellite table does not exist on this site even '
					. 'though ' . $tag . ' is in VirtueMart\'s active_languages. Language tables are only '
					. 'created when that list GROWS (models/config.php:541-546).';
			} elseif (!$info['exists']) {
				$translations[$tag]['warning'] = 'No row for this product. Shoppers browsing in ' . $tag
					. ' cannot see this product at all — the join is INNER.';
			}
		}

		$response = [
			'ok'            => true,
			'product'       => $base,
			'translations'  => $translations,
			'categories'    => $state['categories'],
			'manufacturers' => $state['manufacturers'],
			'shoppergroups' => $state['shoppergroups'],
			'medias'        => $state['medias'],
			'prices'        => $state['prices'],
			'variants'      => $state['variants'],
		];

		if ((bool) ($arguments['include_customfields'] ?? true)) {
			$response['customfields'] = $state['customfields'];
			$response['customfields_note'] = 'disabler and override hold a PARENT row\'s '
				. 'virtuemart_customfield_id, not a boolean (models/customfields.php:1378-1384).';
		}

		$response['price_note'] = 'product_price is the base/cost price. The shopper-facing sales price is '
			. 'derived at display time by calculationHelper (helpers/calculationh.php:404-498) and is not '
			. 'stored anywhere. Multiple price rows per product are normal: one per shopper group and per '
			. 'quantity tier.';

		if ($state['prices'] !== []) {
			$response['price_scale_note'] = 'product_price is decimal(15,6) but product_override_price is '
				. 'decimal(15,5). Writing more precision than the column holds truncates at the DB, so do '
				. 'not round-trip these figures through anything that reformats them.';
		}

		if ((int) $base['product_parent_id'] > 0) {
			$response['variant_note'] = 'This is a variant: product_parent_id is '
				. (int) $base['product_parent_id'] . '. Variants inherit the parent\'s custom fields '
				. 'unless the child row sets override, disabler or noninheritable '
				. '(models/customfields.php:1373-1400).';
		}

		$response['storefront_visibility'] = $this->visibilityChecklist($id, $state);
		$response['component']             = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/**
	 * The ordered "why is this product not showing?" checklist.
	 *
	 * @param  array<string,mixed> $state
	 * @return array<string,mixed>
	 */
	private function visibilityChecklist(int $id, array $state): array
	{
		$base   = $state['base'];
		$checks = [];

		$missingLangs = [];

		foreach ($state['translations'] as $tag => $info) {
			if (!$info['exists']) {
				$missingLangs[] = (string) $tag;
			}
		}

		$checks[] = [
			'check'  => 'language row present for every active language',
			'passed' => $missingLangs === [],
			'detail' => $missingLangs === []
				? 'A row exists in each active language satellite table.'
				: 'Missing in: ' . implode(', ', $missingLangs) . '. The read join is INNER '
					. '(helpers/vmtable.php:1065-1068), so this product does not exist for shoppers using '
					. 'those languages. This is the single most common cause of an externally created '
					. 'product never appearing. Fix with set_virtuemart_product_translation.',
		];

		$emptySlugs = [];

		foreach ($state['translations'] as $tag => $info) {
			if ($info['exists'] && trim((string) ($info['row']['slug'] ?? '')) === '') {
				$emptySlugs[] = (string) $tag;
			}
		}

		$checks[] = [
			'check'  => 'non-empty slug in every language row',
			'passed' => $emptySlugs === [],
			'detail' => $emptySlugs === []
				? 'Every present language row has a slug.'
				: 'Empty slug in: ' . implode(', ', $emptySlugs) . '. SEF URLs will 404. The slug column '
					. 'carries a UNIQUE KEY on the language table (helpers/tableupdater.php:193-198).',
		];

		$published = (int) $base['published'] === 1;

		$checks[] = [
			'check'  => 'published = 1',
			'passed' => $published,
			'detail' => $published
				? 'Published.'
				: 'Unpublished. Note the SQL default for this column is 0 (install.sql:842) while the PHP '
					. 'table class default is 1 (tables/products.php:93), so a raw INSERT that omits it '
					. 'produces an unpublished product. Use set_virtuemart_product_state.',
		];

		$categoryCount = \count($state['categories']);
		$publishedCats = 0;

		if ($categoryCount > 0 && $this->vmTableExists('categories')) {
			$publishedCats = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select('COUNT(*)')
					->from($this->db->quoteName($this->vmTable('categories')))
					->whereIn($this->db->quoteName('virtuemart_category_id'), $state['categories'])
					->where($this->db->quoteName('published') . ' = 1')
			)->loadResult();
		}

		$checks[] = [
			'check'  => 'assigned to at least one published category',
			'passed' => $publishedCats > 0,
			'detail' => $publishedCats > 0
				? $publishedCats . ' of ' . $categoryCount . ' assigned categories are published.'
				: ($categoryCount === 0
					? 'No rows in #__virtuemart_product_categories. The product is reachable by direct URL '
						. 'but appears in no listing.'
					: 'All ' . $categoryCount . ' assigned categories are unpublished.'),
		];

		$checks[] = [
			'check'  => 'at least one price row',
			'passed' => $state['prices'] !== [],
			'detail' => $state['prices'] !== []
				? \count($state['prices']) . ' price row(s). Use virtuemart_shoppergroup_id 0 for "everyone".'
				: 'No rows in #__virtuemart_product_prices. Unless the shop runs with prices disabled, the '
					. 'product shows without a price and cannot be bought.',
		];

		$flags       = $this->vmHasFlags($state);
		$flagOk      = true;
		$flagDetails = [];

		foreach ($flags as $flag => $expected) {
			$actual = (int) ($base[$flag] ?? 0);

			if ($actual !== $expected) {
				$flagOk        = false;
				$flagDetails[] = $flag . ' is ' . $actual . ' but should be ' . $expected;
			}
		}

		$checks[] = [
			'check'  => 'has_* join hints agree with the satellite rows',
			'passed' => $flagOk,
			'detail' => $flagOk
				? 'All five flags match.'
				: implode('; ', $flagDetails) . '. These are recomputed on every save at '
					. 'models/product.php:2765-2776. A flag reading 0 makes VirtueMart skip that join '
					. 'entirely, so the rows exist but are never displayed. update_virtuemart_product '
					. 'recomputes them.',
		];

		$vendorOk = (int) $base['virtuemart_vendor_id'] > 0;

		$checks[] = [
			'check'  => 'virtuemart_vendor_id set',
			'passed' => $vendorOk,
			'detail' => $vendorOk
				? 'Vendor ' . (int) $base['virtuemart_vendor_id'] . '. On a single-vendor shop '
					. '(multix = none, the default) VirtueMart forces this to 1 on every save regardless '
					. 'of what is passed — helpers/vmtable.php:1526-1603.'
				: 'Vendor id is 0, which matches no vendor row.',
		];

		$failed = 0;

		foreach ($checks as $check) {
			if (!$check['passed']) {
				$failed++;
			}
		}

		return [
			'all_passed'   => $failed === 0,
			'failed_count' => $failed,
			'checks'       => $checks,
		];
	}
}
