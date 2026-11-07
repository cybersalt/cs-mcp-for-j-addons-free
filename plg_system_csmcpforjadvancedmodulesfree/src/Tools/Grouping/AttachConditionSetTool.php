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


final class AttachConditionSetTool extends AbstractTool
{
	use AmmBootTrait;

	public function getName(): string { return 'attach_advancedmodules_condition_set'; }

	public function getDescription(): string
	{
		return 'Put many front-end modules on one Condition Set in a single call, so they share one set of rules and a later '
			. 'change is made once. This is the normal way to assign modules. A module can have one set, so this replaces any '
			. 'set it had, and the response lists those and any set left with nothing attached. dry_run reports without '
			. 'changing. Sets that run PHP, trashed sets and administrator modules are refused.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'set_id'     => ['type' => 'integer'],
				'module_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Up to 500.'],
				'dry_run'    => ['type' => 'boolean'],
			],
			'required'             => ['set_id', 'module_ids'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ammInstalled()) {
			return $this->ammNotInstalledError();
		}

		$setId = $this->ammId($arguments, 'set_id');
		$ids   = $this->ammIds($arguments, 'module_ids');

		$this->ammRequire($actor, 'com_conditions', 'core.edit');
		$this->ammRequireModuleEdit($actor, $ids);

		return ToolResult::json($this->ammAttach($setId, $ids, !empty($arguments['dry_run'])));
	}
}
