<?php
/**
 * @package     MCP for Advanced Module Manager
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * Advanced Module Manager (Regular Labs) MCP add-on, the free tier.
 * Built against 12.1.0 and 12.1.0PRO with Conditions 26.9.10756.
 * cs-mcp-for-j-addons-free#11.
 *
 * WHY THERE ARE TWO PACKAGES. Advanced Module Manager has a free and a Pro
 * edition, and an add-on's tier mirrors the wrapped extension's. This package
 * covers what the Free edition does and works on both. Extra fields, HTML around
 * a module, the empty-module HTML, remove-module tags and the Pro rule types are
 * in csmcpforjadvancedmodulespro, which refuses unless the vendor's Pro build is
 * installed.
 *
 * ---------------------------------------------------------------------------
 * THE FACTS THAT SHAPED THE TOOLS (all from the 12.1.0 source)
 * ---------------------------------------------------------------------------
 *
 *   1. With plg_system_advancedmodules on, the plugin builds the module list
 *      itself on onPrepareModuleList (Helper::getModuleList()): `0 AS menuid`,
 *      and no m.access filter on the front end. So core's access and menu
 *      assignment stop deciding, core's update_module can report a change that
 *      does nothing, and a module with no set shows on every page, even one
 *      assigned to no pages. Language and publish dates aren't checked either
 *      (seen live: a module past its publish_down still shows). Every read says
 *      what actually decides.
 *
 *   2. A set that isn't published counts as no set
 *      (Helper::getConditionIdsByModuleIds() filters c.published = 1, and a
 *      module with no row passes). Unpublishing or trashing a shared set shows
 *      all its modules everywhere, so those need confirm.
 *
 *   3. Sets are shared: #__conditions_map attaches one set to many items, and
 *      other Regular Labs extensions use the same table. Every write that
 *      changes a set names what it reaches.
 *
 *   4. other__php runs code. Security::checkCanSavePhp() lets a Super User
 *      through, and an MCP token can be one, so nothing here writes a PHP rule,
 *      or attaches, publishes, copies or nests a set that runs one. Reads give a
 *      size and fingerprint, never the code.
 *
 *   5. Free and Pro ship the same Conditions package. The edition gate is
 *      Helper::getConditionTypes() in the plugin: Free returns a list, Pro
 *      returns []. Api\Conditions::passGroup() skips any type not on the list,
 *      so a Pro rule on a Free site is ignored rather than failing, and a set
 *      holding only those shows its modules everywhere.
 *
 *   6. A save replaces every group and rule row (GroupModel::replaceByCondition()),
 *      so rule ids don't survive a save. The rule tools refuse a stale id rather
 *      than guess by position.
 *
 *   7. map() doesn't reset the condition cache, and delete() doesn't either, so
 *      we do it after every write (ammClearCaches()).
 *
 *   8. A set only acts on the front end: handleOnPrepareModuleList() returns on
 *      administrator pages. Administrator modules are refused.
 *
 *   9. A loop of nested sets fails the whole check (hasCircularReference()),
 *      hiding the module everywhere. Rules that would make one are refused.
 *
 *  10. Rule classes read options like include_children with no default, so
 *      ammBuildRule() always writes every option the form would.
 */
final class Csmcpforjadvancedmodulesfree extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Diagnostics.
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Diagnostics\GetComponentInfoTool::class,
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Diagnostics\CheckHealthTool::class,

		// Modules and what decides where they show.
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Modules\ListModulesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Modules\GetModuleVisibilityTool::class,
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Modules\SetModuleOptionsTool::class,

		// Condition Sets.
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Sets\ListConditionSetsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Sets\GetConditionSetTool::class,
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Sets\CreateConditionSetTool::class,
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Sets\UpdateConditionSetTool::class,
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Sets\SetConditionSetStateTool::class,
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Sets\DuplicateConditionSetTool::class,
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Sets\DeleteConditionSetTool::class,

		// Rules within a set.
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Rules\ListRuleTypesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Rules\AddRuleTool::class,
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Rules\UpdateRuleTool::class,
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Rules\RemoveRuleTool::class,

		// Grouping modules on shared sets.
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Grouping\ListModulesWithIdenticalRulesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Grouping\AttachConditionSetTool::class,
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Grouping\DetachConditionSetTool::class,
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Grouping\CopyModuleConditionSetTool::class,

		// From Joomla's own assignments to Condition Sets.
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Conversion\GetCoreConversionPreviewTool::class,
		\Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Conversion\ConvertCoreAssignmentsTool::class,
	];

	public static function getSubscribedEvents(): array
	{
		/*
		 * This runs on EVERY request, for every enabled system plugin, before
		 * anything has a chance to check whether MCP for J is present.
		 * RegisterToolsEvent lives in com_csmcpforj; reading ::EVENT_NAME off it
		 * without the component installed is a fatal on every page load, front
		 * end AND administrator. class_exists() returns false instead of
		 * throwing. Do not "simplify" this back to a bare constant access.
		 */
		if (!class_exists(RegisterToolsEvent::class)) {
			return [];
		}

		return [RegisterToolsEvent::EVENT_NAME => 'onRegisterTools'];
	}

	public function onRegisterTools(RegisterToolsEvent $event): void
	{
		$registry = $event->getRegistry();
		$db       = $this->getDatabase();

		foreach (self::TOOLS as $toolClass) {
			$registry->register(new $toolClass($db));
		}
	}

	public static function getToolClasses(): array
	{
		return self::TOOLS;
	}
}
