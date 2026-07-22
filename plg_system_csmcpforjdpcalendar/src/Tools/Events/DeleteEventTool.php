<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Events;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

/**
 * Hard-delete an event via EventModel::delete. Cascades whatever DPCalendar's
 * delete method cascades — junction tables and tickets get cleaned by the
 * model.
 *
 * For a soft delete (recoverable), use set_dpcalendar_event_state with
 * state=-2 (trashed) instead.
 */
final class DeleteEventTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'delete_dpcalendar_event'; }

	public function getDescription(): string
	{
		return 'HARD-DELETE one DPCalendar event via EventModel::delete. Cascades '
			. 'through junction tables and tickets per DPCalendar\'s delete logic. '
			. 'Required: id. For a recoverable delete, use set_dpcalendar_event_state '
			. 'with state=-2 (trashed) instead.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => ['id' => ['type' => 'integer']],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$model = $this->getModel('com_dpcalendar', 'Event');
		$pks = [$id];
		if (!$model->delete($pks)) {
			$err = method_exists($model, 'getError') ? (string) $model->getError() : '';
			return ToolResult::error('Event delete failed: ' . ($err ?: 'unknown error'));
		}

		return ToolResult::json(['ok' => true, 'deleted_event_id' => $id]);
	}
}
