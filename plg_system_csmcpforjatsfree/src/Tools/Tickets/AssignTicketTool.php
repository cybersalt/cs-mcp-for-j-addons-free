<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;

/**
 * Assign a ticket to a support agent, or unassign it.
 *
 * WHY TicketModel::save() AND NOT batchAssignTo()
 * -----------------------------------------------
 * batchAssignTo() is protected and only reachable through AdminModel::batch(); it
 * insists the caller be a category MANAGER (TicketModel.php:919-924), which is
 * narrower than ATS' own ats.assign privilege, and it ends in the same check() +
 * store() pair a normal save uses anyway (TicketModel.php:933, 942). Going through
 * the model save is the documented house rule, is non-destructive for the columns
 * we do not send, and lets us apply the vendor's real gate —
 * Permissions::canAssignTickets(), which accepts core.admin, core.manage OR
 * ats.assign (Permissions.php:1243-1253).
 *
 * ASSIGNMENT MAIL COMES FROM THE TABLE, NOT FROM A CONTROLLER
 * ------------------------------------------------------------
 * This is worth stating explicitly, because the equivalent is NOT true for posts.
 * A reply's notification is sent by PostController::postSaveHook() via
 * Helper\PostNotification::notify(), so a model-only save of a post is silent —
 * PostNotification's own docblock says as much, and that asymmetry is exactly why
 * the helper was extracted in 5.6.0. Assignment is the opposite case: the mail is
 * fired from TicketTable::onChangedValues(), which the table triggers from its own
 * onAfterStore() (TicketTable.php:763-766, 991-998). Saving the row IS the
 * notification. This tool therefore makes no separate notify call, and adding one
 * would double the mail.
 *
 * WHY THE CALL IS INSIDE withSiteAppContext() RATHER THAN SPLIT
 * -------------------------------------------------------------
 * The preferred shape is to run a vendor method that does its own ACL check under
 * the real API application and wrap only the mail. That is not available here: the
 * ACL check and the mail send are the same call. TicketTable::check() runs
 * onBeforeCheck(), which asserts core.create and canBeAssignedTickets()
 * (TicketTable.php:831-834, 836-845); TicketTable::store() then runs onAfterStore(),
 * which diffs the row against the values captured at load time and fires
 * onChangedValues(), which calls EmailSending::sendAssignedEmails() when
 * assigned_to changed to someone other than the current user
 * (TicketTable.php:991-998). Both sit inside one AdminModel::save().
 *
 * Splitting them would mean writing the column with raw SQL and then invoking
 * EmailSending by hand, which this add-on does not do — a raw UPDATE would skip
 * the canBeAssignedTickets assertion, skip the two table events, and leave the
 * value on the table object out of step with the database. So the whole save runs
 * inside the wrapper, WITH $actor passed. That last part is not optional: a
 * SiteApplication built from the DI container carries no identity, and
 * Permissions::getUser() reads exactly that property (Permissions.php:1003-1013),
 * so without it every one of the checks above would be answered for an anonymous
 * guest and TicketModel::prepareTable() would dereference null.
 *
 * THE TARGET IS VALIDATED BEFORE THE SAVE
 * ---------------------------------------
 * onBeforeCheck() asserts Permissions::canBeAssignedTickets($catid, $assigned_to)
 * and, on failure, aborts with the language key
 * COM_ATS_TICKETS_ERR_INVALID_ASSIGNED_TO — which says nothing about which
 * privilege is missing or on which asset. This tool runs the same check first so
 * the refusal names the category asset and the three privileges that satisfy it.
 *
 * catid IS ALWAYS SENT, AND A PRIVATE TICKET IS PROTECTED
 * -------------------------------------------------------
 * Both for the same reasons as update_ats_ticket: TicketModel::save() resolves the
 * category from `($data['catid'] ?? $table->catid) ?: null` against an unloaded
 * table (TicketModel.php:749), so omitting catid silently moves every ACL question
 * onto the com_ats component asset; and any full save of a private ticket forces
 * public to 1 when neither the actor nor the ticket owner holds ats.private
 * (TicketModel.php:796-802 injecting the key, TicketTable.php:880-906 acting on
 * it). Assigning a ticket must not publish it, so that case is refused.
 */
