<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * Akeeba Ticket System MCP add-on plugin. Registers a tool set for Akeeba Ltd's
 * com_ats help-desk component.
 *
 * WHAT SHAPE THIS COMPONENT IS
 * ----------------------------
 * A pleasant surprise after RSTicketsPro and Events Booking: ATS 5.x is a modern,
 * properly namespaced Joomla component (Akeeba\Component\ATS) with a real
 * services/provider.php, an MVCFactory, and models that genuinely extend
 * Joomla's AdminModel. There is no legacy-loader dance, no vendor-specific MVC
 * framework, and no RADInput object to construct. TicketModel and PostModel are
 * reachable through the factory and `save()` loads-then-binds, so a partial
 * payload is non-destructive — which is the opposite of the Events Booking
 * situation and worth saying out loud.
 *
 * ATS is also, unusually, already API-application-aware: it carries explicit
 * isClient('api') branches and an API-specific getForm() fix. Akeeba ship a
 * JSON:API plugin in the Pro edition, so someone has actually run this code
 * outside a web request. That is why this add-on needs fewer contortions than
 * its siblings.
 *
 * WHY THIS IS THE FREE ADD-ON
 * ---------------------------
 * ATS ships as a free Core edition and a paid Professional edition, so by the
 * house rule — an add-on's tier mirrors the wrapped extension's tier — the base
 * surface belongs in the free repo. Everything registered below is a capability
 * that exists in Core. Nobody should have to buy anything from us to drive an
 * extension they got for free from Akeeba.
 *
 * The Pro-only surface (attachments, canned replies, auto-replies, manager
 * notes, user tags, the Timecard, the Log, scheduling, the email-template
 * manager, the mail gateway) is deliberately absent rather than half-present.
 * It belongs in a separate csmcpforjatspro add-on, and every tool in it will
 * re-implement the vendor's own gate.
 *
 * THE EDITION GATE IS A CONSTANT WE MUST DEFINE OURSELVES
 * -------------------------------------------------------
 * Roughly thirty-five places in ATS read `defined('ATS_PRO') && ATS_PRO`. The
 * constant is set by Dispatcher::loadVersion(), which only runs when Joomla
 * dispatches com_ats through its own entry point — which an MCP tool call never
 * does. Left alone, the constant is simply absent, and absent reads as Core
 * everywhere. On a genuine Pro install that would mean silently reporting
 * attachments and manager notes as unavailable on a site that has them, and
 * ControlpanelModel.php:67 reads the constant bare, which is a fatal on PHP 8
 * rather than a notice. ATSBootTrait::atsDefineVersionConstants() therefore
 * replicates loadVersion() exactly, fallback included. See that trait's docblock.
 *
 * THE ONE TABLE THIS ADD-ON WILL NOT TOUCH
 * -----------------------------------------
 * #__ats_managernotes. Every other Pro-only table is created by ATS' installer
 * unconditionally and is simply empty on Core, so reading it is harmless. Notes
 * are the exception: they are private staff commentary, and a site that ran Pro
 * and later dropped to Core keeps every note it ever wrote, because the
 * downgrade removes code, not data. ATS fails closed there —
 * TicketTable::managerNotes() returns [] on the edition check before it builds a
 * query — so those rows are invisible in the Core UI. A generic SELECT would not
 * fail closed. The table is absent from the query escape hatch's allowlist, and
 * check_ats_health reports only a COUNT of such rows, never their content.
 *
 * TRAPS THE TOOLS ABSORB (all confirmed in 5.6.0 source on a live Core install)
 * -----------------------------------------------------------------------------
 *   - #__ats_tickets has NO access and NO language column. Both are inherited
 *     from the category via catid, so no write tool offers to set them.
 *   - `modified` / `modified_by` on a ticket mean "last reply", not "last
 *     edited" — the usual assignment is commented out in both
 *     TicketTable::onBeforeStore() and TicketModel::prepareTable(), with a
 *     comment saying so. Reports label it accordingly and compute real activity
 *     from the newest enabled post instead.
 *   - #__ats_tickets.timespent is DERIVED: PostTable::onAfterStore() recomputes
 *     it as SUM over enabled posts. Writing it directly is pointless, and
 *     because of the `enabled = '1'` filter, unpublishing a post only reduces
 *     the ticket total on the NEXT reply.
 *   - Posting to a CLOSED ticket is a near-no-op. onAfterStore() skips the whole
 *     status / modified / timespent block when status is already 'C', so the row
 *     lands and nothing else moves and nobody is told.
 *   - `priority` is TINYINT NOT NULL with no DEFAULT — an INSERT omitting it
 *     errors under STRICT_TRANS_TABLES.
 *   - #__ats_posts.attachment_id is a comma-separated list in a singular-named
 *     VARCHAR(512), not a foreign key. It can disagree with
 *     #__ats_attachments.post_id, which is INT(11) against a bigint id.
 *   - #__ats_tickets_users has no unique index and no index at all. Duplicate
 *     invitations are prevented only in PHP, and TicketTable::onAfterDelete()
 *     does not clean the table — deleting a ticket orphans its rows.
 *   - The opening message of a ticket is a POST row, not a ticket column. A
 *     ticket with no posts is a broken ticket, which check_ats_health looks for.
 *   - `catid` is a bare bigint with no foreign key, so every category join MUST
 *     carry `extension = 'com_ats'` or it will happily match a com_content
 *     category.
 *   - Priorities are hidden by default: the `ticketPriorities` param defaults to
 *     0 and TicketModel::getForm() removes the field when it is off.
 *   - The component's own manifest version lies on at least one shipped build —
 *     com_ats/ats.xml says 5.5.2 while version.php and pkg_ats say 5.6.0. Never
 *     key a capability check off #__extensions.manifest_cache for com_ats.
 */
final class Csmcpforjatsfree extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Tickets
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Tickets\ListTicketsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Tickets\GetTicketTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Tickets\CreateTicketTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Tickets\UpdateTicketTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Tickets\SetTicketStatusTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Tickets\SetTicketPublicTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Tickets\AssignTicketTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Tickets\DeleteTicketTool::class,

		// Posts — the replies, including each ticket's opening message
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Posts\ListPostsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Posts\GetPostTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Posts\AddPostTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Posts\UpdatePostTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Posts\SetPostStateTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Posts\DeletePostTool::class,

		// Collaborators — invitations are a Core feature, despite looking premium
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Collaborators\ListTicketUsersTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Collaborators\InviteUserTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Collaborators\RemoveInviteTool::class,

		// People
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\People\ListManagersTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\People\ListAssigneesTool::class,

		// Lookups
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Lookups\ListCategoriesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Lookups\GetCategoryTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Lookups\ListStatusesTool::class,

		// Reports
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Reports\GetTicketSummaryTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Reports\ListStaleTicketsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Reports\GetAgentWorkloadTool::class,

		// Config + diagnostics
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Config\GetComponentInfoTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Config\GetConfigTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Config\CheckHealthTool::class,

		// Scoped table access (escape hatches)
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Tables\ListTablesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Tables\QueryTableTool::class,
	];

	public static function getSubscribedEvents(): array
	{
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
