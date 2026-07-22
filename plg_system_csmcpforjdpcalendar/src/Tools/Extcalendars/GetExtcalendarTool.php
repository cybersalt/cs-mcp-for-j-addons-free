<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Extcalendars;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class GetExtcalendarTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'get_dpcalendar_extcalendar'; }

	public function getDescription(): string
	{
		return 'Get one DPCalendar external calendar (sync feed) with its full params '
			. '(feed URL, auth credentials, etc. — stored in the params JSON). '
			. 'Required: id.';
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

		$row = $this->db->setQuery(
			$this->db->getQuery(true)->select('*')
				->from($this->db->quoteName($this->db->getPrefix() . 'dpcalendar_extcalendars'))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();
		if (!$row) return ToolResult::error('External calendar ' . $id . ' not found.');

		foreach (['id', 'asset_id', 'color_force', 'state', 'ordering', 'created_by', 'version',
			'modified_by', 'access', 'access_content'] as $k) {
			if (array_key_exists($k, $row)) $row[$k] = $row[$k] === null ? null : (int) $row[$k];
		}
		$row['state_label'] = $this->contentStateLabel((int) $row['state']);
		return ToolResult::json(['ok' => true, 'extcalendar' => $row]);
	}
}
