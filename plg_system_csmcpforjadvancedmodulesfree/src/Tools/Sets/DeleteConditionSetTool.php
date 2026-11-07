<?php
/**
 * @package     MCP for Advanced Module Manager
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Sets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\AmmBootTrait;
use Joomla\CMS\User\User;


final class DeleteConditionSetTool extends AbstractTool
{
	use AmmBootTrait;

	public function getName(): string { return 'delete_advancedmodules_condition_set'; }

	public function getDescription(): string
	{
		return 'Permanently delete a Condition Set and its attachments. Every module on it then has no set and shows on every '
			. 'page, to every visitor. Without confirm: true this only reports what would happen. Refused while another set '
			. 'includes this one.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'set_id'  => ['type' => 'integer'],
				'confirm' => ['type' => 'boolean', 'description' => 'true to delete. Without it nothing changes.'],
			],
			'required'             => ['set_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ammInstalled()) {
			return $this->ammNotInstalledError();
		}

		$this->ammRequire($actor, 'com_conditions', 'core.delete');

		$id    = $this->ammId($arguments, 'set_id');
		$set   = $this->ammSet($id);
		$usage = $this->ammUsage([$id])[$id];
		$users = $this->ammSetsIncluding($id);

		if ($users !== []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Nothing was deleted. These sets include this one in an "another Condition Set" rule. Change them first.',
				'sets'  => $users,
			], true);
		}

		if (!$this->ammConfirmed($arguments)) {
			return ToolResult::json([
				'ok'            => true,
				'dry_run'       => true,
				'set'           => ['id' => $id, 'name' => $set->name],
				'would_affect'  => $usage,
				'message'       => 'Nothing was deleted. Send confirm: true to delete. The modules listed would show on every page, to every visitor.',
			]);
		}

		$model = $this->getModel('com_conditions', 'Item');
		$pks   = [$id];

		if (!$model->delete($pks)) {
			throw new \RuntimeException('Advanced Module Manager refused the delete: ' . ((string) $model->getError() ?: 'no reason given'));
		}

		$this->ammClearCaches();

		if (isset($this->ammLoadSets([$id])[$id])) {
			throw new \RuntimeException('The delete reported success but the set is still there.');
		}

		return ToolResult::json(['ok' => true, 'deleted' => ['id' => $id, 'name' => $set->name], 'now_on_every_page' => $usage['modules'], 'other_uses_removed' => $usage['other_extensions']] + $this->ammEffectNotes());
	}
}
