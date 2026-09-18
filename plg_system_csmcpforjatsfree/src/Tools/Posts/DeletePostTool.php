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
 * Permanently delete a post.
 *
 * WHAT GOES WITH IT, AND WHAT CONSPICUOUSLY DOES NOT
 * ---------------------------------------------------
 * PostTable::onAfterDelete() (PostTable.php:229-260) does exactly two things, and
 * both of them are inside `if (defined('ATS_PRO') && ATS_PRO)`: it deletes every
 * row in #__ats_attachments whose post_id matches — going through AttachmentTable
 * so the files on disk go too — and it fires the onAtsPostDelete plugin event. On a
 * Core install neither happens, because neither can: attachments are a Professional
 * feature and the table holds no rows.
 *
 * What it does NOT do is the interesting part. There is no recalculation of the
 * parent ticket's `timespent`. That total is rebuilt only in
 * PostTable::onAfterStore(), and only when a NEW post is saved (PostTable.php:291),
 * so a deleted post's logged time stays in the ticket's billed figure until the
 * next reply arrives. The ticket's status and its `modified` / `modified_by` — which
 * in ATS mean "last reply", not "last edited" — are likewise untouched, so deleting
 * the most recent reply leaves the ticket claiming a reply that is no longer there.
 *
 * DELETING THE OPENING POST IS THE DANGEROUS CASE
 * -----------------------------------------------
 * A ticket's opening message is a post row, not a column on the ticket. Delete it
 * and the ticket keeps its title and its status but loses the question it was
 * raised to answer, with nothing in the UI to indicate that anything is missing.
 * EmailSending::sendPostEmails() also identifies a brand-new ticket by comparing
 * against the ticket's first post (EmailSending.php:178), so the next reply to such
 * a ticket is classified differently from how it would otherwise have been. Both
 * conditions are reported before the deletion, and deleting the last remaining post
 * leaves a ticket with no posts at all — the shape check_ats_health looks for.
 */
final class DeletePostTool extends AbstractTool
{
	use ATSBootTrait;

	public function getName(): string { return 'delete_ats_post'; }

