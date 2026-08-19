<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Tasks;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Joomla\CMS\User\User;

final class ListTasksTool extends AbstractTool
{
	use ConvertFormsBootTrait;

	public function getName(): string { return 'list_convertforms_tasks'; }

	public function getDescription(): string
	{
		return 'List Convert Forms Tasks — the actions that run when a form is '
			. 'submitted, such as sending an email notification. Filter by form_id, '
			. 'app or state. This is where a form\'s email notifications live in '
			. 'Convert Forms 5: they are rows in #__convertforms_tasks, not part of '
			. 'the form\'s own configuration.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'form_id' => ['type' => 'integer', 'description' => 'Restrict to one form.'],
				'app'     => ['type' => 'string', 'description' => 'Restrict to one app, e.g. "email".'],
				'state'   => ['description' => 'enabled/disabled OR 1/0'],
				'include_options' => ['type' => 'boolean', 'description' => 'Include each task\'s full options and conditions. Default false.'],
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

		if (!$this->cfTableExists('tasks')) {
			return ToolResult::error(
				'#__convertforms_tasks does not exist. The Tasks engine arrived in '
				. 'Convert Forms 5.0 — this site is on ' . ($this->cfVersion() ?? 'an older release')
				. ', where email notifications live in the form\'s params.emails instead '
				. '(read them with get_convertforms_form).'
			);
		}

		$table = $this->cfTableName('tasks');

		$q = $this->db->getQuery(true)
			->select(['id', 'form_id', 'title', 'state', 'action', 'app', 'trigger',
				'connection_id', 'options', 'conditions', 'silentfail', 'created', 'modified', 'ordering'])
			->from($this->db->quoteName($table))
			->order($this->db->quoteName('form_id') . ' ASC, ' . $this->db->quoteName('ordering') . ' ASC');

		$formId = (int) ($arguments['form_id'] ?? 0);
		if ($formId > 0) {
			$q->where($this->db->quoteName('form_id') . ' = ' . $formId);
		}

		if (!empty($arguments['app'])) {
			$q->where($this->db->quoteName('app') . ' = ' . $this->db->quote((string) $arguments['app']));
		}

		$state = $this->normaliseTaskState($arguments['state'] ?? null);
		if ($state !== null) {
			$q->where($this->db->quoteName('state') . ' = ' . $state);
		}

		$limit  = $this->cfLimit($arguments);
		$offset = $this->cfOffset($arguments);

		$this->db->setQuery($q, $offset, $limit);
		$rows = $this->db->loadAssocList() ?: [];

		$withOptions = !empty($arguments['include_options']);
		$formNames   = $this->formNames();

		$tasks = [];
		foreach ($rows as $row) {
			$options    = $this->cfDecodeParams($row['options']);
			$conditions = $this->cfDecodeParams($row['conditions']);

			$entry = [
				'id'            => (int) $row['id'],
				'form_id'       => (int) $row['form_id'],
				'form_name'     => $formNames[(int) $row['form_id']] ?? null,
				'title'         => (string) $row['title'],
				'app'           => (string) $row['app'],
				'action'        => (string) $row['action'],
				'trigger'       => (string) $row['trigger'],
				'state'         => (int) $row['state'],
				'enabled'       => (int) $row['state'] === 1,
				'connection_id' => $row['connection_id'] === null ? null : (int) $row['connection_id'],
				'silentfail'    => (int) $row['silentfail'] === 1,
				'has_conditions' => $conditions !== [],
				'created'       => (string) $row['created'],
				'modified'      => $row['modified'],
				'ordering'      => (int) $row['ordering'],
			];

			if ($withOptions) {
				$entry['options']    = $options;
				$entry['conditions'] = $conditions;
			}

			$tasks[] = $entry;
		}

		$countQuery = clone $q;
		$countQuery->clear('select')->clear('order')->select('COUNT(*)');
		$total = (int) $this->db->setQuery($countQuery)->loadResult();

		return ToolResult::json([
			'ok'             => true,
			'count'          => count($tasks),
			'total_matching' => $total,
			'limit'          => $limit,
			'offset'         => $offset,
			'tasks'          => $tasks,
			'hint'           => $withOptions ? null : 'Pass include_options=true to see each task\'s configuration (email recipients, body, conditions).',
		]);
	}

	/** Task state is a plain on/off flag, not the Joomla content enum. */
	private function normaliseTaskState(mixed $raw): ?int
	{
		if ($raw === null || $raw === '') {
			return null;
		}
		if (is_bool($raw)) {
			return $raw ? 1 : 0;
		}
		if (is_int($raw) || (is_string($raw) && ctype_digit((string) $raw))) {
			return ((int) $raw) === 1 ? 1 : 0;
		}

		return match (strtolower(trim((string) $raw))) {
			'enabled', 'published', 'on', 'true'   => 1,
			'disabled', 'unpublished', 'off', 'false' => 0,
			default => null,
		};
	}

	/** @return array<int, string> */
	private function formNames(): array
	{
		$q = $this->db->getQuery(true)
			->select(['id', 'name'])
			->from($this->db->quoteName($this->cfTableName()));

		$rows = $this->db->setQuery($q)->loadAssocList() ?: [];

		$out = [];
		foreach ($rows as $row) {
			$out[(int) $row['id']] = (string) $row['name'];
		}

		return $out;
	}
}
