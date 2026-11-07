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


final class AddRuleTool extends AbstractTool
{
	use AmmBootTrait;

	public function getName(): string { return 'add_advancedmodules_rule'; }

	public function getDescription(): string
	{
		return 'Add one rule to a Condition Set. This changes every module attached to the set, and the response names them. '
			. 'Other rules\' ids change on every save. PHP rules are refused, as are changes to sets that run PHP.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'set_id'      => ['type' => 'integer'],
				'type'        => ['type' => 'string', 'description' => 'From list_advancedmodules_rule_types.'],
				'exclude'     => ['type' => 'boolean', 'description' => 'true to show when this rule does NOT match.'],
				'params'      => ['type' => 'object', 'description' => 'selection and the type\'s options.'],
				'group_index' => ['type' => 'integer', 'description' => 'Which group, from 0. Default 0. Use new_group to start another.'],
				'new_group'   => ['type' => 'boolean', 'description' => 'Put the rule in a new group of its own.'],
			],
			'required'             => ['set_id', 'type'],
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

		$id = $this->ammId($arguments, 'set_id');
		$this->ammRefuseIfPhp($id, 'changing its rules');
		$this->ammRefuseIfFeedsPhp($id);

		$set  = $this->ammSet($id);
		$rule = $this->ammBuildRule(['type' => $arguments['type'] ?? '', 'exclude' => $arguments['exclude'] ?? 0, 'params' => $arguments['params'] ?? []], $id);

		if (!empty($arguments['new_group']) || $set->groups === []) {
			$rule->ordering = 0;
			$set->groups[]  = (object) ['match_all' => 1, 'ordering' => \count($set->groups), 'rules' => [$rule]];
		} else {
			$index = (int) ($arguments['group_index'] ?? 0);

			if (!isset($set->groups[$index])) {
				throw new \RuntimeException('Nothing was saved. This set has ' . \count($set->groups) . ' group(s), counted from 0.');
			}

			$rule->ordering               = \count($set->groups[$index]->rules);
			$set->groups[$index]->rules[] = $rule;
		}

		$this->ammSaveSet($set);

		return ToolResult::json([
			'ok'      => true,
			'set'     => $this->ammFormatSet($this->ammSet($id)),
			'changed' => $this->ammUsage([$id])[$id],
		] + $this->ammEffectNotes());
	}
}
