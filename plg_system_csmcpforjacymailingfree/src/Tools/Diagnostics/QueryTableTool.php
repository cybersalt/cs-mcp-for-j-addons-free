<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

/**
 * SELECT-only escape hatch over AcyMailing's own tables.
 *
 *  1. The table must be one of ACYM_TABLES, matched exactly on the short name.
 *     The full name is built from a constant, never from input.
 *  2. Every selected / filtered / ordered column is checked against the real
 *     schema and quoted. WHERE is structured {column, op, value} with an
 *     operator allowlist; there is no raw SQL parameter.
 *  3. Secrets are enforced, not advised: #__acym_user.key (the token behind
 *     every unsubscribe / profile / autologin link), mailbox passwords and
 *     add-on settings are never selectable, filterable or sortable — a
 *     `WHERE key LIKE 'a%'` would be an extraction oracle. In
 *     #__acym_configuration the `value` of any credential-shaped row is
 *     replaced, and `value` cannot be filtered on.
 *  4. #__acym_mail / mail_archive text columns are decoded from AcyMailing's
 *     double encoding unless raw: true, so subjects read as written.
 */
final class QueryTableTool extends AbstractTool
{
	use AcymailingBootTrait;

	private const OPS = ['=', '!=', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IS NULL', 'IS NOT NULL', 'IN', 'NOT IN'];

	public function getName(): string { return 'query_acym_table'; }

	public function getDescription(): string
	{
		return 'Read rows from one AcyMailing table (#__acym_<table>; use list_acym_tables for the list). '
			. 'SELECT only. Arguments: table (short name, e.g. "user", "queue"), columns (optional list), where '
			. '(list of {column, op, value}; ops = != < <= > >= LIKE, NOT LIKE, IS NULL, IS NOT NULL, IN, NOT IN), '
			. 'order_by, order_dir, limit (max 500), offset. Credentials are always withheld: subscriber `key` '
			. '(it authorises unsubscribe/profile links), mailbox passwords, add-on settings, and the value of '
			. 'any credential-shaped configuration row; those columns cannot be filtered or sorted on either. '
			. 'Mail name/subject/body/preheader are decoded from AcyMailing\'s double-UTF-8 storage unless raw: true.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['table'],
			'properties' => [
				'table'     => ['type' => 'string', 'enum' => self::ACYM_TABLES],
				'columns'   => ['type' => 'array', 'items' => ['type' => 'string']],
				'where'     => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
					'column' => ['type' => 'string'], 'op' => ['type' => 'string'],
					'value'  => ['description' => 'Scalar, or an array for IN / NOT IN.'],
				], 'required' => ['column', 'op']]],
				'order_by'  => ['type' => 'string'],
				'order_dir' => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
				'limit'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
				'offset'    => ['type' => 'integer', 'minimum' => 0],
				'raw'       => ['type' => 'boolean', 'description' => 'Return mail text columns exactly as stored (double-encoded).'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->acyAdminBase() === null) {
			return $this->notInstalledError();
		}

		$table = (string) ($arguments['table'] ?? '');
		if (!\in_array($table, self::ACYM_TABLES, true)) {
			return ToolResult::error('Unknown table "' . $table . '". Allowed: ' . implode(', ', self::ACYM_TABLES) . '.');
		}
		if (!$this->acyTableExists($table)) {
			return ToolResult::error('Table #__acym_' . $table . ' does not exist on this install (mail_stat_detail is AcyMailing 11+ only).');
		}

		$full     = $this->acyTable($table);
		$columns  = array_keys($this->db->getTableColumns($full));
		$redacted = ListTablesTool::REDACTED_COLUMNS[$table] ?? [];
		$noFilter = $redacted;
		if ($table === 'configuration') {
			$noFilter[] = 'value';
		}

		$wanted = (array) ($arguments['columns'] ?? []);
		if ($wanted === []) {
			$wanted = array_values(array_diff($columns, $redacted));
		}
		foreach ($wanted as $c) {
			if (!\in_array($c, $columns, true)) {
				return ToolResult::error('Unknown column "' . $c . '" on #__acym_' . $table . '. Columns: ' . implode(', ', $columns) . '.');
			}
			if (\in_array($c, $redacted, true)) {
				return ToolResult::error('Column "' . $c . '" holds a credential or subscriber secret and is never returned.');
			}
		}

		$q = $this->db->getQuery(true)->select($this->acyQn($wanted))->from($this->acyQn($full));

		foreach ((array) ($arguments['where'] ?? []) as $i => $w) {
			$col = (string) ($w['column'] ?? '');
			$op  = strtoupper(trim((string) ($w['op'] ?? '')));
			if (!\in_array($col, $columns, true)) {
				return ToolResult::error('where[' . $i . ']: unknown column "' . $col . '".');
			}
			if (\in_array($col, $noFilter, true)) {
				return ToolResult::error('where[' . $i . ']: "' . $col . '" cannot be filtered on - a filter on a secret is an extraction oracle.');
			}
			if (!\in_array($op, self::OPS, true)) {
				return ToolResult::error('where[' . $i . ']: operator must be one of ' . implode(', ', self::OPS) . '.');
			}
			$qc = $this->acyQn($col);
			if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
				$q->where($qc . ' ' . $op);
			} elseif ($op === 'IN' || $op === 'NOT IN') {
				$vals = (array) ($w['value'] ?? []);
				if ($vals === []) {
					return ToolResult::error('where[' . $i . ']: IN needs a non-empty array value.');
				}
				$q->where($qc . ' ' . $op . ' (' . implode(',', array_map(fn ($v) => $this->db->quote((string) $v), $vals)) . ')');
			} else {
				$q->where($qc . ' ' . $op . ' ' . $this->db->quote((string) ($w['value'] ?? '')));
			}
		}

		if (!empty($arguments['order_by'])) {
			$ob = (string) $arguments['order_by'];
			if (!\in_array($ob, $columns, true) || \in_array($ob, $noFilter, true)) {
				return ToolResult::error('order_by must be a non-secret column of #__acym_' . $table . '.');
			}
			$q->order($this->acyQn($ob) . ' ' . (($arguments['order_dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC'));
		}

		$limit  = $this->acyLimit($arguments);
		$offset = $this->acyOffset($arguments);
		$rows   = $this->db->setQuery($q, $offset, $limit)->loadAssocList() ?: [];

		$decode = !($arguments['raw'] ?? false) && \in_array($table, ['mail', 'mail_archive'], true);
		foreach ($rows as &$r) {
			if ($decode) {
				$r = $this->acyDecodeMail($r);
			}
			if ($table === 'configuration' && isset($r['name'], $r['value']) && $this->acyIsSecretName((string) $r['name'])) {
				$r['value'] = ['redacted' => true, 'set' => $r['value'] !== ''];
			}
		}
		unset($r);

		return ToolResult::json([
			'table'            => '#__acym_' . $table,
			'count'            => count($rows),
			'limit'            => $limit,
			'offset'           => $offset,
			'decoded'          => $decode,
			'withheld_columns' => $redacted,
			'rows'             => $rows,
		]);
	}
}
