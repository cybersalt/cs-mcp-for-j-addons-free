<?php
/**
 * @package     MCP for Advanced Module Manager
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Grouping;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\AmmBootTrait;
use Joomla\CMS\User\User;


final class DetachConditionSetTool extends AbstractTool
{
	use AmmBootTrait;

	public function getName(): string { return 'detach_advancedmodules_condition_set'; }

	public function getDescription(): string
	{
		return 'Take modules off their Condition Set. With the plugin on, a module with no set shows on every page, to every '
			. 'visitor, and the response says so for each one. The set stays. dry_run reports without changing.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'module_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Up to 500.'],
				'dry_run'    => ['type' => 'boolean'],
			],
			'required'             => ['module_ids'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ammInstalled()) {
			return $this->ammNotInstalledError();
		}

		$ids = $this->ammIds($arguments, 'module_ids');
		$this->ammRequire($actor, 'com_conditions', 'core.edit');
		$this->ammRequireModuleEdit($actor, $ids);

		$modules = $this->ammModules($ids);

		if ($missing = array_diff($ids, array_keys($modules))) {
			throw new \RuntimeException('Nothing was changed. There is no module with id ' . implode(', ', $missing) . '.');
		}

		$sets = $this->ammLoadSets(array_column($modules, 'condition_id'));
		$out  = [];

		foreach ($modules as $id => $module) {
			$set   = $sets[$module->condition_id] ?? null;
			$out[] = ['id' => (int) $id, 'title' => $module->title, 'was_on_set' => $set ? ['id' => (int) $set->id, 'name' => $set->name] : null];
		}

		if (empty($arguments['dry_run'])) {
			$this->ammUnmap($ids);
		}

		return ToolResult::json([
			'ok'      => true,
			'dry_run' => !empty($arguments['dry_run']),
			'modules' => $out,
			'note'    => 'A module with no Condition Set shows on every page, to every visitor, while the plugin is on.',
		] + $this->ammEffectNotes());
	}
}
