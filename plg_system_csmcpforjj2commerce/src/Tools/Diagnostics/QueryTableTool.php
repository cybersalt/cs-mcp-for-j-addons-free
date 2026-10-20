<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

/**
 * Scoped, read-only access to any J2Commerce table.
 *
 *   1. SELECT only. There is no write path in this class.
 *   2. Scoped to `#__j2commerce_*` tables that exist, five denied outright.
 *   3. No caller string reaches SQL uninterpolated: table and columns are
 *      matched against the live schema, operators come from a fixed set,
 *      values go through quote().
 *   4. Secret columns are redacted on the way out AND refused as filter or sort
 *      targets — otherwise `WHERE token LIKE 'a%'` recovers them one character
 *      at a time and the redaction is decorative.
 */
final class QueryTableTool extends AbstractTool
{
	use J2commerceBootTrait;

	private const OPERATORS = ['=', '!=', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IS NULL', 'IS NOT NULL', 'IN', 'NOT IN'];

	public function getName(): string { return 'query_j2commerce_table'; }

	public function getDescription(): string
	{
		return 'Run a scoped, READ-ONLY, structured query against any #__j2commerce_* table — the escape hatch '
			. 'for the parts of the 58-table schema no dedicated tool covers (filters, custom fields, email '
			. 'and invoice templates, the integration queue and its logs, option values, order fees and '
			. 'taxes, and so on). SELECT ONLY, no raw SQL: you pass a table, columns, structured filters '
			. '(AND-combined) and an order, and the query is built here. Table and column names are matched '
			. 'against the live schema, operators come from a fixed set, and values are quoted. '
			. 'REDACTED: token (the guest order-view key carried in emailed order links), '
			. 'transaction_details (raw payment-gateway JSON), order_params, all_payment, payment_token, '
			. 'customer_profile_id, voucher_code (a bearer credential — whoever holds it spends the '
			. 'balance), session_id, cart_browser, and the IP-address columns. They are masked in output AND '
			. 'refused as filter or sort targets, because filtering on a masked column would let its value '
			. 'be recovered a character at a time. '
			. 'DENIED TABLES: carts, cartitems, paymentprofiles, geocode_cache and uploads. '
			. 'Remember that a J2Commerce product has no name, price or stock column of its own: the name is '
			. 'its Joomla article\'s title, price and SKU are on the master variant, and stock is in '
			. 'productquantities — get_j2commerce_product assembles all of it.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'table'   => ['type' => 'string', 'description' => 'Accepts "orders", "j2commerce_orders" or "#__j2commerce_orders". Required.'],
				'columns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Columns to return. Omit for all (redacted ones stay masked).'],
				'filters' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'column'   => ['type' => 'string'],
							'operator' => ['type' => 'string', 'description' => '= != < <= > >= LIKE "NOT LIKE" "IS NULL" "IS NOT NULL" IN "NOT IN". Default =.'],
							'value'    => ['description' => 'Scalar, or an array for IN / NOT IN. Omit for IS NULL / IS NOT NULL.'],
						],
						'required' => ['column'],
					],
					'description' => 'Structured filters, combined with AND.',
				],
				'order_by'  => ['type' => 'string'],
				'order_dir' => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
				'limit'     => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'    => ['type' => 'integer'],
			],
			'required' => ['table'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->j2Installed()) {
			return $this->j2NotInstalledError();
		}

		$this->j2RequireAccess($actor, 'j2commerce.viewsetup');

		$requested = strtolower(trim((string) ($arguments['table'] ?? '')));

		if ($requested === '') {
			throw new \InvalidArgumentException('table is required.');
		}

		$short = preg_replace('/^#__/', '', $requested);

		if (!str_starts_with($short, 'j2commerce_')) {
			$short = 'j2commerce_' . $short;
		}

		if (\in_array($short, self::J2_READ_DENYLIST, true)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => '#__' . $short . ' is not readable through this tool. Carts hold live session-bound '
					. 'shopper state, payment profiles hold stored-card references, the geocode cache holds '
					. 'customer addresses and uploads map customer files to orders.',
			], true);
		}

		if (!\in_array($this->db->getPrefix() . $short, $this->j2AllTables(), true)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No table "#__' . $short . '" on this site. list_j2commerce_tables shows what exists.',
			], true);
		}

		$table   = '#__' . $short;
		$columns = array_keys($this->db->getTableColumns($table) ?: []);

		if ($columns === []) {
			throw new \RuntimeException('Could not read the column list for ' . $table . '.');
		}

		$redacted = array_values(array_intersect(self::J2_REDACTED_COLUMNS, $columns));
		$wanted   = array_values(array_filter(array_map('strval', (array) ($arguments['columns'] ?? []))));

		if ($wanted !== [] && ($unknown = array_values(array_diff($wanted, $columns))) !== []) {
			return ToolResult::json([
				'ok'                => false,
				'error'             => 'These columns do not exist on ' . $table . ': ' . implode(', ', $unknown),
				'available_columns' => $columns,
			], true);
		}

		$select = $wanted !== [] ? $wanted : $columns;
		$where  = [];

		foreach ((array) ($arguments['filters'] ?? []) as $i => $filter) {
			if (!\is_array($filter)) {
				// A filter we cannot read must not silently widen the query to the whole table.
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Filter #' . $i . ' is not an object. Refusing rather than running the query '
						. 'without it, which would return rows the filter was meant to exclude.',
				], true);
			}

			$column = (string) ($filter['column'] ?? '');

			if (!\in_array($column, $columns, true)) {
				return ToolResult::json([
					'ok'                => false,
					'error'             => 'Filter column "' . $column . '" does not exist on ' . $table . '.',
					'available_columns' => $columns,
				], true);
			}

			if (\in_array($column, self::J2_REDACTED_COLUMNS, true)) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Refusing to filter on "' . $column . '": it is redacted, and filtering on it would '
						. 'let its value be recovered a character at a time.',
				], true);
			}

			$operator = strtoupper(trim((string) ($filter['operator'] ?? '=')));

			if (!\in_array($operator, self::OPERATORS, true)) {
				return ToolResult::json([
					'ok'                  => false,
					'error'               => 'Unsupported operator "' . $operator . '".',
					'supported_operators' => self::OPERATORS,
				], true);
			}

			$quoted = $this->db->quoteName($column);

			if ($operator === 'IS NULL' || $operator === 'IS NOT NULL') {
				$where[] = $quoted . ' ' . $operator;
				continue;
			}

			if (!\array_key_exists('value', $filter)) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Filter on "' . $column . '" with ' . $operator . ' needs a value.',
				], true);
			}

			if ($operator === 'IN' || $operator === 'NOT IN') {
				$values = (array) $filter['value'];

				if ($values === []) {
					return ToolResult::json([
						'ok'    => false,
						'error' => $operator . ' on "' . $column . '" was given an empty list, which can never match.',
					], true);
				}

				$where[] = $quoted . ' ' . $operator . ' ('
					. implode(', ', array_map(fn ($v): string => $this->db->quote((string) $v), $values)) . ')';
				continue;
			}

			$where[] = $quoted . ' ' . $operator . ' ' . $this->db->quote((string) $filter['value']);
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
		$orderSql = '';

		if (($orderBy = trim((string) ($arguments['order_by'] ?? ''))) !== '') {
			if (!\in_array($orderBy, $columns, true)) {
				return ToolResult::json([
					'ok'                => false,
					'error'             => 'order_by column "' . $orderBy . '" does not exist on ' . $table . '.',
					'available_columns' => $columns,
				], true);
			}

			if (\in_array($orderBy, self::J2_REDACTED_COLUMNS, true)) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Refusing to sort on "' . $orderBy . '": sorting on a redacted column leaks its ordering.',
				], true);
			}

			$dir      = strtoupper((string) ($arguments['order_dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
			$orderSql = ' ORDER BY ' . $this->db->quoteName($orderBy) . ' ' . $dir;
		}

		$limit  = $this->j2Limit($arguments);
		$offset = $this->j2Offset($arguments);

		$total = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->db->quoteName($table) . $whereSql)->loadResult();

		$rows = $this->db->setQuery(
			'SELECT ' . implode(', ', array_map(fn (string $c): string => $this->db->quoteName($c), $select))
			. ' FROM ' . $this->db->quoteName($table) . $whereSql . $orderSql,
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$response = [
			'ok'      => true,
			'table'   => $table,
			'total'   => $total,
			'limit'   => $limit,
			'offset'  => $offset,
			'showing' => \count($rows),
			'rows'    => array_map(fn (array $r): array => $this->j2Redact($r), $rows),
		];

		if ($redacted !== []) {
			$response['redacted_columns'] = $redacted;
		}

		if ($short === 'j2commerce_products') {
			$response['note'] = 'A product row has no name, price, SKU or stock. Use get_j2commerce_product.';
		}

		return ToolResult::json($response);
	}
}
