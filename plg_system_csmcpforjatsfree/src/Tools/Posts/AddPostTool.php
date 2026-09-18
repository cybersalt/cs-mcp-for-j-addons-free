<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Posts;

\defined('_JEXEC') or die;

use Akeeba\Component\ATS\Administrator\Helper\Permissions;
use Akeeba\Component\ATS\Administrator\Helper\PostNotification;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;

/**
 * Reply to a ticket. This is the tool that actually answers a customer.
 *
 * It is also the only tool in the add-on that sends mail to third parties and
 * mutates a row other than the one it wrote, so the five things below are all
 * load-bearing.
 *
 * 1. THE MODEL DOES NOT SEND THE NOTIFICATIONS — THE CONTROLLER DOES
 * ------------------------------------------------------------------
 * PostModel::save() writes the row and PostTable::onAfterStore() updates the
 * ticket, but nothing in either sends a single email. The notification is fired
 * by PostController::postSaveHook() (PostController.php:238-241), which calls
 * ControllerNewPostTrait::postNotifiable() → PostNotification::notify(). Saving
 * through the model alone therefore produces a silent reply: the ticket moves,
 * the customer is never told. That exact failure is what ATS 5.6.0 introduced
 * PostNotification for — its class docblock (PostNotification.php:23-28) says the
 * logic used to be private on a controller trait, so "anything that creates a post
 * outside a controller sent no notifications at all". This tool therefore calls
 * PostNotification::notify() itself, after the save, and reports the outcome.
 *
 * Who gets mail, from EmailSending::sendPostEmails() (EmailSending.php:163-297):
 * every manager of the ticket's category (:199-201), minus the post's own author
 * (:204-206), optionally narrowed to just the assigned manager by the
 * `assigned_noemail` param (:209-216) and further filtered by the category's
 * notify_managers / exclude_managers params (:219-222); the ticket owner, but only
 * when this is a brand-new ticket or the post was written by somebody other than
 * the owner (:247); and every invited collaborator except the author (:265-296).
 *
 * 2. WHY EVERYTHING RUNS INSIDE withSiteAppContext(), NOT JUST THE MAIL
 * ----------------------------------------------------------------------
 * The obvious split — save under the real API application, wrap only the
 * notification — is wrong here, because the SAVE path can send mail by itself.
 * PostTable::onAfterStore() auto-assigns an unassigned ticket to a replying
 * manager (PostTable.php:343-347), and TicketTable::onChangedValues() mails the new
 * assignee whenever assigned_to changes (TicketTable.php:991-997). That runs
 * EmailSending::getTicketEmailData(), which calls Route::link('site', …)
 * (EmailSending.php:766) — the exact URL build that fails under the API app. So
 * the save has to be inside the wrapper too.
 *
 * 3. THE IDENTITY INSIDE THE WRAPPER IS THE POST'S AUTHOR, DELIBERATELY
 * ---------------------------------------------------------------------
 * A SiteApplication out of the DI container has no identity loaded and reads as a
 * guest, so withSiteAppContext() takes a User to load in. Every other tool passes
 * the caller. This one passes the effective AUTHOR, which is the same object
 * whenever created_by is not overridden, and is the whole mechanism when it is:
 *
 * ATS has a dedicated route for a caller-supplied created_by —
 * PostModel::validate() re-injects it after parent::validate() strips it, because
 * forms/post_new.xml has no created_by field (PostModel.php:176-193) — but that
 * route is gated on `$app->isClient('api')`, and prepareTable() gates its half of
 * the same deal identically (PostModel.php:296-302). Having swapped the API
 * application out for a SiteApplication in order to send mail at all, neither gate
 * can fire, and prepareTable() would re-attribute the post to whatever identity it
 * finds. Loading the author as that identity reaches the same result down the
 * ordinary non-API path.
 *
 * This is not cosmetic. The status matrix keys off the stored created_by —
 * `Permissions::isManager($ticket->catid, $this->created_by)` at PostTable.php:296
 * — so getting created_by wrong gets the resulting ticket status wrong, silently
 * and at the data level. A manager's reply misattributed to a guest would leave the
 * ticket Open when it should be Pending. The tool re-reads the ticket afterwards
 * and reports the status ATS actually chose rather than predicting it.
 *
 * A consequence to be aware of: the reply form is then shaped by the author's
 * privileges rather than the caller's, so `timespent` is dropped when the author is
 * not a manager (see 5). That is consistent — the post is theirs — and the override
 * itself is gated on the CALLER holding core.admin on com_ats, the same authority
 * ATS demands at PostModel.php:183.
 *
 * 4. A CLOSED TICKET SWALLOWS EVERYTHING EXCEPT THE ROW
 * -----------------------------------------------------
 * PostTable::onAfterStore() opens with `if ($this->isNewPost && $ticket->status != 'C')`
 * (PostTable.php:291). When the ticket is already Closed the entire block is
 * skipped: the status is not recomputed, `modified` / `modified_by` (which in ATS
 * mean "last reply") are not touched, the ticket's `timespent` SUM is not
 * refreshed, and the auto-assign does not happen. The row lands and the ticket
 * looks untouched.
 *
 * We require an explicit acknowledge_closed_ticket flag rather than refusing
 * outright, for two reasons. First, ATS itself permits it — a manager's
 * getTicketPrivileges() array is all-true regardless of status, and the component's
 * own UI will take the reply — so a flat refusal would block something the vendor
 * grants, and appending a closing note to a closed ticket is a real workflow.
 * Second, a flat refusal is unnecessary: non-managers cannot reach this path at
 * all, because Permissions::getTicketPrivileges() collapses every privilege except
 * `view` to false on a closed ticket for anyone who is not a manager
 * (Permissions.php:713-727), so our `post` check has already refused them. The flag
 * is aimed squarely at the one caller who can do it — a manager — who needs to know
 * that the ticket will not reopen and that their time will not be billed.
 *
 * Note that the notifications DO still go out for a closed ticket: PostNotification
 * is independent of that status block. So the surprise is not silence; it is a
 * customer receiving "there is a new reply on your ticket" about a ticket that is
 * still marked Closed, with the agent's time unbilled.
 *
 * 5. timespent IS REMOVED FROM THE FORM FOR NON-MANAGERS
 * ------------------------------------------------------
 * PostModel::getForm() removes the timespent field unless the poster holds
 * `admin` (core.manage) on the category, and removes it for everyone when the
 * `timespent_hide` param is on (PostModel.php:98-101). validate() then strips the
 * value, silently. This tool inspects the built form and tells the caller when a
 * supplied timespent was dropped instead of letting it vanish.
 *
 * (A vendor quirk worth knowing but not worth working around: the variable at
 * PostModel.php:90 is inverted — `$timeSpentMandatory = !$timeSpentHidden &&
 * $cParams->get('timespent_mandatory', 0) != 1`, so turning the "Time Spent
 * Mandatory" option ON is what makes the field NOT required. post_new.xml declares
 * no `required` attribute on timespent either way, so on a new reply it is moot.)
 */
