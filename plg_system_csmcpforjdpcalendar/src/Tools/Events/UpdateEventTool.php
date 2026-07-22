<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Events;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

/**
 * Partial-update one event through EventModel::save.
 */
final class UpdateEventTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'update_dpcalendar_event'; }

	public function getDescription(): string
	{
		return 'Update one DPCalendar event via EventModel::save. Required: id. Any of: '
			. 'title, alias, catid, start_date, end_date, all_day, description, color, '
			. 'url, capacity, max_tickets, state, featured, access, access_content, '
			. 'language, rrule, publish_up, publish_down. Only supplied fields are '
			. 'overwritten; the rest of the row is left as-is (DPCalendar\'s save loads '
			. 'the existing record then merges).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'             => ['type' => 'integer'],
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
		$id = $this->requirePositiveInt($arguments, 'id');
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$data = ['id' => $id];
		foreach (['title', 'alias', 'description', 'color', 'url', 'language', 'rrule', 'publish_up', 'publish_down', 'start_date', 'end_date'] as $k) {
			if (array_key_exists($k, $arguments)) $data[$k] = (string) $arguments[$k];
		}
		if (array_key_exists('catid', $arguments)) $data['catid'] = (string) $arguments['catid'];
		foreach (['all_day', 'state', 'featured', 'access', 'access_content', 'capacity', 'max_tickets'] as $k) {
			if (array_key_exists($k, $arguments)) $data[$k] = (int) $arguments[$k];
		}
		if (count($data) === 1) {
			return ToolResult::error('Nothing to update — supply at least one field besides id.');
		}

		$model = $this->getModel('com_dpcalendar', 'Event');
		$out = $this->saveAdminModel($model, $data);
		if ($out['id'] <= 0) {
			return ToolResult::error('Event update failed: ' . ($out['error'] ?: 'no id returned'));
		}
		return ToolResult::json(['ok' => true, 'id' => $out['id'], 'fields_updated' => array_keys($data), 'save_warnings' => $out['error'] ?: null]);
	}
}
