<?php
/**
 * @package     MCP for DPCalendar
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Events;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\Component\ComponentHelper;
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
		// Copying an event means reading all of it: Edit (or Edit Own) on its calendar, as opening it in
		// DPCalendar's editor needs, plus Create there for the copy.
		$this->dpRequireEvent($actor, (int) ($arguments['id'] ?? 0), 'core.edit');
		$this->dpRequireEvent($actor, (int) ($arguments['id'] ?? 0), 'core.create');

		$srcId = $this->requirePositiveInt($arguments, 'id');
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$src = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->db->getPrefix() . 'dpcalendar_events'))
				->where($this->db->quoteName('id') . ' = ' . $srcId)
		)->loadAssoc();
		if (!$src) return ToolResult::error('Source event ' . $srcId . ' not found.');
		if (!$this->dpSeesAllLevels($actor) && !in_array((int) $src['access'], $this->dpViewLevels($actor), true)) {
			return ToolResult::error('Source event ' . $srcId . ' not found.');
		}

		$data = $src;
		unset($data['id'], $data['uid'], $data['alias'], $data['created'], $data['modified'],
			$data['checked_out'], $data['checked_out_time'], $data['hits'], $data['capacity_used'],
			$data['original_id'], $data['recurrence_id'], $data['xreference']);
		// The copy gets no online meeting of its own: the Zoom plugin keeps the meeting id and passwords in params.
		$params = json_decode((string) ($data['params'] ?? ''), true);
		if (is_array($params)) {
			foreach (array_keys($params) as $k) {
				if (str_starts_with((string) $k, 'zoom_') || str_starts_with((string) $k, 'jitsi_')) unset($params[$k]);
			}
			$data['params'] = json_encode($params);
		}
		$data['id']         = 0;
		$data['title']      = (string) ComponentHelper::filterText((string) ($arguments['title'] ?? ((string) $src['title'] . ' (Copy)')));
		$data['state']      = 0; // unpublished by default
		$data['created_by'] = (int) $actor->id ?: (int) ($src['created_by'] ?? 0);
		if (!empty($arguments['start_date'])) $data['start_date'] = (string) $arguments['start_date'];
		if (!empty($arguments['end_date']))   $data['end_date']   = (string) $arguments['end_date'];

		// Force catid to string per schema.
		if (isset($data['catid'])) $data['catid'] = (string) $data['catid'];

		$model = $this->getModel('com_dpcalendar', 'Event');
		$out = $this->saveAdminModel($model, $data);
		$error = $this->dpModelError($model, $actor);
		if ($out['id'] <= 0) {
			return ToolResult::error('Duplicate failed: ' . ($error ?: 'no id returned'));
		}
		return ToolResult::json([
			'ok' => true,
			'new_id' => $out['id'],
			'source_id' => $srcId,
			'new_title' => $data['title'],
			'save_warnings' => $error ?: null,
		]);
	}
}
