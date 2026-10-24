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

final class RemoveMenuItemsTool extends AbstractTool
{
	use PageProtectorBootTrait;
	use SettingsSupportTrait;

	public function getName(): string { return 'remove_page_protector_menu_items'; }

	public function getDescription(): string
	{
		return 'Take menu items out of Page Protector\'s page list, keeping the rest. In "selected" mode that UNPROTECTS '
			. 'them; in "all_except" mode the list is the exclusions, so removing an item PROTECTS it again. The response '
			. 'says which applies. Ids not in the list are reported, not an error. Works for ids whose menu item has '
			. 'since been deleted, which is how to clear one the dashboard lists as "deleted".';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'menu_item_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Menu item ids to take out of the list.'],
			],
			'required'             => ['menu_item_ids'],
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

		$ids     = $this->ppIdList($arguments, 'menu_item_ids');
		$params  = $this->ppStoredParams();
		$current = ProtectionHelper::getMenuItemIds($params);
		$remove  = array_values(array_intersect($ids, $current));
		$mode    = (string) $params->get('protection_mode', ProtectionHelper::MODE_SELECTED);
		$meaning = $mode === ProtectionHelper::MODE_ALL_EXCEPT
			? 'protection_mode is all_except: these menu items are no longer excluded, so they are PROTECTED again'
			: 'protection_mode is selected: these menu items are no longer protected';

		if ($remove === []) {
			return ToolResult::json(['ok' => true, 'saved' => false, 'reason' => 'none of the ids is in the list', 'not_in_list' => $ids, 'menu_items' => $current]);
		}

		$remaining = array_values(array_diff($current, $remove));
		$checked   = $this->ppValidatePatch(['menu_items' => $remaining], $params);
		$result    = $this->ppSaveSettings($checked['values']);

		$note = null;

		if ($mode === ProtectionHelper::MODE_SELECTED && $remaining === [] && !ProtectionHelper::hasModuleProtection($params)) {
			$note = 'Nothing is protected now: no menu items and no modules.';
		}

		return ToolResult::json([
			'ok'          => $result['saved'] && $result['verified'],
			'meaning'     => $meaning,
			'removed'     => $remove,
			'not_in_list' => array_values(array_diff($ids, $current)),
			'menu_items'  => $remaining,
			'note'        => $note,
		] + $result);
	}
}
