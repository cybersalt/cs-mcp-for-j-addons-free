<?php
/**
 * @package     MCP for Community Builder
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Moderation;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

final class ListUserReportsTool extends AbstractTool
{
	use CommunitybuilderBootTrait;

	public function getName(): string { return 'list_cb_user_reports'; }

	public function getDescription(): string
	{
		return 'List Community Builder user reports — members reporting other members\' profiles (CB\'s "Report '
			. 'User", enabled by allowUserReports). reportedstatus 0 is open, 1 processed (CB\'s own labels). Each '
			. 'report shows who was reported, by whom, when, the explanation, and the reported member\'s current '
			. 'ban state. Filter by status or by reported user. Read-only; CB processes reports from its front-end '
			. 'moderation screen.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'status'        => ['type' => 'string', 'enum' => ['open', 'processed', 'any'], 'description' => 'Default open.'],
				'reported_user' => ['type' => 'integer'],
				'limit'         => ['type' => 'integer'],
				'offset'        => ['type' => 'integer'],
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
		$where  = [];
		$status = (string) ($arguments['status'] ?? 'open');

		if ($status === 'open') {
			$where[] = 'r.reportedstatus = 0';
		} elseif ($status === 'processed') {
			$where[] = 'r.reportedstatus = 1';
		}

		if (($uid = (int) ($arguments['reported_user'] ?? 0)) > 0) {
			$where[] = 'r.reporteduser = ' . $uid;
		}

		$w     = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
		$from  = ' FROM ' . $db->quoteName('#__comprofiler_userreports') . ' r'
			. ' LEFT JOIN ' . $db->quoteName('#__users') . ' ru ON ru.id = r.reporteduser'
			. ' LEFT JOIN ' . $db->quoteName('#__users') . ' rb ON rb.id = r.reportedbyuser'
			. ' LEFT JOIN ' . $db->quoteName('#__comprofiler') . ' c ON c.id = r.reporteduser';
		$total = (int) $db->setQuery('SELECT COUNT(*)' . $from . $w)->loadResult();

		$rows = $db->setQuery(
			'SELECT r.reportid, r.reporteduser, ru.username AS reported_username, r.reportedbyuser, rb.username AS reported_by_username,'
			. ' r.reportedondate, r.reportexplaination, r.reportedstatus, c.banned' . $from . $w
			. ' ORDER BY r.reportedondate DESC, r.reportid DESC',
			$this->cbOffset($arguments),
			$this->cbLimit($arguments)
		)->loadAssocList() ?: [];

		$reports = array_map(static fn (array $r): array => [
			'report_id'      => (int) $r['reportid'],
			'reported_user'  => ['id' => (int) $r['reporteduser'], 'username' => $r['reported_username']],
			'reported_by'    => ['id' => (int) $r['reportedbyuser'], 'username' => $r['reported_by_username']],
			'date'           => $r['reportedondate'],
			'explanation'    => $r['reportexplaination'],
			'status'         => (int) $r['reportedstatus'] === 0 ? 'open' : 'processed',
			'reported_user_banned' => $r['banned'] === null ? null : (int) $r['banned'],
		], $rows);

		return ToolResult::json(['ok' => true, 'total' => $total, 'showing' => \count($reports), 'reports' => $reports]);
	}
}
