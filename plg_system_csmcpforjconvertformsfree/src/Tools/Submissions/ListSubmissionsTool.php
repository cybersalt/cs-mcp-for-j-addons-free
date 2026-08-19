<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Submissions;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\SubmissionTrait;
use Joomla\CMS\User\User;

final class ListSubmissionsTool extends AbstractTool
{
	use ConvertFormsBootTrait;
	use SubmissionTrait;

	public function getName(): string { return 'list_convertforms_submissions'; }

	public function getDescription(): string
	{
		return 'List Convert Forms submissions (#__convertforms_conversions), newest '
			. 'first. Filters: form_id, state, date range (created_from / created_to, '
			. 'YYYY-MM-DD), and search across the submitted values. Each row carries a '
			. 'one-line answer summary; pass include_answers=true for the full '
			. 'labelled answer set. Unlike the Convert Forms dashboard, this returns '
			. 'every state by default rather than only published+archived.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'form_id' => ['type' => 'integer', 'description' => 'Restrict to one form.'],
				'state'   => ['description' => 'published/unpublished/archived/trashed/spam OR 1/0/2/-2/3'],
				'created_from' => ['type' => 'string', 'description' => 'Inclusive lower bound, YYYY-MM-DD.'],
				'created_to'   => ['type' => 'string', 'description' => 'Inclusive upper bound, YYYY-MM-DD.'],
				'search'  => ['type' => 'string', 'description' => 'Substring match against the submitted values blob.'],
				'user_id' => ['type' => 'integer', 'description' => 'Only submissions from this logged-in Joomla user.'],
				'include_answers' => ['type' => 'boolean', 'description' => 'Return the full labelled answers per row. Default false.'],
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

		if (!$this->cfTableExists('conversions')) {
			return ToolResult::error('#__convertforms_conversions does not exist — the Convert Forms install looks incomplete.');
		}

		$table   = $this->cfTableName('conversions');
		$columns = $this->cfExistingColumns('conversions', self::SUBMISSION_COLUMNS);

		$select = $columns;
		$select[] = 'params';

		$q = $this->db->getQuery(true)
			->select(array_map([$this->db, 'quoteName'], $select))
			->from($this->db->quoteName($table))
			->order($this->db->quoteName('id') . ' DESC');

		$where = [];

		$formId = (int) ($arguments['form_id'] ?? 0);
		if ($formId > 0) {
			$q->where($this->db->quoteName('form_id') . ' = ' . $formId);
			$where[] = 'form_id';
		}

		$state = $this->normaliseContentStateFilter($arguments['state'] ?? null);
		if ($state !== null) {
			$q->where($this->db->quoteName('state') . ' = ' . $state);
			$where[] = 'state';
		}

		$userId = (int) ($arguments['user_id'] ?? 0);
		if ($userId > 0) {
			$q->where($this->db->quoteName('user_id') . ' = ' . $userId);
			$where[] = 'user_id';
		}

		foreach (['created_from' => '>=', 'created_to' => '<='] as $arg => $operator) {
			if (empty($arguments[$arg])) {
				continue;
			}

			$date = $this->normaliseDate((string) $arguments[$arg], $arg === 'created_to');
			if ($date === null) {
				return ToolResult::error($arg . ' must be a date in YYYY-MM-DD form (an ISO datetime is also accepted).');
			}

			$q->where($this->db->quoteName('created') . ' ' . $operator . ' ' . $this->db->quote($date));
			$where[] = $arg;
		}

		if (!empty($arguments['search'])) {
			$q->where($this->db->quoteName('params') . ' LIKE ' . $this->cfLikeTerm((string) $arguments['search']));
			$where[] = 'search';
		}

		$limit  = $this->cfLimit($arguments);
		$offset = $this->cfOffset($arguments);

		$this->db->setQuery($q, $offset, $limit);
		$rows = $this->db->loadAssocList() ?: [];

		$withAnswers = !empty($arguments['include_answers']);
		$lookups     = [];
		$submissions = [];

		foreach ($rows as $row) {
			$params = $this->cfDecodeParams($row['params']);
			unset($row['params']);

			$rowFormId = (int) ($row['form_id'] ?? 0);
			if (!array_key_exists($rowFormId, $lookups)) {
				$form                 = $this->cfFetchForm($rowFormId);
				$lookups[$rowFormId]  = $form === null ? [] : $this->cfFieldLookup($form['params']);
			}

			$shaped = $this->cfShapeAnswers($params, $lookups[$rowFormId]);
			$entry  = $this->cfShapeSubmissionRow($row);

			$entry['summary'] = $this->cfAnswerSummary($shaped['answers']);

			if ($withAnswers) {
				$entry['answers'] = $shaped['answers'];
				if ($shaped['notes'] !== null) {
					$entry['admin_notes'] = $shaped['notes'];
				}
				if ($shaped['unmapped'] !== []) {
					$entry['unmapped_values'] = $shaped['unmapped'];
				}
			}

			$submissions[] = $entry;
		}

		// Total matching the same filters, so paging is meaningful.
		$countQuery = clone $q;
		$countQuery->clear('select')->clear('order')->select('COUNT(*)');
		$total = (int) $this->db->setQuery($countQuery)->loadResult();

		$out = [
			'ok'              => true,
			'count'           => count($submissions),
			'total_matching'  => $total,
			'limit'           => $limit,
			'offset'          => $offset,
			'filters_applied' => $where,
			'submissions'     => $submissions,
		];

		if (!$withAnswers) {
			$out['hint'] = 'Pass include_answers=true for the full labelled answers, or use get_convertforms_submission for one row.';
		}

		return ToolResult::json($out);
	}

	/**
	 * Accept YYYY-MM-DD (or a full datetime) and return an SQL datetime.
	 * For an upper bound, a bare date is widened to the end of that day so
	 * created_to=2026-08-19 includes submissions made during the 19th.
	 */
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
