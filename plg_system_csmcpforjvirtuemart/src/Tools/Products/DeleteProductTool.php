<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Products;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Delete a product and every row that hangs off it.
 *
 * VirtueMart declares no foreign keys anywhere in its schema — every
 * relationship is a plain indexed int and every cascade is done in PHP. So a
 * delete that only removes the base row leaves orphaned language rows, price
 * rows, xref rows and custom field values behind. Worse, the orphaned language
 * row keeps its slug, and the slug column is UNIQUE, so the next product with
 * the same name silently gets `-1` appended for no visible reason.
 *
 * The default posture is to unpublish rather than delete, because deletion here
 * is genuinely irreversible and because an unpublished product is invisible to
 * shoppers anyway.
 */
final class DeleteProductTool extends AbstractTool
{
	use VirtuemartBootTrait;

	/** Satellite tables keyed on virtuemart_product_id. */
	private const SATELLITES = [
		'product_categories',
		'product_manufacturers',
		'product_shoppergroups',
		'product_medias',
		'product_prices',
		'product_customfields',
		'ratings',
		'rating_votes',
		'rating_reviews',
		'waitingusers',
	];

	public function getName(): string { return 'delete_virtuemart_product'; }

	public function getDescription(): string
	{
		return 'Permanently delete a VirtueMart product and everything hanging off it: its row in every '
			. 'language satellite table, its category / manufacturer / shopper-group / media xref rows, '
			. 'its price rows, its custom field values, and its ratings, votes, reviews and back-in-stock '
			. 'waiting list. '
			. 'VirtueMart declares NO foreign keys anywhere in its schema — every cascade is done in PHP — '
			. 'so deleting only the base row would leave all of that orphaned. The orphaned language row '
			. 'matters most: it keeps its slug, the slug column is UNIQUE, and the next product created '
			. 'with the same name then gets "-1" appended for no reason anyone can see. '
			. 'THIS IS IRREVERSIBLE. There is no trash state for VirtueMart products. Consider '
			. 'set_virtuemart_product_state with published: false instead — an unpublished product is '
			. 'already invisible to shoppers and can be brought back. '
			. 'REFUSES if the product has variants (child products with product_parent_id set to it), '
			. 'unless delete_variants: true, because orphaned children point at a parent that no longer '
			. 'exists and inherit nothing. '
			. 'Order line items are NOT deleted and NOT modified. #__virtuemart_order_items stores a '
			. 'snapshot of the name, SKU and price at the time of sale, so order history and invoices '
			. 'survive intact; only the virtuemart_product_id reference becomes dangling. The count of '
			. 'affected order items is reported.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'              => ['type' => 'integer', 'description' => 'virtuemart_product_id. Required.'],
				'delete_variants' => ['type' => 'boolean', 'description' => 'Also delete every child product whose product_parent_id is this id. Default false, and the call refuses when children exist.'],
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

		$id = $this->requirePositiveInt($arguments, 'id');

		$state = $this->vmReadProductState($id);

		if ($state === null) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No product with virtuemart_product_id ' . $id . '. Nothing was deleted.',
			], true);
		}

		$variants = $state['variants'];

		if ($variants !== [] && !(bool) ($arguments['delete_variants'] ?? false)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Product ' . $id . ' has ' . \count($variants) . ' variant(s). Deleting it '
					. 'would leave them pointing at a parent that no longer exists, so they would inherit '
					. 'nothing and display as orphans. Refusing; nothing was deleted.',
				'variant_ids' => $variants,
				'resolution'  => 'Pass delete_variants: true to remove them as well, or re-parent them '
					. 'first with update_virtuemart_product.',
			], true);
		}

		$targets = array_merge([$id], (bool) ($arguments['delete_variants'] ?? false) ? $variants : []);

		$orderItems = 0;

		if ($this->vmTableExists('order_items')) {
			$orderItems = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select('COUNT(*)')
					->from($this->db->quoteName($this->vmTable('order_items')))
					->whereIn($this->db->quoteName('virtuemart_product_id'), $targets)
			)->loadResult();
		}

		$deleted = ['base' => 0, 'language_rows' => [], 'satellites' => []];

		foreach ($this->vmActiveLangTags() as $tag) {
			if (!$this->vmLangTableExists('products', $tag)) {
				continue;
			}

			$table = $this->vmLangTable('products', $tag);

			$this->db->setQuery(
				$this->db->getQuery(true)
					->delete($this->db->quoteName($table))
					->whereIn($this->db->quoteName('virtuemart_product_id'), $targets)
			)->execute();

			$deleted['language_rows'][$tag] = $this->db->getAffectedRows();
		}

		foreach (self::SATELLITES as $table) {
			if (!$this->vmTableExists($table)) {
				continue;
			}

			if (!\in_array('virtuemart_product_id', $this->vmColumns($table), true)) {
				continue;
			}

			$this->db->setQuery(
				$this->db->getQuery(true)
					->delete($this->db->quoteName($this->vmTable($table)))
					->whereIn($this->db->quoteName('virtuemart_product_id'), $targets)
			)->execute();

			$affected = $this->db->getAffectedRows();

			if ($affected > 0) {
				$deleted['satellites'][$table] = $affected;
			}
		}

		$this->db->setQuery(
			$this->db->getQuery(true)
				->delete($this->db->quoteName($this->vmTable('products')))
				->whereIn($this->db->quoteName('virtuemart_product_id'), $targets)
		)->execute();

		$deleted['base'] = $this->db->getAffectedRows();

		$response = [
			'ok'              => true,
			'deleted_ids'     => $targets,
			'deleted'         => $deleted,
			'irreversible'    => 'VirtueMart has no trash state for products. These rows are gone.',
		];

		if ($orderItems > 0) {
			$response['order_items_affected'] = $orderItems;
			$response['order_items_note'] = $orderItems . ' row(s) in #__virtuemart_order_items still '
				. 'reference the deleted product id(s). They were deliberately left alone: that table '
				. 'stores its own snapshot of order_item_name, order_item_sku and the price components, so '
				. 'order history and invoices remain correct. Only the virtuemart_product_id link is now '
				. 'dangling.';
		}

		$response['plugin_note'] = 'plgVmOnCustomfieldRemove did NOT fire for the deleted custom field '
			. 'values, because this delete did not go through VirtueMart\'s models. A third-party '
			. 'custom-field plugin holding its own side data for this product will not have cleaned it up.';

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}
}
