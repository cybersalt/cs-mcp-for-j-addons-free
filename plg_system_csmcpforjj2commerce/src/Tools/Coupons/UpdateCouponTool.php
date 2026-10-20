<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Coupons;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

final class UpdateCouponTool extends AbstractTool
{
	use J2commerceBootTrait;
	use CouponSupportTrait;
	use CouponWriteTrait;

	public function getName(): string { return 'update_j2commerce_coupon'; }

	public function getDescription(): string
	{
		return 'Change a J2Commerce coupon. Pass coupon_id and only the fields to change; everything else is '
			. 'left as stored. Restriction lists (product_ids, category_ids, manufacturer_ids, user_ids) REPLACE '
			. 'the stored list — pass an empty list to remove a restriction. Same validation as '
			. 'create_j2commerce_coupon. Use enabled: false to switch a coupon off without losing its '
			. 'redemption history. Changing value or value_type does not alter discounts already given on past '
			. 'orders — those are stored on the order. Fixing value_type is how a coupon that "applies but '
			. 'discounts nothing" is repaired (list_j2commerce_coupons state: broken finds them).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => ['coupon_id' => ['type' => 'integer', 'description' => 'Required.']] + $this->couponSchemaProps(),
			'required' => ['coupon_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->j2Installed()) {
			return $this->j2NotInstalledError();
		}

		$this->j2RequireAccess($actor, 'j2commerce.editorders');

		$db    = $this->db;
		$id    = $this->requirePositiveInt($arguments, 'coupon_id');
		$table = $db->quoteName($this->j2Table('coupons'));
		$row   = $db->setQuery('SELECT * FROM ' . $table . ' WHERE j2commerce_coupon_id = ' . $id)->loadAssoc();

		if (!\is_array($row)) {
			return ToolResult::json(['ok' => false, 'error' => 'No coupon with id ' . $id . '. Nothing was written.'], true);
		}

		$columns = $this->couponColumns($arguments, $row);
		$changes = [];

		foreach ($columns as $col => $value) {
			if ((string) ($row[$col] ?? '') !== (string) ($value ?? '')) {
				$changes[$col] = ['before' => $row[$col], 'after' => $value];
			}
		}

		if ($changes === []) {
			return ToolResult::json(['ok' => true, 'changed' => [], 'note' => 'Nothing to change. Nothing was written.']);
		}

		$obj = (object) (array_map(static fn (array $c) => $c['after'], $changes) + [
			'j2commerce_coupon_id' => $id,
			'modified_on'          => $this->j2Now(),
			'modified_by'          => (int) $actor->id,
		]);

		// updateNulls so a cleared validity date really becomes NULL.
		$db->updateObject($this->j2Table('coupons'), $obj, 'j2commerce_coupon_id', true);

		$saved = $db->setQuery('SELECT * FROM ' . $table . ' WHERE j2commerce_coupon_id = ' . $id)->loadAssoc();

		return ToolResult::json([
			'ok'      => true,
			'changed' => $changes,
			'coupon'  => $this->shapeCoupon($saved, $this->couponUsage([$id])[$id] ?? 0),
		]);
	}
}
