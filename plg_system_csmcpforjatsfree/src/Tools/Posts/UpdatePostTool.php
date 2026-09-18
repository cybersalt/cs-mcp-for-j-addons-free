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
 * Edit an existing post's body or logged time.
 *
 * THE EDIT GRACE TIME IS REAL AND IT IS HONOURED, NOT ROUTED AROUND
 * -----------------------------------------------------------------
 * ATS gives the author of a post a temporary right to edit it that has nothing to
 * do with their ACL. Permissions::getPostPrivileges() computes it inline
 * (Permissions.php:851-878): the window is the `editeableforxminutes` component
 * param, default 15, measured from the post's `created`, and it is ORed with
 * `ats.edit.own.post` and `core.edit` before landing in the `edit` key. Once the
 * window closes, a non-manager author with neither of those two ACL actions can no
 * longer edit their own post at all.
 *
 * This tool gates on that `edit` key and nothing else, and it reports the window
 * so a refusal is explicable rather than mysterious. There is a tempting shortcut —
 * bind and store a PostTable directly, which skips PostModel::canEditState() and
 * every ACL check with it — and we do not take it. The grace time is the vendor's
 * policy about who may rewrite history in a support thread, and a support thread
 * whose history can be rewritten indefinitely is worth less than one that cannot.
 *
 * Note that ATS computes this window in two different places that disagree.
 * Permissions::editGraceTime() (:155-172) measures from `modified` when the actor
 * was the last editor, so it can stay open forever across repeated edits; it is
 * used only by tmpl/ticket/common_post.php:57 to decide whether to draw an Edit
 * button. The inline version in getPostPrivileges() measures from `created` only
 * and is what actually decides whether a save is accepted. Both are reported;
 * `can_edit` is the one that mattered.
 *
 * AN EDIT DOES NOT RE-BILL THE TICKET
 * -----------------------------------
 * PostTable::onAfterStore() guards its entire body with `if ($this->isNewPost && …)`,
 * and onBeforeStore() sets isNewPost from `empty($this->id)`. On an edit that is
 * false, so none of it runs: changing a post's `timespent` does NOT update the
 * parent ticket's derived total. The ticket keeps the old figure until the next
 * NEW reply triggers the SUM. The response reports both numbers so the drift is
 * visible instead of silent.
 *
 * WHAT THE FORM WILL AND WILL NOT LET YOU CHANGE
 * ----------------------------------------------
 * An edit uses forms/post.xml, not post_new.xml (PostModel::getForm() picks by
 * whether an id is present, PostModel.php:57-58). That form declares `enabled` and
 * `origin` as required, so both are re-submitted from the stored row to get through
 * validation — this tool never changes them; set_ats_post_state is the tool for
 * `enabled`. `timespent`, `origin`, `created`, `created_by`, `modified` and
 * `modified_by` are all removed from the form unless the caller holds core.manage
 * on the ticket's category (PostModel.php:98-106), so a supplied timespent from a
 * non-manager is dropped by validate(); we detect that and say so.
 */
final class UpdatePostTool extends AbstractTool
{
	use ATSBootTrait;

	public function getName(): string { return 'update_ats_post'; }

