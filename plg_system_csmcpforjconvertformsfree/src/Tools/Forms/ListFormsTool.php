<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Forms;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Joomla\CMS\User\User;

final class ListFormsTool extends AbstractTool
{
	use ConvertFormsBootTrait;

	public function getName(): string { return 'list_convertforms_forms'; }

	public function getDescription(): string
	{
		return 'List Convert Forms forms (#__convertforms). Filters: state '
			. '(published/unpublished/archived/trashed or 1/0/2/-2), search (name '
			. 'LIKE %term%). Returns id, name, state, state_label, created, '
			. 'field_count, submission_count, and the field names — enough to pick a '
			. 'form without a second call. Use get_convertforms_form for the full '
			. 'configuration.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'state'  => ['description' => 'published/unpublished/archived/trashed OR 1/0/2/-2'],
				'search' => ['type' => 'string', 'description' => 'Match against form name.'],
				'limit'  => ['type' => 'integer'],
				'offset' => ['type' => 'integer'],
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

		$table = $this->cfTableName();

		$q = $this->db->getQuery(true)
			->select(['id', 'name', 'state', 'created', 'ordering', 'params'])
			->from($this->db->quoteName($table))
			->order($this->db->quoteName('id') . ' DESC');

		$state = $this->normaliseContentStateFilter($arguments['state'] ?? null);
		if ($state !== null) {
			$q->where($this->db->quoteName('state') . ' = ' . $state);
		}

		if (!empty($arguments['search'])) {
			$q->where($this->db->quoteName('name') . ' LIKE ' . $this->cfLikeTerm((string) $arguments['search']));
		}

		$limit  = $this->cfLimit($arguments);
		$offset = $this->cfOffset($arguments);

		$this->db->setQuery($q, $offset, $limit);
		$rows = $this->db->loadAssocList() ?: [];

		$counts = $this->submissionCounts();

		$forms = [];
		foreach ($rows as $row) {
			$id     = (int) $row['id'];
			$params = $this->cfDecodeParams($row['params']);
			$fields = $this->cfFields($params);

			$names = [];
			foreach ($fields as $field) {
				if (!empty($field['name'])) {
					$names[] = (string) $field['name'];
				}
			}

			$forms[] = [
				'id'               => $id,
				'name'             => (string) $row['name'],
				'state'            => (int) $row['state'],
				'state_label'      => $this->contentStateLabel((int) $row['state']),
				'created'          => (string) $row['created'],
				'field_count'      => count($fields),
				'field_names'      => $names,
				'submission_count' => $counts[$id] ?? 0,
			];
		}

		$totalQuery = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName($table));
		$total = (int) $this->db->setQuery($totalQuery)->loadResult();

		return ToolResult::json([
			'ok'               => true,
			'count'            => count($forms),
			'limit'            => $limit,
			'offset'           => $offset,
			'total_unfiltered' => $total,
			'forms'            => $forms,
		]);
	}

	/**
	 * Submission totals per form id, counting every state so the number matches
	 * what list_convertforms_submissions can actually return. (Convert Forms'
	 * own dashboard counts only states 1 and 2, which reads as data loss when an
	 * agent then lists submissions and sees more.)
	 *
	 * @return array<int, int>
	 */
	private function submissionCounts(): array
	{
		if (!$this->cfTableExists('conversions')) {
			return [];
		}

		$q = $this->db->getQuery(true)
			->select([$this->db->quoteName('form_id'), 'COUNT(*) AS ' . $this->db->quoteName('total')])
			->from($this->db->quoteName($this->cfTableName('conversions')))
			->group($this->db->quoteName('form_id'));

		$rows = $this->db->setQuery($q)->loadAssocList() ?: [];

		$out = [];
		foreach ($rows as $row) {
			$out[(int) $row['form_id']] = (int) $row['total'];
		}

		return $out;
	}
}
