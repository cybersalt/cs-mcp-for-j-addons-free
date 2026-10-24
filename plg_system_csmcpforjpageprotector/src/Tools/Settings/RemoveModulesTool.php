<?php
/**
 * @package     MCP for Page Protector
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Settings;

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\ProtectionHelper;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\PageProtectorBootTrait;
use Joomla\CMS\User\User;

final class RemoveModulesTool extends AbstractTool
{
	use PageProtectorBootTrait;
	use SettingsSupportTrait;

	public function getName(): string { return 'remove_page_protector_modules'; }

	public function getDescription(): string
	{
		return 'Stop protecting site modules, by id and/or by position, keeping the rest. A module protected BOTH by its '
			. 'id and by its position stays protected until both are removed; the response lists any module that is '
			. 'still protected through its position.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'module_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
				'positions'  => ['type' => 'array', 'items' => ['type' => 'string']],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($error = $this->ppCheck()) {
			return $error;
		}

		$this->ppRequireAccess($actor, 'options');

		$ids       = $this->ppIdList($arguments, 'module_ids', false);
		$positions = isset($arguments['positions']) ? $this->ppNormalisePositions($arguments['positions']) : [];

		if ($ids === [] && $positions === []) {
			throw new \InvalidArgumentException('Pass module_ids and/or positions.');
		}

		$params       = $this->ppStoredParams();
		$curIds       = ProtectionHelper::getProtectedModuleIds($params);
		$curPositions = ProtectionHelper::getProtectedPositions($params);
		$keepIds      = array_values(array_diff($curIds, $ids));
		$keepPos      = array_values(array_diff($curPositions, $positions));
		$patch        = [];

		if ($keepIds !== $curIds) {
			$patch['protect_modules'] = $keepIds;
		}

		if ($keepPos !== $curPositions) {
			$patch['protect_positions'] = $keepPos;
		}

		if ($patch === []) {
			return ToolResult::json(['ok' => true, 'saved' => false, 'reason' => 'none of these is protected', 'protect_modules' => $curIds, 'protect_positions' => $curPositions]);
		}

		$checked = $this->ppValidatePatch($patch, $params);
		$result  = $this->ppSaveSettings($checked['values']);

		// A module whose id was removed but whose position is still protected stays protected.
		$stillByPosition = [];

		foreach ($this->ppModules($ids) as $module) {
			if (empty($module['missing']) && \in_array($module['position'], $keepPos, true)) {
				$stillByPosition[] = ['id' => $module['id'], 'title' => $module['title'], 'position' => $module['position']];
			}
		}

		return ToolResult::json([
			'ok'                          => $result['saved'] && $result['verified'],
			'removed_module_ids'          => array_values(array_intersect($ids, $curIds)),
			'removed_positions'           => array_values(array_intersect($positions, $curPositions)),
			'not_protected'               => ['module_ids' => array_values(array_diff($ids, $curIds)), 'positions' => array_values(array_diff($positions, $curPositions))],
			'still_protected_by_position' => $stillByPosition,
			'protect_modules'             => $keepIds,
			'protect_positions'           => $keepPos,
		] + $result);
	}
}