final class AssignTicketTool extends AbstractTool
{
	use ATSBootTrait;

	public function getName(): string { return 'assign_ats_ticket'; }

	public function getDescription(): string
	{
		return 'Assign an Akeeba Ticket System ticket to a support agent, or unassign it. Required: '
			. 'id, assigned_to (a Joomla user id, or 0 to unassign). '
			. 'THIS TOOL SENDS EMAIL, AND IT DOES SO BY ITSELF. Unlike a ticket reply — where the '
			. 'notification is the controller\'s job, so a model-only save is silent — assignment '
			. 'mail is fired from the table\'s own hook: TicketTable::onChangedValues() calls '
			. 'EmailSending::sendAssignedEmails() from inside store(). Saving the row is enough; no '
			. 'separate notify step exists or is needed. Assigning to yourself sends nothing — ATS '
			. 'skips the notification when the new assignee is the acting user. Unassigning sends '
			. 'nothing either. One caveat on the mail that does go out: ATS resolves its {siteurl} '
			. 'token with Uri::base() whenever it is running as a site application, which is what '
			. 'this tool arranges, and Joomla memoises Uri::base() per request against the /api/ '
			. 'entry point. Swapping applications mid-request cannot reset that static, so links in '
			. 'the notification may be /api/-prefixed. Treat the mail as sent, but verify a link '
			. 'before relying on it. '
			. 'WHO MAY CALL IT: ATS requires core.admin, core.manage or ats.assign on the ticket\'s '
			. 'category. WHO MAY RECEIVE: the target must hold core.admin, core.manage or '
			. 'ats.assignee on that same category. This tool checks the target first, because the '
			. 'vendor\'s own check is an assertion inside the save whose message '
			. '(COM_ATS_TICKETS_ERR_INVALID_ASSIGNED_TO) identifies neither the privilege nor the '
			. 'asset. '
			. 'SAFETY BEHAVIOURS: the ticket\'s category id is always included in the save, because '
			. 'ATS otherwise evaluates the privacy rules against the component asset rather than the '
			. 'category asset. And if the ticket is private while neither you nor its owner holds '
			. 'the ats.private privilege, the assignment is REFUSED rather than performed, because a '
			. 'full save in that state republishes the ticket — being a category manager does not '
			. 'prevent it. Use set_ats_ticket_public first if that is really what you want. '
			. 'The stored assigned_to and public values are read back from the database and reported '
			. 'rather than echoed.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['id', 'assigned_to'],
			'properties' => [
				'id' => [
					'type'        => 'integer',
					'description' => 'Ticket id (#__ats_tickets.id).',
				],
				'assigned_to' => [
					'type'        => 'integer',
					'description' => 'Joomla user id of the agent to assign, or 0 to unassign. The '
						. 'user must be able to be assigned tickets in this ticket\'s category — '
						. 'core.admin, core.manage or ats.assignee on com_ats.category.<catid>. '
						. 'Assigning to anyone but yourself sends notification mail.',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->atsBoot()) {
			return $this->notInstalledError();
		}

		$id = $this->requirePositiveInt($arguments, 'id');

		if (!\array_key_exists('assigned_to', $arguments) || $arguments['assigned_to'] === null) {
			return ToolResult::error(
				'assigned_to is required. Pass a Joomla user id to assign the ticket, or 0 to '
				. 'unassign it.'
			);
		}

		$target = (int) $arguments['assigned_to'];

		if ($target < 0) {
			return ToolResult::error('assigned_to must be a positive user id, or 0 to unassign.');
		}

		$ticket = $this->atsLoadTicket($id);

		if ($ticket === null) {
			return ToolResult::error('Ticket ' . $id . ' does not exist in #__ats_tickets.');
		}

		$catid      = (int) $ticket->catid;
		$actorId    = (int) $actor->id;
		$before     = (int) $ticket->assigned_to;
		$privileges = $this->atsTicketPrivileges($ticket, $actor);

		if (empty($privileges['view'])) {
			return ToolResult::error('Ticket ' . $id . ' is not visible to you.');
		}

		if (!\Akeeba\Component\ATS\Administrator\Helper\Permissions::canAssignTickets($catid, $actorId)) {
			return ToolResult::error(
				'You do not have permission to assign tickets in category ' . $catid . '. ATS '
				. 'requires core.admin, core.manage or ats.assign on com_ats.category.' . $catid
				. ' (Permissions::canAssignTickets, Permissions.php:1243-1253).'
			);
		}

		if ($target > 0) {
			if (!$this->atsUserExists($target)) {
				return ToolResult::error('assigned_to ' . $target . ' is not a user in #__users.');
			}

			if (!\Akeeba\Component\ATS\Administrator\Helper\Permissions::canBeAssignedTickets($catid, $target)) {
				return ToolResult::error(
					'User ' . $target . ' cannot be assigned tickets in category ' . $catid . '. ATS '
					. 'requires core.admin, core.manage or ats.assignee on com_ats.category.' . $catid
					. ' (Permissions::canBeAssignedTickets, Permissions.php:1266-1276). Checking here '
					. 'rather than letting the save hit it, because the vendor\'s own version is an '
					. 'assertion in TicketTable::onBeforeCheck() (TicketTable.php:831-834) whose '
					. 'message names neither the privilege nor the asset.'
				);
			}
		}

		if ($before === $target) {
			return ToolResult::json([
				'ok'           => true,
				'id'           => $id,
				'changed'      => false,
				'assigned_to'  => $before,
				'public'       => (int) $ticket->public,
				'mail_sent'    => false,
				'note'         => 'Ticket ' . $id . ' was already '
					. ($target === 0 ? 'unassigned' : 'assigned to user ' . $target)
					. '. Nothing was written and no notification was sent.',
			]);
		}

		// ------------------------------------------- do not publish a private ticket
		if ((int) $ticket->public === 0) {
			$actorPrivate = (bool) \Akeeba\Component\ATS\Administrator\Helper\Permissions::getAclPrivileges(
				$catid,
				$actorId
			)['ats.private'];

			$ownerPrivate = (bool) \Akeeba\Component\ATS\Administrator\Helper\Permissions::getAclPrivileges(
				$catid,
				(int) $ticket->created_by
			)['ats.private'];

			if (!$actorPrivate && !$ownerPrivate) {
				return ToolResult::error(
					'Refusing to assign ticket ' . $id . ', because saving it would publish it. The '
					. 'ticket is private, and neither you (user ' . $actorId . ') nor its owner (user '
					. (int) $ticket->created_by . ') holds ats.private on com_ats.category.' . $catid
					. '. Assignment goes through a full ticket save, and on that path '
					. 'TicketModel::save() injects public into the payload regardless of what was '
					. 'asked for (TicketModel.php:796-802) and TicketTable::onBeforeCheck() then '
					. 'rewrites a falsy public to 1 (TicketTable.php:880-906). Manager status does '
					. 'not satisfy that check — it tests ats.private alone. Grant ats.private on the '
					. 'category to whichever of those two users should have it, and retry.'
				);
			}
		}

		$model = $this->atsModel('Ticket');

		if ($model === null) {
			return ToolResult::error('Could not create the ATS TicketModel through com_ats\' MVCFactory.');
		}

		$willSendMail      = $target > 0 && $target !== $actorId;
		$siteUrlConfigured = $this->atsSiteUrlConfigured();

		$data = [
			'id'          => $id,
			// Always sent; see the class docblock.
			'catid'       => $catid,
			'assigned_to' => $target,
		];

		// One call, because the ACL checks and the mail send are inseparable — see
		// the class docblock. $actor is what keeps the checks answering for the real
		// caller instead of a guest.
		$saved = $this->withSiteAppContext(
			fn(): array => $this->saveAdminModel($model, $data),
			$actor
		);

		$row = $this->atsReadAssignmentRow($id);

		if ($row === null) {
			return ToolResult::error(
				'Ticket ' . $id . ' is not in #__ats_tickets after the assignment attempt. Inspect '
				. 'the table before retrying.'
			);
		}

		if ($row['assigned_to'] !== $target) {
			return ToolResult::error(
				'The assignment did not take: assigned_to was requested as ' . $target . ' but the '
				. 'row still reads ' . $row['assigned_to'] . '. '
				. ($saved['error'] !== '' ? 'The model reported: ' . $saved['error'] : 'The model '
				. 'reported no error.')
			);
		}

		$result = [
			'ok'                 => true,
			'id'                 => $id,
			'changed'            => true,
			'previous_assigned_to' => $before,
			'assigned_to'        => $row['assigned_to'],
			'public'             => $row['public'],
			'status'             => $row['status'],
			'priority'           => $row['priority'],
			'mail_sent'          => $willSendMail,
			'note'               => 'assigned_to, public, status and priority are read back from '
				. '#__ats_tickets. public is reported because assignment runs through a full save, '
				. 'and TicketTable::onBeforeCheck() is entitled to rewrite it — this tool refuses '
				. 'beforehand in the case where it would, but the value is shown so you can confirm.',
		];

		if ($willSendMail) {
			$result['mail'] = 'ATS attempted the manager_assignedticket notification to user '
				. $target . '. It was fired by the table\'s own hook from inside store() '
				. '(TicketTable::onChangedValues, TicketTable.php:991-998), not by a controller — so '
				. 'saving the row was sufficient and no separate notify step was needed. Links in '
				. 'that mail may be /api/-prefixed: the save runs as a site application, which makes '
				. 'ATS resolve its {siteurl} token with Uri::base() (EmailSending.php:753-754), and '
				. 'Uri::base() caches its result in a static for the lifetime of the request '
				. '(Uri.php:131) — it had already been computed against the /api/ entry point before '
				. 'the application was swapped. Check a link before relying on the notification.';

			if (!$siteUrlConfigured) {
				$result['site_configuration_note'] = 'Unrelated to the mail just sent, but worth '
					. 'flagging: the com_ats `siteurl` param is empty on this site. It was not used '
					. 'here — a site application takes the Uri::base() branch instead — but it is what '
					. 'ATS falls back to from every NON-site context: scheduled auto-close and '
					. 'auto-reply runs, the Professional CLI commands, and the mail gateway. Mail from '
					. 'those will carry broken links until it is filled in.';
			}
		} elseif ($target === 0) {
			$result['mail'] = 'No notification: unassigning sends none. ATS returns early from '
				. 'sendAssignedEmails() when assigned_to is empty (EmailSending.php:59-62), and '
				. 'onChangedValues() would not have called it anyway.';
		} else {
			$result['mail'] = 'No notification: you assigned the ticket to yourself, and ATS skips '
				. 'the notification when the new assignee is the acting user '
				. '(TicketTable.php:994).';
		}

		if ($saved['ok'] === false && $saved['error'] !== '') {
			$result['post_save_warning'] = 'The row was written correctly — the value above is read '
				. 'back from the database — but the model reported: ' . $saved['error']
				. '. AdminModel::save() returns false when anything in the post-save chain throws '
				. 'even though store() succeeded, and mail sending is part of that chain here. Do '
				. 'not retry on the strength of that message: the assignment is already in place.';
		}

		return ToolResult::json($result);
	}

	private function atsUserExists(int $userId): bool
	{
		return (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName('#__users'))
				->where($this->db->quoteName('id') . ' = ' . $userId)
		)->loadResult() > 0;
	}

	/** @return array<string, mixed>|null */
	private function atsReadAssignmentRow(int $id): ?array
	{
		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName(['assigned_to', 'public', 'status', 'priority']))
				->from($this->db->quoteName('#__ats_tickets'))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();

		if (!$row) {
			return null;
		}

		return [
			'assigned_to' => (int) $row['assigned_to'],
			'public'      => (int) $row['public'],
			'status'      => (string) $row['status'],
			'priority'    => (int) $row['priority'],
		];
	}
}
