<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Calendars;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class GetCalendarTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'get_dpcalendar_calendar'; }

	public function getDescription(): string
	{
		return 'Get one DPCalendar calendar (category row + its DPCalendar-specific params '
			. '+ event count + published/unpublished breakdown). Required: id (category id).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => ['id' => ['type' => 'integer']],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$p  = $this->db->getPrefix();

		$cat = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($p . 'categories'))
				->where($this->db->quoteName('id') . ' = ' . $id)
				->where($this->db->quoteName('extension') . ' = ' . $this->db->quote('com_dpcalendar'))
		)->loadAssoc();

		if (!$cat) return ToolResult::error('DPCalendar calendar ' . $id . ' not found.');

		foreach (['id', 'parent_id', 'level', 'published', 'access', 'lft', 'rgt', 'checked_out', 'metadata_id', 'note_id'] as $k) {
			if (array_key_exists($k, $cat)) $cat[$k] = $cat[$k] === null ? null : (int) $cat[$k];
		}
		$cat['published_label'] = $this->contentStateLabel((int) $cat['published']);

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([$this->db->quoteName('state'), 'COUNT(*) AS n'])
				->from($this->db->quoteName($p . 'dpcalendar_events'))
				->where($this->db->quoteName('catid') . ' = ' . $this->db->quote((string) $id))
				->group($this->db->quoteName('state'))
		)->loadAssocList('state') ?: [];
		$cat['event_counts'] = [
			'total'       => (int) ($rows['1']['n'] ?? 0) + (int) ($rows['0']['n'] ?? 0) + (int) ($rows['-2']['n'] ?? 0) + (int) ($rows['2']['n'] ?? 0),
			'published'   => (int) ($rows['1']['n'] ?? 0),
			'unpublished' => (int) ($rows['0']['n'] ?? 0),
			'archived'    => (int) ($rows['2']['n'] ?? 0),
			'trashed'     => (int) ($rows['-2']['n'] ?? 0),
		];

		return ToolResult::json(['ok' => true, 'calendar' => $cat]);
	}
}
