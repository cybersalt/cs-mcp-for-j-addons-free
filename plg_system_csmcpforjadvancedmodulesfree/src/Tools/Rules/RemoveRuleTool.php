<?php
/**
 * @package     MCP for Advanced Module Manager
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Rules;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\AmmBootTrait;
use Joomla\CMS\User\User;


final class RemoveRuleTool extends AbstractTool
{
	use AmmBootTrait;

	public function getName(): string { return 'remove_advancedmodules_rule'; }

	public function getDescription(): string
	{
		return 'Remove one rule from a Condition Set. A group left empty goes too. Removing the set\'s last rule leaves its '
			. 'modules on every page, so that needs confirm: true. Rule ids change every time a set is saved; a stale id is '
			. 'refused. Sets that run PHP can\'t be changed here.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'rule_id' => ['type' => 'integer'],
				'confirm' => ['type' => 'boolean', 'description' => 'Needed to remove the set\'s last rule.'],
			],
			'required'             => ['rule_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ammInstalled()) {
			return $this->ammNotInstalledError();
		}

		$this->ammRequire($actor, 'com_conditions', 'core.edit');

		[$set, $g, $r] = $this->ammFindRule($this->ammId($arguments, 'rule_id'));
		$id = (int) $set->id;
		$this->ammRefuseIfPhp($id, 'changing its rules');
		$this->ammRefuseIfFeedsPhp($id);

		array_splice($set->groups[$g]->rules, $r, 1);

		if ($set->groups[$g]->rules === []) {
			array_splice($set->groups, $g, 1);
		}

		$usage = $this->ammUsage([$id])[$id];

		if ($set->groups === [] && !$this->ammConfirmed($arguments)) {
			return ToolResult::json([
				'ok'           => false,
				'error'        => 'Nothing was changed. This is the set\'s last rule. Without it, these modules show on every page. Send confirm: true to go ahead.',
				'would_affect' => $usage,
			], true);
		}

		$this->ammSaveSet($set);

		return ToolResult::json([
			'ok'      => true,
			'set'     => $this->ammFormatSet($this->ammSet($id)),
			'changed' => $usage,
		] + $this->ammEffectNotes());
	}
}
