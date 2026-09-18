<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Collaborators;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;

/**
 * List the invited collaborators on one ticket.
 *
 * Invitations are a CORE feature. #__ats_tickets_users is created and used on every
 * install; nothing in TicketModel::inviteUser() or removeInvite() is gated on ATS_PRO.
 * It looks like a premium feature because the config switch that reveals its limit
 * field is off by default — see InviteUserTool for what that switch actually does.
 *
 * WHY THIS READS THE TABLE DIRECTLY RATHER THAN CALLING THE VENDOR
 * ----------------------------------------------------------------
 * ATS offers two accessors and neither one can answer the question honestly:
 *
 *   Permissions::getInvited()          Permissions.php:1134 — SELECT user_id ... loadColumn()
 *   TicketModel::getInvitedUsers()     TicketModel.php:635  — the same query, then
 *                                      loadUserById() on each value
 *
 * #__ats_tickets_users has NO unique index and no index at all (install.mysql.utf8.sql),
 * so (ticket_id, user_id) can legitimately repeat. Duplicates are prevented in PHP only,
 * by the isset($users[$user->id]) test at TicketModel.php:317, and that guard is bypassed
 * by any direct INSERT and loses a race between two concurrent invites. When a duplicate
 * does exist, loadColumn() returns the user id twice and getInvitedUsers() hands back two
 * identical User objects — visible to nobody, because the invite panel renders a row per
 * entry and two identical rows read as a rendering hiccup.
 *
 * A duplicate row is a data problem an operator should be told about, not smoothed over,
 * so this tool selects the primary keys as well and reports duplicate_rows explicitly
 * instead of silently collapsing them. It matters operationally: the invite limit check at
 * TicketModel.php:322 counts loadObjectList('user_id') — keyed by user id — so duplicate
 * rows collapse there and the ticket's capacity is computed from DISTINCT users, while the
 * UI shows one row per record. This tool therefore reports both numbers.
 *
 * The LEFT JOIN to #__users is also deliberate. TicketTable::onAfterDelete() does not clean
 * this table, and neither does deleting a Joomla user, so a row can point at an account
 * that no longer exists. loadUserById() would hand back a blank User for those and they
 * would look like anonymous collaborators; here they surface as user_exists: false.
 *
 * Gated on the parent ticket's 'view' privilege via atsTicketPrivileges(), per the
 * add-on's visibility rule — the collaborator list is part of the ticket.
 */
final class ListTicketUsersTool extends AbstractTool
{
	use ATSBootTrait;

	public function getName(): string { return 'list_ats_ticket_users'; }

