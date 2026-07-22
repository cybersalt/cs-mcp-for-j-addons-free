<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

/**
 * Check in a ticket — set state=3 (checked_in). Uses TicketModel::save so
 * DPCalendar's plugins (notification, gamification, etc.) fire on the
 * transition.
 */
final class CheckInTicketTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'check_in_dpcalendar_ticket'; }

	public function getDescription(): string
	{
		return 'Check in one DPCalendar ticket (sets state=3, "checked_in"). Required: '
			. 'id (ticket id). Uses TicketModel::save so DPCalendar\'s state-transition '
			. 'plugins fire.';
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
		$out = $this->saveAdminModel($model, ['id' => $id, 'state' => 3]);
		if ($out['id'] <= 0) {
			return ToolResult::error('Check-in failed: ' . ($out['error'] ?: 'no id returned'));
		}
		return ToolResult::json(['ok' => true, 'id' => $out['id'], 'state' => 3, 'state_label' => 'checked_in']);
	}
}
