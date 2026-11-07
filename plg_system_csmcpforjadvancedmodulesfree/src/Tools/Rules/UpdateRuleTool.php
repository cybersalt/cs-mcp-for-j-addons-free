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


final class UpdateRuleTool extends AbstractTool
{
	use AmmBootTrait;

	public function getName(): string { return 'update_advancedmodules_rule'; }

	public function getDescription(): string
	{
		return 'Change one rule. The params you send are merged over the rule\'s current ones. The type can\'t change: remove '
			. 'the rule and add another. Rule ids change every time a set is saved, so read them from '
			. 'get_advancedmodules_condition_set first; a stale id is refused. This changes every module attached to the set, '
			. 'and the response names them.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'rule_id' => ['type' => 'integer'],
				'exclude' => ['type' => 'boolean'],
				'params'  => ['type' => 'object', 'description' => 'Merged over the current params.'],
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

		$old   = $set->groups[$g]->rules[$r];
		$built = $this->ammBuildRule([
			'type'    => $old->type,
			'exclude' => \array_key_exists('exclude', $arguments) ? $arguments['exclude'] : $old->exclude,
			'params'  => array_merge((array) $old->params, (array) ($arguments['params'] ?? [])),
		], $id);

		$built->ordering          = $old->ordering;
		$set->groups[$g]->rules[$r] = $built;

		$this->ammSaveSet($set);

		return ToolResult::json([
			'ok'      => true,
			'set'     => $this->ammFormatSet($this->ammSet($id)),
			'changed' => $this->ammUsage([$id])[$id],
		] + $this->ammEffectNotes());
	}
}
