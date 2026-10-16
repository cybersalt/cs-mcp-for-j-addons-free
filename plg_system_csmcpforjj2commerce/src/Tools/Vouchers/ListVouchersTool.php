<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Vouchers;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

/**
 * Gift vouchers, with balances derived the vendor's way and codes masked.
 *
 * A voucher's balance is not stored. `VoucherModel::getRemainingBalance()`
 * derives it as face value − redemptions + credits − debits ± corrections
 * (`src/Model/VoucherModel.php:626-639`), and we call exactly that.
 */
final class ListVouchersTool extends AbstractTool
{
	use J2commerceBootTrait;

	public function getName(): string { return 'list_j2commerce_vouchers'; }

	public function getDescription(): string
	{
		return 'List J2Commerce gift vouchers with recipient email, face value, CURRENT balance, validity '
			. 'window, enabled state and the order that bought it. The balance is not stored anywhere: it is '
			. 'derived by J2Commerce\'s own VoucherModel::getRemainingBalance() — face value minus redemptions '
			. 'on placed orders, plus credits, minus debits, plus or minus corrections — and that is the method '
			. 'called here, so the figure matches the admin. '
			. 'CODES ARE MASKED. A voucher code is a bearer credential: J2Commerce ties a voucher to nobody, so '
			. 'whoever holds the code spends the balance (src/Model/VoucherModel.php:909-936). Use '
			. 'get_j2commerce_voucher with reveal_code: true if you genuinely need one. J2Commerce\'s own REST '
			. 'list returns full codes; this does not. '
			. 'Filters: search (recipient email or the last characters of a code), state: active | spent | '
			. 'expired | disabled | all, email. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'search' => ['type' => 'string'],
				'email'  => ['type' => 'string'],
				'state'  => ['type' => 'string', 'enum' => ['all', 'active', 'spent', 'expired', 'disabled']],
				'limit'  => ['type' => 'integer', 'description' => 'Default 100, max 200.'],
				'offset' => ['type' => 'integer'],
			],
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

		$db    = $this->db;
		$where = [];

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			// Suffix match on the code only, so search cannot be used to enumerate codes from the front.
			$where[] = '(email_to LIKE ' . $this->j2Like($search) . ' OR voucher_code LIKE '
				. $db->quote('%' . $db->escape($search, true), false) . ')';
		}

		if (($email = trim((string) ($arguments['email'] ?? ''))) !== '') {
			$where[] = 'LOWER(email_to) = ' . $db->quote(strtolower($email));
		}

		$rows = $db->setQuery(
			'SELECT * FROM ' . $db->quoteName($this->j2Table('vouchers'))
			. ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
			. ' ORDER BY created_on DESC, j2commerce_voucher_id DESC'
		)->loadAssocList() ?: [];

		$model = $rows === [] ? null : $this->j2Model('Voucher');
		$now   = gmdate('Y-m-d H:i:s');
		$state = (string) ($arguments['state'] ?? 'all');
		$out   = [];

		foreach ($rows as $v) {
			$id      = (int) $v['j2commerce_voucher_id'];
			$balance = $model !== null ? (float) $model->getRemainingBalance($id) : null;
			$to      = (string) $v['valid_to'];
			$expired = $to !== '' && !str_starts_with($to, '0000') && $to < $now;
			$from    = (string) $v['valid_from'];
			$pending = $from !== '' && !str_starts_with($from, '0000') && $from > $now;

			$status = match (true) {
				(int) $v['enabled'] !== 1 => 'disabled',
				$expired                  => 'expired',
				$pending                  => 'not_started',
				$balance !== null && $balance <= 0.00001 => 'spent',
				default                   => 'active',
			};

			if ($state !== 'all' && $state !== $status) {
				continue;
			}

			$out[] = [
				'voucher_id'   => $id,
				'code'         => $this->j2MaskCode((string) $v['voucher_code']),
				'email_to'     => (string) $v['email_to'],
				'user_id'      => (int) $v['user_id'],
				'type'         => (string) $v['voucher_type'],
				'face_value'   => $this->j2Money($v['voucher_value']),
				'balance'      => $balance !== null ? round($balance, 5) : null,
				'status'       => $status,
				'valid_from'   => $v['valid_from'],
				'valid_to'     => $v['valid_to'],
				'bought_on_order' => (string) $v['order_id'],
				'created_on'   => $v['created_on'],
			];
		}

		$limit  = $this->j2Limit($arguments, 100);
		$offset = $this->j2Offset($arguments);

		return ToolResult::json([
			'ok'       => true,
			'total'    => \count($out),
			'vouchers' => \array_slice($out, $offset, $limit),
			'outstanding_liability' => round(array_sum(array_map(
				static fn (array $v): float => $v['status'] === 'active' ? (float) $v['balance'] : 0.0,
				$out
			)), 5),
			'note'     => 'outstanding_liability sums the balances of active vouchers in this result — money the store '
				. 'owes as goods. Codes are masked.',
			'vouchers_enabled_in_store' => (string) ($this->j2RawParams()['enable_voucher'] ?? '0') === '1',
		]);
	}
}
