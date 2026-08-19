<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Tasks;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Joomla\CMS\User\User;

final class ListTaskHistoryTool extends AbstractTool
{
	use ConvertFormsBootTrait;

	public function getName(): string { return 'list_convertforms_task_history'; }

	public function getDescription(): string
	{
		return 'Task execution log (#__convertforms_tasks_history): every run of a '
			. 'Convert Forms Task with its success flag, error text and duration. '
			. 'This is the place to look when a form\'s notification email did not '
			. 'arrive — filter with success=false to see only failures.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'task_id' => ['type' => 'integer', 'description' => 'Restrict to one task.'],
				'form_id' => ['type' => 'integer', 'description' => 'Restrict to all tasks of one form.'],
				'success' => ['type' => 'boolean', 'description' => 'true = only successful runs, false = only failures.'],
				'include_payload' => ['type' => 'boolean', 'description' => 'Include the request payload sent to the app. Default false (verbose, may contain submitted data).'],
				'limit'   => ['type' => 'integer'],
				'offset'  => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->cfAdminBase() === null) {
			return $this->notInstalledError();
		}

		if (!$this->cfTableExists('tasks_history')) {
			return ToolResult::error('#__convertforms_tasks_history does not exist (the Tasks engine arrived in Convert Forms 5.0).');
		}

		$history = $this->cfTableName('tasks_history');
		$tasks   = $this->cfTableName('tasks');

		$q = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('h.id'),
				$this->db->quoteName('h.task_id'),
				$this->db->quoteName('h.created'),
				$this->db->quoteName('h.success'),
				$this->db->quoteName('h.errors'),
				$this->db->quoteName('h.execution_time'),
				$this->db->quoteName('h.ref_type'),
				$this->db->quoteName('h.ref_id'),
				$this->db->quoteName('t.title', 'task_title'),
				$this->db->quoteName('t.app'),
				$this->db->quoteName('t.action'),
				$this->db->quoteName('t.form_id'),
			])
			->from($this->db->quoteName($history, 'h'))
			->join('LEFT', $this->db->quoteName($tasks, 't') . ' ON ' . $this->db->quoteName('t.id') . ' = ' . $this->db->quoteName('h.task_id'))
			->order($this->db->quoteName('h.id') . ' DESC');

		if (!empty($arguments['include_payload'])) {
			$q->select($this->db->quoteName('h.payload'));
		}

		$taskId = (int) ($arguments['task_id'] ?? 0);
		if ($taskId > 0) {
			$q->where($this->db->quoteName('h.task_id') . ' = ' . $taskId);
		}

		$formId = (int) ($arguments['form_id'] ?? 0);
		if ($formId > 0) {
			$q->where($this->db->quoteName('t.form_id') . ' = ' . $formId);
		}

		if (array_key_exists('success', $arguments) && $arguments['success'] !== null) {
			$q->where($this->db->quoteName('h.success') . ' = ' . (!empty($arguments['success']) ? 1 : 0));
		}

		$limit  = $this->cfLimit($arguments);
		$offset = $this->cfOffset($arguments);

		$this->db->setQuery($q, $offset, $limit);
		$rows = $this->db->loadAssocList() ?: [];

		$entries  = [];
		$failures = 0;

		foreach ($rows as $row) {
			$success = (int) $row['success'] === 1;
			if (!$success) {
				$failures++;
			}

			$entry = [
				'id'             => (int) $row['id'],
				'task_id'        => (int) $row['task_id'],
				'task_title'     => $row['task_title'],
				'app'            => $row['app'],
				'action'         => $row['action'],
				'form_id'        => $row['form_id'] === null ? null : (int) $row['form_id'],
				'created'        => (string) $row['created'],
				'success'        => $success,
				'errors'         => $row['errors'] !== '' ? $row['errors'] : null,
				'execution_time' => $row['execution_time'] === null ? null : (float) $row['execution_time'],
				'ref_type'       => $row['ref_type'],
				'ref_id'         => $row['ref_id'] === null ? null : (int) $row['ref_id'],
			];

			if ($row['task_title'] === null) {
				$entry['note'] = 'The task this run belongs to has since been deleted.';
			}

			if (array_key_exists('payload', $row)) {
				$entry['payload'] = $this->cfDecodeParams($row['payload']) ?: $row['payload'];
			}

			$entries[] = $entry;
		}

		$countQuery = clone $q;
		$countQuery->clear('select')->clear('order')->select('COUNT(*)');
		$total = (int) $this->db->setQuery($countQuery)->loadResult();

		return ToolResult::json([
			'ok'              => true,
			'count'           => count($entries),
			'total_matching'  => $total,
			'failures_in_page' => $failures,
			'limit'           => $limit,
			'offset'          => $offset,
			'history'         => $entries,
			'hint'            => 'ref_id is the submission id that triggered the run — pass it to get_convertforms_submission.',
		]);
	}
}
