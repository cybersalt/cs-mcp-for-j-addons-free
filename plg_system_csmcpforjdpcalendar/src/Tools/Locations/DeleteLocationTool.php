<?php
/**
 * @package     MCP for DPCalendar
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

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
			. 'matters. Required: id. Like DPCalendar\'s own admin, it only deletes a location that is already '
			. 'in the trash: trash it first with update_dpcalendar_location state=-2.';
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
		$this->dpRequireAccess($actor, 'core.delete');

		$id = $this->requirePositiveInt($arguments, 'id');
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();
		if ($refused = $this->dpRequireTrashed('dpcalendar_locations', $id, 'location')) return $refused;

		$model = $this->getModel('com_dpcalendar', 'Location');
		$pks = [$id];
		if (!$model->delete($pks)) {
			$err = $this->dpModelError($model, $actor);
			return ToolResult::error('Location delete failed: ' . ($err ?: 'unknown error'));
		}
		return ToolResult::json(['ok' => true, 'deleted_location_id' => $id]);
	}
}