	public function getDescription(): string
	{
		return 'Edit an existing Akeeba Ticket System post: its body (content_html) and/or its timespent. '
			. 'REQUIRED: id, plus at least one of content_html or timespent. '
			. 'PERMISSION — READ THIS BEFORE CALLING: ATS decides editability in '
			. 'Permissions::getPostPrivileges(). A category manager may always edit. Anyone else may edit their '
			. 'OWN post only while it is inside the edit grace window — the `editeableforxminutes` component '
			. 'option, 15 minutes by default, measured from when the post was CREATED, not from the last edit — '
			. 'or if they separately hold the ats.edit.own.post or core.edit action. When the window closes, the '
			. 'right goes away. And for a NON-manager, a ticket with status C removes every privilege including '
			. 'edit, so a closed ticket freezes the thread. This tool honours all of that and refuses rather than '
			. 'writing the row directly; call get_ats_post first and read privileges.edit and edit_grace if you '
			. 'want to know in advance. '
			. 'WHAT AN EDIT DOES NOT DO: it does NOT recompute the parent ticket\'s timespent. That total is a SUM '
			. 'over published posts which ATS only refreshes when a NEW reply is saved, so after changing a post\'s '
			. 'timespent the ticket total is stale until somebody replies. The response returns both '
			. 'ticket_timespent_stored and ticket_timespent_if_recomputed so you can see the gap. An edit also '
			. 'does not change the ticket\'s status or its last-reply timestamp. It DOES set the post\'s modified '
			. 'and modified_by to you and now. '
			. 'EMAIL: ATS\' own UI sends "post edited" notifications to the category managers and the ticket owner '
			. 'after an edit. This tool does NOT do that by default, because re-emailing a customer over a '
			. 'corrected typo is not recoverable; pass send_notifications: true to match the component\'s '
			. 'behaviour. When you do, links in the mail are built from the component\'s `siteurl` param. '
			. 'Returns the updated post, the permission reasoning, and any warnings.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => ['type' => 'integer', 'description' => 'The #__ats_posts id to edit.'],
				'content_html' => [
					'type'        => 'string',
					'description' => 'Replacement body, as HTML. Passed through the component\'s configured text filter (HTML Purifier by default). Omit to leave the body alone.',
				],
				'timespent' => [
					'type'        => 'number',
					'description' => 'Replacement time value for this post. Only accepted from a category manager — ATS removes the field from the edit form for everyone else and validate() then drops the value. Changing it does NOT refresh the ticket total until the next new reply.',
				],
				'send_notifications' => [
					'type'        => 'boolean',
					'description' => 'Default FALSE, which is a deliberate divergence from ATS\' own UI. True fires PostNotification::notify($post, false), sending the "post edited" templates to the category managers and the ticket owner.',
				],
			],
			'required'             => ['id'],
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

		$hasContent   = \array_key_exists('content_html', $arguments) && $arguments['content_html'] !== null;
		$hasTimespent = \array_key_exists('timespent', $arguments) && $arguments['timespent'] !== null;

		if (!$hasContent && !$hasTimespent) {
			throw new \InvalidArgumentException(
				'Nothing to do: supply content_html, timespent, or both. Use set_ats_post_state to publish or '
				. 'unpublish a post, and delete_ats_post to remove one.'
			);
		}

		$post = $this->atsTable('Post');

		if ($post === null || !$post->load($id)) {
			return ToolResult::error('No post with id ' . $id . ' exists in #__ats_posts.');
		}

		$ticketId = (int) $post->ticket_id;
		$ticket   = $this->atsLoadTicket($ticketId);

		if ($ticket === null) {
			return ToolResult::error(
				'Post ' . $id . ' is orphaned — its parent ticket (' . $ticketId . ') no longer exists. Every post '
				. 'privilege in ATS is derived from the ticket\'s category, so there is no basis on which to '
				. 'authorise an edit.'
			);
		}

		if (!$this->atsCanViewTicket($ticket, $actor)) {
			return ToolResult::error('No post with id ' . $id . ' is visible to you.');
		}

		$privileges = Permissions::getPostPrivileges($post, $actor);
		$isManager  = Permissions::isManager((int) $ticket->catid, (int) $actor->id);
		$grace      = $this->graceReport($post, $actor);

		if (empty($privileges['edit'])) {
			return ToolResult::error($this->explainRefusal($post, $ticket, $actor, $isManager, $grace));
		}

		$timespent = $hasTimespent ? (float) $arguments['timespent'] : null;

		if ($timespent !== null && $timespent < 0) {
			throw new \InvalidArgumentException('timespent cannot be negative.');
		}

		$notify          = ($arguments['send_notifications'] ?? false) === true;
		$timespentBefore = (float) $post->timespent;
		$contentBefore   = (string) $post->content_html;
		$ticketTimespent = (float) $ticket->timespent;

