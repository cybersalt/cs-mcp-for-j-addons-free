<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Events;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

/**
 * Create a DPCalendar event via DPCalendar's own AdminModel so field plugins,
 * tags, cache invalidation, notification emails etc. fire correctly.
 */
final class CreateEventTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'create_dpcalendar_event'; }

	public function getDescription(): string
	{
		return 'Create a DPCalendar event via DPCalendar\'s EventModel::save. Required: '
			. 'title, catid (calendar id, string), start_date (Y-m-d H:i:s), end_date. '
			. 'Optional: alias, description, all_day (0/1), color, url, capacity, '
			. 'max_tickets, state (default 1 = published), featured (0/1), access, '
			. 'access_content, language, rrule (RFC 5545 iCal recurrence string, e.g. '
			. '"FREQ=WEEKLY;COUNT=10"), publish_up, publish_down. Returns the new id.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['title', 'catid', 'start_date', 'end_date'],
			'properties' => [
				'title'          => ['type' => 'string'],
				'alias'          => ['type' => 'string'],
				'catid'          => ['description' => 'Calendar id (string or int).'],
				'start_date'     => ['type' => 'string'],
				'end_date'       => ['type' => 'string'],
				'all_day'        => ['type' => 'integer', 'enum' => [0, 1]],
				'description'    => ['type' => 'string'],
				'color'          => ['type' => 'string'],
				'url'            => ['type' => 'string'],
				'capacity'       => ['type' => 'integer'],
				'max_tickets'    => ['type' => 'integer'],
				'state'          => ['type' => 'integer', 'enum' => [0, 1, 2, -2]],
				'featured'       => ['type' => 'integer', 'enum' => [0, 1]],
				'access'         => ['type' => 'integer'],
				'access_content' => ['type' => 'integer'],
				'language'       => ['type' => 'string'],
				'rrule'          => ['type' => 'string'],
				'publish_up'     => ['type' => 'string'],
				'publish_down'   => ['type' => 'string'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$data = [
			'id' => 0,
			'title'      => $this->requireString($arguments, 'title'),
			'catid'      => (string) $this->requireString($arguments, 'catid'),
			'start_date' => $this->requireString($arguments, 'start_date'),
			'end_date'   => $this->requireString($arguments, 'end_date'),
			'state'      => (int) ($arguments['state'] ?? 1),
			'access'     => (int) ($arguments['access'] ?? 1),
			'access_content' => (int) ($arguments['access_content'] ?? 1),
			'all_day'    => (int) ($arguments['all_day'] ?? 0),
			'featured'   => (int) ($arguments['featured'] ?? 0),
		];
		foreach (['alias', 'description', 'color', 'url', 'language', 'rrule', 'publish_up', 'publish_down'] as $k) {
			if (array_key_exists($k, $arguments)) $data[$k] = (string) $arguments[$k];
		}
		foreach (['capacity', 'max_tickets'] as $k) {
			if (array_key_exists($k, $arguments)) $data[$k] = (int) $arguments[$k];
		}

		$model = $this->getModel('com_dpcalendar', 'Event');
		$out = $this->saveAdminModel($model, $data);
		if ($out['id'] <= 0) {
			return ToolResult::error('Event create failed: ' . ($out['error'] ?: 'no id returned'));
		}
		return ToolResult::json(['ok' => true, 'id' => $out['id'], 'save_warnings' => $out['error'] ?: null]);
	}
}
