<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Reports;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\SubmissionTrait;
use Joomla\CMS\User\User;

final class GetFormSubmissionsSummaryTool extends AbstractTool
{
	use ConvertFormsBootTrait;
	use SubmissionTrait;

	public function getName(): string { return 'get_convertforms_form_submissions_summary'; }

	public function getDescription(): string
	{
		return 'Analyse one form\'s submissions: totals by state, a daily or monthly '
			. 'time series, per-field completion rates, and the most common answers '
			. 'for choice-style fields. Answers the questions the raw submission list '
			. 'cannot — which fields people skip, and what they pick when they do '
			. 'answer.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['form_id'],
			'properties' => [
				'form_id'  => ['type' => 'integer'],
				'group_by' => ['type' => 'string', 'enum' => ['day', 'month'], 'description' => 'Time-series granularity. Default day.'],
				'created_from' => ['type' => 'string', 'description' => 'Inclusive lower bound, YYYY-MM-DD.'],
				'created_to'   => ['type' => 'string', 'description' => 'Inclusive upper bound, YYYY-MM-DD.'],
				'top_values'   => ['type' => 'integer', 'description' => 'How many distinct values to report per field. Default 5, max 25.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	/**
	 * Cap on rows pulled into PHP for the per-field analysis. The answers live in
	 * a JSON blob, so per-field stats cannot be computed in SQL on the older
	 * MySQL versions Convert Forms still supports. Anything above this is
	 * sampled, and the response says so rather than quietly reporting partial
	 * numbers as if they were complete.
	 */
	private const ANALYSIS_CAP = 5000;

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->cfAdminBase() === null) {
			return $this->notInstalledError();
		}

		if (!$this->cfTableExists('conversions')) {
			return ToolResult::error('#__convertforms_conversions does not exist — the Convert Forms install looks incomplete.');
		}

		$formId = $this->requirePositiveInt($arguments, 'form_id');
		$form   = $this->cfFetchForm($formId);

		if ($form === null) {
			return ToolResult::error('Convert Forms form ' . $formId . ' not found.');
		}

		$conditions = [$this->db->quoteName('form_id') . ' = ' . $formId];

		foreach (['created_from' => '>=', 'created_to' => '<='] as $arg => $operator) {
			if (empty($arguments[$arg])) {
				continue;
			}

			$date = $this->normaliseDate((string) $arguments[$arg], $arg === 'created_to');
			if ($date === null) {
				return ToolResult::error($arg . ' must be a date in YYYY-MM-DD form.');
			}

			$conditions[] = $this->db->quoteName('created') . ' ' . $operator . ' ' . $this->db->quote($date);
		}

		$table = $this->cfTableName('conversions');
		$where = implode(' AND ', $conditions);

		$total = (int) $this->db->setQuery(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName($table) . ' WHERE ' . $where
		)->loadResult();

		$out = [
			'ok'         => true,
			'form_id'    => $formId,
			'form_name'  => $form['name'],
			'total'      => $total,
			'by_state'   => $this->byState($table, $where),
			'time_series' => $this->timeSeries($table, $where, (string) ($arguments['group_by'] ?? 'day')),
		];

		if ($total === 0) {
			$out['note'] = 'No submissions match. The form has collected nothing yet, or the date range excludes everything.';

			return ToolResult::json($out);
		}

		$lookup   = $this->cfFieldLookup($form['params']);
		$topCount = max(1, min(25, (int) ($arguments['top_values'] ?? 5)));

		$out['fields'] = $this->fieldStats($table, $where, $lookup, $total, $topCount, $sampled);

		if ($sampled) {
			$out['field_analysis_sampled'] = [
				'rows_analysed' => self::ANALYSIS_CAP,
				'of_total'      => $total,
				'note'          => 'Per-field figures are based on the most recent '
					. self::ANALYSIS_CAP . ' submissions, not all ' . $total
					. '. Narrow the date range for exact numbers.',
			];
		}

		return ToolResult::json($out);
	}

	/** @return array<string, int> */
	private function byState(string $table, string $where): array
	{
		$rows = $this->db->setQuery(
			'SELECT ' . $this->db->quoteName('state') . ', COUNT(*) AS ' . $this->db->quoteName('total')
			. ' FROM ' . $this->db->quoteName($table) . ' WHERE ' . $where
			. ' GROUP BY ' . $this->db->quoteName('state')
		)->loadAssocList() ?: [];

		$out = [];
		foreach ($rows as $row) {
			$out[$this->contentStateLabel((int) $row['state'])] = (int) $row['total'];
		}

		return $out;
	}

	/** @return array<int, array{period: string, count: int}> */
	private function timeSeries(string $table, string $where, string $groupBy): array
	{
		$format = strtolower($groupBy) === 'month' ? '%Y-%m' : '%Y-%m-%d';

		$rows = $this->db->setQuery(
			'SELECT DATE_FORMAT(' . $this->db->quoteName('created') . ', ' . $this->db->quote($format) . ') AS '
			. $this->db->quoteName('period') . ', COUNT(*) AS ' . $this->db->quoteName('total')
			. ' FROM ' . $this->db->quoteName($table) . ' WHERE ' . $where
			. ' GROUP BY ' . $this->db->quoteName('period')
			. ' ORDER BY ' . $this->db->quoteName('period') . ' ASC'
		)->loadAssocList() ?: [];

		$out = [];
		foreach ($rows as $row) {
			$out[] = ['period' => (string) $row['period'], 'count' => (int) $row['total']];
		}

		return $out;
	}

	/**
	 * Completion rate and most common values per field.
	 *
	 * @param array<string, array{name: string, label: string, type: string}> $lookup
	 * @return array<int, array<string, mixed>>
	 */
	private function fieldStats(string $table, string $where, array $lookup, int $total, int $topCount, ?bool &$sampled): array
	{
		$sampled = $total > self::ANALYSIS_CAP;

		$this->db->setQuery(
			'SELECT ' . $this->db->quoteName('params') . ' FROM ' . $this->db->quoteName($table)
			. ' WHERE ' . $where . ' ORDER BY ' . $this->db->quoteName('id') . ' DESC',
			0,
			self::ANALYSIS_CAP
		);

		$blobs = $this->db->loadColumn() ?: [];

		$answered = [];
		$values   = [];

		foreach ($blobs as $blob) {
			$params = $this->cfDecodeParams($blob);

			foreach ($params as $key => $value) {
				$lower = strtolower((string) $key);

				if ($lower === self::LEAD_NOTES_KEY || !isset($lookup[$lower])) {
					continue;
				}

				$flat = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
				if (trim($flat) === '') {
					continue;
				}

				$answered[$lower] = ($answered[$lower] ?? 0) + 1;

				// Only worth tallying distinct values where the answer set is
				// bounded; free text would produce one bucket per submission.
				if (in_array($lookup[$lower]['type'], ['dropdown', 'radio', 'checkbox', 'number', 'hidden'], true)) {
					$values[$lower][$flat] = ($values[$lower][$flat] ?? 0) + 1;
				}
			}
		}

		$analysed = count($blobs);
		$stats    = [];

		foreach ($lookup as $lower => $meta) {
			$count = $answered[$lower] ?? 0;

			$entry = [
				'name'            => $meta['name'],
				'label'           => $meta['label'],
				'type'            => $meta['type'],
				'answered'        => $count,
				'blank'           => $analysed - $count,
				'completion_rate' => $analysed > 0 ? round(($count / $analysed) * 100, 1) : 0.0,
			];

			if (isset($values[$lower])) {
				arsort($values[$lower]);

				$top = [];
				foreach (array_slice($values[$lower], 0, $topCount, true) as $value => $n) {
					$top[] = ['value' => (string) $value, 'count' => $n];
				}

				$entry['top_values']       = $top;
				$entry['distinct_values']  = count($values[$lower]);
			}

			$stats[] = $entry;
		}

		return $stats;
	}

	private function normaliseDate(string $raw, bool $endOfDay): ?string
	{
		$raw = trim($raw);

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
			return $raw . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
		}

		$ts = strtotime($raw);

		return $ts === false ? null : date('Y-m-d H:i:s', $ts);
	}
}
