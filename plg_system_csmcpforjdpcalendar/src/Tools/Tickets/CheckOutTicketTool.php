<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

/**
 * Undo a check-in — set state back to 1 (confirmed). Use this when a
 * check-in was applied by mistake.
 */
final class CheckOutTicketTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'check_out_dpcalendar_ticket'; }

	public function getDescription(): string
	{
		return 'Undo a check-in on one DPCalendar ticket (sets state back to 1, '
			. '"confirmed"). Use when a check-in was applied by mistake. Required: id.';
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
		$out = $this->saveAdminModel($model, ['id' => $id, 'state' => 1]);
		if ($out['id'] <= 0) {
			return ToolResult::error('Check-out failed: ' . ($out['error'] ?: 'no id returned'));
		}
		return ToolResult::json(['ok' => true, 'id' => $out['id'], 'state' => 1, 'state_label' => 'confirmed']);
	}
}
