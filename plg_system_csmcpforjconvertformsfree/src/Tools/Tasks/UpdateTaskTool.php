<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Tasks;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Joomla\CMS\User\User;

final class UpdateTaskTool extends AbstractTool
{
	use ConvertFormsBootTrait;

	public function getName(): string { return 'update_convertforms_task'; }

	public function getDescription(): string
	{
		return 'Update a Convert Forms Task: its title, enabled state, options '
			. '(email recipient, subject, body, …) or conditions. The options patch '
			. 'is MERGED into the existing options — send only what you want to '
			. 'change, and null to remove a key. Pass replace_options=true to '
			. 'overwrite the whole object instead.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'      => ['type' => 'integer', 'description' => 'Task id.'],
				'title'   => ['type' => 'string'],
				'enabled' => ['type' => 'boolean'],
				'options' => ['type' => 'object', 'description' => 'Merge patch over the task options. null removes a key.'],
				'replace_options' => ['type' => 'boolean', 'description' => 'Replace options wholesale instead of merging. Default false.'],
				'conditions' => ['type' => 'object', 'description' => 'Replace the condition sets. Pass {} to make the task unconditional.'],
				'connection_id' => ['type' => 'integer'],
				'silentfail' => ['type' => 'boolean'],
				'action'  => ['type' => 'string'],
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

		$id = $this->requirePositiveInt($arguments, 'id');

		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->cfTableName('tasks')))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();

		if (!$row) {
			return ToolResult::error('Convert Forms task ' . $id . ' not found.');
		}

		$options    = $this->cfDecodeParams($row['options']);
		$conditions = $this->cfDecodeParams($row['conditions']);

		$touched = [];

		if (array_key_exists('options', $arguments) && is_array($arguments['options'])) {
			$options = !empty($arguments['replace_options'])
				? $arguments['options']
				: $this->cfMergeParams($options, $arguments['options']);
			$touched[] = 'options';
		}

		if (array_key_exists('conditions', $arguments) && is_array($arguments['conditions'])) {
			$conditions = $arguments['conditions'];
			$touched[]  = 'conditions';
		}

		if (strtolower((string) $row['app']) === 'email' && in_array('options', $touched, true)) {
			$recipient = trim((string) ($options['recipient'] ?? $options['to'] ?? ''));
			if ($recipient === '') {
				return ToolResult::error(
					'This is an email task and the patch leaves it with no recipient, so it '
					. 'would stop sending. Set options.recipient, or delete the task if that is the intent.'
				);
			}
		}

		// Rebuild the full row. ConvertFormsTableTask::check() unconditionally
		// json_encodes $this->options, so options MUST be handed over as an array
		// — binding the JSON string straight off the loaded row would encode it a
		// second time and the task's configuration would become an opaque string.
		$data = [
			'id'            => $id,
			'form_id'       => (int) $row['form_id'],
			'title'         => array_key_exists('title', $arguments)
				? $this->requireString($arguments, 'title')
				: (string) $row['title'],
			'state'         => array_key_exists('enabled', $arguments)
				? (!empty($arguments['enabled']) ? 1 : 0)
				: (int) $row['state'],
			'action'        => array_key_exists('action', $arguments)
				? strtolower(trim((string) $arguments['action']))
				: (string) $row['action'],
			'app'           => (string) $row['app'],
			'trigger'       => (string) $row['trigger'],
			'connection_id' => array_key_exists('connection_id', $arguments)
				? (int) $arguments['connection_id']
				: ($row['connection_id'] === null ? null : (int) $row['connection_id']),
			'options'       => $options,
			'conditions'    => $conditions,
			'silentfail'    => array_key_exists('silentfail', $arguments)
				? (!empty($arguments['silentfail']) ? 1 : 0)
				: (int) $row['silentfail'],
			'ordering'      => (int) $row['ordering'],
		];

		foreach (['title', 'enabled', 'action', 'connection_id', 'silentfail'] as $key) {
			if (array_key_exists($key, $arguments)) {
				$touched[] = $key;
			}
		}

		if ($touched === []) {
			return ToolResult::error('Nothing to change — supply at least one of title, enabled, options, conditions, connection_id, silentfail or action.');
		}

		$table = $this->cfTable('Task');
		if ($table === null) {
			return ToolResult::error('Could not instantiate ConvertFormsTableTask.');
		}

		if (!$table->bind($data)) {
			return ToolResult::error('Task update failed at bind: ' . $table->getError());
		}
		if (!$table->check()) {
			return ToolResult::error('Task update failed at check: ' . $table->getError());
		}
		if (!$table->store()) {
			return ToolResult::error('Task update failed at store: ' . $table->getError());
		}

		return ToolResult::json([
			'ok'      => true,
			'id'      => $id,
			'form_id' => $data['form_id'],
			'app'     => $data['app'],
			'action'  => $data['action'],
			'title'   => $data['title'],
			'enabled' => $data['state'] === 1,
			'changed' => array_values(array_unique($touched)),
			'options' => $options,
			'conditions' => $conditions,
		]);
	}
}
