<?php
/**
 * @package     MCP for AcyMailing
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Lists;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

/**
 * Update a list WITHOUT the vendor's partial-save data loss.
 *
 * ListClass::save() ends a partial update by forcing
 *     if (empty($list->translation)) $list->translation = '';
 *     if (empty($list->description)) $list->description = '';
 * so saving {id, name} through it erases the list's description and every
 * multilingual name/description translation. This tool loads the whole row,
 * overlays the caller's fields and saves the complete object, then diffs the
 * row before/after to prove only the requested columns moved.
 */
final class UpdateListTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'update_acym_list'; }

	public function getDescription(): string
	{
		return 'Update an AcyMailing list through ListClass::save(), hydrating the full row first: a raw partial '
			. 'save through the vendor class silently blanks the list description and all its translations. '
			. 'Pass id plus any of name, display_name, description, color, active, visible, tracking, access. '
			. 'Lists of type followup are refused (their name is owned and rewritten by the follow-up). Returns '
			. 'the before/after diff. Sends nothing.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['id'],
			'properties' => ListPayload::schema(true),
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->acyAdminBase() === null) {
			return $this->notInstalledError();
		}
		if (!$this->ensureAcyLoaded()) {
			return ToolResult::error('Could not load the AcyMailing framework.');
		}

		$id     = $this->requirePositiveInt($arguments, 'id');
		$table  = $this->acyQn($this->acyTable('list'));
		$before = $this->db->setQuery('SELECT * FROM ' . $table . ' WHERE id = ' . $id)->loadAssoc();
		if (!$before) {
			return ToolResult::error('No AcyMailing list with id ' . $id . '.');
		}
		if ($before['type'] === 'followup') {
			return ToolResult::error('List ' . $id . ' is the hidden list of a follow-up sequence; FollowupClass rewrites its name on every follow-up save. Edit the follow-up instead.');
		}

		$payload = ListPayload::build($arguments);
		if (\is_string($payload)) {
			return ToolResult::error($payload);
		}
		if ($payload === []) {
			return ToolResult::error('Nothing to update: pass at least one field besides id.');
		}
		if (array_key_exists('name', $payload) && trim($payload['name']) === '') {
			return ToolResult::error('name cannot be empty.');
		}

		$count  = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $table)->loadResult();
		$object = (object) array_merge($before, $payload);
		$class  = new \AcyMailing\Classes\ListClass();
		$saved  = $class->save($object);
		$msgs   = $this->acyDrainMessages();

		if ((int) $saved !== $id) {
			return ToolResult::error('ListClass::save() did not update list ' . $id . ' (returned ' . json_encode($saved) . '): ' . implode('; ', array_merge($class->errors, $msgs)));
		}

		$after   = $this->db->setQuery('SELECT * FROM ' . $table . ' WHERE id = ' . $id)->loadAssoc();
		$countAf = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $table)->loadResult();
		$changed = [];
		foreach ($after as $k => $v) {
			if ((string) $v !== (string) ($before[$k] ?? '')) {
				$changed[$k] = ['from' => $before[$k] ?? null, 'to' => $v];
			}
		}

		return ToolResult::json([
			'ok'               => true,
			'id'               => $id,
			'row_count_stable' => $countAf === $count,
			'changed'          => $changed,
			'unexpected'       => array_values(array_diff(array_keys($changed), array_keys($payload))),
			'vendor_messages'  => $msgs,
		]);
	}
}
