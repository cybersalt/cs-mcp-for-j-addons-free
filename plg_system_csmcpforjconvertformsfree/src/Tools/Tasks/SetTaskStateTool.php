<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Tasks;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

final class SetTaskStateTool extends AbstractTool
{
	use ConvertFormsBootTrait;

	public function getName(): string { return 'set_convertforms_task_state'; }

	public function getDescription(): string
	{
		return 'Enable or disable Convert Forms Tasks without deleting them. '
			. 'Disabling a form\'s email task is the quickest way to stop '
			. 'notifications while keeping the configuration — useful during '
			. 'testing or a migration. Accepts one id or a list.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['ids', 'enabled'],
			'properties' => [
				'ids' => [
					'description' => 'One task id, or a list of ids.',
					'oneOf'       => [
						['type' => 'integer'],
						['type' => 'array', 'items' => ['type' => 'integer']],
					],
				],
				'enabled' => ['type' => 'boolean', 'description' => 'true = task runs on new submissions, false = task is skipped.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->cfAdminBase() === null) {
			return $this->notInstalledError();
		}

		if (!$this->cfTableExists('tasks')) {
			return ToolResult::error('#__convertforms_tasks does not exist (the Tasks engine arrived in Convert Forms 5.0).');
		}

		if (!array_key_exists('enabled', $arguments)) {
			return ToolResult::error('"enabled" is required (true to run the task, false to skip it).');
		}

		$state = !empty($arguments['enabled']) ? 1 : 0;

		$raw = $arguments['ids'] ?? null;
		$ids = array_values(array_filter(array_map('intval', is_array($raw) ? $raw : [$raw]), static fn($i) => $i > 0));

		if ($ids === []) {
			return ToolResult::error('ids is required and must contain at least one positive task id.');
		}

		$table = $this->cfTableName('tasks');

		$found = array_map('intval', $this->db->setQuery(
			$this->db->getQuery(true)
				->select('id')
				->from($this->db->quoteName($table))
				->whereIn($this->db->quoteName('id'), $ids)
		)->loadColumn() ?: []);

		$missing = array_values(array_diff($ids, $found));

		if ($found !== []) {
			// Updated with a direct statement rather than the Table class: its
			// check() re-encodes options on every store, so a round-trip just to
			// flip one flag is a needless chance to mangle the configuration.
			$this->db->setQuery(
				$this->db->getQuery(true)
					->update($this->db->quoteName($table))
					->set($this->db->quoteName('state') . ' = ' . $state)
					->set($this->db->quoteName('modified') . ' = ' . $this->db->quote(Factory::getDate()->toSql()))
					->whereIn($this->db->quoteName('id'), $found)
			)->execute();
		}

		return ToolResult::json([
			'ok'            => $missing === [],
			'enabled'       => $state === 1,
			'updated'       => $found,
			'updated_count' => count($found),
			'not_found'     => $missing,
		]);
	}
}
