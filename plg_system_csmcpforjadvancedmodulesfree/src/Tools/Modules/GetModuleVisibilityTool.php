<?php
/**
 * @package     MCP for Advanced Module Manager
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Modules;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\AmmBootTrait;
use Joomla\CMS\User\User;


final class GetModuleVisibilityTool extends AbstractTool
{
	use AmmBootTrait;

	public function getName(): string { return 'get_advancedmodules_module_visibility'; }

	public function getDescription(): string
	{
		return 'Why one module shows where it does: what decides it right now, its Condition Set with every rule in plain '
			. 'English, the other modules sharing that set (a change to the set changes them too), and the Joomla settings '
			. 'being ignored. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => ['module_id' => ['type' => 'integer']],
			'required'             => ['module_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ammInstalled()) {
			return $this->ammNotInstalledError();
		}

		$this->ammRequire($actor, 'com_advancedmodules');
		$this->ammRequire($actor, 'com_conditions');

		$id     = $this->ammId($arguments, 'module_id');
		$module = $this->ammModules([$id])[$id] ?? null;

		if ($module === null) {
			throw new \RuntimeException('There is no module with id ' . $id . '.');
		}

		$pluginOn   = $this->ammPluginEnabled();
		$set        = $module->condition_id ? ($this->ammLoadSets([(int) $module->condition_id])[(int) $module->condition_id] ?? null) : null;
		$assignment = $this->ammCoreAssignments([$id])[$id];
		$out        = $this->ammFormatModule($module, $set, $assignment, $this->ammPublicLevels(), $pluginOn);

		$out['core_menu_assignment'] = $assignment;

		if ((int) $module->client_id !== 0) {
			$out['note'] = 'This is an administrator module. Condition Sets only act on front-end modules.';
		}

		if ($set !== null) {
			$usage                  = $this->ammUsage([(int) $set->id])[(int) $set->id];
			$out['set']             = $this->ammFormatSet($set);
			$out['shares_set_with'] = array_values(array_filter($usage['modules'], static fn($m) => $m['id'] !== $id));

			if ($usage['other_extensions'] !== []) {
				$out['set_also_used_by'] = $usage['other_extensions'];
			}
		}

		return ToolResult::json(['ok' => true, 'module' => $out]);
	}
}
