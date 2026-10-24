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

final class AddModulesTool extends AbstractTool
{
	use PageProtectorBootTrait;
	use SettingsSupportTrait;

	public function getName(): string { return 'add_page_protector_modules'; }

	public function getDescription(): string
	{
		return 'Protect site modules, by id and/or by whole position, keeping what is already protected. Visitors '
			. 'without a pass see a placeholder with a "Show content" check instead of the module (module_mode '
			. '"placeholder") or nothing at all ("hide"), on every page the module appears on, including {loadmodule}. '
			. 'Logged-in users, allow-listed IPs and verified search engines still see it. Positions must hold at least '
			. 'one site module, as in the Options picker. Saved through Joomla\'s Options save, which also cleans the '
			. 'page and module caches so an unprotected copy is not served on.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'module_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Site module ids.'],
				'positions'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Module positions: every module in them is protected.'],
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
		$newIds       = array_values(array_diff($ids, $curIds));
		$newPositions = array_values(array_diff($positions, $curPositions));
		$patch        = [];

		if ($newIds !== []) {
			$patch['protect_modules'] = array_merge($curIds, $newIds);
		}

		if ($newPositions !== []) {
			$patch['protect_positions'] = array_merge($curPositions, $newPositions);
		}

		if ($patch === []) {
			return ToolResult::json(['ok' => true, 'saved' => false, 'reason' => 'already protected', 'protect_modules' => $curIds, 'protect_positions' => $curPositions]);
		}

		$checked = $this->ppValidatePatch($patch, $params);
		$result  = $this->ppSaveSettings($checked['values']);

		return ToolResult::json([
			'ok'                => $result['saved'] && $result['verified'],
			'added_modules'     => array_values($this->ppModules($newIds)),
			'added_positions'   => $newPositions,
			'already_protected' => ['module_ids' => array_values(array_intersect($ids, $curIds)), 'positions' => array_values(array_intersect($positions, $curPositions))],
			'module_mode'       => (string) $params->get('module_mode', ProtectionHelper::MODULE_MODE_PLACEHOLDER),
			'protect_modules'   => $patch['protect_modules'] ?? $curIds,
			'protect_positions' => $patch['protect_positions'] ?? $curPositions,
		] + $result);
	}
}
