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
 * Publish or unpublish a post — ATS' soft delete for a reply.
 *
 * THE SIDE EFFECT THIS TOOL EXISTS TO DOCUMENT
 * ---------------------------------------------
 * A ticket's `timespent` is not a stored total anybody maintains. It is recomputed
 * from scratch, as `SUM(timespent) WHERE ticket_id = :id AND enabled = '1'`, in
 * exactly one place: PostTable::onAfterStore() (PostTable.php:332-341). That block
 * is guarded by `if ($this->isNewPost && $ticket->status != 'C')` (:291), so it
 * runs when, and only when, a NEW post is saved to a non-closed ticket.
 *
 * Unpublishing a post therefore does change what the SUM would produce — the
 * `enabled = '1'` predicate excludes it — but nothing recomputes the SUM at that
 * moment. Joomla's AdminModel::publish() calls Table::publish(), which issues a
 * direct UPDATE of the state column; ATS' AbstractTable::publish() wraps it only
 * with onBeforePublish / onAfterPublish events (AbstractTable.php:142-152), and
 * PostTable implements neither. No store, no onAfterStore, no rollup.
 *
 * The consequence is a ticket whose billed time is stale — still counting a reply
 * that is no longer in the thread — until somebody posts the next reply, at which
 * point it silently corrects itself. Note that saving the post through the model
 * instead would not help: the rollup is gated on isNewPost, so an edit does not
 * trigger it either. There is no supported way to force the recalculation short of
 * adding a post, and writing #__ats_tickets.timespent directly would be pointless
 * because the next reply overwrites it. So this tool reports both numbers — what
 * the ticket says and what the SUM would now produce — and leaves the row alone.
 *
 * WHY NOT JUST UPDATE THE COLUMN
 * ------------------------------
 * PostModel::canEditState() routes through Permissions::getPostPrivileges()
 * (PostModel.php:225-241), which is where the real policy lives: a non-manager
 * loses `edit.state` entirely once the ticket is closed (Permissions.php:841-844).
 * A direct UPDATE would skip that. The pre-flight check here answers with the
 * reason; AdminModel::publish() then enforces it again for itself.
 */
final class SetPostStateTool extends AbstractTool
{
	use ATSBootTrait;

	public function getName(): string { return 'set_ats_post_state'; }

	public function getDescription(): string
	{
		return 'Publish or unpublish an Akeeba Ticket System post (#__ats_posts.enabled). Unpublishing is ATS\' '
			. 'soft delete for a reply: the row stays, the reply disappears from the ticket thread, and it stops '
			. 'counting towards the ticket\'s billed time. REQUIRED: id, enabled (1 = published, 0 = unpublished). '
			. 'THE SIDE EFFECT YOU MUST KNOW ABOUT: a ticket\'s timespent is a derived SUM over its PUBLISHED posts, '
			. 'and ATS recomputes that SUM in exactly one place — when a NEW post is saved to the ticket. '
			. 'Publishing or unpublishing a post does not recompute anything, because Joomla\'s publish path is a '
			. 'direct UPDATE of the state column that never reaches the table\'s save hooks. So after this call the '
			. 'ticket\'s stored timespent is STALE: it still includes the time from a reply you just hid (or still '
			. 'excludes one you just restored), and it will only correct itself when somebody next replies to that '
			. 'ticket. The response returns ticket_timespent_stored and ticket_timespent_if_recomputed so you can '
			. 'see the gap and decide whether it matters; there is no supported way to force the recalculation, and '
			. 'writing the ticket column directly would be pointless because the next reply overwrites it. '
			. 'PERMISSION: requires the edit.state privilege on the post, which means core.edit.state on the '
			. 'ticket\'s category — or manager rights, which grant everything. For a NON-manager, a ticket with '
			. 'status C removes every privilege including this one. Unpublishing does NOT change the ticket\'s '
			. 'status, its last-reply timestamp, or anything else, and sends no email.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'      => ['type' => 'integer', 'description' => 'The #__ats_posts id.'],
				'enabled' => [
					'type'        => 'integer',
					'enum'        => [0, 1],
					'description' => '1 publishes the post (visible in the thread, counted in the ticket total); 0 unpublishes it.',
				],
			],
			'required'             => ['id', 'enabled'],
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

		if (!\array_key_exists('enabled', $arguments) || $arguments['enabled'] === null) {
			throw new \InvalidArgumentException('enabled is required: 1 to publish, 0 to unpublish.');
		}

		$enabled = (int) $arguments['enabled'] === 0 ? 0 : 1;

		$post = $this->atsTable('Post');

		if ($post === null || !$post->load($id)) {
			return ToolResult::error('No post with id ' . $id . ' exists in #__ats_posts.');
		}

		$ticketId = (int) $post->ticket_id;
		$ticket   = $this->atsLoadTicket($ticketId);

		if ($ticket === null) {
			return ToolResult::error(
				'Post ' . $id . ' is orphaned — its parent ticket (' . $ticketId . ') no longer exists, so there is '
				. 'no category from which to derive a privilege for this change.'
			);
		}

