<?php
/**
 * @package     MCP for AcyMailing
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * MCP for AcyMailing (Starter) — the free add-on for Acyba's AcyMailing
 * (com_acym), every edition. Registers the tools for everything AcyMailing
 * Starter can do; capabilities that exist only in Essential/Enterprise live in
 * csmcpforjacymailingpro and share no tool name with this plugin
 * (ToolRegistry::register() is a silent last-wins overwrite).
 *
 * Read AcymailingBootTrait first: it explains the /api/ URL trap, the
 * double-encoded mail columns and why every mail side effect is opt-in.
 */
final class Csmcpforjacymailingfree extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Diagnostics, configuration and scoped table access
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Diagnostics\GetComponentInfoTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Diagnostics\CheckHealthTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Diagnostics\GetConfigTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Diagnostics\SetConfigTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Diagnostics\ListTablesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Diagnostics\QueryTableTool::class,

		// Lists
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Lists\ListListsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Lists\GetListTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Lists\CreateListTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Lists\UpdateListTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Lists\DeleteListTool::class,

		// Subscribers and subscriptions
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Subscribers\ListSubscribersTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Subscribers\GetSubscriberTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Subscribers\CreateSubscriberTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Subscribers\UpdateSubscriberTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Subscribers\DeleteSubscriberTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Subscribers\SubscribeSubscribersTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Subscribers\UnsubscribeSubscribersTool::class,

		// Reports
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Reports\GetListMembershipReportTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Reports\CheckSubscriberDuplicatesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Reports\CheckEventbookingSyncTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Reports\GetCampaignStatsTool::class,

		// Campaigns and templates
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Campaigns\ListCampaignsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Campaigns\GetCampaignTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Campaigns\CreateCampaignTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Campaigns\UpdateCampaignTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Campaigns\DeleteCampaignTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Campaigns\ListTemplatesTool::class,

		// Sending — every path that can reach a real inbox is confirm-gated
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Sending\SendTestEmailTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Sending\QueueCampaignTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Sending\GetQueueStatusTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Sending\CancelCampaignSendingTool::class,
		\Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Sending\SetCampaignPausedTool::class,
	];

	public static function getSubscribedEvents(): array
	{
		/*
		 * Runs on every request. Reading RegisterToolsEvent::EVENT_NAME autoloads a
		 * class from com_csmcpforj; without that component it would be a fatal on
		 * every page, admin included. class_exists() returns false instead.
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
