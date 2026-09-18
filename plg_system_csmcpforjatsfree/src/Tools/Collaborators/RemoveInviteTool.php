<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Collaborators;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;

/**
 * Withdraw a collaborator's invitation to a ticket.
 *
 * TWO WAYS TO BE ALLOWED, NOT ONE
 * --------------------------------
 * TicketModel::removeInvite() (TicketModel.php:345) computes
 *
 *     $isSelfRemoval = !empty($me->id) && ($invited_user === (int) $me->id);   // :364
 *     if (!$isSelfRemoval && !($myPrivileges['ticket.invite'] ?? false)) { 403 }  // :366
 *
 * so a collaborator can always walk away from a ticket they were invited to, even though
 * Permissions.php:676 forces 'ticket.invite' to false for invited users precisely so they
 * cannot hand the ticket around. Self-removal is the deliberate exception. Anyone else
 * needs ats.invite on the ticket. Note also that the self-removal branch does not require
 * 'view' — someone whose access came solely from the invitation can still revoke it, which
 * would otherwise be a catch-22.
 *
 * The argument order in the vendor method is (user, ticket), the reverse of inviteUser()'s
 * (ticket, username), and it takes a USER ID where inviteUser() takes a USERNAME. This tool
 * accepts either user_id or username and normalises, so the asymmetry stops here.
 *
 * NO POST, NO MAIL — DELIBERATELY ASYMMETRIC WITH INVITING
 * ---------------------------------------------------------
 * TicketController::invite() writes a visible system post and calls postNotifiable().
 * TicketsController::removeinvite() (TicketsController.php:197) does neither — it calls the
 * model, sets a flash message and redirects. Nothing is written to #__ats_posts and no mail
 * is sent. The removed collaborator simply stops being able to open the ticket, with no
 * notice of any kind. That is why this tool needs no withSiteAppContext() wrapper and no
 * siteurl check: there is no URL to build because there is no mail to build it for.
 *
 * DELETES EVERY MATCHING ROW
 * ---------------------------
 * The DELETE at TicketModel.php:372 filters on ticket_id AND user_id with no LIMIT, so on
 * an unindexed table that has somehow acquired duplicates for the same user, one call
 * clears all of them. That makes remove-then-re-invite the clean repair for the duplicate
 * rows list_ats_ticket_users reports, and it is why rows_deleted here can be greater
 * than one.
 */
final class RemoveInviteTool extends AbstractTool
{
	use ATSBootTrait;

	public function getName(): string { return 'remove_ats_invite'; }

