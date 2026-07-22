<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Events;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

/**
 * Set event state via EventModel::publish — the same code path used by the
 * admin list view's toolbar buttons (publish / unpublish / archive / trash).
 */
final class SetEventStateTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'set_dpcalendar_event_state'; }

	public function getDescription(): string
	{
		return 'Set the state of one DPCalendar event via EventModel::publish (same '
			. 'code path as the admin toolbar). Required: id, state (1=published, '
			. '0=unpublished, 2=archived, -2=trashed). Also accepts the friendly '
			. 'state string (published / unpublished / archived / trashed).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id', 'state'],
			'properties' => [
				'id'    => ['type' => 'integer'],
				'state' => ['description' => '1/0/2/-2 or published/unpublished/archived/trashed'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');
		$state = $this->normaliseContentStateFilter($arguments['state'] ?? null);
		if ($state === null) return ToolResult::error('state must be one of 1/0/2/-2 or published/unpublished/archived/trashed.');
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$model = $this->getModel('com_dpcalendar', 'Event');
		$pks = [$id];
		if (!$model->publish($pks, $state)) {
			$err = method_exists($model, 'getError') ? (string) $model->getError() : '';
			return ToolResult::error('publish failed: ' . ($err ?: 'unknown error'));
		}

		return ToolResult::json([
			'ok' => true,
			'id' => $id,
			'state' => $state,
			'state_label' => $this->contentStateLabel($state),
		]);
	}
}
