<?php
/**
 * @package     MCP for Advanced Module Manager
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\AmmBootTrait;
use Joomla\CMS\User\User;


final class GetComponentInfoTool extends AbstractTool
{
	use AmmBootTrait;

	public function getName(): string { return 'get_advancedmodules_component_info'; }

	public function getDescription(): string
	{
		return 'Start here. Which edition and versions of Advanced Module Manager (Regular Labs) this site runs, whether its '
			. 'system plugin is on, what that means for who decides module visibility, how many Condition Sets and attached '
			. 'modules there are, and which rule types this add-on writes. Read-only.';
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

		$this->ammRequire($actor, 'com_advancedmodules');

		$db       = $this->db;
		$pluginOn = $this->ammPluginEnabled();

		return ToolResult::json([
			'ok'         => true,
			'edition'    => $this->ammEdition(),
			'versions'   => $this->ammVersions(),
			'extensions' => [
				'component_enabled'           => $this->ammExtensionEnabled('component', 'com_advancedmodules'),
				'conditions_enabled'          => $this->ammExtensionEnabled('component', 'com_conditions'),
				'system_plugin_enabled'       => $pluginOn,
				'regular_labs_plugin_enabled' => $this->ammExtensionEnabled('plugin', 'regularlabs', 'system'),
			],
			'what_decides_visibility' => $pluginOn
				? 'The System - Advanced Module Manager plugin is on, so Condition Sets decide. Joomla\'s own module access and menu assignment no longer do, and a module with no Condition Set shows on every page.'
				: 'The System - Advanced Module Manager plugin is off, so Joomla\'s own module access and menu assignment decide, and Condition Sets do nothing.',
			'counts' => [
				'condition_sets'   => (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__conditions') . ' WHERE published <> -2')->loadResult(),
				'modules_attached' => (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__conditions_map') . ' WHERE extension = ' . $db->quote('com_advancedmodules'))->loadResult(),
			],
			'rule_types_this_add_on_writes' => $this->ammFreeRuleTypes(),
			'next' => 'check_advancedmodules_health says what needs attention. MCP for Advanced Module Manager Pro adds extra fields, HTML around modules and the Pro rule types, on sites running Advanced Module Manager Pro.',
		]);
	}
}
