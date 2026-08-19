<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Forms;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Joomla\CMS\User\User;

final class SetFormStateTool extends AbstractTool
{
	use ConvertFormsBootTrait;

	public function getName(): string { return 'set_convertforms_form_state'; }

	public function getDescription(): string
	{
		return 'Publish, unpublish, archive or trash Convert Forms forms. Accepts one '
			. 'id or a list. Unpublishing matters at render time: ConvertForms\\Form::load() '
			. 'filters on state = 1, so an unpublished form stops appearing on the '
			. 'front end immediately while its submissions are retained.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['ids', 'state'],
			'properties' => [
				'ids' => [
					'description' => 'One form id, or a list of ids.',
					'oneOf'       => [
						['type' => 'integer'],
						['type' => 'array', 'items' => ['type' => 'integer']],
					],
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

		$state = $this->normaliseContentStateFilter($arguments['state'] ?? null);
		if ($state === null || !in_array($state, [0, 1, 2, -2], true)) {
			return ToolResult::error('state must be one of published/unpublished/archived/trashed (or 1/0/2/-2).');
		}

		$raw = $arguments['ids'] ?? null;
		$ids = array_values(array_filter(array_map('intval', is_array($raw) ? $raw : [$raw]), static fn($i) => $i > 0));

		if ($ids === []) {
			return ToolResult::error('ids is required and must contain at least one positive form id.');
		}

		$table = $this->cfTableName();

		$existing = $this->db->setQuery(
			$this->db->getQuery(true)
				->select(['id', 'name'])
				->from($this->db->quoteName($table))
				->whereIn($this->db->quoteName('id'), $ids)
		)->loadAssocList('id') ?: [];

		$missing = array_values(array_diff($ids, array_map('intval', array_keys($existing))));
		$found   = array_map('intval', array_keys($existing));

		if ($found !== []) {
			$this->db->setQuery(
				$this->db->getQuery(true)
					->update($this->db->quoteName($table))
					->set($this->db->quoteName('state') . ' = ' . $state)
					->whereIn($this->db->quoteName('id'), $found)
			)->execute();
		}

		$this->cfClearFormCache();

		return ToolResult::json([
			'ok'          => $missing === [],
			'state'       => $state,
			'state_label' => $this->contentStateLabel($state),
			'updated'     => $found,
			'updated_count' => count($found),
			'not_found'   => $missing,
		]);
	}
}
