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
 * Add a note to an order's history through the vendor's own methods.
 *
 * Every note lives in `#__j2commerce_orderhistories`. What makes one private is
 * its params: `addAdminNote()` stores `{"type":"admin_note"}`
 * (`src/Model/OrderModel.php:1275-1307`) and the shopper's order page hides any
 * typed row (`components/com_j2commerce/src/Model/MyprofileModel.php:230`).
 * `addOrderHistory()` stores `{}` (`:1240-1273`), which the shopper sees.
 */
final class AddOrderNoteTool extends AbstractTool
{
	use J2commerceBootTrait;
	use OrderSupportTrait;

	public function getName(): string { return 'add_j2commerce_order_note'; }

	public function getDescription(): string
	{
		return 'Add a note to a J2Commerce order\'s history without changing its status. visibility "private" '
			. '(the default) adds a staff-only admin note through J2Commerce\'s own OrderModel::addAdminNote() — '
			. 'stored with type admin_note, hidden from the shopper\'s order page, never emailed. visibility '
			. '"customer" adds an ordinary history entry through OrderModel::addOrderHistory(), which the '
			. 'shopper SEES on their order page (it is not emailed). The note is recorded against the order\'s '
			. 'current status. '
			. 'J2Commerce lets the author of an admin note delete it from the admin; customer-visible entries '
			. 'cannot be removed there. Order notes are plain text.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => $this->orderSchemaProps() + [
				'note'       => ['type' => 'string', 'description' => 'Required.'],
				'visibility' => ['type' => 'string', 'enum' => ['private', 'customer'], 'description' => 'Default private.'],
			],
			'required' => ['note'],
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

		$order      = $this->resolveOrder($arguments);
		$note       = $this->requireString($arguments, 'note');
		$visibility = (string) ($arguments['visibility'] ?? 'private');
		$number     = (string) $order['order_id'];
		$status     = (int) $order['order_state_id'];

		if (!\in_array($visibility, ['private', 'customer'], true)) {
			throw new \InvalidArgumentException('visibility must be private or customer.');
		}

		$model = $this->j2Model('Order');

		[$ok, $messages] = $this->j2Run(
			static fn () => $visibility === 'private'
				? $model->addAdminNote($number, $status, $note)
				: $model->addOrderHistory($number, $status, false, $note)
		);

		if (!$ok) {
			return ToolResult::json([
				'ok'                  => false,
				'error'               => 'J2Commerce did not store the note.',
				'j2commerce_messages' => $messages,
			], true);
		}

		$row = $this->db->setQuery(
			'SELECT j2commerce_orderhistory_id, params, created_by, created_on FROM ' . $this->db->quoteName($this->j2Table('orderhistories'))
			. ' WHERE order_id = ' . $this->db->quote($number) . ' ORDER BY j2commerce_orderhistory_id DESC',
			0,
			1
		)->loadAssoc();

		$response = [
			'ok'               => true,
			'order_pk'         => (int) $order['j2commerce_order_id'],
			'order_number'     => $number,
			'history_id'       => \is_array($row) ? (int) $row['j2commerce_orderhistory_id'] : null,
			'visibility'       => $visibility,
			'customer_visible' => $visibility === 'customer',
			'stored_params'    => \is_array($row) ? (string) $row['params'] : null,
			'emailed'          => false,
		];

		if (($note = $this->j2IdentityNote($actor)) !== null) {
			$response['identity_note'] = $note;
		}

		return ToolResult::json($response);
	}
}
