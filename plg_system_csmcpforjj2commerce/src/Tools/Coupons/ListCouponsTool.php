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

final class ListCouponsTool extends AbstractTool
{
	use J2commerceBootTrait;
	use CouponSupportTrait;

	public function getName(): string { return 'list_j2commerce_coupons'; }

	public function getDescription(): string
	{
		return 'List J2Commerce coupons with code, value and value type, validity window, usage against its '
			. 'limits, restrictions, and a usable_now verdict with the reasons when it is not. Usage is not '
			. 'stored anywhere in J2Commerce: it is counted from discount rows on orders in a "placed" status '
			. '(the store\'s clear_cart_states setting, or else statuses typed complete, approved, shipped, '
			. 'delivered or open), exactly as CouponModel counts it. '
			. 'Flags a coupon whose value_type is not one of fixed_cart, fixed_product, percentage_cart or '
			. 'percentage_product — J2Commerce accepts such a coupon at checkout and discounts nothing. '
			. 'Filters: search (code or name), state: usable | expired | not_started | exhausted | disabled | '
			. 'broken | all. Dates are UTC. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'search' => ['type' => 'string'],
				'state'  => ['type' => 'string', 'enum' => ['all', 'usable', 'expired', 'not_started', 'exhausted', 'disabled', 'broken']],
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
		$where = '';

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$like  = $this->j2Like($search);
			$where = ' WHERE coupon_code LIKE ' . $like . ' OR coupon_name LIKE ' . $like;
		}

		$rows  = $db->setQuery('SELECT * FROM ' . $db->quoteName($this->j2Table('coupons')) . $where . ' ORDER BY ordering, j2commerce_coupon_id DESC')->loadAssocList() ?: [];
		$usage = $this->couponUsage(array_map(static fn (array $r): int => (int) $r['j2commerce_coupon_id'], $rows));
		$state = (string) ($arguments['state'] ?? 'all');
		$out   = [];

		foreach ($rows as $r) {
			$c = $this->shapeCoupon($r, $usage[(int) $r['j2commerce_coupon_id']] ?? 0);
			$why = implode(' ', $c['not_usable_because']);

			$match = match ($state) {
				'usable'      => $c['usable_now'],
				'expired'     => str_contains($why, 'expired'),
				'not_started' => str_contains($why, 'not started'),
				'exhausted'   => str_contains($why, 'usage limit'),
				'disabled'    => !$c['enabled'],
				'broken'      => str_contains($why, 'value_type') || str_contains($why, 'value is 0'),
				default       => true,
			};

			if ($match) {
				$out[] = $c;
			}
		}

		$limit  = $this->j2Limit($arguments, 100);
		$offset = $this->j2Offset($arguments);

		return ToolResult::json([
			'ok'      => true,
			'total'   => \count($out),
			'showing' => \count(\array_slice($out, $offset, $limit)),
			'coupons' => \array_slice($out, $offset, $limit),
			'coupons_enabled_in_store' => (string) ($this->j2RawParams()['enable_coupon'] ?? '0') === '1',
		]);
	}
}
