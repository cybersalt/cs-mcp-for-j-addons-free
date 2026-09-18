<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;

/**
 * Create an ATS ticket together with its opening post.
 *
 * WHY TWO SAVES AND NOT ONE
 * -------------------------
 * The opening message is a row in #__ats_posts, not a column on the ticket, and
 * TicketModel::save() does not write it for you. The vendor does it in the
 * controller: TicketController::save() splits the submitted jform into ticket
 * keys and post keys using the form's `post` fieldset (TicketController.php:109-119),
 * saves the ticket with the state flag `ticket.savingNew` set — which makes
 * TicketModel::getForm() strip that same fieldset (TicketModel.php:576-584) — and
 * then hands the post data to PostController::save() (TicketController.php:172-176).
 * We reproduce the two-step through the two models. We also reproduce the vendor's
 * rollback: if the post fails the ticket is deleted, exactly as
 * TicketController.php:191 does, because a ticket with no opening post is a record
 * no ATS view can render properly.
 *
 * WHY THE TICKET SAVE IS WRAPPED AND THE POST SAVE IS NOT
 * -------------------------------------------------------
 * Passing `assigned_to` makes TicketTable::onChangedValues() fire
 * EmailSending::sendAssignedEmails() from inside store() (TicketTable.php:991-998),
 * and that builds a front-end URL with Route::link('site', ...) (EmailSending.php:
 * around 770), which throws "Error loading menu: api" under the API application.
 * So the ticket save runs inside withSiteAppContext().
 *
 * The post save deliberately does NOT. PostModel::prepareTable() honours a
 * caller-supplied created_by only when the application is the API app
 * (PostModel.php:292, 300-303) — ATS added that branch specifically for API
 * clients. Swapping in a SiteApplication takes the `else` branch and silently
 * rewrites the opening post's author to the MCP actor, which is wrong whenever a
 * manager files a ticket on a customer's behalf. PostModel::save() sends no mail
 * of its own (sendPostEmails() is reached only from Helper\PostNotification, which
 * only the controllers call), so leaving it unwrapped is safe. The one residual
 * case — the nested ticket save in PostTable::onAfterStore() auto-assigning an
 * unassigned ticket to a manager poster (PostTable.php:344-347) and thereby
 * reaching sendAssignedEmails — is detected up front and reported.
 *
 * A MODEL-ONLY POST SAVE IS A SILENT ONE — SO THE TOOL NOTIFIES EXPLICITLY
 * -------------------------------------------------------------------------
 * Nothing in PostModel::save() or PostTable::onAfterStore() sends mail. A post's
 * notification is the controller's job: PostController::postSaveHook() calls
 * Helper\PostNotification::notify(). PostNotification's own docblock spells out
 * the consequence — before 5.6.0 the logic was private on a controller trait, so
 * "anything that creates a post outside a controller sent no notifications at
 * all", and that is exactly why the helper was extracted into a public static.
 *
 * Saving the ticket and its post through the models alone would therefore file a
 * support request that reaches nobody. This tool calls PostNotification::notify()
 * itself after the post save, inside withSiteAppContext() because
 * EmailSending::sendPostEmails() builds ticket URLs with Route::link('site', ...).
 * notify() respects the component's own sendEmails option, so a site that has
 * turned mail off still gets none. Pass notify: false to suppress it deliberately
 * — for a bulk import, say — and the response says which happened either way.
 *
 * Note the contrast with assignment, which is the opposite case: that mail comes
 * from TicketTable::onChangedValues() inside store() (TicketTable.php:991-998), so
 * the ticket save notifies the assignee by itself and must not be notified again.
 *
 * The opening post is never written as a system post. ATS treats created_by <= 0
 * as an automated notice: Permissions::getUser(-1) fabricates a `system` user that
 * joins to nothing in #__users, EmailSending::sendPostEmails() suppresses mail for
 * it, and PostTable::onAfterStore() leaves the ticket status untouched
 * (PostTable.php:294, 327-330). An opening message is a real person's, so
 * created_by is always resolved to a positive user id before the save.
 *
 * IDENTITY INSIDE withSiteAppContext()
 * ------------------------------------
 * $actor is passed to the wrapper, which is mandatory here rather than merely
 * tidy. The container's SiteApplication has never dispatched, so its `identity`
 * property is null (IdentityAware::getIdentity() is a bare property read), and
 * Permissions::getUser() resolves to that property (Permissions.php:1003-1013).
 * Without the identity, TicketModel::prepareTable() dereferences null at
 * TicketModel.php:1175 (`$user->id`), and every ACL decision inside
 * TicketTable::onBeforeCheck() — the core.create assert, the ats.private test that
 * decides whether a private ticket stays private — would be answered for an
 * anonymous visitor. The ticket save cannot be moved outside the wrapper either:
 * the ACL checks and the mail send are the same call, since onChangedValues()
 * fires from within store().
 *
 * VALUES ARE REPORTED AS STORED, NOT AS REQUESTED
 * -----------------------------------------------
 * TicketTable::onBeforeCheck() rewrites four of the fields this tool accepts, and
 * TicketModel::save() rewrites a fifth before that. All confirmed against 5.6.0:
 *   - public   forced to 1 when neither the actor nor the ticket owner holds
 *              ats.private on the category (TicketTable.php:880-906); and
 *              overwritten outright by the category's `forcetype` param before
 *              that (TicketModel.php:803-806).
 *   - status   any value outside {O,P,C,'1'..'99'} silently becomes 'O'
 *              (TicketTable.php:916-919). The comparison is case-sensitive, so
 *              lowercase 'o' lands on Open as well.
 *   - priority any falsy value becomes 1 on a private ticket and 5 on a public
 *              one (TicketTable.php:927-939), so priority 0 cannot be stored.
 *   - alias    auto-slugged from the title and given a numeric suffix if taken
 *              (TicketTable.php:850-877).
 *   - origin   anything but 'web'/'email' becomes 'web' (TicketTable.php:921-925);
 *              this tool does not offer it at all.
 * The response therefore carries the row read back out of the database plus an
 * explicit warning for each field whose stored value differs from the request.
 */
