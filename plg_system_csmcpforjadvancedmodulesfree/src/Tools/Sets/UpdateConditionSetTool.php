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


final class UpdateConditionSetTool extends AbstractTool
{
	use AmmBootTrait;

	public function getName(): string { return 'update_advancedmodules_condition_set'; }

	public function getDescription(): string
	{
		return 'Change a Condition Set\'s name, description, category, colour, matching or whole rule list. This changes every '
			. 'module attached to the set, and the response names them; dry_run shows the before and after without saving. '
			. 'Sending rules or groups replaces all existing rules. To change one rule use update_advancedmodules_rule. Sets '
			. 'running PHP keep their rules and matching: only their labels can change here.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'set_id'      => ['type' => 'integer'],
				'name'        => ['type' => 'string', 'description' => 'Plain text, up to 100 characters.'],
				'description' => ['type' => 'string'],
				'category'    => ['type' => 'string'],
				'color'       => ['type' => 'string', 'description' => 'Like #dc2a28, or "none".'],
				'dry_run'     => ['type' => 'boolean', 'description' => 'Report the effect; save nothing.'],

				'rules'       => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
					'type'    => ['type' => 'string', 'description' => 'From list_advancedmodules_rule_types.'],
					'exclude' => ['type' => 'boolean', 'description' => 'true to show when this rule does NOT match.'],
					'params'  => ['type' => 'object', 'description' => 'selection and the type\'s options; list_advancedmodules_rule_types has them.'],
				], 'required' => ['type']], 'description' => 'The rules, as one group. Use groups instead for more than one.'],
				'rules_match' => ['type' => 'string', 'enum' => ['all', 'any'], 'description' => 'For rules: must all match, or any one. Default all.'],
				'groups'      => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
					'match' => ['type' => 'string', 'enum' => ['all', 'any']],
					'rules' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
					'type'    => ['type' => 'string', 'description' => 'From list_advancedmodules_rule_types.'],
					'exclude' => ['type' => 'boolean', 'description' => 'true to show when this rule does NOT match.'],
					'params'  => ['type' => 'object', 'description' => 'selection and the type\'s options; list_advancedmodules_rule_types has them.'],
				], 'required' => ['type']]],
				], 'required' => ['rules']], 'description' => 'Rule groups, for (A and B) or (C). Send rules or groups, not both.'],
				'match'       => ['type' => 'string', 'enum' => ['all', 'any'], 'description' => 'Across groups: must all match, or any one. Default all.'],

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

		$this->ammRefuseUnknown($arguments);
		$this->ammRequire($actor, 'com_conditions', 'core.edit');

		$id     = $this->ammId($arguments, 'set_id');
		$set    = $this->ammSet($id);
		$before = $this->ammSetEffect($set);
		$rules  = isset($arguments['rules']) || isset($arguments['groups']);

		if ($rules || isset($arguments['match'])) {
			$this->ammRefuseIfPhp($id, 'changing its rules or matching');
			$this->ammRefuseIfFeedsPhp($id);
		}

		foreach (['name' => 100, 'description' => 2000, 'category' => 50] as $field => $max) {
			if (\array_key_exists($field, $arguments)) {
				$set->{$field} = $this->ammPlainText($field, (string) $arguments[$field], $max);
			}
		}

		if (trim((string) $set->name) === '') {
			throw new \RuntimeException('Nothing was saved. A Condition Set needs a name.');
		}

		if (\array_key_exists('color', $arguments)) {
			$set->color = $this->ammColor((string) $arguments['color']) ?: null;
		}

		if (isset($arguments['match'])) {
			$set->match_all = $this->ammMatch($arguments['match']);
		}

		if ($rules) {
			$set->groups = $this->ammBuildGroups($arguments, $id);
		}

		$usage = $this->ammUsage([$id])[$id];

		if (!empty($arguments['dry_run'])) {
			return ToolResult::json([
				'ok'           => true,
				'dry_run'      => true,
				'effect_now'   => $before,
				'effect_after' => $this->ammSetEffect($set),
				'would_change' => $usage,
			]);
		}

		$this->ammSaveSet($set);

		return ToolResult::json([
			'ok'      => true,
			'set'     => $this->ammFormatSet($this->ammSet($id)),
			'changed' => $usage,
		] + $this->ammEffectNotes());
	}
}
