<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Locations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class DeleteLocationTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'delete_dpcalendar_location'; }

	public function getDescription(): string
	{
		return 'Delete one DPCalendar location via LocationModel::delete. Any events '
			. 'currently referencing this location keep their event_location junction '
			. 'rows pointing at a now-missing location id — clean up manually if that '
			. 'matters. Required: id.';
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

		$model = $this->getModel('com_dpcalendar', 'Location');
		$pks = [$id];
		if (!$model->delete($pks)) {
			$err = method_exists($model, 'getError') ? (string) $model->getError() : '';
			return ToolResult::error('Location delete failed: ' . ($err ?: 'unknown error'));
		}
		return ToolResult::json(['ok' => true, 'deleted_location_id' => $id]);
	}
}
