<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Forms;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Joomla\CMS\User\User;

final class DeleteFormTool extends AbstractTool
{
	use ConvertFormsBootTrait;

	public function getName(): string { return 'delete_convertforms_form'; }

	public function getDescription(): string
	{
		return 'Permanently delete a Convert Forms form. This is irreversible. The '
			. 'form\'s submissions are NOT deleted by Convert Forms itself and would '
			. 'be left orphaned, so this tool reports the count and refuses unless '
			. 'you decide: pass delete_submissions=true to remove them too, or '
			. 'keep_submissions=true to knowingly orphan them. Consider '
			. 'set_convertforms_form_state with state=trashed instead — it hides the '
			. 'form from the front end and is reversible.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id' => ['type' => 'integer'],
				'delete_submissions' => [
					'type'        => 'boolean',
					'description' => 'Also delete every submission collected by this form.',
				],
				'keep_submissions' => [
					'type'        => 'boolean',
					'description' => 'Delete the form and deliberately leave its submissions orphaned in the database.',
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

		$id   = $this->requirePositiveInt($arguments, 'id');
		$form = $this->cfFetchForm($id);

		if ($form === null) {
			return ToolResult::error('Convert Forms form ' . $id . ' not found.');
		}

		$submissions = $this->submissionCount($id);

		$deleteSubs = !empty($arguments['delete_submissions']);
		$keepSubs   = !empty($arguments['keep_submissions']);

		if ($submissions > 0 && !$deleteSubs && !$keepSubs) {
			return ToolResult::error(
				'Form ' . $id . ' ("' . $form['name'] . '") has ' . $submissions
				. ' submission(s). Deleting the form does not delete them — they would be '
				. 'left orphaned and unreadable, because the field names needed to '
				. 'interpret them live in the form. Re-run with delete_submissions=true '
				. 'to remove them as well, or keep_submissions=true to orphan them '
				. 'deliberately. To simply take the form offline, use '
				. 'set_convertforms_form_state with state=trashed instead.'
			);
		}

		$deletedSubmissions = 0;
		if ($deleteSubs && $submissions > 0) {
			$deletedSubmissions = $this->deleteSubmissions($id);
		}

		$deletedTasks = $this->deleteTasks($id);

		$table = $this->cfTable('Form');
		if ($table === null) {
			return ToolResult::error('Could not instantiate ConvertFormsTableForm.');
		}

		if (!$table->delete($id)) {
			return ToolResult::error('Form delete failed: ' . ($table->getError() ?: 'unknown error'));
		}

		$this->cfClearFormCache();

		return ToolResult::json([
			'ok'                   => true,
			'deleted_form_id'      => $id,
			'deleted_form_name'    => $form['name'],
			'deleted_submissions'  => $deletedSubmissions,
			'orphaned_submissions' => ($keepSubs && !$deleteSubs) ? $submissions : 0,
			'deleted_tasks'        => $deletedTasks,
		]);
	}

	private function submissionCount(int $formId): int
	{
		if (!$this->cfTableExists('conversions')) {
			return 0;
		}

		$q = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName($this->cfTableName('conversions')))
			->where($this->db->quoteName('form_id') . ' = ' . $formId);

		return (int) $this->db->setQuery($q)->loadResult();
	}

	/**
	 * Remove a form's submissions, preferring the vendor's documented API so any
	 * per-submission cleanup (uploaded files, submission meta) runs.
	 */
	private function deleteSubmissions(int $formId): int
	{
		$before = $this->submissionCount($formId);

		if ($this->cfApiAvailable() && method_exists('\ConvertForms\Api', 'removeFormSubmissions')) {
			try {
				\ConvertForms\Api::removeFormSubmissions($formId);

				return $before - $this->submissionCount($formId);
			} catch (\Throwable $e) {
				// Fall through to the direct delete below.
			}
		}

		$this->db->setQuery(
			$this->db->getQuery(true)
				->delete($this->db->quoteName($this->cfTableName('conversions')))
				->where($this->db->quoteName('form_id') . ' = ' . $formId)
		)->execute();

		return $before;
	}

	/** Remove the form's Tasks rows so the tasks table doesn't accumulate orphans. */
	private function deleteTasks(int $formId): int
	{
		if (!$this->cfTableExists('tasks')) {
			return 0;
		}

		$q = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName($this->cfTableName('tasks')))
			->where($this->db->quoteName('form_id') . ' = ' . $formId);
		$count = (int) $this->db->setQuery($q)->loadResult();

		if ($count > 0) {
			$this->db->setQuery(
				$this->db->getQuery(true)
					->delete($this->db->quoteName($this->cfTableName('tasks')))
					->where($this->db->quoteName('form_id') . ' = ' . $formId)
			)->execute();
		}

		return $count;
	}
}
