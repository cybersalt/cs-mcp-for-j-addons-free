<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

/**
 * Scoped, read-only access to the CB core tables.
 *
 *   1. SELECT only.
 *   2. Scoped to tables CB core and its bundled plugins create; three refused.
 *   3. Table and column names are matched against the live schema, operators
 *      come from a fixed set, values go through quote().
 *   4. Redacted columns are masked in output AND refused as filter or sort
 *      targets — otherwise `WHERE cbactivation LIKE 'reg1%'` recovers a
 *      confirmation code a character at a time and the masking is decorative.
 */
final class QueryTableTool extends AbstractTool
{
	use CommunitybuilderBootTrait;

	private const OPERATORS = ['=', '!=', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IS NULL', 'IS NOT NULL', 'IN', 'NOT IN'];

	public function getName(): string { return 'query_cb_table'; }

	public function getDescription(): string
	{
		return 'Run a scoped, READ-ONLY, structured query against a Community Builder core table — the escape hatch '
			. 'for what the dedicated tools do not cover. Tables: comprofiler (one row per member, one column per '
			. 'custom field), comprofiler_fields, comprofiler_field_values, comprofiler_tabs, comprofiler_lists, '
			. 'comprofiler_members (connections), comprofiler_plugin, comprofiler_userreports, comprofiler_views, '
			. 'comprofiler_ratings, comprofiler_plugin_blogs. SELECT only, no raw SQL: pass a table, columns, '
			. 'AND-combined filters and an order. REDACTED (masked in output and refused as filter/sort targets): '
			. 'comprofiler.cbactivation (the email-confirmation code — whoever holds it can confirm the account), '
			. 'comprofiler.registeripaddr, columns of CB password-type fields, comprofiler_plugin.params (plugin '
			. 'settings, where API keys live; use get_cb_config for the core configuration), views.lastip, '
			. 'ratings.ip_address. REFUSED: comprofiler_sessions and the private-message tables. Joomla\'s '
			. '#__users is not in scope (use get_cb_user / list_cb_users).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'table'   => ['type' => 'string', 'description' => 'Accepts "fields", "comprofiler_fields" or "#__comprofiler_fields"; "comprofiler" for the member table. Required.'],
				'columns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Columns to return. Omit for all.'],
				'filters' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'column'   => ['type' => 'string'],
							'operator' => ['type' => 'string', 'description' => '= != < <= > >= LIKE "NOT LIKE" "IS NULL" "IS NOT NULL" IN "NOT IN". Default =.'],
							'value'    => ['description' => 'Scalar, or an array for IN / NOT IN.'],
						],
						'required' => ['column'],
					],
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
		if (!$this->cbInstalled()) {
			return $this->cbNotInstalledError();
		}

		$this->cbRequireAccess($actor, 'core.manage', 'com_comprofiler.tools');

		$requested = strtolower(trim((string) ($arguments['table'] ?? '')));

		if ($requested === '') {
			throw new \InvalidArgumentException('table is required.');
		}

		$short = preg_replace('/^#__/', '', $requested);

		if ($short !== 'comprofiler' && !str_starts_with($short, 'comprofiler_')) {
			$short = 'comprofiler_' . $short;
		}

		if (isset(self::CB_READ_DENYLIST[$short])) {
			return ToolResult::json([
				'ok'    => false,
				'error' => '#__' . $short . ' is not readable through this tool: it holds ' . self::CB_READ_DENYLIST[$short],
			], true);
		}

		if (!\in_array($short, array_merge(self::CB_CORE_TABLES, self::CB_CORE_PLUGIN_TABLES), true)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => '#__' . $short . ' is not a Community Builder core table. Tables created by CB add-ons are out of scope; list_cb_tables shows what is readable.',
			], true);
		}

		if (!$this->cbTableExists($short)) {
			return ToolResult::json(['ok' => false, 'error' => 'No table #__' . $short . ' on this site.'], true);
		}

		$table   = '#__' . $short;
		$columns = array_keys($this->db->getTableColumns($table) ?: []);
		$secret  = self::CB_REDACTED_COLUMNS[$short] ?? [];

		if ($short === 'comprofiler') {
			$secret = array_merge($secret, $this->cbSecretProfileColumns());
		}

		$wanted = array_values(array_filter(array_map('strval', (array) ($arguments['columns'] ?? []))));

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
				return ToolResult::json(['ok' => false, 'error' => 'Filter #' . $i . ' is not an object. Refusing rather than running the query without it.'], true);
			}

			$column = (string) ($filter['column'] ?? '');

			if (!\in_array($column, $columns, true)) {
				return ToolResult::json(['ok' => false, 'error' => 'Filter column "' . $column . '" does not exist on ' . $table . '.', 'available_columns' => $columns], true);
			}

			if (\in_array($column, $secret, true)) {
				return ToolResult::json(['ok' => false, 'error' => 'Refusing to filter on "' . $column . '": it is redacted, and filtering on it would let its value be recovered a character at a time.'], true);
			}

			$operator = strtoupper(trim((string) ($filter['operator'] ?? '=')));

			if (!\in_array($operator, self::OPERATORS, true)) {
				return ToolResult::json(['ok' => false, 'error' => 'Unsupported operator "' . $operator . '".', 'supported_operators' => self::OPERATORS], true);
			}

			$quoted = $this->db->quoteName($column);

			if ($operator === 'IS NULL' || $operator === 'IS NOT NULL') {
				$where[] = $quoted . ' ' . $operator;
				continue;
			}

			if (!\array_key_exists('value', $filter)) {
				return ToolResult::json(['ok' => false, 'error' => 'Filter on "' . $column . '" with ' . $operator . ' needs a value.'], true);
			}

			if ($operator === 'IN' || $operator === 'NOT IN') {
				$values = (array) $filter['value'];

				if ($values === []) {
					return ToolResult::json(['ok' => false, 'error' => $operator . ' on "' . $column . '" was given an empty list.'], true);
				}

				$where[] = $quoted . ' ' . $operator . ' (' . implode(', ', array_map(fn ($v): string => $this->db->quote((string) $v), $values)) . ')';
				continue;
			}

			$where[] = $quoted . ' ' . $operator . ' ' . $this->db->quote((string) $filter['value']);
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
		$orderSql = '';

		if (($orderBy = trim((string) ($arguments['order_by'] ?? ''))) !== '') {
			if (!\in_array($orderBy, $columns, true)) {
				return ToolResult::json(['ok' => false, 'error' => 'order_by column "' . $orderBy . '" does not exist on ' . $table . '.', 'available_columns' => $columns], true);
			}

			if (\in_array($orderBy, $secret, true)) {
				return ToolResult::json(['ok' => false, 'error' => 'Refusing to sort on "' . $orderBy . '": sorting on a redacted column leaks its ordering.'], true);
			}

			$dir      = strtoupper((string) ($arguments['order_dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
			$orderSql = ' ORDER BY ' . $this->db->quoteName($orderBy) . ' ' . $dir;
		}

		$limit  = $this->cbLimit($arguments);
		$offset = $this->cbOffset($arguments);
		$total  = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->db->quoteName($table) . $whereSql)->loadResult();

		$rows = $this->db->setQuery(
			'SELECT ' . implode(', ', array_map(fn (string $c): string => $this->db->quoteName($c), $select))
			. ' FROM ' . $this->db->quoteName($table) . $whereSql . $orderSql,
			$offset,
			$limit
		)->loadAssocList() ?: [];

		foreach ($rows as &$row) {
			foreach ($secret as $column) {
				if (\array_key_exists($column, $row) && $row[$column] !== null && $row[$column] !== '') {
					$row[$column] = '[redacted]';
				}
			}
		}
		unset($row);

		$response = [
			'ok'      => true,
			'table'   => $table,
			'total'   => $total,
			'limit'   => $limit,
			'offset'  => $offset,
			'showing' => \count($rows),
			'rows'    => $rows,
		];

		$redacted = array_values(array_intersect($secret, $select));

		if ($redacted !== []) {
			$response['redacted_columns'] = $redacted;
		}

		return ToolResult::json($response);
	}
}
