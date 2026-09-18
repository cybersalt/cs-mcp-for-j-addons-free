<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Collaborators;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;

/**
 * Invite a user, by username, to collaborate on a ticket.
 *
 * Invitations are CORE, not Professional. TicketModel::inviteUser() (TicketModel.php:278)
 * has no ATS_PRO check anywhere in its body, and neither does removeInvite(). The feature
 * reads as premium only because the `invite_users` config switch defaults to 0.
 *
 * WHAT `invite_users` ACTUALLY DOES — NOTHING
 * -------------------------------------------
 * A full-text grep of the live install for the string `invite_users` returns exactly two
 * hits, both in administrator/components/com_ats/config.xml: the field declaration at
 * line 13 and the `showon="invite_users:1"` attribute on `invite_limit` at line 32. No PHP
 * file, no layout, no template reads it. It is a vestigial switch whose only remaining
 * effect is whether the Joomla config form reveals the invite_limit field next to it.
 *
 * The real gate is the `ats.invite` ACL action, surfaced as the 'ticket.invite' key of
 * Permissions::getTicketPrivileges() (Permissions.php:668). So this tool does NOT refuse
 * when invite_users is 0 — refusing would be stricter than ATS itself and would make the
 * tool useless on a default install. It reports the param's value in the response instead,
 * because an operator reading the ATS Options page will reasonably believe it matters.
 *
 * THE FOUR PRECONDITIONS, ALL FROM TicketModel::inviteUser()
 * ----------------------------------------------------------
 *   1. TicketModel.php:300 — the INVITER needs both 'view' AND 'ticket.invite' on the
 *      ticket, else RuntimeException(JERROR_ALERTNOAUTHOR, 403). Note that an invited
 *      collaborator has 'ticket.invite' forced to false at Permissions.php:676, so
 *      collaborators cannot recruit further collaborators.
 *   2. TicketModel.php:317 — the invitee must not already be on the ticket
 *      (COM_ATS_TICKET_INVITE_ALREADY_INVITED).
 *   3. TicketModel.php:322 — count(existing) >= invite_limit is refused
 *      (COM_ATS_TICKET_INVITE_TOO_MANY). Default 10; config.xml constrains it to 1..20.
 *      The count is loadObjectList('user_id'), keyed by user id, so duplicate rows in the
 *      unindexed #__ats_tickets_users collapse and do not consume capacity.
 *   4. TicketModel.php:330 — an invitee who ALREADY has both 'view' and 'post' on the
 *      ticket is refused (COM_ATS_TICKET_INVITE_ALREADY_HAVE_ACCESS). This is the one that
 *      surprises people: every category manager, and every user holding ats.reply on a
 *      public ticket's category, is un-invitable. Invitations exist for people who would
 *      otherwise see nothing.
 *
 * All four are checked here before the vendor call, purely so the caller gets a specific,
 * actionable message instead of a bare exception string. The vendor's own checks still run
 * and remain authoritative — this pre-flight can only narrow, never widen.
 *
 * THE SIDE EFFECTS — A PUBLIC POST, AND MAIL
 * -------------------------------------------
 * TicketController::invite() (TicketController.php:221) does two things after the model
 * call, and both are replicated here because an invitation without them is invisible:
 *
 *   - It saves an ordinary post to #__ats_posts with created_by = -1 and enabled = 1,
 *     content COM_ATS_TICKET_INVITE_USER_ADDED ("User %s has been invited to the
 *     conversation"). `enabled = 1` is the decisive detail: this post is NOT a private
 *     manager note. It renders in the ticket thread for the customer, for the new
 *     collaborator and for everyone else who can view the ticket. Inviting someone
 *     announces it to the customer by name. The response says so.
 *   - It calls postNotifiable(), i.e. PostNotification::notify(), which runs
 *     EmailSending::sendPostEmails(). Because the post's created_by (-1) never equals the
 *     ticket owner, the $createdByOwner branch at EmailSending.php:247 is false and the
 *     ticket OWNER is mailed; so are the category managers (minus the post's author, minus
 *     any excluded by notify_managers / exclude_managers), and so is every invited user
 *     including the one just added (EmailSending.php:265-283). One invite can generate
 *     several emails. Honour the component's own `sendEmails` param — set it to 0 to
 *     suppress all of it.
 *
 * PostTable::onAfterStore() (PostTable.php:278) treats created_by <= 0 as a system post
 * and leaves the ticket status alone, so inviting somebody does not reopen a Pending
 * ticket. It does still stamp modified / modified_by = -1 and recompute timespent, and on
 * a ticket whose status is already 'C' the entire block is skipped.
 *
 * withSiteAppContext() wraps ONLY the post + notification. The model call deliberately runs
 * outside it: inviteUser() resolves the acting identity through Permissions::getUser() with
 * no argument, which is Factory::getApplication()->getIdentity() (Permissions.php:1003-1013)
 * — under the MCP API app that is the authenticated actor, but a freshly-built
 * SiteApplication from the container has no identity loaded and would read as a guest,
 * turning every invite into a 403. Mail-building needs the site app; the ACL check needs
 * the API app's identity. So each gets the context it needs.
 */