	public function getDescription(): string
	{
		return 'Permanently delete an Akeeba Ticket System post. There is no trash and no undo — ATS deletes the '
			. 'row outright. REQUIRES confirm: true; called without it, the tool deletes nothing and instead '
			. 'returns the full blast radius so you can decide. Consider set_ats_post_state with enabled: 0 first: '
			. 'unpublishing hides the reply from the thread and drops it out of the ticket\'s billed time while '
			. 'keeping the record, which is what you usually want for a mistaken or inappropriate post. '
			. 'WHAT ELSE GOES: on an ATS Professional install, every attachment on the post — the '
			. '#__ats_attachments rows AND the files on disk — plus the onAtsPostDelete plugin event, which is how '
			. 'any webhook integration hears about it. On this free Core edition neither applies: attachments do '
			. 'not exist there and the table holds no rows. '
			. 'WHAT DOES NOT HAPPEN, AND IS THE REASON TO BE CAREFUL: the parent ticket is not updated in any way. '
			. 'Its timespent still includes the deleted post\'s logged time and stays wrong until the next NEW '
			. 'reply triggers the recalculation; its status is unchanged; and its modified / modified_by — which in '
			. 'ATS mean "last reply" — still point at the reply you just removed. '
			. 'THE OPENING POST: a ticket\'s original message IS a post, so deleting the oldest post on a ticket '
			. 'destroys the customer\'s question while leaving the ticket looking intact. The response flags '
			. 'is_opening_post and is_only_post before you confirm. '
			. 'PERMISSION: requires the delete privilege on the post — core.delete on the ticket\'s category, or '
			. 'manager rights. For a NON-manager, a closed ticket removes every privilege including this one.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'      => ['type' => 'integer', 'description' => 'The #__ats_posts id to delete.'],
				'confirm' => [
					'type'        => 'boolean',
					'description' => 'Must be true to actually delete. Call once without it to see what the deletion would take with it.',
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

		$post = $this->atsTable('Post');

		if ($post === null || !$post->load($id)) {
			return ToolResult::error('No post with id ' . $id . ' exists in #__ats_posts.');
		}

		$ticketId = (int) $post->ticket_id;
		$ticket   = $this->atsLoadTicket($ticketId);

		if ($ticket === null) {
			return ToolResult::error(
				'Post ' . $id . ' is orphaned — its parent ticket (' . $ticketId . ') no longer exists, so there is '
				. 'no category from which to derive a delete privilege. Deleting a ticket is supposed to delete its '
				. 'posts (TicketTable::onAfterDelete), so an orphan means rows were written or removed outside the '
				. 'component.'
			);
		}

		if (!$this->atsCanViewTicket($ticket, $actor)) {
			return ToolResult::error('No post with id ' . $id . ' is visible to you.');
		}

		$privileges = Permissions::getPostPrivileges($post, $actor);
		$isManager  = Permissions::isManager((int) $ticket->catid, (int) $actor->id);

		if (empty($privileges['delete'])) {
			return ToolResult::error(
				'You do not hold the "delete" privilege on post ' . $id . '.'
				. (!$isManager && (string) $ticket->status === 'C'
					? ' Its ticket is CLOSED, and Permissions::getPostPrivileges() collapses every privilege to '
					. 'false on a closed ticket for anyone who is not a manager of its category.'
					: ' That privilege is the core.delete action on the ticket\'s category; managers hold it '
					. 'automatically.')
			);
		}

		$blast = $this->blastRadius($post, $ticket);

		if (($arguments['confirm'] ?? false) !== true) {
			return ToolResult::json([
				'ok'      => true,
				'deleted' => false,
				'confirm_required' => true,
				'would_delete'     => $blast,
				'note'             => 'Nothing was deleted. Call again with confirm: true to proceed. '
					. 'Deleting a post is permanent — ATS has no trash for posts. If you only want the reply out of '
					. 'the thread, set_ats_post_state with enabled: 0 keeps the record and still removes its time '
					. 'from the ticket total. Note that the parent ticket is NOT updated by a deletion: its '
					. 'timespent keeps counting this post until the next new reply recomputes the SUM, and its '
					. 'status and last-reply timestamp stay exactly as they are.',
			]);
		}

		$model = $this->atsModel('Post');

		if ($model === null) {
			return ToolResult::error('Could not instantiate ATS\' PostModel through the component MVCFactory.');
		}

		// AdminModel::delete() takes $pks by reference, re-runs PostModel::canDelete()
		// per id and fires the onContentBeforeDelete / onContentAfterDelete chain.
		$pks     = [$id];
		$deleted = (bool) $model->delete($pks);

		$check = $this->atsTable('Post');
		$gone  = $check === null || !$check->load($id);

		if (!$gone) {
			$errors = method_exists($model, 'getErrors') ? ($model->getErrors() ?: []) : [];
			$errors = array_map(
				static fn($e): string => $e instanceof \Throwable ? $e->getMessage() : (string) $e,
				$errors
			);

			return ToolResult::error(
				'Post ' . $id . ' was NOT deleted: ' . (implode(' | ', $errors) ?: 'ATS gave no reason.')
			);
		}

		$recomputed      = $this->publishedTimespentSum($ticketId);
		$timespentStored = (float) $ticket->timespent;
		$remaining       = $this->postCount($ticketId);

		$warnings = [];

		if (abs($recomputed - $timespentStored) > 0.0001) {
			$warnings[] = 'The ticket\'s stored timespent is still ' . $timespentStored . ' but the SUM over its '
				. 'remaining published posts is ' . $recomputed . '. Deleting a post does not recompute that total '
				. '— only saving a NEW post to the ticket does — so the ticket is over-billed until somebody '
				. 'replies to it.';
		}

		if ($blast['is_opening_post']) {
			$warnings[] = 'That was the ticket\'s OPENING post: the customer\'s original question is gone, while the '
				. 'ticket itself still shows its title and status as if nothing were missing.';
		}

		if ($remaining === 0) {
			$warnings[] = 'Ticket ' . $ticketId . ' now has NO posts at all. ATS has no UI for that state — the '
				. 'ticket will render as an empty thread.';
		}

		if (!$deleted) {
			$warnings[] = 'ATS\' delete() returned false even though the row is gone, which normally means a '
				. 'plugin in the delete chain threw afterwards. Do not retry.';
		}

		return ToolResult::json([
			'ok'      => true,
			'deleted' => true,
			'post'    => $blast,
			'ticket'  => [
				'id'                      => $ticketId,
				'title'                   => (string) $ticket->title,
				'status'                  => (string) $ticket->status,
				'status_label'            => $this->atsStatusLabel((string) $ticket->status),
				'remaining_posts'         => $remaining,
				'timespent_stored'        => $timespentStored,
				'timespent_if_recomputed' => $recomputed,
				'status_unchanged'        => true,
				'last_reply_unchanged'    => true,
			],
			'warnings' => $warnings,
			'note'     => 'The post row is gone permanently. On ATS Professional its attachments (rows and files) '
				. 'went with it and the onAtsPostDelete plugin event fired; on this Core edition neither applies. '
				. 'The parent ticket was not touched: its status, its last-reply timestamp and its billed time all '
				. 'still read as they did before, and the billed time will only correct itself when the next new '
				. 'reply triggers the recalculation.',
		]);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function blastRadius(object $post, object $ticket): array
	{
		$ticketId      = (int) $post->ticket_id;
		$attachmentIds = array_values(array_filter(
			array_map('trim', explode(',', (string) $post->attachment_id)),
			static fn(string $v): bool => $v !== '' && $v !== '0'
		));

		$total = $this->postCount($ticketId);

		return [
			'id'                    => (int) $post->id,
			'ticket_id'             => $ticketId,
			'ticket_title'          => (string) $ticket->title,
			'created'               => $post->created,
			'created_by'            => (int) $post->created_by,
			'is_system_post'        => (int) $post->created_by <= 0,
			'enabled'               => (int) $post->enabled,
			'timespent'             => (float) $post->timespent,
			'content_length'        => \strlen((string) $post->content_html),
			'content_preview'       => mb_substr(trim(strip_tags((string) $post->content_html)), 0, 240),
			'is_opening_post'       => $this->isOpeningPost($ticketId, (int) $post->id),
			'is_only_post'          => $total === 1,
			'posts_on_ticket'       => $total,
			'declared_attachments'  => \count($attachmentIds),
			'attachment_ids'        => $attachmentIds,
			'attachments_deleted'   => $this->atsIsPro()
				? 'Their #__ats_attachments rows and the files on disk will be deleted with the post.'
				: 'None. Attachments are an ATS Professional feature; this is the Core edition, where '
					. '#__ats_attachments holds no rows. Any ids listed above are leftovers from a former Pro '
					. 'install and will simply be forgotten.',
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

	private function postCount(int $ticketId): int
	{
		$db = $this->db;

		$query = $db->getQuery(true)
			->select('COUNT(*)')
			->from($db->quoteName('#__ats_posts'))
			->where($db->quoteName('ticket_id') . ' = ' . $ticketId);

		return (int) $db->setQuery($query)->loadResult();
	}

	private function publishedTimespentSum(int $ticketId): float
	{
		$db = $this->db;

		$query = $db->getQuery(true)
			->select('SUM(' . $db->quoteName('timespent') . ')')
			->from($db->quoteName('#__ats_posts'))
			->where($db->quoteName('ticket_id') . ' = ' . $ticketId)
			->where($db->quoteName('enabled') . ' = ' . $db->quote('1'));

		return (float) ($db->setQuery($query)->loadResult() ?: 0.0);
	}
}
