<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Orders;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

/**
 * Store a carrier tracking number through `OrderModel::saveTrackingNumber()`
 * (`src/Model/OrderModel.php:1627-1690`), the method J2Commerce's own
 * `POST /v1/j2commerce/orders/:id/tracking` calls.
 */
final class SetOrderTrackingTool extends AbstractTool
{
	use J2commerceBootTrait;
	use OrderSupportTrait;

	public function getName(): string { return 'set_j2commerce_order_tracking'; }

	public function getDescription(): string
	{
		return 'Record a carrier tracking number on a J2Commerce order, through J2Commerce\'s own '
			. 'OrderModel::saveTrackingNumber() — the method its REST tracking endpoint uses. It writes the '
			. 'number onto the order\'s shipping row(s), releases any shipping-label purchase claim, and fires '
			. 'onJ2CommerceAfterSaveTrackingNumber so fulfilment apps can react. '
			. 'It does NOT change the order status and does NOT email the customer: follow it with '
			. 'set_j2commerce_order_status (e.g. to 7 Shipped, notify: true) if the shopper should be told. '
			. 'Refused for an order with no shipping row (nothing to attach it to — J2Commerce would report '
			. 'success and store nothing) and for an empty number; clearing a tracking number is done from the '
			. 'order edit screen, because an empty value would release the label claim while storing nothing.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => $this->orderSchemaProps() + [
				'tracking_number' => ['type' => 'string', 'description' => 'Required.'],
			],
			'required' => ['tracking_number'],
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

		if (!$actor->authorise('core.fulfilment', 'com_j2commerce') && !$actor->authorise('core.edit', 'com_j2commerce')) {
			throw new \RuntimeException('Saving tracking requires core.fulfilment or core.edit on com_j2commerce.');
		}

		$order    = $this->resolveOrder($arguments);
		$tracking = $this->requireString($arguments, 'tracking_number');
		$number   = (string) $order['order_id'];
		$table    = $this->db->quoteName($this->j2Table('ordershippings'));

		$before = $this->db->setQuery(
			'SELECT j2commerce_ordershipping_id, ordershipping_name, ordershipping_tracking_id FROM ' . $table
			. ' WHERE order_id = ' . $this->db->quote($number)
		)->loadAssocList() ?: [];

		if ($before === []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Order ' . $number . ' has no shipping row, so there is nothing to attach a tracking number to. '
					. 'It may be a non-shippable order. Nothing was written.',
			], true);
		}

		$pk    = (int) $order['j2commerce_order_id'];
		$model = $this->j2Model('Order');

		[$ok, $messages] = $this->j2Run(static fn () => $model->saveTrackingNumber($pk, $tracking));

		$after = $this->db->setQuery(
			'SELECT j2commerce_ordershipping_id, ordershipping_tracking_id, ordershipping_label_claim FROM ' . $table
			. ' WHERE order_id = ' . $this->db->quote($number)
		)->loadAssocList() ?: [];

		$stored = array_filter($after, static fn (array $r): bool => (string) $r['ordershipping_tracking_id'] === $tracking);

		if (!$ok || $stored === []) {
			return ToolResult::json([
				'ok'                  => false,
				'error'               => 'J2Commerce did not store the tracking number.',
				'j2commerce_messages' => $messages,
			], true);
		}

		return ToolResult::json([
			'ok'              => true,
			'order_pk'        => $pk,
			'order_number'    => $number,
			'tracking_number' => $tracking,
			'shipping_rows'   => array_map(static fn (array $r): array => [
				'ordershipping_id' => (int) $r['j2commerce_ordershipping_id'],
				'method'           => (string) $r['ordershipping_name'],
				'previous'         => (string) $r['ordershipping_tracking_id'],
			], $before),
			'not_done'        => 'Status unchanged and no email sent. Use set_j2commerce_order_status to mark it shipped and notify the customer.',
		]);
	}
}
