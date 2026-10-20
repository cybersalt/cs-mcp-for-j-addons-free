<?php
/**
 * @package     MCP for Community Builder
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Members;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

final class ListUsersTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use MemberSupportTrait;

	private const STATUSES = [
		'any', 'active', 'awaiting_approval', 'awaiting_confirmation', 'pending', 'rejected', 'banned',
		'unban_requested', 'blocked', 'no_cb_profile',
	];

	private const ORDER = ['id', 'username', 'name', 'email', 'registerDate', 'lastvisitDate'];

	public function getName(): string { return 'list_cb_users'; }

	public function getDescription(): string
	{
		return 'List Community Builder members: Joomla account plus CB moderation state, with optional CB profile '
			. 'field values. A CB member is a #__users row plus a #__comprofiler row with the same id; Joomla users '
			. 'created outside CB may have no CB row (status no_cb_profile). Status filter: active (can log in), '
			. 'awaiting_approval, awaiting_confirmation (email not confirmed), pending (either of the two), rejected, '
			. 'banned, unban_requested, blocked (Joomla block flag), no_cb_profile. Search matches username, name, '
			. 'email and CB first/last name. include_fields adds named CB fields (e.g. ["cb_company"]) with option '
			. 'labels resolved. Never returns passwords, activation codes or registration IPs. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'status'          => ['type' => 'string', 'enum' => self::STATUSES, 'description' => 'Default any.'],
				'search'          => ['type' => 'string'],
				'group_id'        => ['type' => 'integer', 'description' => 'Only members of this Joomla user group.'],
				'registered_after'  => ['type' => 'string', 'description' => 'YYYY-MM-DD (UTC).'],
				'registered_before' => ['type' => 'string', 'description' => 'YYYY-MM-DD (UTC).'],
				'include_fields'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'CB field names to include, e.g. ["firstname","cb_company"].'],
				'order_by'        => ['type' => 'string', 'enum' => self::ORDER, 'description' => 'Default registerDate.'],
				'order_dir'       => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'description' => 'Default DESC.'],
				'limit'           => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'          => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cbInstalled()) {
			return $this->cbNotInstalledError();
		}

		$this->cbRequireAccess($actor, 'core.manage', 'com_users');

		$db     = $this->db;
		$status = (string) ($arguments['status'] ?? 'any');

		if (!\in_array($status, self::STATUSES, true)) {
			throw new \InvalidArgumentException('Unknown status "' . $status . '".');
		}

		$where = [];

		switch ($status) {
			case 'active':
				$where[] = 'c.id IS NOT NULL AND u.block = 0 AND c.approved = 1 AND c.confirmed = 1';
				break;
			case 'awaiting_approval':
				$where[] = 'c.approved = 0';
				break;
			case 'awaiting_confirmation':
				$where[] = 'c.confirmed = 0';
				break;
			case 'pending':
				$where[] = '(c.approved = 0 OR c.confirmed = 0)';
				break;
			case 'rejected':
				$where[] = 'c.approved = 2';
				break;
			case 'banned':
				$where[] = 'c.banned = 1';
				break;
			case 'unban_requested':
				$where[] = 'c.banned = 2';
				break;
			case 'blocked':
				$where[] = 'u.block = 1';
				break;
			case 'no_cb_profile':
				$where[] = 'c.id IS NULL';
				break;
		}

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$like    = $this->cbLike($search);
			$where[] = '(u.username LIKE ' . $like . ' OR u.name LIKE ' . $like . ' OR u.email LIKE ' . $like
				. ' OR c.firstname LIKE ' . $like . ' OR c.lastname LIKE ' . $like . ')';
		}

		if (($group = (int) ($arguments['group_id'] ?? 0)) > 0) {
			$where[] = 'u.id IN (SELECT user_id FROM ' . $db->quoteName('#__user_usergroup_map') . ' WHERE group_id = ' . $group . ')';
		}

		foreach (['registered_after' => '>=', 'registered_before' => '<'] as $arg => $op) {
			if (($d = trim((string) ($arguments[$arg] ?? ''))) !== '') {
				if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1) {
					throw new \InvalidArgumentException($arg . ' must be YYYY-MM-DD.');
				}

				$where[] = 'u.registerDate ' . $op . ' ' . $db->quote($d . ' 00:00:00');
			}
		}

		$orderBy = (string) ($arguments['order_by'] ?? 'registerDate');
		$orderBy = \in_array($orderBy, self::ORDER, true) ? $orderBy : 'registerDate';
		$dir     = strtoupper((string) ($arguments['order_dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

		// Requested fields.
		$fieldDefs = [];

		foreach (array_values(array_filter(array_map('strval', (array) ($arguments['include_fields'] ?? [])))) as $name) {
			$f = $this->cbFindField($name);

			if ($f === null) {
				throw new \InvalidArgumentException('No CB field named "' . $name . '". list_cb_fields shows them.');
			}

			$fieldDefs[$name] = $f;
		}

		$from = ' FROM ' . $db->quoteName('#__users') . ' u LEFT JOIN ' . $db->quoteName('#__comprofiler') . ' c ON c.id = u.id';
		$wSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		$total = (int) $db->setQuery('SELECT COUNT(*)' . $from . $wSql)->loadResult();

		$cols = array_map(static fn (string $c): string => 'u.' . $c, self::CB_USERS_COLUMNS);

		$rows = $db->setQuery(
			// c.* first so the #__users columns (u.id included) win on name clashes.
			'SELECT c.*, c.id AS cb_id, ' . implode(', ', $cols) . $from . $wSql
			. ' ORDER BY u.' . $db->quoteName($orderBy) . ' ' . $dir,
			$this->cbOffset($arguments),
			$this->cbLimit($arguments)
		)->loadAssocList() ?: [];

		$secret  = $this->cbSecretProfileColumns();
		$members = [];

		foreach ($rows as $r) {
			$m = [
				'id'            => (int) $r['id'],
				'username'      => $r['username'],
				'name'          => $r['name'],
				'email'         => $r['email'],
				'registerDate'  => $r['registerDate'],
				'lastvisitDate' => $r['lastvisitDate'],
				'status'        => $this->cbStatus($r),
			];

			if ($r['cb_id'] !== null) {
				$m['firstname']      = $r['firstname'];
				$m['lastname']       = $r['lastname'];
				$m['has_activation_code'] = (string) $r['cbactivation'] !== '';
			}

			foreach ($fieldDefs as $name => $f) {
				$m['fields'][$name] = $r['cb_id'] === null ? null : $this->cbFieldValue($f, $r, $secret);
			}

			$members[] = $m;
		}

		return ToolResult::json([
			'ok'      => true,
			'total'   => $total,
			'showing' => \count($members),
			'members' => $members,
		]);
	}
}
