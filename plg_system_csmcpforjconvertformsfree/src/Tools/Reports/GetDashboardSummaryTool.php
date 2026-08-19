<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Reports;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Joomla\CMS\User\User;

final class GetDashboardSummaryTool extends AbstractTool
{
	use ConvertFormsBootTrait;

	public function getName(): string { return 'get_convertforms_dashboard_summary'; }

	public function getDescription(): string
	{
		return 'Site-wide Convert Forms overview: form and submission totals, '
			. 'submissions broken down by state, per-form counts with each form\'s '
			. 'most recent submission, recent volume (today / 7 / 30 days), and any '
			. 'failing Tasks. Good first call for "how are the forms on this site '
			. 'doing" or "has anything stopped working".';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'top_forms' => ['type' => 'integer', 'description' => 'How many forms to include in the per-form breakdown, busiest first. Default 20.'],
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

		$hasConversions = $this->cfTableExists('conversions');

		$forms = $this->db->setQuery(
			$this->db->getQuery(true)
				->select(['id', 'name', 'state'])
				->from($this->db->quoteName($this->cfTableName()))
				->order($this->db->quoteName('id') . ' ASC')
		)->loadAssocList() ?: [];

		$formsByState = ['published' => 0, 'unpublished' => 0, 'archived' => 0, 'trashed' => 0, 'other' => 0];
		foreach ($forms as $form) {
			$label = $this->contentStateLabel((int) $form['state']);
			$key   = array_key_exists($label, $formsByState) ? $label : 'other';
			$formsByState[$key]++;
		}

		$out = [
			'ok'      => true,
			'version' => $this->cfVersion(),
			'edition' => $this->cfIsPro() ? 'pro' : 'free',
			'forms'   => ['total' => count($forms), 'by_state' => $formsByState],
		];

		if (!$hasConversions) {
			$out['submissions'] = ['total' => 0, 'note' => '#__convertforms_conversions does not exist.'];

			return ToolResult::json($out);
		}

		$conversions = $this->cfTableName('conversions');

		$out['submissions'] = [
			'total'    => $this->scalar('SELECT COUNT(*) FROM ' . $this->db->quoteName($conversions)),
			'by_state' => $this->submissionsByState(),
			'today'    => $this->since('today'),
			'last_7_days'  => $this->since('-7 days'),
			'last_30_days' => $this->since('-30 days'),
		];

		$out['per_form'] = $this->perForm($forms, max(1, min(200, (int) ($arguments['top_forms'] ?? 20))));

		$stale = [];
		foreach ($out['per_form'] as $row) {
			if ($row['state_label'] === 'published' && $row['submission_count'] === 0) {
				$stale[] = $row['id'];
			}
		}
		if ($stale !== []) {
			$out['published_forms_with_no_submissions'] = $stale;
		}

		if ($this->cfTableExists('tasks_history')) {
			$out['task_failures_last_30_days'] = $this->recentTaskFailures();
		}

		return ToolResult::json($out);
	}

	private function scalar(string $sql): int
	{
		return (int) $this->db->setQuery($sql)->loadResult();
	}

	/** @return array<string, int> */
	private function submissionsByState(): array
	{
		$q = $this->db->getQuery(true)
			->select([$this->db->quoteName('state'), 'COUNT(*) AS ' . $this->db->quoteName('total')])
			->from($this->db->quoteName($this->cfTableName('conversions')))
			->group($this->db->quoteName('state'));

		$rows = $this->db->setQuery($q)->loadAssocList() ?: [];

		$out = [];
		foreach ($rows as $row) {
			$out[$this->contentStateLabel((int) $row['state'])] = (int) $row['total'];
		}

		return $out;
	}

	private function since(string $modifier): int
	{
		$from = $modifier === 'today'
			? date('Y-m-d 00:00:00')
			: date('Y-m-d H:i:s', strtotime($modifier) ?: time());

		$q = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName($this->cfTableName('conversions')))
			->where($this->db->quoteName('created') . ' >= ' . $this->db->quote($from));

		return (int) $this->db->setQuery($q)->loadResult();
	}

	/**
	 * Per-form counts and last-submission timestamps, busiest first.
	 *
	 * @param array<int, array<string, mixed>> $forms
	 * @return array<int, array<string, mixed>>
	 */
	private function perForm(array $forms, int $limit): array
	{
		$q = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('form_id'),
				'COUNT(*) AS ' . $this->db->quoteName('total'),
				'MAX(' . $this->db->quoteName('created') . ') AS ' . $this->db->quoteName('last_submission'),
			])
			->from($this->db->quoteName($this->cfTableName('conversions')))
			->group($this->db->quoteName('form_id'));

		$stats = [];
		foreach ($this->db->setQuery($q)->loadAssocList() ?: [] as $row) {
			$stats[(int) $row['form_id']] = [
				'total' => (int) $row['total'],
				'last'  => $row['last_submission'],
			];
		}

		$rows = [];
		foreach ($forms as $form) {
			$id = (int) $form['id'];

			$rows[] = [
				'id'               => $id,
				'name'             => (string) $form['name'],
				'state_label'      => $this->contentStateLabel((int) $form['state']),
				'submission_count' => $stats[$id]['total'] ?? 0,
				'last_submission'  => $stats[$id]['last'] ?? null,
			];
		}

		usort($rows, static fn($a, $b) => $b['submission_count'] <=> $a['submission_count']);

		return array_slice($rows, 0, $limit);
	}

	/** @return array<string, mixed> */
	private function recentTaskFailures(): array
	{
		$from = date('Y-m-d H:i:s', strtotime('-30 days') ?: time());

		$q = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('h.task_id'),
				'COUNT(*) AS ' . $this->db->quoteName('failures'),
				'MAX(' . $this->db->quoteName('h.created') . ') AS ' . $this->db->quoteName('last_failure'),
				$this->db->quoteName('t.title'),
				$this->db->quoteName('t.app'),
				$this->db->quoteName('t.form_id'),
			])
			->from($this->db->quoteName($this->cfTableName('tasks_history'), 'h'))
			->join('LEFT', $this->db->quoteName($this->cfTableName('tasks'), 't')
				. ' ON ' . $this->db->quoteName('t.id') . ' = ' . $this->db->quoteName('h.task_id'))
			->where($this->db->quoteName('h.success') . ' = 0')
			->where($this->db->quoteName('h.created') . ' >= ' . $this->db->quote($from))
			->group($this->db->quoteName('h.task_id') . ', ' . $this->db->quoteName('t.title')
				. ', ' . $this->db->quoteName('t.app') . ', ' . $this->db->quoteName('t.form_id'))
			->order($this->db->quoteName('failures') . ' DESC');

		$this->db->setQuery($q, 0, 20);
		$rows = $this->db->loadAssocList() ?: [];

		$failing = [];
		$total   = 0;

		foreach ($rows as $row) {
			$total += (int) $row['failures'];

			$failing[] = [
				'task_id'      => (int) $row['task_id'],
				'title'        => $row['title'],
				'app'          => $row['app'],
				'form_id'      => $row['form_id'] === null ? null : (int) $row['form_id'],
				'failures'     => (int) $row['failures'],
				'last_failure' => $row['last_failure'],
			];
		}

		return [
			'total'   => $total,
			'tasks'   => $failing,
			'hint'    => $failing === []
				? null
				: 'Use list_convertforms_task_history with success=false and the task_id for the error text.',
		];
	}
}
