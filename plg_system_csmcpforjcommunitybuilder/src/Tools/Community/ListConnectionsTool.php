<?php
/**
 * @package     MCP for Community Builder
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Community;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

/**
 * Connections are rows in `#__comprofiler_members`, one per direction. From
 * `cbConnection::addConnectionToDatabase()`: with mutual connections on, the
 * requester's row (referenceid = requester, memberid = target) is
 * accepted=1 pending=1 and the reverse row accepted=0 pending=0 until the
 * target accepts, after which both are accepted=1 pending=0.
 */
final class ListConnectionsTool extends AbstractTool
{
	use CommunitybuilderBootTrait;

	public function getName(): string { return 'list_cb_connections'; }

	public function getDescription(): string
	{
		return 'List Community Builder connections (CB\'s friends feature, #__comprofiler_members). One row per '
			. 'direction: "from" is the member whose connection list it is, "to" the connected member. state: '
			. 'connected (accepted, not pending), pending (a request the other side has not answered — "from" '
			. 'sent it), unaccepted (the reverse row of a pending request). Filter by user_id (either side, or '
			. 'with direction outgoing / incoming), or by state. Includes the request message and connection '
			. 'type/description the members gave. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'user_id'   => ['type' => 'integer'],
				'direction' => ['type' => 'string', 'enum' => ['both', 'outgoing', 'incoming'], 'description' => 'With user_id. Default both.'],
				'state'     => ['type' => 'string', 'enum' => ['any', 'connected', 'pending', 'unaccepted'], 'description' => 'Default any.'],
				'limit'     => ['type' => 'integer'],
				'offset'    => ['type' => 'integer'],
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

		$db    = $this->db;
		$where = [];

		if (($uid = (int) ($arguments['user_id'] ?? 0)) > 0) {
			$where[] = match ((string) ($arguments['direction'] ?? 'both')) {
				'outgoing' => 'm.referenceid = ' . $uid,
				'incoming' => 'm.memberid = ' . $uid,
				default    => '(m.referenceid = ' . $uid . ' OR m.memberid = ' . $uid . ')',
			};
		}

		switch ((string) ($arguments['state'] ?? 'any')) {
			case 'connected':
				$where[] = 'm.accepted = 1 AND m.pending = 0';
				break;
			case 'pending':
				$where[] = 'm.pending = 1';
				break;
			case 'unaccepted':
				$where[] = 'm.accepted = 0';
				break;
		}

		$w    = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
		$from = ' FROM ' . $db->quoteName('#__comprofiler_members') . ' m'
			. ' LEFT JOIN ' . $db->quoteName('#__users') . ' a ON a.id = m.referenceid'
			. ' LEFT JOIN ' . $db->quoteName('#__users') . ' b ON b.id = m.memberid';

		$total = (int) $db->setQuery('SELECT COUNT(*)' . $from . $w)->loadResult();

		$rows = $db->setQuery(
			'SELECT m.*, a.username AS from_username, b.username AS to_username' . $from . $w
			. ' ORDER BY m.membersince DESC',
			$this->cbOffset($arguments),
			$this->cbLimit($arguments)
		)->loadAssocList() ?: [];

		$out = array_map(static function (array $r): array {
			$state = (int) $r['pending'] === 1 ? 'pending' : ((int) $r['accepted'] === 1 ? 'connected' : 'unaccepted');

			return [
				'from'        => ['id' => (int) $r['referenceid'], 'username' => $r['from_username']],
				'to'          => ['id' => (int) $r['memberid'], 'username' => $r['to_username']],
				'state'       => $state,
				'since'       => $r['membersince'],
				'message'     => $r['reason'],
				'type'        => $r['type'],
				'description' => $r['description'],
			];
		}, $rows);

		return ToolResult::json(['ok' => true, 'total' => $total, 'showing' => \count($out), 'connections' => $out]);
	}
}
