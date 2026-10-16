<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Orders;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

/**
 * Order statuses, and the two different ways J2Commerce decides what a status
 * MEANS — which do not agree with each other.
 *
 * "Paid", coupon usage, voucher balance and the New-order cleanup task read
 * `orderstatus_type` (`src/Helper/OrderStatusHelper.php:52-74`); the task plugin
 * says outright that "status ids are install-dependent … never by a literal id"
 * (`plg_task_j2commerce/src/Extension/J2Commerce.php:139-140`). Stock does the
 * opposite: it releases on the literal ids 3, 5 and 6
 * (`src/Helper/InventoryHelper.php:986-996`). On a fresh install the seeded ids
 * line up, so nobody notices. On a store whose statuses were renumbered — a
 * migration, a re-seed — they can diverge, and this tool is where that shows.
 */
final class ListOrderStatusesTool extends AbstractTool
{
	use J2commerceBootTrait;

	/** What the seeded ids are expected to be, by type (`sql/install.mysql.utf8.sql:923-931`). */
	private const SEEDED_TYPES = [1 => 'complete', 2 => 'approved', 3 => 'failed', 4 => 'open', 5 => 'new', 6 => 'cancelled', 7 => 'shipped', 8 => 'delivered'];

	public function getName(): string { return 'list_j2commerce_order_statuses'; }

	public function getDescription(): string
	{
		return 'List J2Commerce order statuses with id, name, lifecycle type, core flag, enabled flag, how many '
			. 'orders are in each, and the two behaviours that matter before moving an order: whether the '
			. 'status HOLDS stock and whether it counts as PAID. '
			. 'These come from two different mechanisms. Paid, coupon usage and voucher balance follow the '
			. 'lifecycle type — complete, approved, shipped and delivered count as paid. Stock follows the '
			. 'literal status id: only ids 3 (Failed), 5 (New) and 6 (Cancelled) release stock, and every other '
			. 'status, including any the merchant created, holds it. So a custom "Refunded" or "Returned" status '
			. 'never puts stock back. The tool flags any status whose id and type disagree with the seeded '
			. 'layout, because then stock and payment logic will contradict each other. '
			. 'There is no status state machine in J2Commerce: any status can move to any other, including a '
			. 'disabled one. Read-only; there is deliberately no status-editing tool.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->j2Installed()) {
			return $this->j2NotInstalledError();
		}

		$this->j2RequireAccess($actor, 'j2commerce.vieworders');

		$counts = [];

		foreach ($this->db->setQuery(
			'SELECT order_state_id, COUNT(*) AS n FROM ' . $this->db->quoteName($this->j2Table('orders')) . ' GROUP BY order_state_id'
		)->loadAssocList() ?: [] as $r) {
			$counts[(int) $r['order_state_id']] = (int) $r['n'];
		}

		$out      = [];
		$warnings = [];

		foreach ($this->j2StatusMap() as $id => $s) {
			$holds = !\in_array($id, self::J2_STOCK_RELEASING_STATUSES, true);
			$type  = $s['type'];

			$entry = [
				'id'             => $id,
				'name'           => $s['label'],
				'language_key'   => $s['name'],
				'type'           => $type,
				'core'           => $s['core'],
				'enabled'        => $s['enabled'],
				'orders'         => $counts[$id] ?? 0,
				'holds_stock'    => $holds,
				'counts_as_paid' => \in_array((string) $type, self::J2_FINALIZED_TYPES, true),
			];

			if ($type === null) {
				$entry['note'] = 'No lifecycle type: J2Commerce treats it as merchant-defined with no core meaning. It holds stock and does not count as paid.';
			}

			if ($holds && \in_array((string) $type, ['cancelled', 'failed', 'new'], true)) {
				$warnings[] = 'Status ' . $id . ' "' . $s['label'] . '" has type ' . $type . ' but, not being id 3, 5 or 6, '
					. 'it HOLDS stock. Orders moved here keep their stock reserved.';
			}

			if (!$holds && isset(self::SEEDED_TYPES[$id]) && $type !== self::SEEDED_TYPES[$id]) {
				$warnings[] = 'Status ' . $id . ' "' . $s['label'] . '" RELEASES stock (J2Commerce hardwires ids 3, 5, 6) '
					. 'but its type is ' . ($type ?? 'none') . ', not ' . self::SEEDED_TYPES[$id] . '. Stock and '
					. 'payment logic disagree about this status.';
			}

			$out[] = $entry;
		}

		foreach ($counts as $id => $n) {
			if (!isset($this->j2StatusMap()[$id])) {
				$warnings[] = $n . ' order(s) are in status ' . $id . ', which does not exist.';
			}
		}

		$response = ['ok' => true, 'statuses' => $out];

		if ($warnings !== []) {
			$response['warnings'] = $warnings;
		}

		$response['rules'] = [
			'stock'      => 'Only status ids 3, 5 and 6 release stock (src/Helper/InventoryHelper.php:986-996). All others hold it.',
			'paid'       => 'Types complete, approved, shipped and delivered count as paid (src/Helper/OrderStatusHelper.php:69-74).',
			'transitions' => 'Unrestricted. Any status to any status, enabled or not.',
		];

		return ToolResult::json($response);
	}
}
