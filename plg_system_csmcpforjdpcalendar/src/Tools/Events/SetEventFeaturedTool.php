<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Events;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

/**
 * Toggle featured flag on an event. Goes through EventModel::featured which
 * matches the admin list's featured-star button.
 */
final class SetEventFeaturedTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'set_dpcalendar_event_featured'; }

	public function getDescription(): string
	{
		return 'Set featured flag on one DPCalendar event. Required: id, featured (0/1). '
			. 'Goes through EventModel::featured — same code path as the admin list '
			. 'featured-star toggle. Featured events are surfaced by the frontend event '
			. 'list modules and layouts.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id', 'featured'],
			'properties' => [
				'id'       => ['type' => 'integer'],
				'featured' => ['type' => 'integer', 'enum' => [0, 1]],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');
		$featured = (int) ($arguments['featured'] ?? -1);
		if (!in_array($featured, [0, 1], true)) {
			return ToolResult::error('featured must be 0 or 1.');
		}
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$model = $this->getModel('com_dpcalendar', 'Event');
		if (!method_exists($model, 'featured')) {
			// Fallback: direct SQL if this DPCalendar build doesn't have the method.
			$this->db->setQuery(
				$this->db->getQuery(true)
					->update($this->db->quoteName($this->db->getPrefix() . 'dpcalendar_events'))
					->set($this->db->quoteName('featured') . ' = ' . $featured)
					->where($this->db->quoteName('id') . ' = ' . $id)
			)->execute();
			return ToolResult::json(['ok' => true, 'id' => $id, 'featured' => $featured, 'via' => 'direct_sql_fallback']);
		}

		$pks = [$id];
		if (!$model->featured($pks, $featured)) {
			$err = method_exists($model, 'getError') ? (string) $model->getError() : '';
			return ToolResult::error('featured toggle failed: ' . ($err ?: 'unknown error'));
		}
		return ToolResult::json(['ok' => true, 'id' => $id, 'featured' => $featured]);
	}
}
