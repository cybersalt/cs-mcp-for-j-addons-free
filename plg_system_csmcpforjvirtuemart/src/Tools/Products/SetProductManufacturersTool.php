<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Products;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Set a product's manufacturer links, and optionally its shopper-group
 * restrictions.
 *
 * Both of these xrefs are worse than categories in VirtueMart's own save path,
 * not better: `models/product.php:2935` and `:2937` call
 * `updateXrefAndChildTables()` unconditionally on every non-child save, with no
 * guard and no `-2` sentinel. Omit `virtuemart_manufacturer_id` or
 * `virtuemart_shoppergroup_id` from a `store()` call and the rows are gone, full
 * stop.
 *
 * Shopper groups are folded in here rather than given their own tool because
 * the mechanic is identical and the two are almost always reasoned about
 * together — "who is this from, and who is allowed to see it".
 */
final class SetProductManufacturersTool extends AbstractTool
{
	use VirtuemartBootTrait;
	use ProductWriteTrait;

	public function getName(): string { return 'set_virtuemart_product_manufacturers'; }

	public function getDescription(): string
	{
		return 'Set which manufacturers a VirtueMart product is linked to, and optionally which shopper '
			. 'groups may see it. Requires id, plus manufacturer_ids and/or shoppergroup_ids. Each is a '
			. 'full replacement of that set; a set you do not pass is not touched. '
			. 'These two xrefs are the most exposed of all in VirtueMart\'s own save path. Unlike '
			. 'categories, which at least have the "-2" sentinel, models/product.php:2935 and :2937 call '
			. 'updateXrefAndChildTables() unconditionally on every non-child save — so any partial call to '
			. 'ProductModel::store() that omits virtuemart_manufacturer_id or '
			. 'virtuemart_shoppergroup_id deletes those rows outright '
			. '(helpers/vmtablexarray.php:226-237). This tool only ever writes a set you named. '
			. 'SHOPPER GROUPS ARE A RESTRICTION, NOT A GRANT. A product with no rows in '
			. '#__virtuemart_product_shoppergroups is visible to EVERYONE. Adding a shopper group limits '
			. 'the product to members of that group, so passing shoppergroup_ids: [3] hides the product '
			. 'from every shopper outside group 3, including guests. Passing an empty array makes it '
			. 'visible to everyone again. '
			. 'Ids are validated before anything is written; an id that does not exist is refused rather '
			. 'than inserted, because VirtueMart has no foreign keys and would accept it silently. '
			. 'The has_manufacturers and has_shoppergroups join hints are recomputed afterwards '
			. '(models/product.php:2765-2776) — when either reads 0, VirtueMart skips that join and the '
			. 'rows have no effect.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'               => ['type' => 'integer', 'description' => 'virtuemart_product_id. Required.'],
				'manufacturer_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Replace the manufacturer links with these. Empty array removes them all. Omit to leave manufacturers alone.'],
				'shoppergroup_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Replace the shopper-group RESTRICTION with these. Empty array means visible to everyone. Omit to leave shopper groups alone.'],
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

		$exists = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName($this->vmTable('products')))
				->where($this->db->quoteName('virtuemart_product_id') . ' = ' . $id)
		)->loadResult();

		if ($exists === 0) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No product with virtuemart_product_id ' . $id . '. Nothing was written.',
			], true);
		}

		$doManufacturers = \array_key_exists('manufacturer_ids', $arguments);
		$doShoppergroups = \array_key_exists('shoppergroup_ids', $arguments);

		if (!$doManufacturers && !$doShoppergroups) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Supply manufacturer_ids and/or shoppergroup_ids. Neither set is touched '
					. 'unless you name it.',
				'current' => [
					'manufacturer_ids' => $this->vmXrefIds('product_manufacturers', 'virtuemart_product_id', $id, 'virtuemart_manufacturer_id'),
					'shoppergroup_ids' => $this->vmXrefIds('product_shoppergroups', 'virtuemart_product_id', $id, 'virtuemart_shoppergroup_id'),
				],
			], true);
		}

		$response = ['ok' => true, 'virtuemart_product_id' => $id];

		if ($doManufacturers) {
			if (!$this->vmTableExists('product_manufacturers') || !$this->vmTableExists('manufacturers')) {
				return $this->vmMissingTableError('product_manufacturers');
			}

			$target  = $this->cleanIds((array) $arguments['manufacturer_ids']);
			$current = $this->vmXrefIds('product_manufacturers', 'virtuemart_product_id', $id, 'virtuemart_manufacturer_id');

			$refusal = $this->assertIdsExist('manufacturers', 'virtuemart_manufacturer_id', $target, 'manufacturer');

			if ($refusal !== null) {
				return $refusal;
			}

			$this->vmReplaceXref('product_manufacturers', 'virtuemart_product_id', $id, 'virtuemart_manufacturer_id', $target);

			$response['manufacturers'] = [
				'previous' => $current,
				'current'  => $target,
			];
		}

		if ($doShoppergroups) {
			if (!$this->vmTableExists('product_shoppergroups') || !$this->vmTableExists('shoppergroups')) {
				return $this->vmMissingTableError('product_shoppergroups');
			}

			$target  = $this->cleanIds((array) $arguments['shoppergroup_ids']);
			$current = $this->vmXrefIds('product_shoppergroups', 'virtuemart_product_id', $id, 'virtuemart_shoppergroup_id');

			$refusal = $this->assertIdsExist('shoppergroups', 'virtuemart_shoppergroup_id', $target, 'shopper group');

			if ($refusal !== null) {
				return $refusal;
			}

			$this->vmReplaceXref('product_shoppergroups', 'virtuemart_product_id', $id, 'virtuemart_shoppergroup_id', $target);

			$response['shoppergroups'] = [
				'previous' => $current,
				'current'  => $target,
			];

			$response['shoppergroup_effect'] = $target === []
				? 'This product is now visible to EVERYONE — an empty shopper-group set means no '
					. 'restriction at all.'
				: 'This product is now visible ONLY to members of shopper group(s) '
					. implode(', ', $target) . '. Every other shopper, including guests, will not see it '
					. 'anywhere in the shop.';
		}

		$response['has_flags']         = $this->refreshHasFlags($id);
		$response['direct_write_note'] = $this->vmDirectWriteNotice();
		$response['component']         = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/** @param array<int,int> $ids */
	private function assertIdsExist(string $table, string $keyColumn, array $ids, string $label): ?ToolResult
	{
		if ($ids === []) {
			return null;
		}

		$found = array_map('intval', $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName($keyColumn))
				->from($this->db->quoteName($this->vmTable($table)))
				->whereIn($this->db->quoteName($keyColumn), $ids)
		)->loadColumn() ?: []);

		$missing = array_values(array_diff($ids, $found));

		if ($missing === []) {
			return null;
		}

		return ToolResult::json([
			'ok'    => false,
			'error' => 'These ' . $label . ' ids do not exist: ' . implode(', ', $missing)
				. '. Nothing was written. VirtueMart declares no foreign keys, so an invalid id would '
				. 'have inserted cleanly and simply never matched anything.',
		], true);
	}

	/** @return array<int,int> */
	private function cleanIds(array $raw): array
	{
		$out = [];

		foreach ($raw as $value) {
			$id = (int) $value;

			if ($id > 0 && !\in_array($id, $out, true)) {
				$out[] = $id;
			}
		}

		return $out;
	}
}