final class AddPostTool extends AbstractTool
{
	use ATSBootTrait;

	public function getName(): string { return 'add_ats_post'; }

	public function getDescription(): string
	{
		return 'Reply to an Akeeba Ticket System ticket. This is how you answer a customer: it writes a row '
			. 'to #__ats_posts, lets ATS move the parent ticket\'s status, and sends the notification emails. '
			. 'REQUIRED: ticket_id, content_html (HTML; it is run through the component\'s configured filter — '
			. 'HTML Purifier by default — so disallowed tags are stripped before storage). '
			. 'OPTIONAL: timespent (float, the time this reply took, in the unit the site has configured — it is '
			. 'only accepted from a category manager and is silently dropped by ATS for anyone else, so this tool '
			. 'reports when that happened); created_by (attribute the reply to another user — requires the CALLING '
			. 'user to hold core.admin on com_ats, mirroring the gate in PostModel::validate(), and the named user '
			. 'must themselves be allowed to post on the ticket); enabled (1 published, default; 0 writes the reply '
			. 'unpublished, which means it is excluded from the ticket\'s timespent SUM); send_notifications '
			. '(default true — set false only for a correction nobody should be emailed about); '
			. 'acknowledge_closed_ticket (see below). '
			. 'STATUS MATRIX — what your reply does to the ticket, decided by ATS in PostTable::onAfterStore() and '
			. 'NOT overridable here: a category MANAGER replying to somebody else\'s ticket sets the ticket to P '
			. '(Pending = waiting on the customer); a manager replying to a ticket THEY THEMSELVES opened sets O, '
			. 'because they are the customer on it; the ticket owner, an invited collaborator, or any other '
			. 'non-manager with ats.reply sets O (Open = waiting on support). The matrix keys off the post\'s '
			. 'author, so if you use created_by it is the named user\'s role that decides, not yours. The tool '
			. 're-reads the ticket after saving and reports the status it actually ended up in, so do not infer it '
			. '— read ticket.status_after. '
			. 'SIDE EFFECTS: the ticket\'s modified / modified_by are updated (in ATS these mean "last reply", not '
			. '"last edited"); the ticket\'s timespent is recomputed as the SUM over all PUBLISHED posts; and an '
			. 'UNASSIGNED ticket is auto-assigned to the author if they are a manager of its category, which itself '
			. 'emails the new assignee. '
			. 'CLOSED TICKETS: if the ticket status is already C, ATS skips that entire block — no status change, no '
			. 'modified, no timespent recalculation, no auto-assign — while still emailing everyone that there is a '
			. 'new reply. The reply becomes a row and nothing else moves. This tool refuses unless you pass '
			. 'acknowledge_closed_ticket: true. If you meant to reopen the ticket, change its status first and then '
			. 'reply. (Non-managers cannot reply to a closed ticket at all: ATS strips every privilege but "view".) '
			. 'MAIL: managers of the category (minus the author, and optionally narrowed to the assigned manager or '
			. 'by the category\'s notify/exclude lists), the ticket owner when the reply is not theirs, and every '
			. 'invited collaborator. '
			. 'ATTACHMENTS cannot be added — they are an ATS Professional feature.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'ticket_id' => [
					'type'        => 'integer',
					'description' => 'The ticket to reply to. You must hold the "post" privilege on it.',
				],
				'content_html' => [
					'type'        => 'string',
					'description' => 'The reply body, as HTML. Filtered by the component\'s configured filter method before storage; if that strips it to nothing the save is refused rather than storing a blank reply that emails everyone.',
				],
				'timespent' => [
					'type'        => 'number',
					'description' => 'Time spent on this reply. Only accepted from a category manager (and only when the timespent_hide param is off); otherwise ATS removes the field and the value is dropped — the response says when that happened. Feeds the ticket\'s derived total.',
				],
				'created_by' => [
					'type'        => 'integer',
					'description' => 'Attribute the reply to this user id instead of you. Requires core.admin on com_ats. The named user must be allowed to post on the ticket, and it is their manager status — not yours — that decides the resulting ticket status. Values <= 0 are refused: ATS treats those as automated system notices and skips the status assignment for them.',
				],
				'enabled' => [
					'type'        => 'integer',
					'enum'        => [0, 1],
					'description' => 'Default 1. 0 writes the reply unpublished: it is invisible in the thread and excluded from the ticket\'s timespent SUM.',
				],
				'send_notifications' => [
					'type'        => 'boolean',
					'description' => 'Default true. False skips PostNotification::notify() entirely — no emails and no onATSPost plugin event. The ticket status change still happens.',
				],
				'acknowledge_closed_ticket' => [
					'type'        => 'boolean',
					'description' => 'Required (true) to reply to a ticket whose status is already C. Confirms you understand the reply will NOT reopen the ticket, will not update its last-reply timestamp and will not be counted in its billed time — while still emailing everyone.',
				],
			],
			'required'             => ['ticket_id', 'content_html'],
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
		$content  = $this->requireString($arguments, 'content_html');

		$ticket = $this->atsLoadTicket($ticketId);

		if ($ticket === null) {
			return ToolResult::error('No ticket with id ' . $ticketId . ' exists in #__ats_tickets.');
		}

		$ticketPrivileges = $this->atsTicketPrivileges($ticket, $actor);

		if (empty($ticketPrivileges['view'])) {
			return ToolResult::error(
				'No ticket with id ' . $ticketId . ' is visible to you, so you cannot reply to it.'
			);
		}

		$statusBefore = (string) $ticket->status;
		$isClosed     = $statusBefore === 'C';

		if (empty($ticketPrivileges['post'])) {
			return ToolResult::error(
				'You do not hold the "post" privilege on ticket ' . $ticketId . ', so ATS will not accept a reply '
				. 'from you.'
				. ($isClosed
					? ' The ticket is CLOSED, and that is very likely why: Permissions::getTicketPrivileges() '
					. 'collapses every privilege except "view" to false on a closed ticket for anyone who is not '
					. 'a manager of its category. Reopen the ticket first.'
					: ' The "post" privilege is the ats.reply action on the ticket\'s category (invited '
					. 'collaborators get it automatically). Use get_ats_ticket to see the full privilege array.')
			);
		}

		// -- Effective author ------------------------------------------------------
		$author         = $actor;
		$authorOverride = false;
		$requestedBy    = \array_key_exists('created_by', $arguments) && $arguments['created_by'] !== null
			? (int) $arguments['created_by']
			: 0;

		if ($requestedBy !== 0 && $requestedBy !== (int) $actor->id) {
			if ($requestedBy < 0) {
				return ToolResult::error(
					'created_by must be a positive user id. ATS treats created_by <= 0 as an automated "system" '
					. 'notice and skips the status assignment for it (PostTable.php:294, 327-330) — the ticket '
					. 'would keep whatever status it already had, no matter who the reply was really from. It '
					. 'would still stamp the ticket\'s last-reply timestamp with modified_by = ' . $requestedBy
					. ' and still recompute the billed time, so the result is a ticket that looks freshly active '
					. 'but never moved out of its old state. ATS writes those rows itself for things like '
					. 'invitation notices; they are not something to create by hand.'
				);
			}

			// Same authority ATS demands before it will honour a caller-supplied
			// created_by (PostModel::validate(), PostModel.php:183).
			if (!$actor->authorise('core.admin', 'com_ats')) {
				return ToolResult::error(
					'Attributing a reply to another user requires core.admin on com_ats. ATS applies the same '
					. 'condition to its own API path (PostModel::validate()); without it the post would be '
					. 'attributed to you regardless, which would be a silent lie in the ticket thread.'
				);
			}

			$candidate = Permissions::getUser($requestedBy);

			if ($candidate === null || (int) $candidate->id !== $requestedBy) {
				return ToolResult::error('created_by user ' . $requestedBy . ' does not exist.');
			}

			$authorPrivileges = $this->atsTicketPrivileges($ticket, $candidate);

			if (empty($authorPrivileges['post'])) {
				return ToolResult::error(
					'User ' . $requestedBy . ' (' . $candidate->username . ') does not hold the "post" privilege on '
					. 'ticket ' . $ticketId . '. Attributing a reply to somebody who could not have written it would '
					. 'also change what the reply does: the status matrix keys off the AUTHOR\'s manager status, not '
					. 'yours.'
				);
			}

			$author         = $candidate;
			$authorOverride = true;
		}

		// -- Closed-ticket acknowledgement ----------------------------------------
		if ($isClosed && ($arguments['acknowledge_closed_ticket'] ?? false) !== true) {
			return ToolResult::error(
				'Ticket ' . $ticketId . ' is CLOSED, and replying to a closed ticket in ATS is very nearly a no-op. '
				. 'PostTable::onAfterStore() guards its entire body with "is this a new post AND is the ticket not '
				. 'closed", so your reply would be stored as a row and then: the ticket status would stay C (it does '
				. 'NOT reopen), its modified / modified_by — which in ATS mean "last reply" — would not be updated, '
				. 'its timespent total would not be recalculated so any time you logged would go unbilled, and it '
				. 'would not be auto-assigned to you. The notification emails WOULD still go out, so the customer '
				. 'would be told there is a new reply on a ticket that still reads as closed. '
				. 'If you want the ticket reopened, set its status first (O = waiting on support, P = waiting on the '
				. 'customer) and then reply. If you genuinely want to append a note to a closed ticket, call this '
				. 'again with acknowledge_closed_ticket: true.'
			);
		}

		$timespentRequested = \array_key_exists('timespent', $arguments) && $arguments['timespent'] !== null;
		$timespent          = $timespentRequested ? (float) $arguments['timespent'] : 0.0;

		if ($timespent < 0) {
			throw new \InvalidArgumentException('timespent cannot be negative.');
		}

		$enabled = \array_key_exists('enabled', $arguments) && $arguments['enabled'] !== null
			? ((int) $arguments['enabled'] === 0 ? 0 : 1)
			: 1;

		$notify = ($arguments['send_notifications'] ?? true) !== false;

		$data = [
			'id'           => 0,
			'ticket_id'    => $ticketId,
			'content_html' => $content,
			'enabled'      => $enabled,
			'timespent'    => $timespent,
			'created_by'   => (int) $author->id,
			'origin'       => 'web',
		];

		// The identity loaded into the swapped application is the AUTHOR, not the
		// caller — that is what makes created_by stick, and created_by is what the
		// status matrix reads. See the class docblock, point 3.
		$outcome = $this->withSiteAppContext(
			function () use ($data, $notify): array {
				$model = $this->atsModel('Post');

				if ($model === null) {
					return ['error' => 'Could not instantiate ATS\' PostModel through the component MVCFactory.'];
				}

				// Mirrors FormController::save(): build the form WITHOUT loading data
				// (we are supplying all of it), then validate through the model so the
				// vendor's own field filters and its non-manager body-size limit apply.
				$form = $model->getForm($data, false);

				if ($form === false || $form === null) {
					return ['error' => 'ATS could not build its com_ats.post_new form, so the reply was not saved.'];
				}

				$timespentAccepted = $form->getField('timespent') !== false;

				$valid = $model->validate($form, $data);

				if ($valid === false) {
					$errors = method_exists($model, 'getErrors') ? ($model->getErrors() ?: []) : [];
					$errors = array_map(
						static fn($e): string => $e instanceof \Throwable ? $e->getMessage() : (string) $e,
						$errors
					);

					return [
						'error' => 'ATS rejected the reply during validation: '
							. (implode(' | ', $errors) ?: 'no reason given by the component.'),
					];
				}

				// content_html is REMOVED from the form when the poster lacks the "post"
				// privilege (PostModel.php:97). We check that before this point, but the
				// form is shaped by the author rather than the caller, so verify rather
				// than assume — a stripped body would otherwise be stored as an empty
				// reply and emailed to everyone.
				if (!\array_key_exists('content_html', $valid) || trim((string) $valid['content_html']) === '') {
					return [
						'error' => 'The reply body did not survive ATS\' own filtering — either the poster lacks the '
							. '"post" privilege (in which case ATS removes the content field from the form entirely) '
							. 'or the configured text filter stripped every tag you sent. Nothing was saved.',
					];
				}

				$save = $this->saveAdminModel($model, $valid);

				if ($save['id'] <= 0) {
					return ['error' => 'The reply was not saved: ' . ($save['error'] ?: 'ATS gave no reason.')];
				}

				// The row exists from here on. Anything that fails below is reported,
				// never thrown, so the caller never retries and double-posts.
				$notifyError = null;
				$notifySent  = false;

				if ($notify) {
					if (!class_exists(PostNotification::class)) {
						$notifyError = 'This ATS install predates 5.6.0, where PostNotification was introduced. '
							. 'Before that the notification logic was private to a controller trait and cannot be '
							. 'reached from outside a web request, so NO emails were sent for this reply.';
					} else {
						try {
							$post = $model->getItem($save['id']);

							if ($post === false || $post === null) {
								$notifyError = 'The reply saved but could not be re-read, so no notifications were sent.';
							} else {
								PostNotification::notify($post, true);
								$notifySent = true;
							}
						} catch (\Throwable $e) {
							$notifyError = $e->getMessage();
						}
					}
				}

				return [
					'id'                 => $save['id'],
					'save_reported_ok'   => $save['ok'],
					'save_error'         => $save['error'],
					'timespent_accepted' => $timespentAccepted,
					'notify_sent'        => $notifySent,
					'notify_error'       => $notifyError,
				];
			},
			$author
		);

		if (isset($outcome['error'])) {
			return ToolResult::error($outcome['error']);
		}

		// Re-read the ticket: the resulting status depends on whether the AUTHOR is a
		// manager of the category, and on whether ATS skipped the block entirely.
		$after       = $this->atsLoadTicket($ticketId);
		$statusAfter = $after === null ? $statusBefore : (string) $after->status;

		$authorIsManager = Permissions::isManager((int) $ticket->catid, (int) $author->id);
		$authorIsOwner   = (int) $author->id === (int) $ticket->created_by;
		$predicted       = $isClosed ? $statusBefore : (($authorIsManager && !$authorIsOwner) ? 'P' : 'O');

		$warnings = [];

		if ($isClosed) {
			$warnings[] = 'The ticket was already CLOSED, so ATS skipped its whole post-save block: the status is '
				. 'unchanged, the last-reply timestamp was not touched, the ticket\'s timespent was NOT recomputed '
				. '(so any time on this reply is not billed), and no auto-assignment happened. The notification '
				. 'emails went out regardless.';
		}

		if ($statusAfter !== $predicted) {
			$warnings[] = 'The ticket ended up in status "' . $statusAfter . '" where the documented matrix predicts '
				. '"' . $predicted . '" for this author. Something outside PostTable::onAfterStore() — a site-defined '
				. 'status, an ats plugin, or a concurrent edit — moved it. The reported status is what is actually in '
				. 'the database.';
		}

		if ($timespentRequested && ($outcome['timespent_accepted'] ?? true) === false) {
			$warnings[] = 'The timespent value you supplied was DROPPED. ATS removes that field from the reply form '
				. 'unless the poster holds core.manage on the ticket\'s category, or when the timespent_hide '
				. 'component param is on. The post was saved with timespent 0.';
		}

		if (!empty($outcome['notify_error'])) {
			$warnings[] = 'The reply was saved but the notifications failed: ' . $outcome['notify_error'];
		}

		if ($notify && !$this->atsSiteUrlConfigured()) {
			$warnings[] = 'The com_ats `siteurl` param is empty. Mail sent from here runs under a site application, '
				. 'so ATS fills the {siteurl} token from Uri::base() instead (EmailSending.php:703) and the links '
				. 'are probably usable — but `siteurl` is the fallback for every code path that mails outside a '
				. 'site request, so it is worth setting in Components → Akeeba Ticket System → Options → Site URL.';
		}

		if ($enabled === 0) {
			$warnings[] = 'The reply was saved UNPUBLISHED (enabled = 0). It is not visible in the thread and its '
				. 'timespent is excluded from the ticket total, but the notification emails were still sent.';
		}

		if (($outcome['save_reported_ok'] ?? true) === false) {
			$warnings[] = 'ATS\' save() returned false even though the row was written (id ' . $outcome['id'] . '). '
				. 'That normally means a post-save plugin threw. Do NOT retry — you would post twice. '
				. ($outcome['save_error'] ?: '');
		}

		return ToolResult::json([
			'ok'      => true,
			'post_id' => (int) $outcome['id'],
			'ticket'  => [
				'id'              => $ticketId,
				'title'           => (string) $ticket->title,
				'status_before'   => $statusBefore,
				'status_after'    => $statusAfter,
				'status_label'    => $this->atsStatusLabel($statusAfter),
				'status_changed'  => $statusBefore !== $statusAfter,
				'assigned_to'     => $after === null ? (int) $ticket->assigned_to : (int) $after->assigned_to,
				'timespent_total' => $after === null ? (float) $ticket->timespent : (float) $after->timespent,
				'last_reply'      => $after === null ? $ticket->modified : $after->modified,
			],
			'author' => [
				'id'              => (int) $author->id,
				'username'        => (string) $author->username,
				'is_override'     => $authorOverride,
				'is_manager_here' => $authorIsManager,
				'is_ticket_owner' => $authorIsOwner,
			],
			'timespent_recorded'  => ($outcome['timespent_accepted'] ?? true) ? $timespent : 0.0,
			'enabled'             => $enabled,
			'notifications_sent'  => (bool) ($outcome['notify_sent'] ?? false),
			'site_url_configured' => $this->atsSiteUrlConfigured(),
			'warnings'            => $warnings,
			'note'                => 'status_after is read back from the database, not predicted. ATS decides it in '
				. 'PostTable::onAfterStore() from the AUTHOR\'s role: a manager replying to someone else\'s ticket '
				. 'sets P (waiting on the customer), everyone else — including a manager on their own ticket — sets '
				. 'O (waiting on support). The ticket\'s timespent is a derived SUM over published posts and was '
				. 'recomputed by this save unless the ticket was closed.',
		]);
	}
}
