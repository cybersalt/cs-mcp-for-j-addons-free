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


final class GetConditionSetTool extends AbstractTool
{
	use AmmBootTrait;

	public function getName(): string { return 'get_advancedmodules_condition_set'; }

	public function getDescription(): string
	{
		return 'One Condition Set in full: its groups and rules with ids, what it means in plain English, and every module and '
			. 'other extension using it. PHP rules are reported by size and fingerprint only. Rule ids change every time the set '
			. 'is saved, so read them again before updating a rule. Read-only.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => ['set_id' => ['type' => 'integer']], 'required' => ['set_id'], 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ammInstalled()) {
			return $this->ammNotInstalledError();
		}

		$this->ammRequire($actor, 'com_conditions');

		$id = $this->ammId($arguments, 'set_id');

		return ToolResult::json(['ok' => true, 'set' => $this->ammFormatSet($this->ammSet($id), $this->ammUsage([$id])[$id])]);
	}
}
