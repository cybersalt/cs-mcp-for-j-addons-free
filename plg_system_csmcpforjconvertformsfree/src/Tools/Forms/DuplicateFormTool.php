<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Forms;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Joomla\CMS\User\User;

final class DuplicateFormTool extends AbstractTool
{
	use ConvertFormsBootTrait;

	public function getName(): string { return 'duplicate_convertforms_form'; }

	public function getDescription(): string
	{
		return 'Duplicate a Convert Forms form, including its fields and its Tasks '
			. '(email notifications and app integrations) — the copy runs through '
			. 'ConvertFormsModelForm::copy(), which fires onConvertFormsDuplicate so '
			. 'the Tasks plugin clones the task rows too. Submissions are NOT copied. '
			. 'The copy is created unpublished; optionally rename it in the same call.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'   => ['type' => 'integer', 'description' => 'Form id to duplicate.'],
				'name' => [
					'type'        => 'string',
					'description' => 'Name for the copy. Default is Convert Forms\' own "Copy of <name>".',
				],
				'publish' => [
					'type'        => 'boolean',
					'description' => 'Publish the copy immediately. Default false (copies are created unpublished).',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	/**
	 * "duplicate_" is not an auto-classified prefix. Each call creates another
	 * copy, so it is emphatically not idempotent — an MCP client must not retry
	 * this one blindly after a timeout.
	 */
	public function getMcpAnnotations(): array
	{
		return [
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		];
	}

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ensureCfLoaded()) {
			return $this->notInstalledError();
		}

		$id     = $this->requirePositiveInt($arguments, 'id');
		$source = $this->cfFetchForm($id);

		if ($source === null) {
			return ToolResult::error('Convert Forms form ' . $id . ' not found.');
		}

		$model = $this->cfModel('Form');
		if ($model === null || !method_exists($model, 'copy')) {
			return ToolResult::error('This Convert Forms build does not expose ConvertFormsModelForm::copy().');
		}

		$before = $this->maxFormId();

		$ok = (bool) $model->copy($id);

		// copy() returns save()'s boolean, which is false whenever any post-save
		// plugin throws even though the row was written. The assigned state id is
		// the truthful signal — same reasoning as AbstractTool::saveAdminModel().
		$newId = (int) $model->getState('form.id');

		if ($newId <= 0 || $newId === $id) {
			// Last resort: a brand-new row will have an id above the previous max.
			$after = $this->maxFormId();
			$newId = $after > $before ? $after : 0;
		}

		if ($newId <= 0) {
			return ToolResult::error(
				'Form duplicate failed: ' . (method_exists($model, 'getError') ? (string) $model->getError() : '')
				?: 'no new id returned'
			);
		}

		$rename    = array_key_exists('name', $arguments) ? trim((string) $arguments['name']) : '';
		$publish   = !empty($arguments['publish']);
		$updates   = [];

		if ($rename !== '') {
			$updates[$this->db->quoteName('name')] = $this->db->quote($rename);
		}
		if ($publish) {
			$updates[$this->db->quoteName('state')] = '1';
		}

		if ($updates !== []) {
			$q = $this->db->getQuery(true)->update($this->db->quoteName($this->cfTableName()));
			foreach ($updates as $col => $val) {
				$q->set($col . ' = ' . $val);
			}
			$q->where($this->db->quoteName('id') . ' = ' . $newId);
			$this->db->setQuery($q)->execute();
		}

		$this->cfClearFormCache();

		$copy  = $this->cfFetchForm($newId);
		$tasks = $this->taskCount($newId);

		return ToolResult::json([
			'ok'              => true,
			'source_form_id'  => $id,
			'new_form_id'     => $newId,
			'name'            => $copy['name'] ?? null,
			'state'           => $copy['state'] ?? null,
			'state_label'     => $copy === null ? null : $this->contentStateLabel($copy['state']),
			'field_count'     => $copy === null ? null : count($this->cfFields($copy['params'])),
			'tasks_copied'    => $tasks,
			'submissions_copied' => 0,
			'model_reported_success' => $ok,
		]);
	}

	private function maxFormId(): int
	{
		$q = $this->db->getQuery(true)
			->select('MAX(' . $this->db->quoteName('id') . ')')
			->from($this->db->quoteName($this->cfTableName()));

		return (int) $this->db->setQuery($q)->loadResult();
	}

	private function taskCount(int $formId): int
	{
		if (!$this->cfTableExists('tasks')) {
			return 0;
		}

		$q = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName($this->cfTableName('tasks')))
			->where($this->db->quoteName('form_id') . ' = ' . $formId);

		return (int) $this->db->setQuery($q)->loadResult();
	}
}
