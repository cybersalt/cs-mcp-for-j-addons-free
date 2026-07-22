<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Extcalendars;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class DeleteExtcalendarTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'delete_dpcalendar_extcalendar'; }

	public function getDescription(): string
	{
		return 'Delete one DPCalendar external calendar via ExtcalendarModel::delete. '
			. 'This detaches the feed — the source calendar (Google, iCloud, etc.) is '
			. 'not touched. Required: id.';
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

		$model = $this->getModel('com_dpcalendar', 'Extcalendar');
		$pks = [$id];
		if (!$model->delete($pks)) {
			$err = method_exists($model, 'getError') ? (string) $model->getError() : '';
			return ToolResult::error('External calendar delete failed: ' . ($err ?: 'unknown error'));
		}
		return ToolResult::json(['ok' => true, 'deleted_extcalendar_id' => $id]);
	}
}
