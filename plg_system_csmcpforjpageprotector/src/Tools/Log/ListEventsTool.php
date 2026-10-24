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

final class ListEventsTool extends AbstractTool
{
	use PageProtectorBootTrait;

	/** The Event Log's sortable columns (LogsModel::ORDERABLE, which is private). */
	private const ORDERING = ['created' => 'a.created', 'id' => 'a.id', 'event' => 'a.event', 'ip' => 'a.ip', 'url' => 'a.url', 'user_agent' => 'a.user_agent', 'page' => 'm.title'];

	private const SINCE = ['1h', '24h', '7d', '30d'];

	/**
	 * LogHelper::EVENTS, copied because tools/list builds this schema on sites
	 * where Page Protector is not installed, and touching a vendor class there
	 * would take every tool in the add-on down. run() checks against the real one.
	 */
	private const EVENTS = ['challenged', 'passed', 'failed', 'blocked', 'bot', 'error'];

	public function getName(): string { return 'list_page_protector_events'; }

	public function getDescription(): string
	{
		return 'Page Protector\'s event log with the Event Log screen\'s own filters, run through its own list model: '
			. 'event (challenged, passed, failed, blocked, bot, error), since (1h, 24h, 7d, 30d), menu_item_id, ip '
			. '(matches from the start, so "203.0.113." finds a whole /24), url and user_agent (contain), search (IP, URL, '
			. 'user agent, details or page title; "id:123" finds one row). Newest first by default. IPs are shown as the '
			. 'admin shows them: with IP shortening on (the default) they are network addresses, not exact visitor IPs, '
			. 'and block_target gives the range for a firewall rule. URLs and user agents are whatever the visitor sent.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'event'        => ['type' => 'string', 'enum' => self::EVENTS],
				'since'        => ['type' => 'string', 'enum' => self::SINCE],
				'menu_item_id' => ['type' => 'integer'],
				'ip'           => ['type' => 'string', 'description' => 'IP prefix.'],
				'url'          => ['type' => 'string'],
				'user_agent'   => ['type' => 'string'],
				'search'       => ['type' => 'string'],
				'ordering'     => ['type' => 'string', 'enum' => array_keys(self::ORDERING)],
				'direction'    => ['type' => 'string', 'enum' => ['asc', 'desc']],
				'limit'        => ['type' => 'integer', 'description' => '1-200, default 50.'],
				'offset'       => ['type' => 'integer'],
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

		// The model drops an unknown event or since silently (LogsModel::getListQuery()); refuse instead,
		// so a typo is not read as "no matching events".
		$event = (string) ($arguments['event'] ?? '');

		if ($event !== '' && !\in_array($event, LogHelper::EVENTS, true)) {
			throw new \InvalidArgumentException('event must be one of ' . implode(', ', LogHelper::EVENTS) . '.');
		}

		$since = (string) ($arguments['since'] ?? '');

		if ($since !== '' && !\in_array($since, self::SINCE, true)) {
			throw new \InvalidArgumentException('since must be one of ' . implode(', ', self::SINCE) . '.');
		}

		$ordering = (string) ($arguments['ordering'] ?? 'created');

		if (!isset(self::ORDERING[$ordering])) {
			throw new \InvalidArgumentException('ordering must be one of ' . implode(', ', array_keys(self::ORDERING)) . '.');
		}

		$limit  = $this->ppLimit($arguments);
		$offset = $this->ppOffset($arguments);

		/** @var \Cybersalt\Component\Cspageprotector\Administrator\Model\LogsModel $model */
		$model = $this->getModel(self::PP_COMPONENT, 'Logs');

		$model->setState('filter.search', trim((string) ($arguments['search'] ?? '')));
		$model->setState('filter.event', $event);
		$model->setState('filter.since', $since);
		$model->setState('filter.menu_item', isset($arguments['menu_item_id']) ? (string) max(0, (int) $arguments['menu_item_id']) : '');
		$model->setState('filter.ip', trim((string) ($arguments['ip'] ?? '')));
		$model->setState('filter.url', trim((string) ($arguments['url'] ?? '')));
		$model->setState('filter.ua', trim((string) ($arguments['user_agent'] ?? '')));
		$model->setState('list.ordering', self::ORDERING[$ordering]);
		$model->setState('list.direction', strtolower((string) ($arguments['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC');
		$model->setState('list.start', $offset);
		$model->setState('list.limit', $limit);

		$items = $model->getItems() ?: [];
		$total = (int) $model->getTotal();

		// ListModel::getStart() pulls an offset past the end back to the last page, as the admin
		// pagination does; report the offset that was really used.
		$offset = (int) $model->getStart();

		$params    = $this->ppStoredParams();
		$shortened = $this->ppIpsShortened($params);
		$rows      = [];

		foreach ($items as $item) {
			$rows[] = [
				'id'           => (int) $item->id,
				'created_utc'  => (string) $item->created,
				'event'        => (string) $item->event,
			] + $this->ppIpShape((string) $item->ip, $shortened) + [
				'menu_item_id' => (int) $item->menu_item_id,
				'page'         => $item->menu_title !== null ? (string) $item->menu_title : null,
				'url'          => (string) $item->url,
				'user_agent'   => (string) $item->user_agent,
				'captcha'      => (string) $item->captcha,
				'details'      => (string) $item->details,
			];
		}

		return ToolResult::json([
			'ok'      => true,
			'total'   => $total,
			'offset'  => $offset,
			'limit'   => $limit,
			'count'   => \count($rows),
			'ip_note' => $this->ppIpNote($params),
			'events'  => $rows,
		]);
	}
}
