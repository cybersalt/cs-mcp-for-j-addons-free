<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class DeleteTicketTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'delete_dpcalendar_ticket'; }

	public function getDescription(): string
	{
		return 'Delete one DPCalendar ticket via TicketModel::delete. Updates the '
			. 'event capacity_used counter per DPCalendar\'s delete logic. Required: id. '
			. 'Note: this does NOT delete the parent booking — use delete_dpcalendar_booking '
			. 'for that.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object', 'required' => ['id'],
			'properties' => ['id' => ['type' => 'integer']],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$model = $this->getModel('com_dpcalendar', 'Ticket');
		$pks = [$id];
		if (!$model->delete($pks)) {
			$err = method_exists($model, 'getError') ? (string) $model->getError() : '';
			return ToolResult::error('Ticket delete failed: ' . ($err ?: 'unknown error'));
		}
		return ToolResult::json(['ok' => true, 'deleted_ticket_id' => $id]);
	}
}
