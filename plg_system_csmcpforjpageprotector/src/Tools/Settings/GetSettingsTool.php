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

final class GetSettingsTool extends AbstractTool
{
	use PageProtectorBootTrait;
	use SettingsSupportTrait;

	public function getName(): string { return 'get_page_protector_settings'; }

	public function getDescription(): string
	{
		return 'Every Page Protector option with its current value (defaults marked where nothing is stored yet), with '
			. 'protected menu items, modules, positions and exempt user groups resolved to titles, and the IP allow-list '
			. 'and trusted-proxy lists parsed the way Page Protector reads them: an invalid entry is flagged because Page '
			. 'Protector ignores it without a word, and an entry after a comma inside a # comment is flagged because it is '
			. 'live. include_schema adds each option\'s type, allowed values and limits, for set_page_protector_settings. '
			. 'The pass-signing salt is never returned.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'include_schema' => ['type' => 'boolean', 'description' => 'Add each option\'s type, options, default and limits.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($error = $this->ppCheck()) {
			return $error;
		}

		$this->ppRequireAccess($actor, 'view');

		return ToolResult::json(['ok' => true] + $this->ppSettingsView($this->ppStoredParams(), !empty($arguments['include_schema'])));
	}
}
