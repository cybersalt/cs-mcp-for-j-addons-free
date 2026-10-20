<?php
/**
 * @package     MCP for FlexBanners
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

/**
 * Scoped, read-only access to the six FlexBanners tables.
 *
 * SELECT only; table and columns matched against the live schema; operators
 * from a fixed set; values quoted. Nothing in these tables is a secret.
 */
final class QueryTableTool extends AbstractTool
{
	use FlexbannersBootTrait;

	private const OPERATORS = ['=', '!=', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IS NULL', 'IS NOT NULL', 'IN', 'NOT IN'];

	public function getName(): string { return 'query_flexbanners_table'; }

	public function getDescription(): string
	{
		return 'Run a scoped, READ-ONLY structured query against one FlexBanners table: banners (#__flexbanners), '
			. 'restrictions (#__flexbannersin), locations, clients (#__flexbannersclient), links (#__flexbannerslink) '
			. 'or sizes (#__flexbannerssize). No raw SQL: pass table, columns, AND-combined filters and an order. For '
			. 'verifying writes and for anything the dedicated tools do not show.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'table'   => ['type' => 'string', 'enum' => array_keys(self::FB_TABLES)],
				'columns' => ['type' => 'array', 'items' => ['type' => 'string']],
				'filters' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'column'   => ['type' => 'string'],
							'operator' => ['type' => 'string'],
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
			'required'             => ['table'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->fbInstalled()) {
			return $this->fbNotInstalledError();
		}

		$this->fbRequireAccess($actor);

		$label = (string) ($arguments['table'] ?? '');

		if (!\array_key_exists($label, self::FB_TABLES)) {
			throw new \InvalidArgumentException('table must be one of: ' . implode(', ', array_keys(self::FB_TABLES)) . '.');
		}

		$table   = $this->fbTable(self::FB_TABLES[$label]);
		$db      = $this->db;
		$columns = array_keys($db->getTableColumns($table) ?: []);

		$wanted = array_values(array_filter(array_map('strval', (array) ($arguments['columns'] ?? []))));

		if (($unknown = array_diff($wanted, $columns)) !== []) {
			return ToolResult::json(['ok' => false, 'error' => 'Unknown columns: ' . implode(', ', $unknown), 'available_columns' => $columns], true);
		}

		$where = [];

		foreach ((array) ($arguments['filters'] ?? []) as $i => $f) {
			if (!\is_array($f)) {
				return ToolResult::json(['ok' => false, 'error' => 'Filter #' . $i . ' is not an object; refusing rather than widening the query.'], true);
			}

			$col = (string) ($f['column'] ?? '');

			if (!\in_array($col, $columns, true)) {
				return ToolResult::json(['ok' => false, 'error' => 'Filter column "' . $col . '" does not exist.', 'available_columns' => $columns], true);
			}

			$op = strtoupper(trim((string) ($f['operator'] ?? '=')));

			if (!\in_array($op, self::OPERATORS, true)) {
				return ToolResult::json(['ok' => false, 'error' => 'Unsupported operator "' . $op . '".', 'supported_operators' => self::OPERATORS], true);
			}

			$q = $db->quoteName($col);

			if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
				$where[] = $q . ' ' . $op;
				continue;
			}

			if (!\array_key_exists('value', $f)) {
				return ToolResult::json(['ok' => false, 'error' => 'Filter on "' . $col . '" needs a value.'], true);
			}

			if ($op === 'IN' || $op === 'NOT IN') {
				$vals = (array) $f['value'];

				if ($vals === []) {
					return ToolResult::json(['ok' => false, 'error' => $op . ' was given an empty list.'], true);
				}

				$where[] = $q . ' ' . $op . ' (' . implode(', ', array_map(fn ($v): string => $db->quote((string) $v), $vals)) . ')';
				continue;
			}

			$where[] = $q . ' ' . $op . ' ' . $db->quote((string) $f['value']);
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
		$orderSql = '';

		if (($ob = trim((string) ($arguments['order_by'] ?? ''))) !== '') {
			if (!\in_array($ob, $columns, true)) {
				return ToolResult::json(['ok' => false, 'error' => 'order_by column "' . $ob . '" does not exist.', 'available_columns' => $columns], true);
			}

			$orderSql = ' ORDER BY ' . $db->quoteName($ob) . (strtoupper((string) ($arguments['order_dir'] ?? 'ASC')) === 'DESC' ? ' DESC' : ' ASC');
		}

		$limit  = $this->fbLimit($arguments);
		$offset = $this->fbOffset($arguments);
		$select = $wanted !== [] ? $wanted : $columns;

		$total = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName($table) . $whereSql)->loadResult();
		$rows  = $db->setQuery(
			'SELECT ' . implode(', ', array_map(fn (string $c): string => $db->quoteName($c), $select)) . ' FROM ' . $db->quoteName($table) . $whereSql . $orderSql,
			$offset,
			$limit
		)->loadAssocList() ?: [];

		return ToolResult::json(['ok' => true, 'table' => $table, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'rows' => $rows]);
	}
}
