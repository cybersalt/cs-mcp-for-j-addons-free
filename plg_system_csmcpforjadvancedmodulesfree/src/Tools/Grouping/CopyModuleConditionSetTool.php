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


final class CopyModuleConditionSetTool extends AbstractTool
{
	use AmmBootTrait;

	public function getName(): string { return 'copy_advancedmodules_module_condition_set'; }

	public function getDescription(): string
	{
		return 'Make other modules show wherever one module shows: they join that module\'s Condition Set, so the rules stay '
			. 'shared and a later change is made once. To give them their own copy, use duplicate_advancedmodules_condition_set '
			. 'and attach the copy. Any set they had is replaced, and the response lists those. dry_run reports without changing.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'from_module_id' => ['type' => 'integer'],
				'to_module_ids'  => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Up to 500.'],
				'dry_run'        => ['type' => 'boolean'],
			],
			'required'             => ['from_module_id', 'to_module_ids'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ammInstalled()) {
			return $this->ammNotInstalledError();
		}

		$from = $this->ammId($arguments, 'from_module_id');
		$ids  = array_values(array_diff($this->ammIds($arguments, 'to_module_ids'), [$from]));

		if ($ids === []) {
			throw new \RuntimeException('to_module_ids must name modules other than from_module_id.');
		}

		$this->ammRequire($actor, 'com_conditions', 'core.edit');
		$this->ammRequireModuleEdit($actor, $ids);

		$source = $this->ammModules([$from])[$from] ?? null;

		if ($source === null) {
			throw new \RuntimeException('There is no module with id ' . $from . '.');
		}

		if (!$source->condition_id) {
			throw new \RuntimeException('Nothing was changed. Module ' . $from . ' has no Condition Set to share.');
		}

		return ToolResult::json($this->ammAttach((int) $source->condition_id, $ids, !empty($arguments['dry_run'])));
	}
}
