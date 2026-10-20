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

final class GetVoucherTool extends AbstractTool
{
	use J2commerceBootTrait;

	public function getName(): string { return 'get_j2commerce_voucher'; }

	public function getDescription(): string
	{
		return 'Get one J2Commerce gift voucher by voucher_id: face value, current balance (from J2Commerce\'s '
			. 'own VoucherModel::getRemainingBalance()), validity, recipient, the order that bought it, and the '
			. 'full ledger from VoucherModel::getLedger() — every redemption against an order and every manual '
			. 'credit, debit and correction with who made it, the reason and a running balance. '
			. 'The code is masked. reveal_code: true returns it in full, and requires the j2commerce.editorders '
			. 'permission, because a voucher code is a bearer credential: anyone who has it can spend the '
			. 'balance, and nothing ties it to the recipient. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'voucher_id'  => ['type' => 'integer', 'description' => 'Required.'],
				'reveal_code' => ['type' => 'boolean', 'description' => 'Return the full code. Requires j2commerce.editorders.'],
			],
			'required' => ['voucher_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->j2Installed()) {
			return $this->j2NotInstalledError();
		}

		$this->j2RequireAccess($actor, 'j2commerce.vieworders');

		$reveal = (bool) ($arguments['reveal_code'] ?? false);

		if ($reveal) {
			$this->j2RequireAccess($actor, 'j2commerce.editorders');
		}

		$id = $this->requirePositiveInt($arguments, 'voucher_id');
		$v  = $this->db->setQuery(
			'SELECT * FROM ' . $this->db->quoteName($this->j2Table('vouchers')) . ' WHERE j2commerce_voucher_id = ' . $id
		)->loadAssoc();

		if (!\is_array($v)) {
			return ToolResult::json(['ok' => false, 'error' => 'No voucher with id ' . $id . '.'], true);
		}

		$model  = $this->j2Model('Voucher');
		$ledger = array_map(static fn ($row): array => (array) $row, $model->getLedger($id));

		$response = [
			'ok'          => true,
			'voucher_id'  => $id,
			'code'        => $reveal ? (string) $v['voucher_code'] : $this->j2MaskCode((string) $v['voucher_code']),
			'code_revealed' => $reveal,
			'email_to'    => (string) $v['email_to'],
			'user_id'     => (int) $v['user_id'],
			'type'        => (string) $v['voucher_type'],
			'subject'     => (string) $v['subject'],
			'face_value'  => $this->j2Money($v['voucher_value']),
			'balance'     => round((float) $model->getRemainingBalance($id), 5),
			'enabled'     => (int) $v['enabled'] === 1,
			'valid_from'  => $v['valid_from'],
			'valid_to'    => $v['valid_to'],
			'bought_on_order' => (string) $v['order_id'],
			'created_on'  => $v['created_on'],
			'ledger'      => $ledger,
			'balance_rule' => 'face value - redemptions on placed orders + credits - debits +/- corrections (src/Model/VoucherModel.php:626-639).',
		];

		if ($reveal) {
			$response['warning'] = 'This is a spendable code. Do not paste it anywhere the recipient would not.';
		}

		return ToolResult::json($response);
	}
}
