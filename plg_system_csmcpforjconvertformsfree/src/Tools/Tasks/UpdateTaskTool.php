<?php
/**
 * @package     MCP for Convert Forms
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

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
			. 'overwrite the whole object instead. The task is re-read from the '
			. 'database after saving and every requested change is verified against '
			. 'the stored row; if any did not land, the call fails and says which. '
			. 'Unknown argument names are refused rather than ignored.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'      => ['type' => 'integer', 'description' => 'Task id.'],
				'title'   => ['type' => 'string'],
				'enabled' => ['type' => 'boolean', 'description' => 'true = the task runs on new submissions.'],
				'state'   => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Alias for enabled, matching the column name get_convertforms_task returns (1 = enabled, 0 = disabled).'],
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

		// The core does not enforce additionalProperties, so an argument this tool
		// does not know would otherwise be dropped silently while the call still
		// reported success (GitHub issue #4: `state` was passed, ignored, and the
		// caller believed the task had been enabled). Refuse instead.
		$known   = array_keys($this->getInputSchema()['properties']);
		$unknown = array_values(array_diff(array_keys($arguments), $known));
		if ($unknown !== []) {
			return ToolResult::error(
				'Unknown argument(s): ' . implode(', ', $unknown) . '. Nothing was changed. '
				. 'Accepted: ' . implode(', ', $known) . '.'
			);
		}

		if (array_key_exists('state', $arguments)) {
			$stateArg = filter_var($arguments['state'], FILTER_VALIDATE_INT);
			if ($stateArg !== 0 && $stateArg !== 1) {
				return ToolResult::error('state must be 0 (disabled) or 1 (enabled). Nothing was changed.');
			}
			if (array_key_exists('enabled', $arguments) && (bool) $arguments['enabled'] !== ($stateArg === 1)) {
				return ToolResult::error('state and enabled disagree. Send one of them. Nothing was changed.');
			}
			$arguments['enabled'] = $stateArg === 1;
			unset($arguments['state']);
		}

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

		if ((int) $table->id !== $id) {
			return ToolResult::error(
				'Task update wrote row ' . (int) $table->id . ' instead of updating task ' . $id
				. '. Task ' . $id . ' was not changed; inspect row ' . (int) $table->id . ' and delete it if it is a stray copy.'
			);
		}

		// Never report success on the strength of store() alone: re-read the row
		// and confirm each requested change is what is now stored.
		$after = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->cfTableName('tasks')))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();

		if (!$after) {
			return ToolResult::error('Task ' . $id . ' could not be re-read after saving, so the update cannot be confirmed.');
		}

		$storedOptions    = $this->cfDecodeParams($after['options']);
		$storedConditions = $this->cfDecodeParams($after['conditions']);

		$expected = [
			'title'         => [$data['title'], (string) $after['title']],
			'enabled'       => [$data['state'] === 1, (int) $after['state'] === 1],
			'action'        => [$data['action'], (string) $after['action']],
			'connection_id' => [$data['connection_id'], $after['connection_id'] === null ? null : (int) $after['connection_id']],
			'silentfail'    => [$data['silentfail'] === 1, (int) $after['silentfail'] === 1],
			'options'       => [$this->canonicalJson($options), $this->canonicalJson($storedOptions)],
			'conditions'    => [$this->canonicalJson($conditions), $this->canonicalJson($storedConditions)],
		];

		$notApplied = [];
		foreach (array_unique($touched) as $key) {
			if (isset($expected[$key]) && $expected[$key][0] !== $expected[$key][1]) {
				$notApplied[$key] = ['requested' => $expected[$key][0], 'stored' => $expected[$key][1]];
			}
		}

		if ($notApplied !== []) {
			return ToolResult::error(
				'Task ' . $id . ' was saved but these changes are not in the stored row: '
				. json_encode($notApplied, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
			);
		}

		return ToolResult::json([
			'ok'         => true,
			'id'         => $id,
			'form_id'    => (int) $after['form_id'],
			'app'        => (string) $after['app'],
			'action'     => (string) $after['action'],
			'title'      => (string) $after['title'],
			'state'      => (int) $after['state'],
			'enabled'    => (int) $after['state'] === 1,
			'silentfail' => (int) $after['silentfail'] === 1,
			'modified'   => $after['modified'],
			'changed'    => array_values(array_unique($touched)),
			'verified'   => 'Re-read from the database after saving; every requested change is stored.',
			'options'    => $storedOptions,
			'conditions' => $storedConditions,
		]);
	}

	/**
	 * Key-order-independent JSON for comparing a requested array with the
	 * stored, decoded one.
	 */
	private function canonicalJson(mixed $value): string
	{
		$sort = static function (mixed $v) use (&$sort): mixed {
			if (!is_array($v)) {
				return $v;
			}
			if (!array_is_list($v)) {
				ksort($v);
			}
			return array_map($sort, $v);
		};

		return (string) json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
}
