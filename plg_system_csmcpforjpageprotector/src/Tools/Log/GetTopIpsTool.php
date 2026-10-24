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
use Cybersalt\Component\Cspageprotector\Administrator\Model\LogsModel;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\PageProtectorBootTrait;
use Joomla\CMS\User\User;

final class GetTopIpsTool extends AbstractTool
{
	use PageProtectorBootTrait;

	private const WINDOWS = ['1h', '24h', '7d', '30d', 'all'];

	public function getName(): string { return 'get_page_protector_top_ips'; }

	public function getDescription(): string
	{
		return 'The most-challenged IP addresses over a window (default 7d, the dashboard\'s), with challenged, passed, '
			. 'failed and blocked counts and when each was last seen. likely_scraper is set when an address was challenged '
			. 'at least min_challenges times (default 5) and never passed, which the dashboard calls "almost always a '
			. 'scraper". With IP shortening on (the default) each row is a /24 or /48 NETWORK, which can hold many '
			. 'visitors; block_target is the range to use at a firewall. Page Protector itself blocks nobody: a failed '
			. 'visitor is simply checked again, and deleting log rows unblocks nothing.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'window'         => ['type' => 'string', 'enum' => self::WINDOWS],
				'limit'          => ['type' => 'integer', 'description' => '1-100, default 10.'],
				'min_challenges' => ['type' => 'integer', 'description' => 'Threshold for likely_scraper (default 5).'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($error = $this->ppCheck()) {
			return $error;
		}

		$this->ppRequireAccess($actor, 'view');

		$window = (string) ($arguments['window'] ?? '7d');

		if (!\in_array($window, self::WINDOWS, true)) {
			throw new \InvalidArgumentException('window must be one of ' . implode(', ', self::WINDOWS) . '.');
		}

		$limit     = $this->ppLimit($arguments, 10, 100);
		$threshold = max(1, (int) ($arguments['min_challenges'] ?? 5));

		// DashboardModel::getTopIps() (DashboardModel.php:291-316) is fixed at 7 days and leaves out
		// "blocked"; this is the same query with the window as a parameter, the cut-off taken from
		// the Event Log's own LogsModel::sinceCutoff().
		$cutoff = $window === 'all' ? null : LogsModel::sinceCutoff($window);
		$db     = $this->db;
		$sum    = static fn (string $event): string => 'SUM(CASE WHEN ' . $db->quoteName('event') . ' = ' . $db->quote($event) . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName($event);

		$query = $db->createQuery()
			->select([
				$db->quoteName('ip'),
				$sum(LogHelper::EVENT_CHALLENGED),
				$sum(LogHelper::EVENT_PASSED),
				$sum(LogHelper::EVENT_FAILED),
				$sum(LogHelper::EVENT_BLOCKED),
				'MAX(' . $db->quoteName('created') . ') AS ' . $db->quoteName('last_seen'),
			])
			->from($db->quoteName(self::PP_LOG_TABLE))
			->where($db->quoteName('ip') . ' <> ' . $db->quote(''))
			->group($db->quoteName('ip'))
			->order($db->quoteName('challenged') . ' DESC, ' . $db->quoteName('last_seen') . ' DESC');

		if ($cutoff !== null) {
			$query->where($db->quoteName('created') . ' >= :cutoff')->bind(':cutoff', $cutoff);
		}

		$rows      = $db->setQuery($query, 0, $limit)->loadAssocList() ?: [];
		$params    = $this->ppStoredParams();
		$shortened = $this->ppIpsShortened($params);
		$out       = [];

		foreach ($rows as $row) {
			$challenged = (int) $row['challenged'];
			$passed     = (int) $row['passed'];

			$out[] = $this->ppIpShape((string) $row['ip'], $shortened) + [
				'challenged'     => $challenged,
				'passed'         => $passed,
				'failed'         => (int) $row['failed'],
				'blocked'        => (int) $row['blocked'],
				'last_seen_utc'  => (string) $row['last_seen'],
				'likely_scraper' => $challenged >= $threshold && $passed === 0,
			];
		}

		return ToolResult::json([
			'ok'             => true,
			'window'         => $window,
			'since_utc'      => $cutoff,
			'min_challenges' => $threshold,
			'count'          => \count($out),
			'ip_note'        => $this->ppIpNote($params),
			'ips'            => $out,
		]);
	}
}
