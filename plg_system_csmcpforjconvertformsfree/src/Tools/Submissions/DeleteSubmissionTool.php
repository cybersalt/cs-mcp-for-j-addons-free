<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Submissions;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Joomla\CMS\User\User;

final class DeleteSubmissionTool extends AbstractTool
{
	use ConvertFormsBootTrait;

	public function getName(): string { return 'delete_convertforms_submission'; }

	public function getDescription(): string
	{
		return 'Permanently delete Convert Forms submissions. Irreversible — prefer '
			. 'set_convertforms_submission_state with state=trashed, which is '
			. 'reversible. Accepts one id or a list; deleting every submission of a '
			. 'form requires form_id plus confirm=true. Goes through '
			. 'ConvertForms\\Api so the vendor\'s own cleanup (uploaded files, '
			. 'submission meta) runs where that API is available.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'ids' => [
					'description' => 'One submission id, or a list of ids.',
					'oneOf'       => [
						['type' => 'integer'],
						['type' => 'array', 'items' => ['type' => 'integer']],
					],
				],
				'form_id' => [
					'type'        => 'integer',
					'description' => 'Delete EVERY submission of this form. Requires confirm=true.',
				],
				'confirm' => [
					'type'        => 'boolean',
					'description' => 'Required when using form_id.',
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

		if (!$this->cfTableExists('conversions')) {
			return ToolResult::error('#__convertforms_conversions does not exist — the Convert Forms install looks incomplete.');
		}

		$hasIds = array_key_exists('ids', $arguments) && $arguments['ids'] !== null;
		$formId = (int) ($arguments['form_id'] ?? 0);

		if ($hasIds && $formId > 0) {
			return ToolResult::error('Pass either ids or form_id, not both.');
		}
		if (!$hasIds && $formId <= 0) {
			return ToolResult::error('Pass ids (one id or a list) or form_id.');
		}

		if ($formId > 0) {
			return $this->deleteWholeForm($formId, !empty($arguments['confirm']));
		}

		$raw = $arguments['ids'];
		$ids = array_values(array_filter(array_map('intval', is_array($raw) ? $raw : [$raw]), static fn($i) => $i > 0));

		if ($ids === []) {
			return ToolResult::error('ids must contain at least one positive submission id.');
		}

		$found = array_map('intval', $this->db->setQuery(
			$this->db->getQuery(true)
				->select('id')
				->from($this->db->quoteName($this->cfTableName('conversions')))
				->whereIn($this->db->quoteName('id'), $ids)
		)->loadColumn() ?: []);

		$missing = array_values(array_diff($ids, $found));
		$deleted = [];

		foreach ($found as $id) {
			if ($this->deleteOne($id)) {
				$deleted[] = $id;
			}
		}

		$failed = array_values(array_diff($found, $deleted));

		return ToolResult::json([
			'ok'            => $missing === [] && $failed === [],
			'deleted'       => $deleted,
			'deleted_count' => count($deleted),
			'not_found'     => $missing,
			'failed'        => $failed,
		]);
	}

	private function deleteWholeForm(int $formId, bool $confirmed): ToolResult
	{
		$total = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName($this->cfTableName('conversions')))
				->where($this->db->quoteName('form_id') . ' = ' . $formId)
		)->loadResult();

		if ($total === 0) {
			return ToolResult::error('Form ' . $formId . ' has no submissions (or does not exist).');
		}

		if (!$confirmed) {
			$form = $this->cfFetchForm($formId);

			return ToolResult::error(
				'This would permanently delete all ' . $total . ' submission(s) of form '
				. $formId . ($form === null ? '' : ' ("' . $form['name'] . '")')
				. '. Re-run with confirm=true if that is intended, or use '
				. 'set_convertforms_submission_state with form_id and state=trashed for a reversible alternative.'
			);
		}

		if ($this->cfApiAvailable() && method_exists('\ConvertForms\Api', 'removeFormSubmissions')) {
			try {
				\ConvertForms\Api::removeFormSubmissions($formId);
			} catch (\Throwable $e) {
				$this->rawDeleteByForm($formId);
			}
		} else {
			$this->rawDeleteByForm($formId);
		}

		$remaining = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName($this->cfTableName('conversions')))
				->where($this->db->quoteName('form_id') . ' = ' . $formId)
		)->loadResult();

		return ToolResult::json([
			'ok'            => $remaining === 0,
			'form_id'       => $formId,
			'deleted_count' => $total - $remaining,
			'remaining'     => $remaining,
		]);
	}

	/**
	 * Delete one submission, preferring the vendor API so file/meta cleanup runs.
	 */
	private function deleteOne(int $id): bool
	{
		if ($this->cfApiAvailable() && method_exists('\ConvertForms\Api', 'removeSubmission')) {
			try {
				\ConvertForms\Api::removeSubmission($id);

				if (!$this->exists($id)) {
					return true;
				}
			} catch (\Throwable $e) {
				// Fall through.
			}
		}

		$table = $this->cfTable('Conversion');
		if ($table !== null && $table->delete($id)) {
			$this->deleteMeta($id);

			return true;
		}

		return false;
	}

	private function exists(int $id): bool
	{
		$q = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName($this->cfTableName('conversions')))
			->where($this->db->quoteName('id') . ' = ' . $id);

		return (int) $this->db->setQuery($q)->loadResult() > 0;
	}

	private function rawDeleteByForm(int $formId): void
	{
		$ids = array_map('intval', $this->db->setQuery(
			$this->db->getQuery(true)
				->select('id')
				->from($this->db->quoteName($this->cfTableName('conversions')))
				->where($this->db->quoteName('form_id') . ' = ' . $formId)
		)->loadColumn() ?: []);

		$this->db->setQuery(
			$this->db->getQuery(true)
				->delete($this->db->quoteName($this->cfTableName('conversions')))
				->where($this->db->quoteName('form_id') . ' = ' . $formId)
		)->execute();

		foreach ($ids as $id) {
			$this->deleteMeta($id);
		}
	}

	/** Drop the submission's meta rows so the side table doesn't accumulate orphans. */
	private function deleteMeta(int $submissionId): void
	{
		if (!$this->cfTableExists('submission_meta')) {
			return;
		}

		$this->db->setQuery(
			$this->db->getQuery(true)
				->delete($this->db->quoteName($this->cfTableName('submission_meta')))
				->where($this->db->quoteName('submission_id') . ' = ' . $submissionId)
		)->execute();
	}
}