final class CreateTicketTool extends AbstractTool
{
	use ATSBootTrait;

	public function getName(): string { return 'create_ats_ticket'; }

	public function getDescription(): string
	{
		return 'Create an Akeeba Ticket System ticket AND its opening message in one call. The '
			. 'opening message is a row in #__ats_posts, not a column on the ticket, so this tool '
			. 'performs two writes through the vendor models (TicketModel then PostModel) and rolls '
			. 'the ticket back by deleting it if the post fails — the same rollback ATS\' own '
			. 'TicketController does. Required: catid (an ATS category id, validated against '
			. '#__categories WHERE extension = \'com_ats\'), title, and message (the opening post '
			. 'body; content_html is accepted as an alias). Optional: public, status, priority, '
			. 'alias, enabled, assigned_to, created_by, post_created_by, custom_fields. '
			. 'WHAT THE VENDOR WILL CHANGE BEHIND YOU — all verified in ATS 5.6.0 source, and all '
			. 'reported back as stored rather than as requested: (1) `public` is overwritten by the '
			. 'category\'s `forcetype` param if that is set to PUB or PRIV, and is forced to 1 if '
			. 'neither you nor the ticket owner holds the ats.private privilege on the category — '
			. 'note that being a category MANAGER is not sufficient for the second check, which '
			. 'tests ats.private alone. (2) `status` is silently reset to O (Open) if it is not one '
			. 'of O, P, C or the strings 1-99, and the check is case-sensitive. (3) `priority` is '
			. 'replaced by 1 (private ticket) or 5 (public ticket) whenever you send 0, null or omit '
			. 'it, so priority 0 is unreachable; the column is TINYINT with NO DEFAULT, which is why '
			. 'the tool always sends something. The category\'s default_priority param is NOT '
			. 'applied on this path — ATS only applies it while building the HTML form. (4) `alias` '
			. 'is auto-generated from the title and suffixed -1, -2 ... if it collides. (5) '
			. '`created_by` is honoured only when you are a manager of the target category; '
			. 'otherwise TicketModel::prepareTable overwrites it with your own user id, and this '
			. 'tool warns when that will happen. (6) Passing `assigned_to` SENDS EMAIL to the '
			. 'assignee from inside the save — that notification comes from the ticket table\'s own '
			. 'hook, so it needs no help from us. '
			. 'NOTIFICATIONS FOR THE OPENING MESSAGE ARE THE OPPOSITE CASE, and this tool handles '
			. 'them for you. Nothing in ATS\' post model or post table sends mail: a reply is '
			. 'notified by the CONTROLLER, so saving a post through the model alone files a support '
			. 'request that reaches nobody. After writing the post this tool calls the vendor\'s own '
			. 'Helper\\PostNotification::notify(), the same call the controller makes, so a ticket '
			. 'created here behaves like one filed through the website. Pass notify: false to '
			. 'suppress that for a bulk import. The response always states whether the notification '
			. 'went out. THINGS THIS TOOL DOES NOT OFFER AND WHY: `access` and '
			. '`language` do not exist as columns on #__ats_tickets — both are inherited from the '
			. 'category. `origin` is always web. `timespent` on the ticket is derived by ATS from '
			. 'the sum of its posts and writing it directly is pointless. `modified`/`modified_by` '
			. 'mean "last reply", not "last edited", and ATS never sets them on an edit.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['catid', 'title'],
			'properties' => [
				'catid' => [
					'type'        => 'integer',
					'description' => 'ATS category id. Validated against #__categories WHERE '
						. 'extension = \'com_ats\' — catid is a bare bigint with no foreign key, so '
						. 'a com_content category id would otherwise be accepted and produce a '
						. 'ticket nobody can see.',
				],
				'title' => [
					'type'        => 'string',
					'description' => 'Ticket subject. Required and trimmed; ATS rejects an empty one.',
				],
				'message' => [
					'type'        => 'string',
					'description' => 'The opening post body (HTML). Stored in #__ats_posts.content_html '
						. 'and run through ATS\' HTMLPurifier filter. Required unless you pass '
						. 'content_html instead.',
				],
				'content_html' => [
					'type'        => 'string',
					'description' => 'Alias for `message` — the vendor\'s own column name. Pass one or '
						. 'the other, not both.',
				],
				'public' => [
					'type'        => 'integer',
					'enum'        => [0, 1],
					'description' => '1 = public, 0 = private. Defaults to the column default of 1. '
						. 'May be overridden by the category forcetype param, and forced to 1 if '
						. 'neither you nor the ticket owner holds ats.private on the category. The '
						. 'stored value is reported back.',
				],
				'status' => [
					'type'        => 'string',
					'description' => 'O = Open (waiting on support), P = Pending (waiting on the '
						. 'customer), C = Closed, or a site-defined numeric status "1".."99". '
						. 'Case-sensitive at the storage layer; this tool upper-cases the letter '
						. 'forms for you and refuses anything the site does not define. Defaults to '
						. 'O. NOTE: the opening post is saved after the ticket, and '
						. 'PostTable::onAfterStore() then rewrites the status to O or P based on who '
						. 'posted — unless you created the ticket as C, in which case that whole '
						. 'block is skipped. The final status is reported back.',
				],
				'priority' => [
					'type'        => 'integer',
					'description' => 'TINYINT. The ATS UI offers 0-10 with 0 as highest, but the save '
						. 'path applies no clamp — only the batch tool does. 0 is not storable: it '
						. 'is falsy, and ATS replaces a falsy priority with 1 (private) or 5 '
						. '(public). Omit to accept that default.',
				],
				'alias' => [
					'type'        => 'string',
					'description' => 'URL slug. Omit to let ATS derive one from the title. A colliding '
						. 'alias is given a numeric suffix; the stored value is reported back.',
				],
				'enabled' => [
					'type'        => 'integer',
					'enum'        => [0, 1],
					'description' => 'The publish flag (the column is named `enabled`; TicketTable '
						. 'aliases `published` onto it). Defaults to 1.',
				],
				'assigned_to' => [
					'type'        => 'integer',
					'description' => 'User id of the support agent to assign. SENDS EMAIL to that '
						. 'user from inside the save unless it is you. The target must satisfy '
						. 'Permissions::canBeAssignedTickets() for the category (core.admin, '
						. 'core.manage or ats.assignee) or the save aborts on an assertion; this '
						. 'tool checks first so you get a clear message. Use 0 or omit for '
						. 'unassigned.',
				],
				'created_by' => [
					'type'        => 'integer',
					'description' => 'User id to record as the ticket owner. Honoured only if you are '
						. 'a manager of the target category — that is ATS\' rule, not ours '
						. '(TicketModel.php:789-793 sets the override flag under exactly that '
						. 'condition). Otherwise it is silently replaced with your own id, and this '
						. 'tool tells you so.',
				],
				'post_created_by' => [
					'type'        => 'integer',
					'description' => 'Author of the opening post, if it should differ from the ticket '
						. 'owner. Defaults to created_by, then to you. Honoured because the MCP '
						. 'endpoint is the API application and PostModel::prepareTable() preserves a '
						. 'supplied created_by there.',
				],
				'custom_fields' => [
					'type'        => 'object',
					'description' => 'Joomla custom field values for context com_ats.ticket, keyed by '
						. 'field NAME (not id). Passed to Joomla\'s fields plugin as com_fields. '
						. 'Fields you omit are left alone.',
					'additionalProperties' => true,
				],
				'notify' => [
					'type'        => 'boolean',
					'description' => 'Send the new-post notification for the opening message. '
						. 'Defaults to TRUE, which matches what happens when a ticket is filed '
						. 'through the website. Set false for a bulk import or a migration, where '
						. 'notifying every historical ticket would be wrong. Either way ATS\' own '
						. '"Send emails" component option still applies on top.',
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

		$catid = $this->requirePositiveInt($arguments, 'catid');
		$title = $this->requireString($arguments, 'title');

		$hasMessage = \array_key_exists('message', $arguments) && trim((string) $arguments['message']) !== '';
		$hasHtml    = \array_key_exists('content_html', $arguments) && trim((string) $arguments['content_html']) !== '';

		if ($hasMessage && $hasHtml) {
			return ToolResult::error(
				'Pass either `message` or `content_html`, not both — they are the same field. '
				. '`content_html` is the vendor\'s column name in #__ats_posts.'
			);
		}

		if (!$hasMessage && !$hasHtml) {
			return ToolResult::error(
				'An opening message is required. In ATS the first message of a ticket is a row in '
				. '#__ats_posts, not a column on the ticket, so a ticket created without one renders '
				. 'as an empty thread. Pass `message` (or `content_html`).'
			);
		}

		$body = (string) ($hasMessage ? $arguments['message'] : $arguments['content_html']);

		$category = $this->atsCategory($catid);

		if ($category === null) {
			return ToolResult::error(
				'Category ' . $catid . ' is not an Akeeba Ticket System category. #__ats_tickets.catid '
				. 'is a plain bigint with no foreign key, so a com_content category id would be '
				. 'accepted by the database and produce a ticket that no ATS view can list. Use '
				. 'list_ats_categories to find a real one.'
			);
		}

		$catParams = new \Joomla\Registry\Registry((string) ($category['params'] ?? '{}'));
		$forceType = strtoupper(trim((string) $catParams->get('forcetype', '')));

		// ---------------------------------------------------------------- status
		$requestedStatus = null;

		if (\array_key_exists('status', $arguments) && trim((string) $arguments['status']) !== '') {
			$requestedStatus = $this->atsNormaliseStatus((string) $arguments['status']);

			if ($requestedStatus === null) {
				return ToolResult::error(
					'"' . (string) $arguments['status'] . '" is not a status this site defines. Valid '
					. 'values are O, P, C and whichever of "1".."99" appear in the component\'s '
					. 'customStatuses param. ATS would not reject it — TicketTable::onBeforeCheck() '
					. 'silently rewrites an unknown status to O — so refusing here is the only way '
					. 'you find out.'
				);
			}
		}

		// ------------------------------------------------------------ assigned_to
		$assignedTo = (int) ($arguments['assigned_to'] ?? 0);

		if ($assignedTo > 0 && !$this->atsUserExists($assignedTo)) {
			return ToolResult::error('assigned_to ' . $assignedTo . ' is not a user in #__users.');
		}

		if ($assignedTo > 0
			&& !\Akeeba\Component\ATS\Administrator\Helper\Permissions::canBeAssignedTickets($catid, $assignedTo)) {
			return ToolResult::error(
				'User ' . $assignedTo . ' cannot be assigned tickets in category ' . $catid . '. ATS '
				. 'requires core.admin, core.manage or ats.assignee on com_ats.category.' . $catid
				. '. TicketTable::onBeforeCheck() asserts this (TicketTable.php:831-834) and the save '
				. 'would abort with the generic message COM_ATS_TICKETS_ERR_INVALID_ASSIGNED_TO.'
			);
		}

		// ------------------------------------------------------------- created_by
		$actorId   = (int) $actor->id;
		$createdBy = (int) ($arguments['created_by'] ?? 0);
		$isManager = \Akeeba\Component\ATS\Administrator\Helper\Permissions::isManager($catid, $actorId);
		$ownerOverrideIgnored = false;

		if ($createdBy > 0) {
			if (!$this->atsUserExists($createdBy)) {
				return ToolResult::error('created_by ' . $createdBy . ' is not a user in #__users.');
			}

			if (!$isManager) {
				// TicketModel::save() only raises TicketTable::$overrideuser when the
				// actor is a manager of the category; without it prepareTable()
				// overwrites created_by with the acting user.
				$ownerOverrideIgnored = true;
				$createdBy            = 0;
			}
		}

		$ticketOwner  = $createdBy > 0 ? $createdBy : $actorId;
		$postAuthor   = (int) ($arguments['post_created_by'] ?? 0);
		$postAuthor   = $postAuthor > 0 ? $postAuthor : $ticketOwner;

		if ($postAuthor > 0 && !$this->atsUserExists($postAuthor)) {
			return ToolResult::error('post_created_by ' . $postAuthor . ' is not a user in #__users.');
		}

		// ---------------------------------------------------------------- payload
		$data = [
			'id'     => 0,
			'catid'  => $catid,
			'title'  => $title,
			'origin' => 'web',
		];

		if ($createdBy > 0) {
			$data['created_by'] = $createdBy;
		}

		$requestedPublic = null;

		if (\array_key_exists('public', $arguments) && $arguments['public'] !== null) {
			$requestedPublic = (int) $arguments['public'] === 1 ? 1 : 0;
			$data['public']  = $requestedPublic;
		}

		if ($requestedStatus !== null) {
			$data['status'] = $requestedStatus;
		}

		$requestedPriority = null;

		if (\array_key_exists('priority', $arguments) && $arguments['priority'] !== null) {
			$requestedPriority = (int) $arguments['priority'];
			$data['priority']  = $requestedPriority;
		}

		if (!empty($arguments['alias'])) {
			$data['alias'] = (string) $arguments['alias'];
		}

		if (\array_key_exists('enabled', $arguments) && $arguments['enabled'] !== null) {
			$data['enabled'] = (int) $arguments['enabled'] === 0 ? 0 : 1;
		}

		if ($assignedTo > 0) {
			$data['assigned_to'] = $assignedTo;
		}

		if (!empty($arguments['custom_fields']) && \is_array($arguments['custom_fields'])) {
			$data['com_fields'] = $arguments['custom_fields'];
		}

		$model = $this->atsModel('Ticket');

		if ($model === null) {
			return ToolResult::error('Could not create the ATS TicketModel through com_ats\' MVCFactory.');
		}

		$sendsMail        = $assignedTo > 0 && $assignedTo !== $actorId;
		$siteUrlConfigured = $this->atsSiteUrlConfigured();

		// Wrapped because assigning from inside the save reaches Route::link('site', ...).
		// $actor is passed so ATS' ACL checks inside onBeforeCheck() see the real
		// caller rather than the guest a container-built SiteApplication defaults to.
		$saved = $this->withSiteAppContext(
			fn(): array => $this->saveAdminModel($model, $data),
			$actor
		);

		$ticketId = (int) $saved['id'];

		if ($ticketId <= 0) {
			return ToolResult::error(
				'The ticket was not created: ' . ($saved['error'] ?: 'TicketModel::save() reported no '
				. 'error and no id.') . ' Nothing was written, so retrying is safe.'
			);
		}

		// ------------------------------------------------------------ opening post
		$postData = [
			'id'           => 0,
			'ticket_id'    => $ticketId,
			'content_html' => $body,
			'enabled'      => 1,
		];

		if ($postAuthor > 0) {
			$postData['created_by'] = $postAuthor;
		}

		$postModel = $this->atsModel('Post');

		if ($postModel === null) {
			$this->atsRollbackTicket($ticketId);

			return ToolResult::error(
				'Could not create the ATS PostModel, so the opening post could not be written. The '
				. 'ticket that had already been created was deleted again, mirroring what ATS\' own '
				. 'TicketController does when the post step fails.'
			);
		}

		$postSaved = $this->saveAdminModel($postModel, $postData);
		$postId    = (int) $postSaved['id'];

		if ($postId <= 0) {
			$rolledBack = $this->atsRollbackTicket($ticketId);

			return ToolResult::error(
				'The ticket was created (id ' . $ticketId . ') but its opening post could not be '
				. 'written: ' . ($postSaved['error'] ?: 'PostModel::save() reported no error and no '
				. 'id.') . ' ' . ($rolledBack
					? 'The ticket has been deleted again, mirroring TicketController.php:191, so '
					. 'nothing is left behind and retrying is safe.'
					: 'ROLLBACK FAILED — ticket ' . $ticketId . ' still exists with no opening post. '
					. 'Delete it with delete_ats_ticket or add a reply to it before retrying.')
			);
		}

		// -------------------------------------------------------- notify explicitly
		// PostModel::save() sends nothing. The notification lives in
		// Helper\PostNotification::notify(), which PostController calls after its own
		// save — so a model-only create files a ticket nobody hears about. Wrapped
		// because sendPostEmails() builds ticket URLs with Route::link('site', ...),
		// and $actor is passed so the ACL reads inside it answer for the real caller.
		$wantsNotify = ($arguments['notify'] ?? true) !== false;
		$notified    = false;
		$notifyError = '';

		if ($wantsNotify) {
			if (!class_exists(\Akeeba\Component\ATS\Administrator\Helper\PostNotification::class)) {
				$notifyError = 'Helper\PostNotification does not exist on this install — it was '
					. 'introduced in ATS 5.6.0. On an older build the notification logic is private '
					. 'to a controller trait and cannot be reached from here at all.';
			} else {
				try {
					$this->withSiteAppContext(function () use ($postId): void {
						$post = $this->atsTable('Post');

						if ($post === null || !$post->load($postId)) {
							throw new \RuntimeException(
								'post ' . $postId . ' could not be re-loaded for notification'
							);
						}

						\Akeeba\Component\ATS\Administrator\Helper\PostNotification::notify($post, true);
					}, $actor);

					$notified = true;
				} catch (\Throwable $e) {
					$notifyError = $e->getMessage();
				}
			}
		}

		// -------------------------------------------------------------- read back
		$row = $this->atsReadTicketRow($ticketId);

		if ($row === null) {
			return ToolResult::error(
				'Ticket ' . $ticketId . ' and post ' . $postId . ' were both reported saved but the '
				. 'ticket row is not in #__ats_tickets. Do not retry blindly — inspect the table first.'
			);
		}

		$result = [
			'ok'             => true,
			'id'             => $ticketId,
			'opening_post_id' => $postId,
			'ticket'         => $row,
			'category'       => [
				'id'        => (int) $category['id'],
				'title'     => (string) $category['title'],
				'forcetype' => $forceType === '' ? null : $forceType,
			],
			'notified'       => $notified,
			'note'           => 'Every value below is read back from #__ats_tickets after the save, '
				. 'not echoed from your request. The ticket\'s `modified` / `modified_by` columns '
				. 'mean "last reply", not "last edited"; the opening post has just set them. '
				. '`timespent` is derived by ATS from the sum of the ticket\'s enabled posts and is '
				. 'recomputed on every reply.',
		];

		if ($notified) {
			$result['notification'] = 'The new-post notification was sent through '
				. 'Helper\PostNotification::notify(), the same call PostController makes after its '
				. 'own save. Without it this would have been a silent ticket: neither PostModel'
				. '::save() nor PostTable::onAfterStore() sends any mail, so a model-only create '
				. 'reaches nobody. ATS\' own "Send emails" component option still applied on top, so '
				. 'a site with mail disabled sent nothing regardless.';
		} elseif (!$wantsNotify) {
			$result['notification'] = 'Suppressed at your request (notify: false). Nobody was told '
				. 'about ticket ' . $ticketId . '. Note this is not the vendor default — filing a '
				. 'ticket through the website does notify.';
		} else {
			$result['notification'] = 'NOT SENT. The ticket and its opening post were both written '
				. 'correctly, but the notification failed: ' . $notifyError . ' Nobody has been told '
				. 'about this ticket. Do not re-create it — tell the support team by another route, '
				. 'or add a reply to trigger a fresh notification.';
		}

		$warnings = [];

		if ($requestedPublic !== null && $row['public'] !== $requestedPublic) {
			$warnings[] = 'public was requested as ' . $requestedPublic . ' but stored as '
				. $row['public'] . '. '
				. ($forceType !== ''
					? 'The category\'s forcetype param is "' . $forceType . '", and '
					. 'TicketModel::save() overwrites public from it unconditionally '
					. '(TicketModel.php:803-806).'
					: 'TicketTable::onBeforeCheck() forces public to 1 when neither the acting user '
					. 'nor the ticket owner holds ats.private on com_ats.category.' . $catid
					. ' (TicketTable.php:880-906). Being a category manager does not satisfy that '
					. 'check — it tests ats.private alone.');
		}

		if ($requestedStatus !== null && (string) $row['status'] !== $requestedStatus) {
			$warnings[] = 'status was requested as "' . $requestedStatus . '" but stored as "'
				. $row['status'] . '". Saving the opening post makes PostTable::onAfterStore() rewrite '
				. 'the ticket status — O for anyone who is not a category manager, P for a manager '
				. 'replying on someone else\'s ticket (PostTable.php:327-330). That block is skipped '
				. 'entirely when the ticket is already Closed.';
		}

		if ($requestedPriority !== null && $row['priority'] !== $requestedPriority) {
			$warnings[] = 'priority was requested as ' . $requestedPriority . ' but stored as '
				. $row['priority'] . '. A falsy priority is replaced with 1 on a private ticket and 5 '
				. 'on a public one (TicketTable.php:927-939), which is why 0 cannot be stored.';
		}

		if ($requestedPriority === null) {
			$result['priority_note'] = 'No priority was requested. ATS stored ' . $row['priority']
				. ' — its default for a ' . ($row['public'] === 1 ? 'public' : 'private') . ' ticket. '
				. 'The category default_priority param was NOT consulted: ATS applies that only while '
				. 'building the HTML form (TicketModel.php:1382-1389), never on the model save path.';
		}

		if (!empty($arguments['alias']) && (string) $row['alias'] !== (string) $arguments['alias']) {
			$warnings[] = 'alias was requested as "' . (string) $arguments['alias'] . '" but stored as "'
				. $row['alias'] . '" — ATS appends a numeric suffix when the slug is already taken.';
		}

		if ($ownerOverrideIgnored) {
			$warnings[] = 'created_by was supplied but ignored: you are not a manager of category '
				. $catid . ', and ATS only honours a created_by override for managers '
				. '(TicketModel.php:789-793). The ticket is owned by user ' . $row['created_by'] . '.';
		}

		if ($assignedTo > 0 && $row['assigned_to'] !== $assignedTo) {
			$warnings[] = 'assigned_to was requested as ' . $assignedTo . ' but stored as '
				. $row['assigned_to'] . '.';
		}

		if ($row['assigned_to'] > 0 && $assignedTo === 0) {
			$warnings[] = 'The ticket came out assigned to user ' . $row['assigned_to']
				. ' even though no assignment was requested. PostTable::onAfterStore() auto-assigns '
				. 'an unassigned ticket to the author of a post when that author is a manager of the '
				. 'category (PostTable.php:344-347).';
		}

		if ($sendsMail) {
			$result['mail'] = 'Assignment notification mail was attempted for user ' . $assignedTo
				. '. That one is fired by the table itself, from TicketTable::onChangedValues() '
				. 'inside store() (TicketTable.php:991-998) — unlike the post notification above, it '
				. 'needs no separate call. Links in it may be /api/-prefixed: the save runs as a site '
				. 'application, so ATS resolves its {siteurl} token with Uri::base() '
				. '(EmailSending.php:753-754), and Uri::base() caches its result in a static for the '
				. 'lifetime of the request (Uri.php:131), having already computed it against the '
				. '/api/ entry point. Verify the link if the notification matters.';
		}

		if (!$siteUrlConfigured) {
			$result['site_configuration_note'] = 'Unrelated to anything sent here, but worth '
				. 'flagging: the com_ats `siteurl` param is empty on this site. It was not used for '
				. 'the mail above — a site application takes the Uri::base() branch instead — but it '
				. 'is what ATS falls back to from every NON-site context: scheduled auto-close and '
				. 'auto-reply runs, the Professional CLI commands, and the mail gateway. Mail from '
				. 'those will carry broken links until the param is filled in.';
		}

		if ($warnings !== []) {
			$result['warnings'] = $warnings;
		}

		return ToolResult::json($result);
	}

	/** The ATS category row, or null when the id is not an ATS category. */
	private function atsCategory(int $catid): ?array
	{
		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName(['id', 'title', 'published', 'access', 'language', 'params']))
				->from($this->db->quoteName('#__categories'))
				->where($this->db->quoteName('id') . ' = ' . $catid)
				->where($this->db->quoteName('extension') . ' = ' . $this->db->quote('com_ats'))
		)->loadAssoc();

		return $row ?: null;
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

	/**
	 * Normalise a requested status, or null when the site does not define it.
	 * Permissions::getStatuses() is keyed 'O', 'P', the custom numeric ids, 'C'.
	 */
	private function atsNormaliseStatus(string $status): ?string
	{
		$status = trim($status);
		$upper  = strtoupper($status);

		if (\in_array($upper, ['O', 'P', 'C'], true)) {
			return $upper;
		}

		if (!ctype_digit($status)) {
			return null;
		}

		$numeric = (int) $status;

		if ($numeric < 1 || $numeric > 99) {
			return null;
		}

		// getStatuses() keys the custom entries with integer ids, and the ENUM
		// stores them as the STRINGS '1'..'99'.
		$all = \Akeeba\Component\ATS\Administrator\Helper\Permissions::getStatuses();

		return \array_key_exists($numeric, $all) ? (string) $numeric : null;
	}

	/** Mirror of TicketController.php:191 — undo the ticket when the post step fails. */
	private function atsRollbackTicket(int $ticketId): bool
	{
		try {
			$table = $this->atsLoadTicket($ticketId);

			return $table !== null && (bool) $table->delete($ticketId);
		} catch (\Throwable) {
			return false;
		}
	}

	/** @return array<string, mixed>|null */
	private function atsReadTicketRow(int $id): ?array
	{
		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName([
					'id', 'catid', 'status', 'title', 'alias', 'public', 'priority', 'origin',
					'assigned_to', 'timespent', 'created', 'created_by', 'modified', 'modified_by',
					'enabled',
				]))
				->from($this->db->quoteName('#__ats_tickets'))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();

		if (!$row) {
			return null;
		}

		foreach (['id', 'catid', 'public', 'priority', 'assigned_to', 'created_by', 'modified_by', 'enabled'] as $k) {
			$row[$k] = (int) $row[$k];
		}

		$row['timespent']    = (float) $row['timespent'];
		$row['status_label'] = $this->atsStatusLabel((string) $row['status']);

		return $row;
	}
}