final class InviteUserTool extends AbstractTool
{
	use ATSBootTrait;

	public function getName(): string { return 'invite_ats_user'; }

	public function getDescription(): string
	{
		return 'Invite a Joomla user, BY USERNAME, to collaborate on an Akeeba Ticket System ticket. '
			. 'Invitations are a CORE feature and work on the free edition. An invited collaborator gains '
			. 'view and post access to that one ticket (and inherits the ticket owner\'s attachment '
			. 'permission), but is explicitly NOT allowed to invite anybody else. '
			. 'Arguments: ticket_id (required), username (required — the Joomla username, not the id, not '
			. 'the email, not the display name). '
			. 'THIS TOOL IS NOT SILENT. It reproduces what the ATS UI does: it writes a visible post into '
			. 'the ticket thread reading "User <username> has been invited to the conversation", authored '
			. 'by the synthetic system user (created_by = -1) and published, so THE CUSTOMER SEES THE '
			. 'INVITATION AND THE INVITEE\'S USERNAME. It then sends ATS\' normal new-post notification '
			. 'mail to the ticket owner, to the category managers, and to every invited user including the '
			. 'new one — potentially several emails from one call. Set the com_ats "sendEmails" param to 0 '
			. 'to suppress the mail; there is no way to suppress the post without diverging from the UI. '
			. 'REFUSALS you should expect, all enforced by ATS itself: (a) you need both view and the '
			. 'ats.invite privilege on the ticket; (b) the user is already invited; (c) the ticket has hit '
			. 'the invite_limit param (default 10, allowed range 1-20), counted by distinct user id; '
			. '(d) THE COMMON ONE — the invitee already has both view and post access, so ATS refuses as '
			. 'redundant. That makes every category manager, and anyone holding ats.reply on a public '
			. 'ticket\'s category, un-invitable. Invitations are for people who would otherwise see '
			. 'nothing at all. '
			. 'The com_ats "invite_users" option (default 0 = No) is reported in the response but is NOT '
			. 'required: it appears nowhere in ATS\' PHP, only in config.xml where it controls whether the '
			. 'invite_limit field is displayed. The real gate is the ats.invite ACL action. '
			. 'Returns ok, ticket_id, user_id, username, name, the system post id, the invite counts '
			. 'before and after, mail_sent, site_url_configured and the invite_users param value. '
			. 'Use list_ats_ticket_users first to see who is already on the ticket.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'ticket_id' => [
					'type'        => 'integer',
					'description' => 'The #__ats_tickets.id to invite the user to. Required.',
				],
				'username'  => [
					'type'        => 'string',
					'description' => 'The Joomla USERNAME of the person to invite. ATS resolves it with '
						. 'loadUserByUsername(), so an email address or a display name will not match. '
						. 'Required.',
				],
			],
			'required'             => ['ticket_id', 'username'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->atsBoot()) {
			return $this->notInstalledError();
		}

		$ticketId = $this->requirePositiveInt($arguments, 'ticket_id');
		$username = $this->requireString($arguments, 'username');

		$ticket = $this->atsLoadTicket($ticketId);

		if ($ticket === null) {
			return ToolResult::error('Ticket ' . $ticketId . ' does not exist.');
		}

		// --- Pre-flight. Purely for message quality; the vendor re-checks all of it. ---

		$myPrivileges = $this->atsTicketPrivileges($ticket, $actor);

		if (!($myPrivileges['view'] ?? false)) {
			return ToolResult::error(
				'Permission denied: you cannot view ticket ' . $ticketId . ', so you cannot invite anyone '
				. 'to it. (TicketModel::inviteUser() requires both "view" and "ticket.invite".)'
			);
		}

		if (!($myPrivileges['ticket.invite'] ?? false)) {
			return ToolResult::error(
				'Permission denied: you do not hold the ats.invite privilege on ticket ' . $ticketId
				. ' (category ' . (int) $ticket->catid . '). Grant "Invite users" on com_ats or on that '
				. 'category, to a group you belong to. Note that if you are yourself an invited '
				. 'collaborator on this ticket, ATS forces ticket.invite to false for you by design '
				. '(Permissions.php:676) — collaborators cannot recruit more collaborators.'
			);
		}

		$invitee = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserByUsername($username);

		if ($invitee === null || empty($invitee->id)) {
			return ToolResult::error(
				'No Joomla user has the username "' . $username . '". ATS resolves invitees with '
				. 'loadUserByUsername(), so this must be the username column of #__users — not an email '
				. 'address, not a display name, not a user id.'
			);
		}

		$existing = $this->distinctInvitedUserIds($ticketId);

		if (\in_array((int) $invitee->id, $existing, true)) {
			return ToolResult::error(
				'User "' . $username . '" (id ' . (int) $invitee->id . ') is already invited to ticket '
				. $ticketId . '. ATS refuses a second invitation (COM_ATS_TICKET_INVITE_ALREADY_INVITED). '
				. 'Use list_ats_ticket_users to see the current collaborators.'
			);
		}

		$inviteLimit = (int) $this->atsParams()->get('invite_limit', 10);

		if (\count($existing) >= $inviteLimit) {
			return ToolResult::error(
				'Ticket ' . $ticketId . ' has reached the invite limit: ' . \count($existing)
				. ' distinct user(s) invited, limit ' . $inviteLimit
				. ' (com_ats "invite_limit" param, default 10, allowed range 1-20). '
				. 'ATS refuses with COM_ATS_TICKET_INVITE_TOO_MANY. Remove a collaborator with '
				. 'remove_ats_invite, or raise the limit in the ATS Options.'
			);
		}

		$theirPrivileges = $this->atsTicketPrivileges($ticket, $invitee);

		if (($theirPrivileges['view'] ?? false) && ($theirPrivileges['post'] ?? false)) {
			return ToolResult::error(
				'User "' . $username . '" already has both view and post access to ticket ' . $ticketId
				. ', so ATS refuses the invitation as redundant '
				. '(COM_ATS_TICKET_INVITE_ALREADY_HAVE_ACCESS, TicketModel.php:330). This is normal for '
				. 'category managers and for anyone holding ats.reply on the category of a public ticket. '
				. 'They can already read and reply to this ticket — no invitation is needed or possible.'
			);
		}

		// --- The vendor call. Deliberately NOT inside withSiteAppContext(); see the class docblock. ---

		$model = $this->atsModel('Ticket');

		if ($model === null) {
			return ToolResult::error('Could not create the ATS TicketModel. The com_ats install may be incomplete.');
		}

		try {
			$model->inviteUser($ticketId, $username);
		} catch (\Throwable $e) {
			return ToolResult::error(
				'ATS refused the invitation: ' . $e->getMessage()
				. ' (raised by TicketModel::inviteUser(). If this is an authorisation error but the '
				. 'pre-flight checks above passed, the acting identity ATS resolved through '
				. 'Permissions::getUser() differs from the MCP actor.)'
			);
		}

		// --- The side effects. These DO need the site app: mail bodies contain ticket URLs. ---

		$siteUrlConfigured = $this->atsSiteUrlConfigured();
		$sendEmails        = (int) $this->atsParams()->get('sendEmails', 1) === 1;

		$postId     = 0;
		$postError  = '';
		$mailFailed = '';

		try {
			$postId = (int) $this->withSiteAppContext(function () use ($ticketId, $username, $actor, &$mailFailed): int {
				// A container-built SiteApplication has no identity loaded. Nothing below
				// depends on it for authorisation any more, but leaving it as an implicit
				// guest is a trap for the next person to touch this code.
				$siteApp = Factory::getApplication();

				if (method_exists($siteApp, 'loadIdentity')) {
					$siteApp->loadIdentity($actor);
				}

				$postTable = $this->atsTable('Post');

				if ($postTable === null) {
					throw new \RuntimeException('Could not create the ATS PostTable.');
				}

				$date = clone Factory::getDate();

				// Exactly TicketController.php:238-256. created_by = -1 is the synthetic
				// "system" user (Permissions::getUser(-1)); enabled = 1 makes it a normal,
				// customer-visible post in the thread.
				$post = [
					'ticket_id'    => $ticketId,
					'content_html' => Text::sprintf('COM_ATS_TICKET_INVITE_USER_ADDED', $username),
					'created'      => $date->toSql(),
					'created_by'   => -1,
					'enabled'      => 1,
				];

				// Without these the create/modify trait would overwrite created_by with the
				// acting user and the post would stop being a system notice.
				$postTable->setUpdateCreated(false);
				$postTable->setUpdateModified(false);
				$postTable->save($post);
				$postTable->setUpdateCreated(true);
				$postTable->setUpdateModified(true);

				try {
					// PostNotification is @since 5.6.0. Before that, notification lived in a
					// PRIVATE method on ControllerNewPostTrait and was unreachable from
					// outside a controller — which is the bug 5.6.0 shipped to fix. On an
					// older install the invite and the post still stand; only the mail is
					// lost, and that is reported rather than fataling after the commit.
					if (!class_exists(\Akeeba\Component\ATS\Administrator\Helper\PostNotification::class)) {
						throw new \RuntimeException(
							'Akeeba\\Component\\ATS\\Administrator\\Helper\\PostNotification does not exist '
							. 'on this install (it is new in ATS 5.6.0). Nothing outside a controller can '
							. 'send post notifications on an older build.'
						);
					}

					\Akeeba\Component\ATS\Administrator\Helper\PostNotification::notify($postTable, true);
				} catch (\Throwable $e) {
					// The invitation and the post are already committed. A mail failure
					// must not be reported as a failed invite — that is how an agent ends
					// up retrying and duplicating.
					$mailFailed = $e->getMessage();
				}

				return (int) $postTable->getId();
			});
		} catch (\Throwable $e) {
			$postError = $e->getMessage();
		}

		$after = $this->distinctInvitedUserIds($ticketId);

		$notes = [
			'The invitation is announced in the ticket thread: a published post authored by the system '
			. 'user (created_by = -1) now reads "User ' . $username . ' has been invited to the '
			. 'conversation". The customer sees it.',
			'System posts do not change the ticket status (PostTable.php:294), so a Pending ticket stays '
			. 'Pending, but modified/modified_by are stamped and timespent is recomputed. On a ticket '
			. 'already Closed, none of that runs at all.',
		];

		if (!$sendEmails) {
			$notes[] = 'No mail was sent: the com_ats "sendEmails" param is 0.';
		} elseif ($mailFailed !== '') {
			$notes[] = 'The invitation and the post succeeded, but sending notification mail threw: '
				. $mailFailed . '. Do not retry the invite — it is already recorded.';
		} else {
			$notes[] = 'Notification mail went to the ticket owner, the category managers, and every '
				. 'invited user including the new one (EmailSending.php:199-283).';
		}

		if (!$siteUrlConfigured) {
			$notes[] = 'WARNING: the com_ats "siteurl" param is empty, so ATS built the ticket links in '
				. 'that mail from an empty base. The mail went out with broken links rather than failing. '
				. 'Set Site URL in the ATS Options.';
		}

		if ($postError !== '') {
			$notes[] = 'THE INVITATION ITSELF SUCCEEDED, but writing the system post failed: ' . $postError
				. '. The collaborator has access; the thread just does not mention it. Do not retry the '
				. 'invite.';
		}

		return ToolResult::json([
			'ok'                     => true,
			'ticket_id'              => $ticketId,
			'ticket_title'           => (string) $ticket->title,
			'user_id'                => (int) $invitee->id,
			'username'               => (string) $invitee->username,
			'name'                   => (string) $invitee->name,
			'system_post_id'         => $postId,
			'system_post_written'    => $postId > 0,
			'invited_before'         => \count($existing),
			'invited_after'          => \count($after),
			'invite_limit'           => $inviteLimit,
			'invites_remaining'      => max(0, $inviteLimit - \count($after)),
			'mail_sent'              => $sendEmails && $mailFailed === '' && $postId > 0,
			'site_url_configured'    => $siteUrlConfigured,
			'param_invite_users'     => (int) $this->atsParams()->get('invite_users', 0),
			'param_invite_users_note' => 'Reported for completeness only. This option is read by nothing '
				. 'in ATS\' PHP — it appears solely in config.xml, where it drives the showon attribute of '
				. 'invite_limit. Invitations work with it set to 0. The real gate is the ats.invite ACL '
				. 'action.',
			'note'                   => implode(' ', $notes),
		]);
	}

	/**
	 * The distinct user ids currently invited to a ticket.
	 *
	 * DISTINCT deliberately: #__ats_tickets_users has no unique index, and ATS' own limit
	 * check keys its result set by user_id (TicketModel.php:315), so duplicate rows do not
	 * count twice there. Matching that exactly is the only way the capacity we report agrees
	 * with the capacity the vendor enforces.
	 *
	 * @return array<int, int>
	 */
	private function distinctInvitedUserIds(int $ticketId): array
	{
		$query = $this->db->getQuery(true)
			->select('DISTINCT ' . $this->db->quoteName('user_id'))
			->from($this->db->quoteName($this->db->getPrefix() . 'ats_tickets_users'))
			->where($this->db->quoteName('ticket_id') . ' = ' . (int) $ticketId);

		return array_map('intval', $this->db->setQuery($query)->loadColumn() ?: []);
	}
}
