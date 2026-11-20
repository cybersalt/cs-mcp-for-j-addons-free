<?php
/**
 * @package     MCP for DPCalendar
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

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
			. '"Active"). Use when a check-in was applied by mistake. Required: id.';
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

	/** A write despite the check_ prefix, which AbstractTool would otherwise mark read-only. */
	public function getMcpAnnotations(): array
	{
		return [
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		];
	}

	protected function run(array $arguments, User $actor): ToolResult
	{
		$this->dpRequireTicket($actor, (int) ($arguments['id'] ?? 0), 'dpcalendar.admin.book');

		$id = $this->requirePositiveInt($arguments, 'id');
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$model = $this->getModel('com_dpcalendar', 'Ticket');
		$out = $this->dpWithSiteRoutes(fn () => $this->saveAdminModel($model, ['id' => $id, 'state' => 1]));
		if ($out['id'] <= 0) {
			return ToolResult::error('Check-out failed: ' . ($this->dpModelError($model, $actor) ?: 'no id returned'));
		}
		return ToolResult::json(['ok' => true, 'id' => $out['id'], 'state' => 1, 'state_label' => 'active']);
	}
}
