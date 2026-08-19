<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Submissions;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

final class SetSubmissionStateTool extends AbstractTool
{
	use ConvertFormsBootTrait;

	public function getName(): string { return 'set_convertforms_submission_state'; }

	public function getDescription(): string
	{
		return 'Set the state of one or more Convert Forms submissions: 1 published '
			. '(Convert Forms 5 labels this "confirmed"), 0 unpublished '
			. '("unconfirmed"), 2 archived, -2 trashed. Accepts a list of ids, or '
			. 'form_id to act on every submission of one form. Nothing is deleted — '
			. 'use delete_convertforms_submission for that.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['state'],
			'properties' => [
				'ids' => [
					'description' => 'One submission id, or a list of ids.',
					'oneOf'       => [
						['type' => 'integer'],
						['type' => 'array', 'items' => ['type' => 'integer']],
					],
				],
				'form_id' => [
					'type'        => 'integer',
					'description' => 'Act on every submission belonging to this form. Mutually exclusive with ids.',
				],
				'state' => ['description' => 'published/unpublished/archived/trashed OR 1/0/2/-2'],
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

		if (!$this->cfTableExists('conversions')) {
			return ToolResult::error('#__convertforms_conversions does not exist — the Convert Forms install looks incomplete.');
		}

		$state = $this->normaliseContentStateFilter($arguments['state'] ?? null);
		if ($state === null || !in_array($state, [0, 1, 2, -2], true)) {
			return ToolResult::error('state must be one of published/unpublished/archived/trashed (or 1/0/2/-2).');
		}

		$hasIds  = array_key_exists('ids', $arguments) && $arguments['ids'] !== null;
		$formId  = (int) ($arguments['form_id'] ?? 0);

		if ($hasIds && $formId > 0) {
			return ToolResult::error('Pass either ids or form_id, not both.');
		}
		if (!$hasIds && $formId <= 0) {
			return ToolResult::error('Pass ids (one id or a list) or form_id.');
		}

		$table = $this->cfTableName('conversions');
		$query = $this->db->getQuery(true)
			->update($this->db->quoteName($table))
			->set($this->db->quoteName('state') . ' = ' . $state)
			->set($this->db->quoteName('modified') . ' = ' . $this->db->quote(Factory::getDate()->toSql()));

		if ($formId > 0) {
			$affected = $this->countWhere($this->db->quoteName('form_id') . ' = ' . $formId);
			if ($affected === 0) {
				return ToolResult::error('Form ' . $formId . ' has no submissions (or does not exist).');
			}

			$query->where($this->db->quoteName('form_id') . ' = ' . $formId);
			$this->db->setQuery($query)->execute();

			return ToolResult::json([
				'ok'            => true,
				'form_id'       => $formId,
				'state'         => $state,
				'state_label'   => $this->contentStateLabel($state),
				'updated_count' => $affected,
			]);
		}

		$raw = $arguments['ids'];
		$ids = array_values(array_filter(array_map('intval', is_array($raw) ? $raw : [$raw]), static fn($i) => $i > 0));

		if ($ids === []) {
			return ToolResult::error('ids must contain at least one positive submission id.');
		}

		$found = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('id')
				->from($this->db->quoteName($table))
				->whereIn($this->db->quoteName('id'), $ids)
		)->loadColumn() ?: [];

		$found   = array_map('intval', $found);
		$missing = array_values(array_diff($ids, $found));

		if ($found !== []) {
			$query->whereIn($this->db->quoteName('id'), $found);
			$this->db->setQuery($query)->execute();
		}

		return ToolResult::json([
			'ok'            => $missing === [],
			'state'         => $state,
			'state_label'   => $this->contentStateLabel($state),
			'updated'       => $found,
			'updated_count' => count($found),
			'not_found'     => $missing,
		]);
	}

	private function countWhere(string $condition): int
	{
		$q = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName($this->cfTableName('conversions')))
			->where($condition);

		return (int) $this->db->setQuery($q)->loadResult();
	}
}
