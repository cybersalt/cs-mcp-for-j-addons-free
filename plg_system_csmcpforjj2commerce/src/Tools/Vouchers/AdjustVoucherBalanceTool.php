<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Vouchers;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

/**
 * Credit, debit or correct a voucher through `VoucherModel::adjustBalance()`
 * (`src/Model/VoucherModel.php:649-723`): a transaction, a `SELECT … FOR UPDATE`
 * row lock so two concurrent adjustments cannot both pass the guard, a refusal
 * of any result below zero, and an append-only ledger row recording the
 * balance before and after. The vendor's REST `vouchers/:id/adjust` calls the
 * same method.
 *
 * The vendor puts no ceiling on a credit — `adjust` can mint any amount of
 * spendable money onto a bearer code. We require a reason and refuse a credit
 * that would take the balance above the voucher's face value unless the caller
 * says so explicitly.
 */
final class AdjustVoucherBalanceTool extends AbstractTool
{
	use J2commerceBootTrait;

	public function getName(): string { return 'adjust_j2commerce_voucher_balance'; }

	public function getDescription(): string
	{
		return 'Change a J2Commerce gift voucher\'s balance through J2Commerce\'s own VoucherModel::adjustBalance(): '
			. 'type credit (add), debit (subtract) or correction (a signed amount). It runs in a transaction with '
			. 'a row lock, refuses any result below zero, and appends a ledger row recording who, why, and the '
			. 'balance before and after — it never edits the voucher\'s face value, so the history stays '
			. 'auditable. reason is required. '
			. 'J2Commerce itself puts no limit on a credit, and a voucher code is spendable by whoever holds it, '
			. 'so this tool refuses a credit or correction that would take the balance above the voucher\'s '
			. 'face value unless allow_above_face_value: true is passed. '
			. 'dry_run: true shows the before and after without writing. Use order_number to tie the adjustment '
			. 'to an order (for example a partial refund to the voucher).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'voucher_id'             => ['type' => 'integer', 'description' => 'Required.'],
				'type'                   => ['type' => 'string', 'enum' => ['credit', 'debit', 'correction'], 'description' => 'Required.'],
				'amount'                 => ['type' => 'number', 'description' => 'Required. A magnitude for credit and debit; signed for correction.'],
				'reason'                 => ['type' => 'string', 'description' => 'Required. Stored on the ledger row.'],
				'note'                   => ['type' => 'string'],
				'order_number'           => ['type' => 'string', 'description' => 'Optional order this relates to.'],
				'allow_above_face_value' => ['type' => 'boolean'],
				'dry_run'                => ['type' => 'boolean'],
			],
			'required' => ['voucher_id', 'type', 'amount', 'reason'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->j2Installed()) {
			return $this->j2NotInstalledError();
		}

		// The vendor endpoint: j2commerce.editorders plus core.edit (api/.../VouchersController.php:39).
		$this->j2RequireAccess($actor, 'j2commerce.editorders');

		if (!$actor->authorise('core.edit', 'com_j2commerce')) {
			throw new \RuntimeException('Adjusting a voucher requires core.edit on com_j2commerce, as J2Commerce\'s own endpoint does.');
		}

		$id     = $this->requirePositiveInt($arguments, 'voucher_id');
		$type   = (string) $arguments['type'];
		$amount = (float) $arguments['amount'];
		$reason = $this->requireString($arguments, 'reason');
		$note   = isset($arguments['note']) ? (string) $arguments['note'] : null;
		$order  = isset($arguments['order_number']) && trim((string) $arguments['order_number']) !== '' ? trim((string) $arguments['order_number']) : null;

		if (!\in_array($type, ['credit', 'debit', 'correction'], true)) {
			throw new \InvalidArgumentException('type must be credit, debit or correction.');
		}

		if (abs($amount) < 0.00001) {
			throw new \InvalidArgumentException('amount cannot be zero.');
		}

		$this->j2RequireFits('reason', $reason, 255);

		$v = $this->db->setQuery(
			'SELECT j2commerce_voucher_id, voucher_value, enabled FROM ' . $this->db->quoteName($this->j2Table('vouchers'))
			. ' WHERE j2commerce_voucher_id = ' . $id
		)->loadAssoc();

		if (!\is_array($v)) {
			return ToolResult::json(['ok' => false, 'error' => 'No voucher with id ' . $id . '. Nothing was written.'], true);
		}

		if ($order !== null) {
			$exists = (int) $this->db->setQuery(
				'SELECT COUNT(*) FROM ' . $this->db->quoteName($this->j2Table('orders')) . ' WHERE order_id = ' . $this->db->quote($order)
			)->loadResult();

			if ($exists === 0) {
				throw new \InvalidArgumentException('No order with number ' . $order . '. Nothing was written.');
			}
		}

		$model  = $this->j2Model('Voucher');
		$before = round((float) $model->getRemainingBalance($id), 5);
		$delta  = match ($type) {
			'credit'     => abs($amount),
			'debit'      => -abs($amount),
			'correction' => $amount,
		};
		$after = round($before + $delta, 5);
		$face  = (float) $v['voucher_value'];

		if ($after < 0) {
			return ToolResult::json([
				'ok'      => false,
				'error'   => 'That would take the balance from ' . $before . ' to ' . $after . '. A voucher cannot go '
					. 'negative. Nothing was written.',
				'balance' => $before,
			], true);
		}

		if ($delta > 0 && $after > $face + 0.00001 && !(bool) ($arguments['allow_above_face_value'] ?? false)) {
			return ToolResult::json([
				'ok'      => false,
				'error'   => 'That would take the balance to ' . $after . ', above the voucher\'s face value of ' . $face
					. '. J2Commerce would allow it — it puts no limit on credits — but this voucher code is spendable '
					. 'by whoever holds it, so the excess is new money. Pass allow_above_face_value: true if that is '
					. 'intended. Nothing was written.',
				'balance' => $before,
			], true);
		}

		if ((bool) ($arguments['dry_run'] ?? false)) {
			return ToolResult::json([
				'ok'      => true,
				'dry_run' => true,
				'balance' => ['before' => $before, 'after' => $after],
				'note'    => 'Nothing was written.',
			]);
		}

		[$result, $messages] = $this->j2Run(
			static fn () => $model->adjustBalance($id, $type, $amount, $reason, $note, $order)
		);

		$response = [
			'ok'           => true,
			'voucher_id'   => $id,
			'type'         => $type,
			'balance'      => ['before' => $before, 'after' => round((float) $result, 5)],
			'verified'     => abs(round((float) $model->getRemainingBalance($id), 5) - round((float) $result, 5)) < 0.00001,
			'ledger_entry' => 'Appended to #__j2commerce_voucheradjustments with the reason; the face value was not changed.',
		];

		if ((int) $v['enabled'] !== 1) {
			$response['note'] = 'This voucher is disabled, so the balance cannot be spent until it is enabled.';
		}

		if ($messages !== []) {
			$response['j2commerce_messages'] = $messages;
		}

		return ToolResult::json($response);
	}
}
