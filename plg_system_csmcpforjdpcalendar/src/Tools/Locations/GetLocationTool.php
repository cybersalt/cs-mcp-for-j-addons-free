<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Locations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class GetLocationTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'get_dpcalendar_location'; }

	public function getDescription(): string
	{
		return 'Get one DPCalendar location with its event usage count. Required: id.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object', 'required' => ['id'],
			'properties' => ['id' => ['type' => 'integer']],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$p = $this->db->getPrefix();
		$loc = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($p . 'dpcalendar_locations'))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();
		if (!$loc) return ToolResult::error('Location ' . $id . ' not found.');

		foreach (['id', 'country', 'state', 'checked_out', 'ordering', 'created_by', 'version', 'modified_by'] as $k) {
			if (array_key_exists($k, $loc)) $loc[$k] = $loc[$k] === null ? null : (int) $loc[$k];
		}
		foreach (['latitude', 'longitude'] as $k) if (array_key_exists($k, $loc) && $loc[$k] !== null) $loc[$k] = (float) $loc[$k];
		$loc['state_label'] = $this->contentStateLabel((int) $loc['state']);

		$loc['events_using_count'] = (int) $this->db->setQuery(
			$this->db->getQuery(true)->select('COUNT(*)')
				->from($this->db->quoteName($p . 'dpcalendar_events_location'))
				->where($this->db->quoteName('location_id') . ' = ' . $id)
		)->loadResult();

		return ToolResult::json(['ok' => true, 'location' => $loc]);
	}
}