		if (!$this->atsCanViewTicket($ticket, $actor)) {
			return ToolResult::error('No post with id ' . $id . ' is visible to you.');
		}

		$before     = (int) $post->enabled;
		$privileges = Permissions::getPostPrivileges($post, $actor);
		$isManager  = Permissions::isManager((int) $ticket->catid, (int) $actor->id);

		if (empty($privileges['edit.state'])) {
			return ToolResult::error(
				'You do not hold the "edit.state" privilege on post ' . $id . ', so ATS will not let you change '
				. 'whether it is published.'
				. (!$isManager && (string) $ticket->status === 'C'
					? ' Its ticket is CLOSED, and Permissions::getPostPrivileges() collapses every privilege to '
					. 'false on a closed ticket for anyone who is not a manager of its category.'
					: ' That privilege is the core.edit.state action on the ticket\'s category; managers hold it '
					. 'automatically.')
			);
		}

		$timespentStored = (float) $ticket->timespent;

		if ($before === $enabled) {
			return ToolResult::json([
				'ok'      => true,
				'changed' => false,
				'post'    => ['id' => $id, 'ticket_id' => $ticketId, 'enabled' => $before],
				'note'    => 'Post ' . $id . ' is already ' . ($enabled === 1 ? 'published' : 'unpublished') . '; '
					. 'nothing was written.',
			]);
		}

		$model = $this->atsModel('Post');

		if ($model === null) {
			return ToolResult::error('Could not instantiate ATS\' PostModel through the component MVCFactory.');
		}

		// AdminModel::publish() takes $pks by reference and re-checks canEditState()
		// for every id, so the gate above is belt to its braces.
		$pks    = [$id];
		$result = (bool) $model->publish($pks, $enabled);

		$after = $this->atsTable('Post');
		$after = ($after !== null && $after->load($id)) ? $after : null;
		$now   = $after === null ? $before : (int) $after->enabled;

		if (!$result && $now !== $enabled) {
			$errors = method_exists($model, 'getErrors') ? ($model->getErrors() ?: []) : [];
			$errors = array_map(
				static fn($e): string => $e instanceof \Throwable ? $e->getMessage() : (string) $e,
				$errors
			);

			return ToolResult::error(
				'ATS refused the state change: ' . (implode(' | ', $errors) ?: 'no reason given by the component.')
			);
		}

		$recomputed = $this->publishedTimespentSum($ticketId);
		$drift      = abs($recomputed - $timespentStored) > 0.0001;

		$warnings = [];

		if ($drift) {
			$warnings[] = 'The parent ticket\'s stored timespent is ' . $timespentStored . ' but the SUM over its '
				. 'published posts is now ' . $recomputed . '. The ticket total is STALE and will stay that way '
				. 'until the next NEW reply is saved to it — that is the only moment ATS recomputes the figure '
				. '(PostTable::onAfterStore). Nothing is broken; the number is simply behind.';
		}

		if ((float) $post->timespent > 0.0 && !$drift) {
			$warnings[] = 'This post carries ' . (float) $post->timespent . ' of logged time, but the ticket total '
				. 'already matches the recomputed SUM, so either it had been refreshed since or the figures happen '
				. 'to coincide.';
		}

		return ToolResult::json([
			'ok'      => true,
			'changed' => $now !== $before,
			'post'    => [
				'id'             => $id,
				'ticket_id'      => $ticketId,
				'enabled_before' => $before,
				'enabled'        => $now,
				'timespent'      => (float) $post->timespent,
				'created_by'     => (int) $post->created_by,
				'created'        => $post->created,
			],
			'ticket' => [
				'id'                        => $ticketId,
				'title'                     => (string) $ticket->title,
				'status'                    => (string) $ticket->status,
				'status_label'              => $this->atsStatusLabel((string) $ticket->status),
				'timespent_stored'          => $timespentStored,
				'timespent_if_recomputed'   => $recomputed,
				'timespent_is_stale'        => $drift,
				'status_unchanged_by_this'  => true,
			],
			'warnings' => $warnings,
			'note'     => 'Unpublishing a post removes its timespent from what the ticket total WOULD be, but '
				. 'nothing recalculates the ticket total at this moment: Joomla\'s publish path is a direct UPDATE '
				. 'that never reaches PostTable\'s save hooks, and the rollup only runs when a NEW post is saved. '
				. 'The ticket total is therefore stale until the next reply. No email is sent and the ticket\'s '
				. 'status and last-reply timestamp are untouched.',
		]);
	}

	private function publishedTimespentSum(int $ticketId): float
	{
		$db = $this->db;

		// Deliberately identical in shape to PostTable::onAfterStore()'s rollup —
		// including the enabled = '1' string comparison — so the two figures are
		// comparable like for like.
		$query = $db->getQuery(true)
			->select('SUM(' . $db->quoteName('timespent') . ')')
			->from($db->quoteName('#__ats_posts'))
			->where($db->quoteName('ticket_id') . ' = ' . $ticketId)
			->where($db->quoteName('enabled') . ' = ' . $db->quote('1'));

		return (float) ($db->setQuery($query)->loadResult() ?: 0.0);
	}
}
