<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * Community Builder MCP add-on. Built against Community Builder 2.12.0
 * (2026-08-28) on Joomla 6.2.0.
 *
 * WHY THIS ADD-ON IS FREE. Community Builder is free and GPL. Joomlapolis sells
 * add-ons for it (CB Paid Subscriptions, GroupJive, CB Gallery, CB Activity,
 * CB Privacy...), each with its own tables; none of them is wrapped here, and
 * the scoped table reader only reaches tables CB core creates.
 *
 * ---------------------------------------------------------------------------
 * FIVE FACTS SHAPED EVERY TOOL HERE
 * ---------------------------------------------------------------------------
 *
 *   1. A MEMBER IS TWO ROWS. `#__users` and `#__comprofiler` share the id. CB's
 *      `UserTable::store()` writes the Joomla user first (through Joomla's own
 *      `User::save()`, so Joomla's user plugins fire) and then the CB row, so
 *      every member write here goes through CB's `UserTable` and every write is
 *      verified by re-reading both rows and diffing them.
 *
 *   2. A CUSTOM FIELD IS A COLUMN. Creating a field ALTERs `#__comprofiler`;
 *      `FieldTable::store()` does it through CB's own XML-described schema
 *      (`cbFieldHandler::adaptSQL()`), renames the field to `cb_<name>`, and
 *      renames the column when the name changes. Deleting a field DROPS the
 *      column and every member's answer, whatever the comment in
 *      `FieldTable::delete()` says (verified live). We never write
 *      `#__comprofiler_fields` by hand. Option lists are a replace-set
 *      (`FieldValueTable::updateFieldValues()` deletes whatever is not resubmitted).
 *
 *   3. MODERATION SENDS MAIL, AND SOMETIMES RESETS PASSWORDS. Approving,
 *      rejecting, confirming, banning and unbanning all send email from inside
 *      CB's own methods, and with "Generate Registration Passwords" (`emailpass`)
 *      on, approving or confirming REPLACES the member's password with a random
 *      one that only the welcome email carries. Every CB email is held back unless
 *      the caller passes `send_email: true` (Support\CbMailGuard), and the
 *      password-resetting paths refuse unless the email is going out.
 *
 *   4. CB DOES NOT RUN UNDER JOOMLA'S API APPLICATION AS-IS. Its mail path calls
 *      `getMenu()`, which throws there. Every CB call runs inside a temporary
 *      AdministratorApplication — CB's own back-end context — see the boot trait.
 *
 *   5. THE CONFIGURATION IS ONE JSON BLOB, `#__comprofiler_plugin.params` id 1,
 *      and CB's Configuration screen replaces it wholesale. `set_cb_config`
 *      re-reads, merges the named keys, validates them against CB's own
 *      configuration form, and stores through CB's `PluginTable` so its side
 *      effect (name style toggles the name fields) still happens.
 *
 * ---------------------------------------------------------------------------
 * WHAT WE DELIBERATELY DO NOT BUILD
 * ---------------------------------------------------------------------------
 *
 *   - No member creation or deletion: Joomla's own user tools do that, and CB's
 *     system plugin cleans up its rows on delete.
 *   - No writes to username, email, password or user groups: those belong to
 *     `#__users` and the core MCP user tools.
 *   - No writes to file, image, password, terms or computed fields.
 *   - Nothing for CB's paid add-ons.
 */
final class Csmcpforjcommunitybuilder extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Diagnostics — orientation, health and scoped table access.
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Diagnostics\GetComponentInfoTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Diagnostics\CheckHealthTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Diagnostics\ListTablesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Diagnostics\QueryTableTool::class,

		// Configuration — the registration audit, and targeted, schema-validated config writes.
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Config\GetRegistrationConfigTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Config\GetConfigTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Config\SetConfigTool::class,

		// Members — the Joomla account plus the CB profile, and narrow profile writes.
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Members\ListUsersTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Members\GetUserTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Members\UpdateUserFieldsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Members\ListPendingRegistrationsTool::class,

		// Moderation — CB's own UserTable methods, mail held back unless asked for.
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Moderation\ApproveUserTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Moderation\ConfirmUserTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Moderation\SetUserBanTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Moderation\SetUserBlockTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Moderation\ListUserReportsTool::class,

		// Fields — a field is a column; created, renamed and deleted through CB's FieldTable.
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Fields\ListFieldsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Fields\GetFieldTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Fields\ListFieldTypesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Fields\CreateFieldTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Fields\UpdateFieldTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Fields\DeleteFieldTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Fields\SetFieldValuesTool::class,

		// Tabs.
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Tabs\ListTabsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Tabs\GetTabTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Tabs\CreateTabTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Tabs\UpdateTabTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Tabs\DeleteTabTool::class,

		// Community — connections, member lists, CB's own plugins.
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Community\ListConnectionsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Community\ListListsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Community\ListPluginsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Community\SetPluginStateTool::class,
	];

	public static function getSubscribedEvents(): array
	{
		/*
		 * This runs on EVERY request, for every enabled system plugin, before
		 * anything has a chance to check whether MCP for Joomla is present.
		 *
		 * RegisterToolsEvent lives in com_csmcpforj. Reading ::EVENT_NAME off it
		 * is a class-constant access, which triggers the autoloader — and if the
		 * component is not installed, PHP raises
		 * `Error: Class "...RegisterToolsEvent" not found`. That is a fatal on
		 * every page load, front end AND administrator, which locks the operator
		 * out of the very screen they would use to disable this plugin.
		 *
		 * class_exists() returns false instead of throwing, so without the core
		 * component this plugin subscribes to nothing and the site is untouched.
		 * Do not "simplify" this back to a bare constant access.
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