		// forms/post.xml declares content_html, enabled and origin as required, so all
		// three are re-submitted from the stored row purely to satisfy validation — a
		// timespent-only edit cannot simply omit the body. That means the stored body
		// makes a round trip through the component's text filter, exactly as it does
		// every time somebody saves the edit form in ATS' own UI. HTML Purifier is
		// idempotent over already-purified markup, so this is normally a no-op; it is
		// compared afterwards and reported if it was not.
		// AdminModel::save() loads-then-binds, so everything not listed here survives.
		$data = [
			'id'           => $id,
			'ticket_id'    => $ticketId,
			'content_html' => $hasContent ? (string) $arguments['content_html'] : (string) $post->content_html,
			'enabled'      => (int) $post->enabled,
			'origin'       => (string) ($post->origin ?: 'web'),
		];

		if ($timespent !== null) {
			$data['timespent'] = $timespent;
		}

		// $actor is passed into the wrapper deliberately. A SiteApplication out of the
		// DI container has NO identity loaded and reads as a guest, and
		// PostModel::prepareTable() dereferences that identity unconditionally on an
		// edit (PostModel.php:305-311) — a guest would either fatal on null or stamp
		// modified_by with 0. Every ACL decision inside ATS runs through
		// Permissions::getUser(), which reads the same identity (Permissions.php:1009).
		$outcome = $this->withSiteAppContext(
			function () use ($data, $notify, $timespent, $id): array {
				$model = $this->atsModel('Post');

				if ($model === null) {
					return ['error' => 'Could not instantiate ATS\' PostModel through the component MVCFactory.'];
				}

				$form = $model->getForm($data, false);

				if ($form === false || $form === null) {
					return ['error' => 'ATS could not build its com_ats.post form, so nothing was changed.'];
				}

				$timespentAccepted = $form->getField('timespent') !== false;

				$valid = $model->validate($form, $data);

				// RESTORE THE PRIMARY KEY. forms/post.xml declares no `id` field, so
				// Form::filter() inside parent::validate() strips it out — validate()
				// returns the filtered data, and filtered data only ever contains keys
				// the form knows about. AdminModel::save() then calls $table->load(null),
				// which Joomla treats as "no record requested" and returns true from
				// without loading, so the bind lands on an empty row and store() INSERTs.
				//
				// The symptom is the worst kind: the edit reports success, the original
				// post is untouched, and a DUPLICATE post appears on the ticket. Caught
				// on a live install — two "edits" produced posts 7 and 8 while post 3
				// kept its original body.
				//
				// ATS knows about this hazard and works around it one line at a time:
				// PostModel::validate() reads $data['id'] to set post.isNew BEFORE
				// calling parent, then re-injects created_by afterwards for exactly the
				// same reason (post_new.xml has no created_by field). We do the same for
				// the key itself.
				if (\is_array($valid)) {
					$valid['id'] = $id;
				}

				if ($valid === false) {
					$errors = method_exists($model, 'getErrors') ? ($model->getErrors() ?: []) : [];
					$errors = array_map(
						static fn($e): string => $e instanceof \Throwable ? $e->getMessage() : (string) $e,
						$errors
					);

					return [
						'error' => 'ATS rejected the edit during validation: '
							. (implode(' | ', $errors) ?: 'no reason given by the component.'),
					];
				}

				if (!\array_key_exists('content_html', $valid) || trim((string) $valid['content_html']) === '') {
					return [
						'error' => 'The post body did not survive ATS\' own filtering, so the edit was abandoned '
							. 'rather than blanking the post.',
					];
				}

				if ($timespent !== null && !$timespentAccepted) {
					// Put it back so the row is not silently rewritten with 0 — although
					// validate() has already dropped it, so this is belt and braces.
					unset($valid['timespent']);
				}

				$save = $this->saveAdminModel($model, $valid);

				if ($save['id'] <= 0) {
					return ['error' => 'The edit was not saved: ' . ($save['error'] ?: 'ATS gave no reason.')];
				}

				// An edit that comes back under a DIFFERENT id did not edit anything — it
				// inserted. Never report that as success: the caller would be told their
				// correction landed while the original text stands and the ticket has
				// gained a duplicate reply. Say what happened and name the stray row so
				// it can be removed.
				if ((int) $save['id'] !== $id) {
					return [
						'error' => 'ATS inserted a NEW post (id ' . (int) $save['id'] . ') instead of editing post '
							. $id . ', so post ' . $id . ' still holds its original text and the ticket now '
							. 'carries a duplicate reply. This happens when the primary key does not survive '
							. 'form filtering. Delete post ' . (int) $save['id'] . ' with delete_ats_post, then '
							. 'report this — the tool is supposed to prevent it.',
					];
				}

				$notifyError = null;
				$notifySent  = false;

				if ($notify) {
					if (!class_exists(PostNotification::class)) {
						$notifyError = 'This ATS install predates 5.6.0 and has no reachable notification helper, '
							. 'so no "post edited" emails were sent.';
					} else {
						try {
							$saved = $model->getItem($save['id']);

							if ($saved === false || $saved === null) {
								$notifyError = 'The edit saved but the post could not be re-read, so no emails went out.';
							} else {
								PostNotification::notify($saved, false);
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
			$actor
		);

		if (isset($outcome['error'])) {
			return ToolResult::error($outcome['error']);
		}

		$after = $this->atsTable('Post');
		$after = ($after !== null && $after->load($id)) ? $after : null;

		$warnings = [];

		if ($timespent !== null && ($outcome['timespent_accepted'] ?? true) === false) {
			$warnings[] = 'The timespent value was DROPPED: ATS removes that field from the edit form unless you '
				. 'hold core.manage on the ticket\'s category, or when the timespent_hide component param is on.';
		}

		if (!$hasContent && $after !== null && (string) $after->content_html !== $contentBefore) {
			$warnings[] = 'You did not ask to change the body, but the stored body is not byte-identical to what it '
				. 'was. ATS\' edit form declares content_html as required, so the existing body has to be '
				. 're-submitted and it passes back through the component\'s text filter on the way — the same round '
				. 'trip the component\'s own UI performs on every edit. The filter has evidently altered something '
				. 'it let through the first time, which usually means the filter configuration changed since the '
				. 'post was written. Compare post.content_html against what you expected.';
		}

		$recomputed = $this->publishedTimespentSum($ticketId);

		if (abs($recomputed - $ticketTimespent) > 0.0001) {
			$warnings[] = 'The parent ticket\'s stored timespent (' . $ticketTimespent . ') no longer matches the '
				. 'SUM over its published posts (' . $recomputed . '). That is expected: ATS only refreshes the '
				. 'ticket total inside PostTable::onAfterStore(), and that block runs for NEW posts only. The '
				. 'ticket will catch up the next time somebody replies to it.';
		}

		if (!empty($outcome['notify_error'])) {
			$warnings[] = 'The edit was saved but the notifications failed: ' . $outcome['notify_error'];
		}

		if ($notify && !$this->atsSiteUrlConfigured()) {
			$warnings[] = 'The com_ats `siteurl` param is empty, so the ticket links in the edit notification '
				. 'emails are broken.';
		}

		if (($outcome['save_reported_ok'] ?? true) === false) {
			$warnings[] = 'ATS\' save() returned false although the row was written. A post-save plugin most '
				. 'likely threw. Do not retry. ' . ($outcome['save_error'] ?: '');
		}

		return ToolResult::json([
			'ok'   => true,
			'post' => [
				'id'               => $id,
				'ticket_id'        => $ticketId,
				'content_html'     => $after === null ? null : (string) $after->content_html,
				'timespent_before' => $timespentBefore,
				'timespent_after'  => $after === null ? $timespentBefore : (float) $after->timespent,
				'enabled'          => $after === null ? (int) $post->enabled : (int) $after->enabled,
				'created'          => $after === null ? $post->created : $after->created,
				'created_by'       => $after === null ? (int) $post->created_by : (int) $after->created_by,
				'modified'         => $after === null ? null : $after->modified,
				'modified_by'      => $after === null ? null : (int) $after->modified_by,
			],
			'ticket' => [
				'id'                          => $ticketId,
				'title'                       => (string) $ticket->title,
				'status'                      => (string) $ticket->status,
				'status_label'                => $this->atsStatusLabel((string) $ticket->status),
				'timespent_stored'            => $ticketTimespent,
				'timespent_if_recomputed'     => $recomputed,
				'status_unchanged_by_an_edit' => true,
			],
			'permission' => [
				'can_edit'   => true,
				'is_manager' => $isManager,
				'is_author'  => (int) $post->created_by === (int) $actor->id,
				'edit_grace' => $grace,
			],
			'notifications_sent'  => (bool) ($outcome['notify_sent'] ?? false),
			'site_url_configured' => $this->atsSiteUrlConfigured(),
			'warnings'            => $warnings,
			'note'                => 'Editing a post never touches the ticket: no status change, no last-reply '
				. 'timestamp, and no recalculation of the ticket\'s billed time — that SUM is only refreshed when a '
				. 'NEW post is saved. The post\'s own modified / modified_by were set to you and now.',
		]);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function graceReport(object $post, User $actor): array
	{
		$minutes     = max((int) $this->atsParams()->get('editeableforxminutes', 15), 0);
		$createdUnix = $post->created ? strtotime((string) $post->created . ' UTC') : false;
		$elapsed     = $createdUnix === false ? null : max(0, time() - $createdUnix);

		try {
			$helper = Permissions::editGraceTime($post, $actor);
		} catch (\Throwable) {
			$helper = false;
		}

		return [
			'editeableforxminutes'     => $minutes,
			'seconds_since_created'    => $elapsed,
			'seconds_remaining'        => ($minutes > 0 && $elapsed !== null)
				? max(0, ($minutes * 60) - $elapsed)
				: 0,
			'window_open_from_created' => $minutes > 0 && $elapsed !== null && $elapsed < ($minutes * 60),
			'edit_grace_time_helper'   => $helper,
		];
	}

	/**
	 * @param array<string, mixed> $grace
	 */
	private function explainRefusal(object $post, object $ticket, User $actor, bool $isManager, array $grace): string
	{
		$message = 'ATS will not let you edit post ' . (int) $post->id . '. ';

		if (!$isManager && (string) $ticket->status === 'C') {
			return $message
				. 'Its ticket is CLOSED, and Permissions::getTicketPrivileges() / getPostPrivileges() collapse every '
				. 'privilege except "view" to false on a closed ticket for anyone who is not a manager of its '
				. 'category. A closed thread is frozen. Reopening the ticket restores the ordinary rules.';
		}

		if ((int) $post->created_by !== (int) $actor->id) {
			return $message
				. 'You are not its author and you hold neither core.edit nor manager rights on the ticket\'s '
				. 'category. ATS only ever extends editing to the person who wrote the post.';
		}

		return $message
			. 'You wrote it, but the author\'s editing window has closed. ATS gives an author '
			. $grace['editeableforxminutes'] . ' minute(s) from the moment the post was CREATED — the '
			. '`editeableforxminutes` option in Components → Akeeba Ticket System → Options — and this post was '
			. 'created ' . ($grace['seconds_since_created'] ?? '?') . ' second(s) ago. After that, editing needs the '
			. 'ats.edit.own.post or core.edit action, or manager rights on the category. Adding a new reply with '
			. 'add_ats_post is the supported way to correct the record once the window has closed.';
	}

	private function publishedTimespentSum(int $ticketId): float
	{
		$db = $this->db;

		// Deliberately the same shape as PostTable::onAfterStore()'s rollup, including
		// the enabled = '1' predicate, so the comparison is like for like.
		$query = $db->getQuery(true)
			->select('SUM(' . $db->quoteName('timespent') . ')')
			->from($db->quoteName('#__ats_posts'))
			->where($db->quoteName('ticket_id') . ' = ' . $ticketId)
			->where($db->quoteName('enabled') . ' = ' . $db->quote('1'));

		return (float) ($db->setQuery($query)->loadResult() ?: 0.0);
	}
}
