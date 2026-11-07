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


final class SetConditionSetStateTool extends AbstractTool
{
	use AmmBootTrait;

	public function getName(): string { return 'set_advancedmodules_condition_set_state'; }

	public function getDescription(): string
	{
		return 'Publish, unpublish or trash a Condition Set. Careful: Advanced Module Manager treats a module on a set that '
			. 'isn\'t published as having no set, so unpublishing or trashing shows every attached module on every page, to '
			. 'every visitor. That needs confirm: true when modules are attached; without it you get the list. Publishing a '
			. 'set that runs PHP is refused.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'set_id'  => ['type' => 'integer'],
				'state'   => ['type' => 'string', 'enum' => ['published', 'unpublished', 'trashed']],
				'confirm' => ['type' => 'boolean', 'description' => 'Needed to unpublish or trash a set with modules attached.'],
			],
			'required'             => ['set_id', 'state'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ammInstalled()) {
			return $this->ammNotInstalledError();
		}

		$this->ammRequire($actor, 'com_conditions', 'core.edit.state');

		$id    = $this->ammId($arguments, 'set_id');
		$state = ['published' => 1, 'unpublished' => 0, 'trashed' => -2][(string) ($arguments['state'] ?? '')] ?? null;

		if ($state === null) {
			throw new \RuntimeException('state must be published, unpublished or trashed.');
		}

		$this->ammSet($id);
		$usage = $this->ammUsage([$id])[$id];

		$this->ammRefuseIfFeedsPhp($id);

		if ($state === 1) {
			$this->ammRefuseIfPhp($id, 'publishing it');
		} elseif ($usage['modules'] !== [] && !$this->ammConfirmed($arguments)) {
			return ToolResult::json([
				'ok'          => false,
				'error'       => 'Nothing was changed. These modules would show on every page, to every visitor. Send confirm: true to go ahead.',
				'would_affect' => $usage,
			], true);
		}

		$model = $this->getModel('com_conditions', 'Item');
		$pks   = [$id];

		if (!$model->publish($pks, $state)) {
			throw new \RuntimeException('Advanced Module Manager refused: ' . ((string) $model->getError() ?: 'no reason given'));
		}

		$this->ammClearCaches();

		$set = $this->ammSet($id);

		if ((int) $set->published !== $state) {
			throw new \RuntimeException('The save reported success but the set is still ' . $this->ammStateLabel((int) $set->published) . '.');
		}

		return ToolResult::json([
			'ok'     => true,
			'set_id' => $id,
			'state'  => $this->ammStateLabel($state),
			'effect' => $this->ammSetEffect($set),
			'affects' => $usage,
		] + $this->ammEffectNotes());
	}
}
