<?php
/**
 * @package     MCP for Page Protector
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Log;

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\LogHelper;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\PageProtectorBootTrait;
use Joomla\CMS\User\User;

final class GetStatsTool extends AbstractTool
{
	use PageProtectorBootTrait;

	public function getName(): string { return 'get_page_protector_stats'; }

	public function getDescription(): string
	{
		return 'Page Protector\'s numbers, as the dashboard and Event Log show them: last-24-hour counts per event '
			. '(challenged, passed, failed, blocked, bot, error), how many different IPs were challenged in that time, '
			. 'all-time counts per event, the total, and the oldest and newest entry. "bot" rows only exist when '
			. 'log_bots is on, and nothing is logged while logging is off; both are reported. Counts of IPs are counts '
			. 'of stored addresses, which are /24 or /48 networks while IP shortening is on.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($error = $this->ppCheck()) {
			return $error;
		}

		$this->ppRequireAccess($actor, 'view');

		/** @var \Cybersalt\Component\Cspageprotector\Administrator\Model\DashboardModel $dashboard */
		$dashboard = $this->getModel(self::PP_COMPONENT, 'Dashboard');

		/** @var \Cybersalt\Component\Cspageprotector\Administrator\Model\LogsModel $logs */
		$logs = $this->getModel(self::PP_COMPONENT, 'Logs');

		$day     = $dashboard->getStats();
		$allTime = $logs->getStats();
		$params  = $this->ppStoredParams();

		$db    = $this->db;
		$range = $db->setQuery(
			$db->createQuery()
				->select(['MIN(' . $db->quoteName('created') . ') AS ' . $db->quoteName('oldest'), 'MAX(' . $db->quoteName('created') . ') AS ' . $db->quoteName('newest')])
				->from($db->quoteName(self::PP_LOG_TABLE))
		)->loadAssoc() ?: [];

		$last24 = [];
		$ever   = [];

		foreach (LogHelper::EVENTS as $event) {
			$last24[$event] = (int) ($day[$event] ?? 0);
			$ever[$event]   = (int) ($allTime[$event] ?? 0);
		}

		return ToolResult::json([
			'ok'      => true,
			'last_24h' => [
				'events'                    => $last24,
				'all_events'                => (int) ($allTime['last24h'] ?? 0),
				'different_ips_challenged'  => (int) ($day['unique_ips'] ?? 0),
			],
			'all_time' => [
				'events' => $ever,
				'total'  => (int) ($allTime['total'] ?? 0),
				'oldest' => $range['oldest'] ?? null,
				'newest' => $range['newest'] ?? null,
			],
			'times'   => 'UTC',
			'logging' => [
				'enabled'        => (int) $params->get('logging_enabled', 1) === 1,
				'log_bots'       => (int) $params->get('log_bots', 0) === 1,
				'retention_days' => (int) $params->get('retention_days', 30),
				'retention_note' => 'Old rows are pruned on about 1 in 50 new log writes (LogHelper::log()), not on a schedule, so a '
					. 'quiet site can hold rows past the retention window.',
			],
			'ip_note' => $this->ppIpNote($params),
		]);
	}
}
