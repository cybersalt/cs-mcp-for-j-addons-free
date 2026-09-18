<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Posts;

\defined('_JEXEC') or die;

use Akeeba\Component\ATS\Administrator\Helper\Permissions;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;

/**
 * Fetch one post in full, with the parent ticket for context and the actor's
 * post-level privileges.
 *
 * THE PRIVILEGE ARRAY IS THE POINT
 * --------------------------------
 * Permissions::getPostPrivileges() is what every write tool in this folder gates
 * on, and its answers are not guessable from the row. Three behaviours that the
 * caller cannot infer otherwise, all in Permissions.php:
 *
 *   - A manager gets every key set to true unconditionally (:828-838), except
 *     `attachment`, which is forced back to false on a Core install.
 *   - For a non-manager, a CLOSED ticket collapses every key to false (:841-844).
 *     Not just `post` — `edit`, `edit.state` and `delete` too. So "I own this post
 *     and I just wrote it" stops mattering the moment the ticket closes.
 *   - The post owner gets a time-limited `edit` on top of their ACL (:855-877).
 *     The window is the `editeableforxminutes` component param, default 15, and it
 *     is measured from the post's `created` — never from `modified`. Note that the
 *     separate Permissions::editGraceTime() helper (:155-172) measures the same
 *     window differently, from `modified` when the actor was the last editor, and
 *     is used only by the front-end template to decide whether to draw an Edit
 *     button (tmpl/ticket/common_post.php:57). Both are reported here, because
 *     they genuinely disagree and `edit` is the one that decides a save.
 *
 * WHY THE TICKET IS LOADED SEPARATELY RATHER THAN VIA getTicket()
 * ---------------------------------------------------------------
 * PostTable::getTicket() throws a RuntimeException when the parent row is missing
 * (PostTable.php:180-183) and getPostPrivileges() swallows that into a blank
 * TicketTable (:797-801), which would silently answer "no privileges on category 0"
 * rather than "this post is orphaned". Loading the ticket ourselves lets us say
 * which of those it is.
 */
final class GetPostTool extends AbstractTool
{
	use ATSBootTrait;

	public function getName(): string { return 'get_ats_post'; }

	public function getDescription(): string
	{
		return 'Get one Akeeba Ticket System post (a ticket reply, or a ticket\'s opening message) by id, '
			. 'in full and untruncated. Returns the post (id, ticket_id, content_html, origin, timespent, '
			. 'email_uid, attachment_id, created, created_by, created_by_name, is_system_post, modified, '
			. 'modified_by, enabled), a `ticket` block with the parent\'s id, title, status, status_label, '
			. 'catid, public, enabled, assigned_to and derived timespent for context, and a `privileges` '
			. 'block holding ATS\' own Permissions::getPostPrivileges() answer for the calling user: '
			. 'delete, edit, edit.state, admin, attachment, attachment.edit.state, attachment.delete, and '
			. '(for the post owner) close. Use `privileges` to decide whether update_ats_post, '
			. 'set_ats_post_state or delete_ats_post will be accepted before you call them. '
			. 'THREE THINGS THE ROW ITSELF WILL NOT TELL YOU: (1) a category manager gets every privilege '
			. 'set to true regardless of ownership; (2) for a NON-manager, a closed ticket collapses every '
			. 'privilege to false — including edit and delete on their own post; (3) the post owner gets a '
			. 'time-limited edit right that expires `editeableforxminutes` minutes (default 15) after the '
			. 'post was created — edit_grace is reported with the window, the elapsed time and whether it is '
			. 'still open. ATTACHMENTS ARE NOT RESOLVED: attachment_id is a raw comma-separated list of ids, '
			. 'not a foreign key, and attachments are an ATS Professional feature — getAttachments() returns '
			. 'an empty array on a Core install. Access requires the "view" privilege on the parent ticket; '
			. 'a post you may not see is reported as not found.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'      => ['type' => 'integer', 'description' => 'The #__ats_posts id.'],
				'post_id' => ['type' => 'integer', 'description' => 'Alias for id.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->atsBoot()) {
			return $this->notInstalledError();
		}

		$id = (int) ($arguments['id'] ?? $arguments['post_id'] ?? 0);

		if ($id <= 0) {
			throw new \InvalidArgumentException('id is required and must be a positive integer.');
		}

		$post = $this->atsTable('Post');

		if ($post === null || !$post->load($id)) {
			return ToolResult::error('No post with id ' . $id . ' exists in #__ats_posts.');
		}

		$ticketId = (int) $post->ticket_id;
		$ticket   = $this->atsLoadTicket($ticketId);

		if ($ticket === null) {
			return ToolResult::error(
				'Post ' . $id . ' exists but its parent ticket (' . $ticketId . ') does not. This post is '
				. 'orphaned: ATS derives every post privilege from the ticket\'s category, so there is no '
				. 'basis on which to decide whether you may read it. Deleting a ticket is supposed to delete '
				. 'its posts (TicketTable::onAfterDelete), so an orphan means something wrote or removed rows '
				. 'outside the component.'
			);
		}

		$ticketPrivileges = $this->atsTicketPrivileges($ticket, $actor);

		if (empty($ticketPrivileges['view'])) {
			return ToolResult::error(
				'No post with id ' . $id . ' is visible to you. (It may exist on a ticket you cannot view — '
				. 'posts inherit every privilege from their parent ticket.)'
			);
		}

		$privileges = Permissions::getPostPrivileges($post, $actor);

		$createdBy = (int) $post->created_by;
		$status    = (string) $ticket->status;

		return ToolResult::json([
			'ok'   => true,
			'post' => [
				'id'                  => (int) $post->id,
				'ticket_id'           => $ticketId,
				'content_html'        => (string) $post->content_html,
				'content_length'      => \strlen((string) $post->content_html),
				'origin'              => $post->origin === null ? null : (string) $post->origin,
				'timespent'           => (float) $post->timespent,
				'email_uid'           => $post->email_uid === null ? null : (string) $post->email_uid,
				'attachment_id'       => (string) $post->attachment_id,
				'created'             => $post->created,
				'created_by'          => $createdBy,
				'created_by_name'     => $this->authorName($createdBy),
				'is_system_post'      => $createdBy <= 0,
				'is_opening_post'     => $this->isOpeningPost($ticketId, (int) $post->id),
				'modified'            => $post->modified,
				'modified_by'         => (int) $post->modified_by,
				'enabled'             => (int) $post->enabled,
			],
			'ticket' => [
				'id'              => (int) $ticket->id,
				'title'           => (string) $ticket->title,
				'status'          => $status,
				'status_label'    => $this->atsStatusLabel($status),
				'catid'           => (int) $ticket->catid,
				'public'          => (int) $ticket->public,
				'enabled'         => (int) $ticket->enabled,
				'assigned_to'     => (int) $ticket->assigned_to,
				'created_by'      => (int) $ticket->created_by,
				'timespent_total' => (float) $ticket->timespent,
				'last_reply'      => $ticket->modified,
			],
			'privileges'        => array_map(static fn($v): bool => (bool) $v, $privileges),
			'ticket_privileges' => array_map(static fn($v): bool => (bool) $v, $ticketPrivileges),
			'actor'             => ['id' => (int) $actor->id, 'username' => (string) $actor->username],
			'is_manager'        => Permissions::isManager((int) $ticket->catid, (int) $actor->id),
			'edit_grace'        => $this->editGrace($post, $actor),
			'note'              => 'privileges comes from ATS\' own Permissions::getPostPrivileges(); trust it over any '
				. 'reasoning about ownership. A category manager gets every key true. A NON-manager gets every key '
				. 'false — edit and delete included — as soon as the ticket status is C. attachment is forced false '
				. 'on this Core install, and attachment_id is a raw comma-separated list that is deliberately not '
				. 'resolved. The ticket\'s timespent_total is a derived SUM over published posts, recomputed only '
				. 'when a new reply is saved, so it can lag the individual post values you see here.',
		]);
	}

