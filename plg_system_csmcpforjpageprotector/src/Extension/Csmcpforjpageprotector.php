<?php
/**
 * @package     MCP for Page Protector
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpageprotector\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * Page Protector MCP add-on. Built against Cybersalt Page Protector 0.2.1
 * (2026-10-20) on Joomla 6.2.0; needs Page Protector 0.2.0 or later.
 *
 * WHY THIS ADD-ON IS FREE. Page Protector is free, so MCP access to it is free too.
 *
 * ---------------------------------------------------------------------------
 * THREE FACTS SHAPED EVERY TOOL HERE
 * ---------------------------------------------------------------------------
 *
 *   1. THE LOGIC IS ALREADY IN PAGE PROTECTOR'S HELPERS. What is protected, why
 *      the captcha cannot run, the dashboard checks and stats, the Event Log
 *      filters: all of it is called, not copied (see PageProtectorBootTrait).
 *
 *   2. SETTINGS MUST BE SAVED THE WAY OPTIONS SAVES THEM. Page Protector's
 *      after-save work (shortening the IPs already logged, cleaning the page
 *      and module caches) only runs on onExtensionAfterSave from com_config.
 *      Writes go through com_config's ComponentModel with the full merged
 *      params, never a partial set (see SettingsSupportTrait).
 *
 *   3. THE ALLOW-LIST FAILS SILENTLY. Page Protector ignores an entry it cannot
 *      parse, and splits on commas before it strips # comments, so a commented-
 *      out "a, b" line leaves b live. Every IP tool parses the list the same
 *      way and says so (see IpListSupportTrait).
 *
 * ---------------------------------------------------------------------------
 * WHAT WE DELIBERATELY DO NOT BUILD
 * ---------------------------------------------------------------------------
 *
 *   - No permission editing: core MCP for J's set_component_permission does it.
 *   - No "accept the no-guarantee notice": that is a person's decision.
 *   - No IP blocking: Page Protector has none (a failed visitor is just checked
 *     again). The most-challenged list gives ranges to block at a firewall.
 */
final class Csmcpforjpageprotector extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Status.
		\Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Status\GetStatusTool::class,

		// Settings.
		\Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Settings\GetSettingsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Settings\SetSettingsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Settings\AddMenuItemsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Settings\RemoveMenuItemsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Settings\AddModulesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Settings\RemoveModulesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Settings\AddExemptIpsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Settings\RemoveExemptIpsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Settings\ResetPassesTool::class,

		// Event log.
		\Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Log\GetStatsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Log\ListEventsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Log\GetTopIpsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Log\DeleteEventsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Log\ClearLogTool::class,
	];

	public static function getSubscribedEvents(): array
	{
		/*
		 * Runs on EVERY request. RegisterToolsEvent lives in com_csmcpforj; a bare
		 * ::EVENT_NAME access would autoload it and fatal the whole site (front end
		 * and administrator) when MCP for Joomla is not installed. class_exists()
		 * returns false instead. Do not "simplify" this.
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
