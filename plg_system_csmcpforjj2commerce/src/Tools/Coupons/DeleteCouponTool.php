<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Coupons;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

final class DeleteCouponTool extends AbstractTool
{
	use J2commerceBootTrait;
	use CouponSupportTrait;

	public function getName(): string { return 'delete_j2commerce_coupon'; }

	public function getDescription(): string
	{
		return 'Delete a J2Commerce coupon by coupon_id. Past orders keep their discount — it is stored on the '
			. 'order — and the redemption rows in #__j2commerce_orderdiscounts survive, but they no longer link '
			. 'to a coupon, so reports cannot attribute them. The code becomes reusable immediately. When the '
			. 'coupon has ever been redeemed, confirm: true is required; consider update_j2commerce_coupon '
			. 'enabled: false instead, which stops it working and keeps the history attributable.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'coupon_id' => ['type' => 'integer', 'description' => 'Required.'],
				'confirm'   => ['type' => 'boolean', 'description' => 'Required when the coupon has redemptions.'],
			],
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

		if (!$actor->authorise('core.delete', 'com_j2commerce')) {
			throw new \RuntimeException('Deleting a coupon requires core.delete on com_j2commerce.');
		}

		$db    = $this->db;
		$id    = $this->requirePositiveInt($arguments, 'coupon_id');
		$table = $db->quoteName($this->j2Table('coupons'));
		$row   = $db->setQuery('SELECT * FROM ' . $table . ' WHERE j2commerce_coupon_id = ' . $id)->loadAssoc();

		if (!\is_array($row)) {
			return ToolResult::json(['ok' => false, 'error' => 'No coupon with id ' . $id . '. Nothing was deleted.'], true);
		}

		$redemptions = (int) $db->setQuery(
			'SELECT COUNT(*) FROM ' . $db->quoteName($this->j2Table('orderdiscounts'))
			. ' WHERE discount_type = ' . $db->quote('coupon') . ' AND discount_entity_id = ' . $id
		)->loadResult();

		if ($redemptions > 0 && !(bool) ($arguments['confirm'] ?? false)) {
			return ToolResult::json([
				'ok'          => false,
				'error'       => 'Coupon ' . $row['coupon_code'] . ' has been redeemed on ' . $redemptions . ' order(s). Deleting '
					. 'it leaves those redemptions unattributable. Pass confirm: true to delete anyway, or disable it with '
					. 'update_j2commerce_coupon enabled: false. Nothing was deleted.',
				'redemptions' => $redemptions,
			], true);
		}

		$db->setQuery('DELETE FROM ' . $table . ' WHERE j2commerce_coupon_id = ' . $id)->execute();

		return ToolResult::json([
			'ok'      => true,
			'deleted' => $this->shapeCoupon($row, $this->couponUsage([$id])[$id] ?? 0),
			'orphaned_redemption_rows' => $redemptions,
		]);
	}
}