	/**
	 * Report the owner's editing window both ways, because ATS computes it twice
	 * and the two results can differ. See the class docblock.
	 *
	 * @return array<string, mixed>
	 */
	private function editGrace(object $post, User $actor): array
	{
		$minutes = max((int) $this->atsParams()->get('editeableforxminutes', 15), 0);

		$createdUnix = $post->created ? strtotime((string) $post->created . ' UTC') : false;
		$elapsed     = $createdUnix === false ? null : max(0, time() - $createdUnix);

		try {
			$helperSaysOpen = Permissions::editGraceTime($post, $actor);
		} catch (\Throwable) {
			$helperSaysOpen = false;
		}

		return [
			'editeableforxminutes'      => $minutes,
			'seconds_since_created'     => $elapsed,
			'window_open_from_created'  => $minutes > 0 && $elapsed !== null && $elapsed < ($minutes * 60),
			'edit_grace_time_helper'    => $helperSaysOpen,
			'explanation'               => 'getPostPrivileges() measures the window from the post\'s `created` '
				. '(Permissions.php:855-870) and only grants it to the post\'s own author; the separate '
				. 'editGraceTime() helper measures from `modified` when you were the last editor '
				. '(Permissions.php:163-168) and is used only to decide whether the front end draws an Edit '
				. 'button. The `edit` key in privileges is the one that decides whether a save is accepted; '
				. 'it is also true outright if you hold ats.edit.own.post or core.edit, or are a manager.',
		];
	}

	private function isOpeningPost(int $ticketId, int $postId): bool
	{
		$db = $this->db;

		$query = $db->getQuery(true)
			->select($db->quoteName('id'))
			->from($db->quoteName('#__ats_posts'))
			->where($db->quoteName('ticket_id') . ' = ' . $ticketId)
			->order($db->quoteName('created') . ' ASC')
			->order($db->quoteName('id') . ' ASC');

		$db->setQuery($query, 0, 1);

		return (int) $db->loadResult() === $postId;
	}

	private function authorName(int $createdBy): string
	{
		if ($createdBy <= 0) {
			return $createdBy === -1
				? 'system (automated post)'
				: 'system / unattributed (created_by ' . $createdBy . ')';
		}

		$user = Permissions::getUser($createdBy);

		return ($user !== null && (int) $user->id === $createdBy)
			? (string) $user->name
			: 'deleted user (id ' . $createdBy . ')';
	}
}
