<?php
/**
 * @package     MCP for Advanced Module Manager
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Conversion;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\AmmBootTrait;
use Joomla\CMS\User\User;


final class GetCoreConversionPreviewTool extends AbstractTool
{
	use AmmBootTrait;
	use ConversionSupportTrait;

	public function getName(): string { return 'get_advancedmodules_core_conversion_preview'; }

	public function getDescription(): string
	{
		return 'Work out the Condition Sets that would keep modules showing as Joomla\'s own menu assignment, access level, '
			. 'language and publishing dates say today. With the plugin on those settings are ignored, so run this before turning it on, or to '
			. 'repair a site where it already is. Modules with the same settings share one proposed set, and an existing '
			. 'set with the same rules is reused. Modules that have a set, or need none, are left alone. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'module_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Default: every published front-end module without a set.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ammInstalled()) {
			return $this->ammNotInstalledError();
		}

		$this->ammRequire($actor, 'com_advancedmodules');
		$this->ammRequire($actor, 'com_conditions');

		$plan = $this->ammConversionPlan(isset($arguments['module_ids']) ? $this->ammIds($arguments, 'module_ids') : null);

		return ToolResult::json(['ok' => true] + $this->ammConversionReport($plan) + $this->ammEffectNotes());
	}
}
