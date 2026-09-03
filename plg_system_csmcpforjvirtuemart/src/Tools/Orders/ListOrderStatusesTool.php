<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Orders;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * The shop's actual order states, plus what each one will DO.
 *
 * Order statuses look like an enum and are not one: they are user-editable rows
 * with a UNIQUE key on `order_status_code` (`install.sql:753`). What matters
 * operationally is not the code but four things attached to it — its
 * `order_stock_handle`, and whether it appears in the `os_trigger_paid`,
 * `inv_os`, `email_os_s` and `email_os_v` config lists. Those decide whether
 * moving an order into that state adjusts stock, marks it paid, mints an
 * invoice number and emails anybody.
 */
final class ListOrderStatusesTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'list_virtuemart_order_statuses'; }

	public function getDescription(): string
	{
		return 'List this shop\'s order states from #__virtuemart_orderstates, and report what moving an '
			. 'order into each one will actually DO. '
			. 'Order statuses are NOT an enum. They are user-editable rows with a UNIQUE key on '
			. 'order_status_code (install.sql:753), so a shop can rename, add or unpublish any of them. '
			. 'getVMCoreStatusCode() returns only P, S and X (models/orderstatus.php:43-45) and that is a '
			. '"do not let the admin delete these" list, not a transition table. THERE IS NO STATE '
			. 'MACHINE ANYWHERE IN VIRTUEMART: any code can move to any code. '
			. 'For each state this reports order_stock_handle — A (available), O (out, decrements '
			. 'product_in_stock) or R (reserved, increments product_ordered), documented in-code at '
			. 'models/orders.php:2049-2052 — and cross-references the state code against five '
			. 'configuration lists that decide the side effects: os_trigger_paid (sets paid = order_total), '
			. 'inv_os and inv_osr (create a PDF invoice / refund document), email_os_s (shopper email) and '
			. 'email_os_v (vendor email). '
			. 'That cross-reference is the useful part. On stock defaults, F (Completed) and D (Denied) '
			. 'appear in NONE of the email or invoice lists, so moving an order to Completed sends no '
			. 'mail and creates no invoice — which surprises almost everyone. '
			. 'An UNPUBLISHED state is flagged as unusable: getOrderStatusNames() filters on published '
			. '(models/orderstatus.php:85-90), and moving an order into a state VirtueMart does not '
			. 'consider real makes stock handling silently decline to act while letting the status change '
			. 'proceed (models/orders.php:2035-2038).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'include_order_counts' => ['type' => 'boolean', 'description' => 'Count the orders currently in each state. Default true.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->vmAdminBase() === null) {
			return $this->vmNotInstalledError();
		}

		if (!$this->vmTableExists('orderstates')) {
			return $this->vmMissingTableError('orderstates');
		}

		$states = $this->vmOrderStates();
		$core   = $this->vmCoreOrderStates();

		$lists = [
			'os_trigger_paid'    => $this->configList('os_trigger_paid', ['C']),
			'os_trigger_refunds' => $this->configList('os_trigger_refunds', ['R']),
			'inv_os'             => $this->configList('inv_os', ['C']),
			'inv_osr'            => $this->configList('inv_osr', ['R']),
			'email_os_s'         => $this->configList('email_os_s', ['U', 'C', 'S', 'R', 'X']),
			'email_os_v'         => $this->configList('email_os_v', ['U', 'C', 'R', 'X']),
			'cp_rm'              => $this->configList('cp_rm', ['C']),
		];

		$counts = [];

		if ((bool) ($arguments['include_order_counts'] ?? true) && $this->vmTableExists('orders')) {
			$rows = $this->db->setQuery(
				$this->db->getQuery(true)
					->select([
						$this->db->quoteName('order_status'),
						'COUNT(*) AS ' . $this->db->quoteName('total'),
					])
					->from($this->db->quoteName($this->vmTable('orders')))
					->group($this->db->quoteName('order_status'))
			)->loadAssocList() ?: [];

			foreach ($rows as $row) {
				$counts[(string) $row['order_status']] = (int) $row['total'];
			}
		}

		$out = [];

		foreach ($states as $code => $row) {
			$code   = (string) $code;
			$handle = (string) $row['order_stock_handle'];

			$effects = [];

			if (\in_array($code, $lists['os_trigger_paid'], true)) {
				$effects[] = 'Sets paid = order_total and stamps paid_on, if paid was empty '
					. '(models/orders.php:1653-1660).';
			}

			if (\in_array($code, $lists['os_trigger_refunds'], true)) {
				$effects[] = 'Treated as a refund status (models/orders.php:1667).';
			}

			if (\in_array($code, $lists['inv_os'], true)) {
				$effects[] = 'Creates an invoice number, and locks the order against a second one '
					. '(models/invoice.php:44, :158). The PDF itself is generated lazily, only when '
					. 'something needs the file.';
			}

			if (\in_array($code, $lists['inv_osr'], true)) {
				$effects[] = 'Creates a refund document number (models/invoice.php:41).';
			}

			if (\in_array($code, $lists['email_os_s'], true)) {
				$effects[] = 'Emails the SHOPPER, unless customer_notified is set to 0.';
			}

			if (\in_array($code, $lists['email_os_v'], true)) {
				$effects[] = 'Emails the VENDOR / store owner. This one CANNOT be suppressed per call — '
					. 'the send at models/orders.php:2562 happens before the customer_notified check at '
					. ':2565.';
			}

			if (\in_array($code, $lists['cp_rm'], true)) {
				$effects[] = 'Removes the order\'s coupon (models/orders.php:1188-1195).';
			}

			$effects[] = match ($handle) {
				'A'     => 'order_stock_handle A (available): leaving an O state adds stock back; leaving '
					. 'an R state releases the reservation.',
				'O'     => 'order_stock_handle O (out): entering this state DECREMENTS product_in_stock '
					. 'and increments product_sales (models/orders.php:2055-2063, '
					. 'models/product.php:3533-3539).',
				'R'     => 'order_stock_handle R (reserved): entering this state INCREMENTS '
					. 'product_ordered, reserving stock without removing it '
					. '(models/orders.php:2067-2074).',
				default => 'order_stock_handle "' . $handle . '" is not one of A, O or R. VirtueMart '
					. 'documents only those three (models/orders.php:2049-2052); anything else means '
					. 'stock will not move as expected.',
			};

			$entry = [
				'order_status_code'        => $code,
				'order_status_name'        => (string) $row['order_status_name'],
				'order_stock_handle'       => $handle,
				'published'                => (int) $row['published'] === 1,
				'ordering'                 => (int) $row['ordering'],
				'virtuemart_orderstate_id' => (int) $row['virtuemart_orderstate_id'],
				'is_core_code'             => isset($core[$code]),
				'effects'                  => $effects,
			];

			if ($counts !== []) {
				$entry['order_count'] = $counts[$code] ?? 0;
			}

			if ((int) $row['published'] !== 1) {
				$entry['unusable'] = 'This state is UNPUBLISHED. getOrderStatusNames() filters on '
					. 'published (models/orderstatus.php:85-90), so moving an order into it makes stock '
					. 'handling raise an error and silently skip the adjustment while letting the status '
					. 'change proceed anyway (models/orders.php:2035-2038). '
					. 'set_virtuemart_order_status refuses to target it.';
			}

			if (isset($core[$code]) && $handle !== $core[$code]['stock']) {
				$entry['stock_handle_changed'] = 'This shop has changed the order_stock_handle for core '
					. 'code ' . $code . ' from the shipped default "' . $core[$code]['stock'] . '" to "'
					. $handle . '". That is legitimate but changes how every order in this state affects '
					. 'stock.';
			}

			$out[] = $entry;
		}

		$orphaned = [];

		foreach ($counts as $code => $count) {
			if ($code !== '' && !isset($states[$code])) {
				$orphaned[] = ['order_status' => $code, 'order_count' => $count];
			}
		}

		$response = [
			'ok'       => true,
			'statuses' => $out,
			'config_lists' => $lists,
			'config_lists_note' => 'These come from the shop\'s own #__virtuemart_configs blob, with '
				. 'VirtueMart\'s shipped defaults used where a key is absent. They, not the status codes, '
				. 'decide the side effects.',
			'no_state_machine' => 'VirtueMart validates no transitions at all: updateStatusForOneOrder() '
				. 'binds whatever status it is given (models/orders.php:1186). The only guard in the '
				. 'codebase, safeUpdateOrderStatus() (:1117), takes a caller-supplied whitelist and is '
				. 'called from nowhere in the package — it exists for payment plugins to use.',
			'default_gap_note' => 'On the shipped defaults, F (Completed) and D (Denied) appear in NONE '
				. 'of the email or invoice lists, so moving an order to Completed sends no mail and '
				. 'creates no invoice.',
		];

		if ($orphaned !== []) {
			$response['orphaned_order_statuses'] = $orphaned;
			$response['orphaned_note'] = 'Orders exist in status codes that have no row in '
				. '#__virtuemart_orderstates — almost always a state that was deleted or renamed after '
				. 'those orders were placed. Changing their status makes stock handling skip silently '
				. '(models/orders.php:2035-2038), so set_virtuemart_order_status refuses to move them '
				. 'until the state is restored.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/**
	 * @param  array<int,string> $default
	 * @return array<int,string>
	 */
	private function configList(string $key, array $default): array
	{
		$value = $this->vmConfigGet($key, null);

		if ($value === null || $value === '') {
			return $default;
		}

		if (!\is_array($value)) {
			$value = [$value];
		}

		return array_values(array_map(static fn ($v): string => (string) $v, $value));
	}
}