	public function getDescription(): string
	{
		return 'Remove an invited collaborator from an Akeeba Ticket System ticket, revoking the view and '
			. 'post access the invitation granted. Arguments: ticket_id (required) plus ONE of user_id or '
			. 'username (ATS\' own method takes a user id; username is accepted here and resolved for you, '
			. 'because invite_ats_user takes a username and the mismatch is an easy mistake). '
			. 'SILENT BY DESIGN: unlike inviting, removing writes NO post to the ticket thread and sends '
			. 'NO email. The person simply loses access with no notification — ATS\' own '
			. 'TicketsController::removeinvite() behaves exactly this way. Nothing appears in the ticket '
			. 'history either. '
			. 'PERMISSIONS: you need the ats.invite privilege on the ticket, OR you are removing yourself. '
			. 'Self-removal is an explicit exception in ATS (TicketModel.php:364) and needs no privilege '
			. 'at all — it is how a collaborator leaves a conversation, and it works even though invited '
			. 'users are otherwise denied ats.invite so that they cannot pass the ticket around. '
			. 'The DELETE matches on ticket_id + user_id with no LIMIT, so if the same user somehow has '
			. 'several rows in the unindexed #__ats_tickets_users, all of them go at once — use this, then '
			. 'invite_ats_user, to repair the duplicates that list_ats_ticket_users reports. '
			. 'Returns ok, ticket_id, user_id, username, rows_deleted, was_self_removal, and the remaining '
			. 'collaborator count. Removing a user who was not invited is reported as an error rather than '
			. 'a silent no-op, because ATS\' DELETE would succeed with zero affected rows and look like it '
			. 'worked.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'ticket_id' => [
					'type'        => 'integer',
					'description' => 'The #__ats_tickets.id to remove the collaborator from. Required.',
				],
				'user_id'   => [
					'type'        => 'integer',
					'description' => 'The #__users.id of the collaborator to remove. Supply this or '
						. 'username. This is what ATS\' own removeInvite() takes.',
				],
				'username'  => [
					'type'        => 'string',
					'description' => 'The Joomla username of the collaborator, as an alternative to '
						. 'user_id. Resolved with loadUserByUsername(). Ignored when user_id is given.',
				],
			],
			'required'             => ['ticket_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	/**
	 * "remove_" is not one of AbstractTool's recognised verb prefixes, so the default
	 * classification would call this a non-destructive write. It revokes access to a
	 * resource; say so, and mark it idempotent because a second call on an already-removed
	 * user changes nothing.
	 *
	 * @return array<string, bool|string>
	 */
	public function getMcpAnnotations(): array
	{
		return [
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		];
	}

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->atsBoot()) {
			return $this->notInstalledError();
		}

		$ticketId = $this->requirePositiveInt($arguments, 'ticket_id');
		$userId   = (int) ($arguments['user_id'] ?? 0);
		$username = trim((string) ($arguments['username'] ?? ''));

		if ($userId <= 0 && $username === '') {
			return ToolResult::error('Supply either user_id or username to identify the collaborator to remove.');
		}

		$userFactory = Factory::getContainer()->get(UserFactoryInterface::class);

		if ($userId <= 0) {
			$resolved = $userFactory->loadUserByUsername($username);

			if ($resolved === null || empty($resolved->id)) {
				return ToolResult::error(
					'No Joomla user has the username "' . $username . '". Note that an orphaned invitation '
					. 'row can name a user id that no longer exists — in that case pass user_id directly; '
					. 'list_ats_ticket_users reports those ids under orphaned_rows.'
				);
			}

			$userId = (int) $resolved->id;
		}

		$ticket = $this->atsLoadTicket($ticketId);

		if ($ticket === null) {
			return ToolResult::error('Ticket ' . $ticketId . ' does not exist.');
		}

		$rowsBefore = $this->rowCountFor($ticketId, $userId);

		if ($rowsBefore === 0) {
			return ToolResult::error(
				'User ' . $userId . ' is not an invited collaborator on ticket ' . $ticketId
				. '. ATS\' DELETE would have run and affected zero rows, which is indistinguishable from '
				. 'success, so this is reported as an error instead. Use list_ats_ticket_users to see who '
				. 'is actually invited. (A manager or anyone with ats.reply on the category has access '
				. 'through ACL, not through an invitation, and cannot be removed this way — adjust the '
				. 'category permissions instead.)'
			);
		}

		$isSelfRemoval = $userId === (int) $actor->id && $userId > 0;

		// Pre-flight mirroring TicketModel.php:364-369, for message quality. The vendor
		// re-checks; this can only narrow.
		if (!$isSelfRemoval) {
			$myPrivileges = $this->atsTicketPrivileges($ticket, $actor);

			if (!($myPrivileges['ticket.invite'] ?? false)) {
				return ToolResult::error(
					'Permission denied: removing someone else\'s invitation needs the ats.invite privilege '
					. 'on ticket ' . $ticketId . ' (category ' . (int) $ticket->catid . '). The only '
					. 'exception ATS makes is self-removal — a collaborator may always remove themselves, '
					. 'whatever their privileges.'
				);
			}
		}

		$model = $this->atsModel('Ticket');

		if ($model === null) {
			return ToolResult::error('Could not create the ATS TicketModel. The com_ats install may be incomplete.');
		}

		try {
			// Vendor argument order is (invited user, ticket) — the reverse of inviteUser().
			$model->removeInvite($userId, $ticketId);
		} catch (\Throwable $e) {
			return ToolResult::error('ATS refused the removal: ' . $e->getMessage());
		}

		$rowsAfter = $this->rowCountFor($ticketId, $userId);
		$remaining = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(DISTINCT ' . $this->db->quoteName('user_id') . ')')
				->from($this->db->quoteName($this->db->getPrefix() . 'ats_tickets_users'))
				->where($this->db->quoteName('ticket_id') . ' = ' . (int) $ticketId)
		)->loadResult();

		$resolvedUser = $userFactory->loadUserById($userId);

		$notes = [
			'Nothing was written to the ticket thread and no email was sent. ATS removes invitations '
			. 'silently (TicketsController.php:197 calls the model and redirects; there is no post and no '
			. 'notification), which is the opposite of inviting — that announces itself in the thread and '
			. 'mails several people.',
		];

		if ($isSelfRemoval) {
			$notes[] = 'This was a self-removal, permitted regardless of privileges by the explicit '
				. 'exception at TicketModel.php:364. You no longer have access to this ticket unless your '
				. 'ACL grants it independently.';
		}

		if ($rowsBefore > 1) {
			$notes[] = 'That user had ' . $rowsBefore . ' rows in #__ats_tickets_users for this ticket — a '
				. 'duplicate, since the table has no unique index. The DELETE has no LIMIT, so all of them '
				. 'are gone. Re-invite with invite_ats_user if the access was meant to stay.';
		}

		return ToolResult::json([
			'ok'                     => true,
			'ticket_id'              => $ticketId,
			'ticket_title'           => (string) $ticket->title,
			'user_id'                => $userId,
			'username'               => $resolvedUser !== null && !empty($resolvedUser->id)
				? (string) $resolvedUser->username
				: null,
			'rows_deleted'           => $rowsBefore - $rowsAfter,
			'was_self_removal'       => $isSelfRemoval,
			'remaining_collaborators' => $remaining,
			'post_written'           => false,
			'mail_sent'              => false,
			'note'                   => implode(' ', $notes),
		]);
	}

	/** How many #__ats_tickets_users rows pair this ticket with this user. Can exceed 1. */
	private function rowCountFor(int $ticketId, int $userId): int
	{
		$query = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName($this->db->getPrefix() . 'ats_tickets_users'))
			->where($this->db->quoteName('ticket_id') . ' = ' . (int) $ticketId)
			->where($this->db->quoteName('user_id') . ' = ' . (int) $userId);

		return (int) $this->db->setQuery($query)->loadResult();
	}
}
