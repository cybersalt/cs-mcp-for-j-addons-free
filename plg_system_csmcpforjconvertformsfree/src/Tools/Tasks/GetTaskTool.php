<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Tasks;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Joomla\CMS\User\User;

final class GetTaskTool extends AbstractTool
{
	use ConvertFormsBootTrait;

	public function getName(): string { return 'get_convertforms_task'; }

	public function getDescription(): string
	{
		return 'Get one Convert Forms Task in full, including its decoded options '
			. '(for an email task: recipient, subject, body, reply-to) and its '
			. 'conditions. Option values commonly contain Smart Tags such as '
			. '{field.email} or {submission.id}, which Convert Forms substitutes at '
			. 'send time.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => ['id' => ['type' => 'integer', 'description' => 'Task id.']],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->cfAdminBase() === null) {
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

		$formId = (int) $row['form_id'];
		$form   = $this->cfFetchForm($formId);

		$out = [
			'ok'            => true,
			'id'            => (int) $row['id'],
			'form_id'       => $formId,
			'form_name'     => $form['name'] ?? null,
			'title'         => (string) $row['title'],
			'app'           => (string) $row['app'],
			'action'        => (string) $row['action'],
			'trigger'       => (string) $row['trigger'],
			'state'         => (int) $row['state'],
			'enabled'       => (int) $row['state'] === 1,
			'silentfail'    => (int) $row['silentfail'] === 1,
			'connection_id' => $row['connection_id'] === null ? null : (int) $row['connection_id'],
			'ordering'      => (int) $row['ordering'],
			'created'       => $row['created'],
			'created_by'    => (int) $row['created_by'],
			'modified'      => $row['modified'],
			'options'       => $this->cfDecodeParams($row['options']),
			'conditions'    => $this->cfDecodeParams($row['conditions']),
		];

		if ($form === null) {
			$out['warning'] = 'Form ' . $formId . ' no longer exists — this task is orphaned and will never fire.';
		}

		$out['notes'] = [
			'silentfail=true means a failure of this task is swallowed rather than '
				. 'aborting the submission with an error shown to the visitor.',
			'conditions are OR-ed across sets and AND-ed within a set; an empty '
				. 'conditions object means the task always runs.',
		];

		return ToolResult::json($out);
	}
}
