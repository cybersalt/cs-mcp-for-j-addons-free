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

final class AddMenuItemsTool extends AbstractTool
{
	use PageProtectorBootTrait;
	use SettingsSupportTrait;

	public function getName(): string { return 'add_page_protector_menu_items'; }

	public function getDescription(): string
	{
		return 'Add menu items to Page Protector\'s page list, keeping the ones already there. In the default "selected" '
			. 'mode that PROTECTS them (visitors without a pass get the check). In "all_except" mode the list is the '
			. 'EXCLUSIONS, so adding an item OPENS it; the response says which applies. Site menu items only; separator, '
			. 'alias, heading and URL items are refused because they have no page of their own. Saved through Joomla\'s '
			. 'Options save like set_page_protector_settings.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'menu_item_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Site menu item ids.'],
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
		$new     = array_values(array_diff($ids, $current));
		$mode    = (string) $params->get('protection_mode', ProtectionHelper::MODE_SELECTED);
		$meaning = $mode === ProtectionHelper::MODE_ALL_EXCEPT
			? 'protection_mode is all_except: these menu items are EXCLUDED, i.e. open to everyone'
			: 'protection_mode is selected: these menu items are PROTECTED';

		if ($new === []) {
			return ToolResult::json(['ok' => true, 'saved' => false, 'reason' => 'every id is already in the list', 'meaning' => $meaning, 'menu_items' => $current]);
		}

		$checked = $this->ppValidatePatch(['menu_items' => array_merge($current, $new)], $params);
		$result  = $this->ppSaveSettings($checked['values']);

		return ToolResult::json([
			'ok'              => $result['saved'] && $result['verified'],
			'meaning'         => $meaning,
			'added'           => array_values($this->ppMenuItems($new)),
			'already_present' => array_values(array_intersect($ids, $current)),
			'menu_items'      => array_merge($current, $new),
			'warnings'        => $checked['warnings'],
		] + $result);
	}
}
