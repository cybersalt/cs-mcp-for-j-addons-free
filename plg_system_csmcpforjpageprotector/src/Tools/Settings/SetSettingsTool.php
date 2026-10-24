<?php
/**
 * @package     MCP for Page Protector
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Settings;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\PageProtectorBootTrait;
use Joomla\CMS\User\User;

final class SetSettingsTool extends AbstractTool
{
	use PageProtectorBootTrait;
	use SettingsSupportTrait;

	public function getName(): string { return 'set_page_protector_settings'; }

	public function getDescription(): string
	{
		return 'Change one or more Page Protector options. Only the keys you pass change; everything else is kept, and '
			. 'null puts an option back to its default (removes it from storage). '
			. 'Saved through Joomla\'s own Options save (com_config), so Page Protector\'s after-save work runs: '
			. 'turning on "anonymize_ip" shortens every IP already in the log (that cannot be undone), and the page, '
			. 'article and module caches are cleaned. Validated against the installed config.xml and the site: menu '
			. 'items, modules, positions and user groups must exist, IP lists must parse, unknown keys are refused. '
			. 'Array options (menu_items, protect_modules, protect_positions, exempt_groups) are replaced whole; to add or '
			. 'remove single entries use the add_/remove_page_protector_* tools. Switching protection_mode to "all_except" '
			. 'puts the check in front of EVERY page and needs confirm_all_pages: true. dry_run runs the same validation '
			. 'and shows the change without saving (a site plugin listening to Options validation, such as mySites.guru\'s '
			. 'action log, may still record it). See get_page_protector_settings include_schema for the keys.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'settings'          => ['type' => 'object', 'description' => 'Option name => new value, e.g. {"pass_lifetime": 72, "auto_submit": 0}.'],
				'confirm_all_pages' => ['type' => 'boolean', 'description' => 'Required to switch protection_mode to all_except.'],
				'dry_run'           => ['type' => 'boolean', 'description' => 'Validate and show the change without saving.'],
			],
			'required'             => ['settings'],
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

		$patch = $arguments['settings'] ?? null;

		if (!\is_array($patch) || array_is_list($patch) && $patch !== []) {
			throw new \InvalidArgumentException('settings must be an object of option => value.');
		}

		$checked = $this->ppValidatePatch($patch, $this->ppStoredParams());

		if ($checked['switches_to_all_except'] && empty($arguments['confirm_all_pages'])) {
			throw new \InvalidArgumentException(
				'Refused, nothing was written: protection_mode "all_except" puts the check in front of EVERY page of the '
				. 'site except the menu_items listed (which then become the exclusions). Pass confirm_all_pages: true if '
				. 'that is intended.'
			);
		}

		$result = $this->ppSaveSettings($checked['values'], !empty($arguments['dry_run']));

		return ToolResult::json([
			'ok'       => $result['saved'] ? $result['verified'] : true,
			'warnings' => $checked['warnings'],
		] + $result);
	}
}
