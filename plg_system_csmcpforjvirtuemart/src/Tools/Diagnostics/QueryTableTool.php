<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Scoped, read-only access to any VirtueMart table.
 *
 * The escape hatch for the parts of a 55-table schema no dedicated tool covers.
 * Four constraints keep it from being a liability:
 *
 *   1. SELECT only. There is no write path in this class at all.
 *   2. Scoped to `#__virtuemart_*` tables that actually exist, with two
 *      denied outright.
 *   3. No caller string ever reaches SQL uninterpolated: the table is matched
 *      against the live table list, every column is matched against the live
 *      column list, operators come from a fixed set, and values go through
 *      `quote()`.
 *   4. Credential and gateway-secret columns are redacted on the way out AND
 *      refused as filter targets, so they cannot be recovered by binary search.
 *
 * That fourth point is the one that is easy to get wrong. Redacting
 * `payment_params` in the output while allowing `WHERE payment_params LIKE ...`
 * would leak it a character at a time.
 */
final class QueryTableTool extends AbstractTool
{
	use VirtuemartBootTrait;

	private const OPERATORS = ['=', '!=', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IS NULL', 'IS NOT NULL', 'IN', 'NOT IN'];

	public function getName(): string { return 'query_virtuemart_table'; }

	public function getDescription(): string
	{
		return 'Run a scoped, READ-ONLY, structured query against any #__virtuemart_* table. The escape '
			. 'hatch for the parts of a 55-table schema the dedicated tools do not cover — coupons, '
			. 'custom field definitions, worldzones, waiting lists, the xref tables, the language '
			. 'satellites. '
			. 'SELECT ONLY. There is no write path in this tool whatsoever, and no raw SQL is accepted. '
			. 'You supply a table name, a list of columns, structured filters and an order; the SQL is '
			. 'built here. The table name is matched against the live table list, every column name is '
			. 'matched against that table\'s live columns, operators come from a fixed set, and all '
			. 'values are quoted. No caller-supplied string reaches SQL uninterpolated. '
			. 'REDACTED COLUMNS: order_pass, order_create_invoice_pass, o_hash, oi_hash, payment_params '
			. 'and shipment_params never come back in cleartext, and — importantly — they are also '
			. 'REFUSED as filter or sort targets. Redacting them in the output while allowing WHERE '
			. 'payment_params LIKE \'sk_live_a%\' would leak them one character at a time. order_pass and '
			. 'order_create_invoice_pass are bearer credentials that let a guest open a whole order '
			. '(models/orders.php:83, :216); payment_params and shipment_params hold live gateway '
			. 'secrets, merchant ids and API keys in VirtueMart\'s pipe-parameter format '
			. '(install.sql:771, :1089). '
			. 'DENIED TABLES: #__virtuemart_configs (use get_virtuemart_config, which parses the blob, '
			. 'strips the two synthetic keys and declines VirtueMart\'s unserialize fallback) and '
			. '#__virtuemart_carts (live serialised cart state — full shopper PII in a serialised '
			. 'payload). '
			. 'Bear in mind that reading a translatable table directly gives you the base row only. '
			. '#__virtuemart_products has no product_name column at all; that lives in '
			. '#__virtuemart_products_<langsuffix>. list_virtuemart_tables shows what exists.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'table'   => ['type' => 'string', 'description' => 'Table name. Accepts "products", "virtuemart_products" or "#__virtuemart_products". Required.'],
				'columns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Columns to return. Omit for all (redacted ones are still masked).'],
				'filters' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'column'   => ['type' => 'string'],
							'operator' => ['type' => 'string', 'description' => '= != < <= > >= LIKE "NOT LIKE" "IS NULL" "IS NOT NULL" IN "NOT IN". Defaults to =.'],
							'value'    => ['description' => 'Scalar, or an array for IN / NOT IN. Omitted for IS NULL / IS NOT NULL.'],
						],
						'required' => ['column'],
					],
					'description' => 'Structured filters, combined with AND.',
				],
				'order_by'  => ['type' => 'string', 'description' => 'Column to sort on. Must exist on the table.'],
				'order_dir' => ['type' => 'string', 'description' => 'ASC or DESC. Default ASC.'],
				'limit'     => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'    => ['type' => 'integer'],
			],
			'required' => ['table'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->vmAdminBase() === null) {
			return $this->vmNotInstalledError();
		}

		$requested = trim((string) ($arguments['table'] ?? ''));

		if ($requested === '') {
			return ToolResult::json(['ok' => false, 'error' => 'table is required.'], true);
		}

		$short = $this->normalise($requested);

		if (\in_array($short, $this->vmReadDenylist(), true)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => '#__' . $short . ' is not readable through this tool.'
					. ($short === 'virtuemart_configs'
						? ' Use get_virtuemart_config: it parses the pipe-delimited blob, strips the two '
							. 'synthetic keys VirtueMart injects at load time, and declines the '
							. 'unserialize() fallback that VmConfig::parseJsonUnSerialize() applies to any '
							. 'value failing json_decode (helpers/config.php:630-661).'
						: ' It holds live serialised cart state: full shopper personal data inside a '
							. 'serialised payload, with no reason to hand it to anybody.'),
			], true);
		}

		if (!\in_array($short, $this->vmAllTables(), true)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No table "#__' . $short . '" on this site. It must be an existing '
					. '#__virtuemart_* table. Use list_virtuemart_tables to see what is there.',
			], true);
		}

		$table   = '#__' . $short;
		$columns = array_keys($this->db->getTableColumns($table) ?: []);

		if ($columns === []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Could not read the column list for ' . $table . '.',
			], true);
		}

		$redacted = array_values(array_intersect($this->vmRedactedColumns(), $columns));

		// --- SELECT list ------------------------------------------------------
		$wanted = array_map('strval', (array) ($arguments['columns'] ?? []));

		if ($wanted !== []) {
			$unknown = array_values(array_diff($wanted, $columns));

			if ($unknown !== []) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'These columns do not exist on ' . $table . ': ' . implode(', ', $unknown),
					'available_columns' => $columns,
				], true);
			}

			$select = $wanted;
		} else {
			$select = $columns;
		}

		// --- filters ----------------------------------------------------------
		$where = [];

		foreach ((array) ($arguments['filters'] ?? []) as $filter) {
			if (!\is_array($filter)) {
				continue;
			}

			$column = (string) ($filter['column'] ?? '');

			if (!\in_array($column, $columns, true)) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Filter column "' . $column . '" does not exist on ' . $table . '.',
					'available_columns' => $columns,
				], true);
			}

			if (\in_array($column, $this->vmRedactedColumns(), true)) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Refusing to filter on "' . $column . '". It is a redacted column, and '
						. 'allowing it as a filter target would let its value be recovered a character at '
						. 'a time by binary search — which would make the redaction decorative.',
				], true);
			}

			$operator = strtoupper(trim((string) ($filter['operator'] ?? '=')));

			if (!\in_array($operator, self::OPERATORS, true)) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Unsupported operator "' . $operator . '".',
					'supported_operators' => self::OPERATORS,
				], true);
			}

			$quoted = $this->db->quoteName($column);

			if ($operator === 'IS NULL' || $operator === 'IS NOT NULL') {
				$where[] = $quoted . ' ' . $operator;

				continue;
			}

			$value = $filter['value'] ?? null;

			if ($operator === 'IN' || $operator === 'NOT IN') {
				$values = (array) $value;

				if ($values === []) {
					return ToolResult::json([
						'ok'    => false,
						'error' => $operator . ' on "' . $column . '" was given an empty list, which can '
							. 'never match anything. Refusing rather than returning a silently empty '
							. 'result.',
					], true);
				}

				$where[] = $quoted . ' ' . $operator . ' ('
					. implode(', ', array_map(fn ($v): string => $this->db->quote((string) $v), $values)) . ')';

				continue;
			}

			$where[] = $quoted . ' ' . $operator . ' ' . $this->db->quote((string) $value);
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		// --- order ------------------------------------------------------------
		$orderSql = '';

		if (($orderBy = trim((string) ($arguments['order_by'] ?? ''))) !== '') {
			if (!\in_array($orderBy, $columns, true)) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'order_by column "' . $orderBy . '" does not exist on ' . $table . '.',
					'available_columns' => $columns,
				], true);
			}

			if (\in_array($orderBy, $this->vmRedactedColumns(), true)) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Refusing to sort on "' . $orderBy . '". Sorting on a redacted column '
						. 'leaks its ordering, which is enough to reconstruct it given enough queries.',
				], true);
			}

			$dir      = strtoupper(trim((string) ($arguments['order_dir'] ?? 'ASC'))) === 'DESC' ? 'DESC' : 'ASC';
			$orderSql = ' ORDER BY ' . $this->db->quoteName($orderBy) . ' ' . $dir;
		}

		$limit  = $this->vmLimit($arguments);
		$offset = $this->vmOffset($arguments);

		$total = (int) $this->db->setQuery(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName($table) . $whereSql
		)->loadResult();

		$rows = $this->db->setQuery(
			'SELECT ' . implode(', ', array_map(fn (string $c): string => $this->db->quoteName($c), $select))
			. ' FROM ' . $this->db->quoteName($table) . $whereSql . $orderSql,
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$out[] = $this->vmRedactRow($row);
		}

		$response = [
			'ok'      => true,
			'table'   => $table,
			'total'   => $total,
			'limit'   => $limit,
			'offset'  => $offset,
			'showing' => \count($out),
			'columns' => $select,
			'rows'    => $out,
			'read_only' => 'SELECT only. This tool has no write path and accepts no raw SQL — the query '
				. 'is built here from validated identifiers and quoted values.',
		];

		if ($redacted !== []) {
			$response['redacted_columns'] = $redacted;
			$response['redaction_note'] = 'These columns are masked in the output and refused as filter '
				. 'or sort targets. order_pass and order_create_invoice_pass are bearer credentials that '
				. 'let a guest open an entire order (models/orders.php:83, :216); payment_params and '
				. 'shipment_params hold live gateway secrets and API keys.';
		}

		foreach (array_keys($this->vmTranslatableTables()) as $base) {
			if ($short === 'virtuemart_' . $base) {
				$response['translatable_note'] = 'This is a translatable base table, so what you are '
					. 'looking at is only half the record. The name, descriptions, meta fields and slug '
					. 'live in ' . $this->vmLangTable($base, $this->vmDefaultLangTag()) . ' and its '
					. 'siblings, and VirtueMart joins them with an INNER JOIN '
					. '(helpers/vmtable.php:1065-1068).';

				break;
			}
		}

		if (str_contains($short, 'userinfos')) {
			$response['dynamic_schema_note'] = 'This table is not in install.sql and its column set is '
				. 'altered at runtime by VirtueMart\'s userfields screen (models/userfields.php:300, '
				. ':306). Columns ending in _DELETED_<unixtime> are from deleted userfields, which '
				. 'VirtueMart renames rather than drops (helpers/vmtable.php:2748-2751).';
		}

		if (str_contains($short, 'userinfos') || $short === 'virtuemart_vmusers') {
			$response['pii_note'] = 'This response contains personal data.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	private function normalise(string $name): string
	{
		$name = trim($name);
		$name = preg_replace('/^#__/', '', $name) ?? $name;
		$name = strtolower($name);

		return str_starts_with($name, 'virtuemart_') ? $name : 'virtuemart_' . $name;
	}
}
