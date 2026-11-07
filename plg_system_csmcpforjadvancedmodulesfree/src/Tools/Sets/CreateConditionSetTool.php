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


final class CreateConditionSetTool extends AbstractTool
{
	use AmmBootTrait;

	public function getName(): string { return 'create_advancedmodules_condition_set'; }

	public function getDescription(): string
	{
		return 'Create a Condition Set: a named group of page and visitor rules that any number of modules can share. Then '
			. 'attach_advancedmodules_condition_set puts modules on it. If a set with the same rules exists the response says '
			. 'so. PHP rules are refused. Pro rule types are refused here and written by the Pro add-on.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'name'        => ['type' => 'string', 'description' => 'Plain text, up to 100 characters. The vendor adds a number if the name is taken.'],
				'description' => ['type' => 'string', 'description' => 'Plain text.'],
				'category'    => ['type' => 'string', 'description' => 'Up to 50 characters.'],
				'color'       => ['type' => 'string', 'description' => 'Like #dc2a28.'],
				'published'   => ['type' => 'boolean', 'description' => 'Default true. A set that isn\'t published does nothing, so its modules show on every page.'],

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
			'required'             => ['name'],
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

		$published = !\array_key_exists('published', $arguments) || $this->ammBool($arguments['published'], 'published');
		$this->ammRequire($actor, 'com_conditions', 'core.create', ...($published ? ['core.edit.state'] : []));

		$set = (object) [
			'name'        => $this->ammPlainText('name', $this->requireString($arguments, 'name'), 100),
			'description' => $this->ammPlainText('description', (string) ($arguments['description'] ?? ''), 2000),
			'category'    => $this->ammPlainText('category', (string) ($arguments['category'] ?? ''), 50),
			'color'       => isset($arguments['color']) ? ($this->ammColor((string) $arguments['color']) ?: null) : null,
			'published'   => $published ? 1 : 0,
			'match_all'   => $this->ammMatch($arguments['match'] ?? null),
			'groups'      => $this->ammBuildGroups($arguments),
		];

		$id  = $this->ammSaveSet($set);
		$set = $this->ammSet($id);

		return ToolResult::json([
			'ok'             => true,
			'set'            => $this->ammFormatSet($set),
			'identical_sets' => $this->ammIdenticalSets($set),
			'next'           => 'attach_advancedmodules_condition_set puts modules on this set.',
		] + $this->ammEffectNotes());
	}
}
