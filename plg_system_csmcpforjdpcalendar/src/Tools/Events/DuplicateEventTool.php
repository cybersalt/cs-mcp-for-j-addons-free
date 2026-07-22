<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Events;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

/**
 * Copy an event to a new row. The new row is created unpublished (state=0)
 * so the operator can review before publishing. Ticket / booking history is
 * NOT copied — only the event definition.
 */
final class DuplicateEventTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'duplicate_dpcalendar_event'; }

	public function getDescription(): string
	{
		return 'Duplicate one DPCalendar event as a new row. The copy is created '
			. 'unpublished (state=0) so it can be reviewed before publishing. '
			. 'Ticket/booking history is NOT copied — only the event definition. '
			. 'Required: id (source event). Optional: title (defaults to "<title> (Copy)"), '
			. 'start_date + end_date to shift the schedule (otherwise dates carry over).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'         => ['type' => 'integer'],
				'title'      => ['type' => 'string'],
				'start_date' => ['type' => 'string'],
				'end_date'   => ['type' => 'string'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$srcId = $this->requirePositiveInt($arguments, 'id');
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$src = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->db->getPrefix() . 'dpcalendar_events'))
				->where($this->db->quoteName('id') . ' = ' . $srcId)
		)->loadAssoc();
		if (!$src) return ToolResult::error('Source event ' . $srcId . ' not found.');

		$data = $src;
		unset($data['id'], $data['uid'], $data['alias'], $data['created'], $data['modified'],
			$data['checked_out'], $data['checked_out_time'], $data['hits'], $data['capacity_used'],
			$data['original_id'], $data['recurrence_id']);
		$data['id']         = 0;
		$data['title']      = (string) ($arguments['title'] ?? ((string) $src['title'] . ' (Copy)'));
		$data['state']      = 0; // unpublished by default
		$data['created_by'] = (int) $actor->id ?: (int) ($src['created_by'] ?? 0);
		if (!empty($arguments['start_date'])) $data['start_date'] = (string) $arguments['start_date'];
		if (!empty($arguments['end_date']))   $data['end_date']   = (string) $arguments['end_date'];

		// Force catid to string per schema.
		if (isset($data['catid'])) $data['catid'] = (string) $data['catid'];

		$model = $this->getModel('com_dpcalendar', 'Event');
		$out = $this->saveAdminModel($model, $data);
		if ($out['id'] <= 0) {
			return ToolResult::error('Duplicate failed: ' . ($out['error'] ?: 'no id returned'));
		}
		return ToolResult::json([
			'ok' => true,
			'new_id' => $out['id'],
			'source_id' => $srcId,
			'new_title' => $data['title'],
			'save_warnings' => $out['error'] ?: null,
		]);
	}
}
