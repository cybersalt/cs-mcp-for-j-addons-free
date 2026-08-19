<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Tasks;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Joomla\CMS\User\User;

final class DeleteTaskTool extends AbstractTool
{
	use ConvertFormsBootTrait;

	public function getName(): string { return 'delete_convertforms_task'; }

	public function getDescription(): string
	{
		return 'Permanently delete Convert Forms Tasks. Deleting a form\'s only email '
			. 'task means submissions stop being notified to anyone, so that case is '
			. 'flagged and requires confirmation. To pause a task instead, use '
			. 'set_convertforms_task_state with enabled=false — reversible and keeps '
			. 'the configuration.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['ids'],
			'properties' => [
				'ids' => [
					'description' => 'One task id, or a list of ids.',
					'oneOf'       => [
						['type' => 'integer'],
						['type' => 'array', 'items' => ['type' => 'integer']],
					],
				],
				'confirm' => [
					'type'        => 'boolean',
					'description' => 'Required when this would leave a form with no enabled notification task.',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ensureCfLoaded()) {
			return $this->notInstalledError();
		}

		if (!$this->cfTableExists('tasks')) {
			return ToolResult::error('#__convertforms_tasks does not exist (the Tasks engine arrived in Convert Forms 5.0).');
		}

		$raw = $arguments['ids'] ?? null;
		$ids = array_values(array_filter(array_map('intval', is_array($raw) ? $raw : [$raw]), static fn($i) => $i > 0));

		if ($ids === []) {
			return ToolResult::error('ids is required and must contain at least one positive task id.');
		}

		$table = $this->cfTableName('tasks');

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select(['id', 'form_id', 'title', 'app', 'state'])
				->from($this->db->quoteName($table))
				->whereIn($this->db->quoteName('id'), $ids)
		)->loadAssocList() ?: [];

		$found   = array_map(static fn($r) => (int) $r['id'], $rows);
		$missing = array_values(array_diff($ids, $found));

		if ($found === []) {
			return ToolResult::error('No matching tasks found for ids: ' . implode(', ', $ids) . '.');
		}

		// Warn when a form is about to lose its last enabled email task — the
		// form keeps working, but nobody is told about submissions any more.
		if (empty($arguments['confirm'])) {
			$stranded = $this->formsLosingLastEmailTask($rows, $found);

			if ($stranded !== []) {
				return ToolResult::error(
					'This would remove the last enabled email notification task from form(s) '
					. implode(', ', $stranded) . '. Submissions would still be recorded, but nobody '
					. 'would be emailed about them. Re-run with confirm=true if that is intended, '
					. 'or use set_convertforms_task_state with enabled=false to pause instead.'
				);
			}
		}

		$deleted = [];
		$failed  = [];

		foreach ($found as $id) {
			$taskTable = $this->cfTable('Task');

			if ($taskTable !== null && $taskTable->delete($id)) {
				$deleted[] = $id;
				continue;
			}

			$failed[] = $id;
		}

		if ($failed !== []) {
			// Table::delete() can refuse for reasons unrelated to the row itself;
			// fall back to a direct delete so a partial failure isn't left half-done.
			$this->db->setQuery(
				$this->db->getQuery(true)
					->delete($this->db->quoteName($table))
					->whereIn($this->db->quoteName('id'), $failed)
			)->execute();

			$stillThere = array_map('intval', $this->db->setQuery(
				$this->db->getQuery(true)
					->select('id')
					->from($this->db->quoteName($table))
					->whereIn($this->db->quoteName('id'), $failed)
			)->loadColumn() ?: []);

			$deleted = array_merge($deleted, array_values(array_diff($failed, $stillThere)));
			$failed  = $stillThere;
		}

		$this->deleteHistory($deleted);

		sort($deleted);

		return ToolResult::json([
			'ok'            => $missing === [] && $failed === [],
			'deleted'       => $deleted,
			'deleted_count' => count($deleted),
			'not_found'     => $missing,
			'failed'        => $failed,
		]);
	}

	/**
	 * Form ids that would end up with no enabled email task once $doomed are gone.
	 *
	 * @param array<int, array<string, mixed>> $rows  The rows being deleted.
	 * @param array<int, int>                  $doomed
	 * @return array<int, int>
	 */
	private function formsLosingLastEmailTask(array $rows, array $doomed): array
	{
		$formIds = [];
		foreach ($rows as $row) {
			if (strtolower((string) $row['app']) === 'email') {
				$formIds[(int) $row['form_id']] = true;
			}
		}

		if ($formIds === []) {
			return [];
		}

		$stranded = [];

		foreach (array_keys($formIds) as $formId) {
			$q = $this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName($this->cfTableName('tasks')))
				->where($this->db->quoteName('form_id') . ' = ' . $formId)
				->where($this->db->quoteName('app') . ' = ' . $this->db->quote('email'))
				->where($this->db->quoteName('state') . ' = 1')
				->whereNotIn($this->db->quoteName('id'), $doomed);

			if ((int) $this->db->setQuery($q)->loadResult() === 0) {
				$stranded[] = $formId;
			}
		}

		return $stranded;
	}

	/** @param array<int, int> $taskIds */
	private function deleteHistory(array $taskIds): void
	{
		if ($taskIds === [] || !$this->cfTableExists('tasks_history')) {
			return;
		}

		$this->db->setQuery(
			$this->db->getQuery(true)
				->delete($this->db->quoteName($this->cfTableName('tasks_history')))
				->whereIn($this->db->quoteName('task_id'), $taskIds)
		)->execute();
	}
}