	public function getDescription(): string
	{
		return 'List the invited collaborators on one Akeeba Ticket System ticket (#__ats_tickets_users). '
			. 'Invitations are a CORE feature, not Professional — they work on every install. '
			. 'Requires ticket_id. Returns one entry per DATABASE ROW: row_id (the primary key of '
			. '#__ats_tickets_users), user_id, user_exists, name, username, email, block (1 = the Joomla '
			. 'account is disabled), and is_duplicate. Plus, at the top level: count (rows), '
			. 'distinct_user_count, duplicate_rows (an array describing any user invited more than once), '
			. 'orphaned_rows (rows whose user_id no longer exists in #__users), invite_limit (the '
			. 'component invite_limit param, default 10) and invites_remaining. '
			. 'WHY ROWS AND NOT USERS: #__ats_tickets_users has no unique index. ATS prevents duplicates '
			. 'in PHP only (TicketModel.php:317), so a direct INSERT or two concurrent invites can '
			. 'duplicate a user, and ATS\' own getInvitedUsers() would return that user twice without '
			. 'saying so. Duplicates are reported here rather than de-duplicated because they are a data '
			. 'problem worth fixing — remove_ats_invite deletes every matching row at once, so calling it '
			. 'for the duplicated user and then re-inviting is the cleanest repair. '
			. 'Note that ATS computes the invite limit from DISTINCT user ids, not rows, so duplicates do '
			. 'not consume extra capacity even though they show as extra rows in the UI. '
			. 'ACCESS: you must be able to view the ticket itself (owner, invited collaborator, or a user '
			. 'who can see the category and either the ticket is public or you hold ats.private.read). '
			. 'Deleting a ticket does NOT delete these rows, so orphaned_rows can be non-zero on a site '
			. 'that has deleted tickets — though this tool only ever reads a ticket that still exists.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'ticket_id' => [
					'type'        => 'integer',
					'description' => 'The #__ats_tickets.id to list collaborators for. Required.',
				],
			],
			'required'             => ['ticket_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->atsBoot()) {
			return $this->notInstalledError();
		}

		$ticketId = $this->requirePositiveInt($arguments, 'ticket_id');
		$ticket   = $this->atsLoadTicket($ticketId);

		if ($ticket === null) {
			return ToolResult::error('Ticket ' . $ticketId . ' does not exist.');
		}

		// The visibility rule: never trust a surrounding query, always ask the vendor's
		// own per-ticket gate. See ATSBootTrait::atsTicketPrivileges().
		$privileges = $this->atsTicketPrivileges($ticket, $actor);

		if (!($privileges['view'] ?? false)) {
			return ToolResult::error(
				'Permission denied: you cannot view ticket ' . $ticketId . ', so you cannot see who is '
				. 'invited to it.'
			);
		}

		$prefix = $this->db->getPrefix();
		$query  = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('tu.id', 'row_id'),
				$this->db->quoteName('tu.user_id'),
				$this->db->quoteName('u.id', 'joomla_user_id'),
				$this->db->quoteName('u.name'),
				$this->db->quoteName('u.username'),
				$this->db->quoteName('u.email'),
				$this->db->quoteName('u.block'),
			])
			->from($this->db->quoteName($prefix . 'ats_tickets_users', 'tu'))
			// LEFT, not INNER: a row can outlive the user account it names.
			->leftJoin(
				$this->db->quoteName('#__users', 'u')
				. ' ON ' . $this->db->quoteName('u.id') . ' = ' . $this->db->quoteName('tu.user_id')
			)
			->where($this->db->quoteName('tu.ticket_id') . ' = ' . (int) $ticketId)
			->order($this->db->quoteName('tu.id') . ' ASC');

		$raw = $this->db->setQuery($query)->loadAssocList() ?: [];

		// Count occurrences per user id so each row can be flagged.
		$occurrences = [];
		foreach ($raw as $row) {
			$uid               = (int) $row['user_id'];
			$occurrences[$uid] = ($occurrences[$uid] ?? 0) + 1;
		}

		$collaborators = [];
		$orphaned      = 0;

		foreach ($raw as $row) {
			$uid    = (int) $row['user_id'];
			$exists = $row['joomla_user_id'] !== null;

			if (!$exists) {
				$orphaned++;
			}

			$collaborators[] = [
				'row_id'       => (int) $row['row_id'],
				'user_id'      => $uid,
				'user_exists'  => $exists,
				'name'         => $exists ? (string) $row['name'] : null,
				'username'     => $exists ? (string) $row['username'] : null,
				'email'        => $exists ? (string) $row['email'] : null,
				'block'        => $exists ? (int) $row['block'] : null,
				'is_duplicate' => ($occurrences[$uid] ?? 0) > 1,
			];
		}

		$duplicates = [];
		foreach ($occurrences as $uid => $times) {
			if ($times > 1) {
				$duplicates[] = [
					'user_id'   => $uid,
					'row_count' => $times,
					'row_ids'   => array_values(array_map(
						static fn(array $r): int => (int) $r['row_id'],
						array_filter($raw, static fn(array $r): bool => (int) $r['user_id'] === $uid)
					)),
				];
			}
		}

		$distinct    = \count($occurrences);
		$inviteLimit = (int) $this->atsParams()->get('invite_limit', 10);

		$notes = [];

		if ($duplicates !== []) {
			$notes[] = 'DATA PROBLEM: ' . \count($duplicates) . ' user(s) appear on this ticket more than '
				. 'once. #__ats_tickets_users has no unique index and ATS only de-duplicates in PHP '
				. '(TicketModel.php:317), so a direct INSERT or a race between two invites can do this. '
				. 'ATS\' own getInvitedUsers() would have returned the user twice without telling you. '
				. 'To repair: remove_ats_invite deletes EVERY row for that user on that ticket in one '
				. 'statement, so remove then re-invite.';
		}

		if ($orphaned > 0) {
			$notes[] = $orphaned . ' row(s) name a user id that no longer exists in #__users. Deleting a '
				. 'Joomla user does not clean this table. They grant nothing (isInvited() still matches '
				. 'the id, but no one can log in as it) and are safe to remove with remove_ats_invite.';
		}

		$notes[] = 'The invite limit is enforced against DISTINCT user ids, not rows — '
			. 'TicketModel.php:315 keys its count by user_id — so duplicate rows do not consume capacity.';

		return ToolResult::json([
			'ok'                  => true,
			'ticket_id'           => $ticketId,
			'ticket_title'        => (string) $ticket->title,
			'ticket_category_id'  => (int) $ticket->catid,
			'count'               => \count($collaborators),
			'distinct_user_count' => $distinct,
			'duplicate_rows'      => $duplicates,
			'orphaned_rows'       => $orphaned,
			'invite_limit'        => $inviteLimit,
			'invites_remaining'   => max(0, $inviteLimit - $distinct),
			'can_invite'          => (bool) ($privileges['ticket.invite'] ?? false),
			'collaborators'       => $collaborators,
			'note'                => implode(' ', $notes),
		]);
	}
}
