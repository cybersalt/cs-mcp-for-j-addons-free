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


final class ListModulesWithIdenticalRulesTool extends AbstractTool
{
	use AmmBootTrait;

	public function getName(): string { return 'list_advancedmodules_modules_with_identical_rules'; }

	public function getDescription(): string
	{
		return 'Find published Condition Sets with the same rules, even when saved in a different order, and the modules on '
			. 'each. Those modules can share one set, so a later change is made once. For each group it suggests the set to '
			. 'keep. Read-only: attach_advancedmodules_condition_set moves the modules and delete_advancedmodules_condition_set '
			. 'removes what is left.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ammInstalled()) {
			return $this->ammNotInstalledError();
		}

		$this->ammRequire($actor, 'com_conditions');

		$ids    = $this->db->setQuery('SELECT id FROM ' . $this->db->quoteName('#__conditions') . ' WHERE published = 1')->loadColumn() ?: [];
		$sets   = $this->ammLoadSets($ids);
		$usage  = $this->ammUsage($ids);
		$byHash = [];

		foreach ($sets as $id => $set) {
			$byHash[$this->ammRulesFingerprint($set)][] = (int) $id;
		}

		$groups = [];

		foreach ($byHash as $members) {
			if (\count($members) < 2) {
				continue;
			}

			usort($members, static fn($a, $b) => [\count($usage[$b]['modules']), $a] <=> [\count($usage[$a]['modules']), $b]);

			$groups[] = [
				'rules_mean'    => $this->ammSetSummary($sets[$members[0]]),
				'suggested_set' => $members[0],
				'sets'          => array_map(static fn($id) => [
					'id'               => $id,
					'name'             => $sets[$id]->name,
					'modules'          => $usage[$id]['modules'],
					'other_extensions' => $usage[$id]['other_extensions'],
				], $members),
			];
		}

		return ToolResult::json(['ok' => true, 'sets_checked' => \count($sets), 'identical_groups' => $groups]);
	}
}
