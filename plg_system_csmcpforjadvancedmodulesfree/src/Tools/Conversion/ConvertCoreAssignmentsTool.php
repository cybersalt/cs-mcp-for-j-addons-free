<?php
/**
 * @package     MCP for Advanced Module Manager
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Conversion;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\AmmBootTrait;
use Joomla\CMS\User\User;


final class ConvertCoreAssignmentsTool extends AbstractTool
{
	use AmmBootTrait;
	use ConversionSupportTrait;

	public function getName(): string { return 'convert_advancedmodules_core_assignments'; }

	public function getDescription(): string
	{
		return 'Create and attach the Condition Sets from get_advancedmodules_core_conversion_preview, so modules keep showing '
			. 'as Joomla\'s own settings say. Joomla\'s settings aren\'t changed. Modules assigned to no pages are not converted: '
			. 'the response lists them, because they need a person to decide. Without confirm: true this only reports the plan.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'module_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Default: every published front-end module without a set.'],
				'confirm'    => ['type' => 'boolean', 'description' => 'true to create and attach. Without it nothing changes.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ammInstalled()) {
			return $this->ammNotInstalledError();
		}

		$this->ammRequire($actor, 'com_conditions', 'core.create', 'core.edit', 'core.edit.state');

		$plan = $this->ammConversionPlan(isset($arguments['module_ids']) ? $this->ammIds($arguments, 'module_ids') : null);

		$moduleIds = [];

		foreach ($plan['proposals'] as $proposal) {
			$moduleIds = array_merge($moduleIds, array_column($proposal['modules'], 'id'));
		}

		$this->ammRequireModuleEdit($actor, $moduleIds);

		if (!$this->ammConfirmed($arguments)) {
			return ToolResult::json(['ok' => true, 'dry_run' => true, 'message' => 'Nothing was changed. Send confirm: true to create and attach these sets.'] + $this->ammConversionReport($plan));
		}

		$done = [];

		foreach ($plan['proposals'] as $proposal) {
			$ids   = array_column($proposal['modules'], 'id');
			$setId = $proposal['reuse_set_id'] ?? $this->ammSaveSet((object) [
				'name'      => $proposal['name'],
				'published' => 1,
				'match_all' => 1,
				'groups'    => [(object) ['match_all' => 1, 'ordering' => 0, 'rules' => $proposal['rules']]],
			]);

			$this->ammMap($setId, $ids);

			$done[] = ['set_id' => $setId, 'reused' => isset($proposal['reuse_set_id']), 'name' => $proposal['name'], 'module_ids' => $ids];
		}

		$report = $this->ammConversionReport($plan);

		return ToolResult::json([
			'ok'               => true,
			'converted'        => $done,
			'no_rules_needed'  => $report['no_rules_needed'],
			'needs_a_decision' => $report['needs_a_decision'],
			'note'             => 'Joomla\'s own module settings were not changed.',
		] + $this->ammEffectNotes());
	}
}
