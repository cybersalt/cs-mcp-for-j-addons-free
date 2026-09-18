<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;

/**
 * Permanently delete a ticket, its posts and its satellite rows.
 *
 * WHICH VENDOR METHOD
 * -------------------
 * TicketModel::delete() — inherited from AdminModel, gated by
 * TicketModel::canDelete(), which is Permissions::getTicketPrivileges($record)
 * ['delete'] (TicketModel.php:1108-1113). Going through the model rather than the
 * table is what fires onContentBeforeDelete / onContentAfterDelete, and those are
 * what make Joomla's fields plugin remove the ticket's custom field values.
 * TicketTable::delete() then cascades through its own onAfterDelete()
 * (TicketTable.php:650-697).
 *
 * WHAT THE CASCADE ACTUALLY COVERS — AND THE ROWS IT LEAVES BEHIND
 * ----------------------------------------------------------------
 * Read from TicketTable::onAfterDelete() and PostTable::onAfterDelete():
 *
 *   #__ats_posts            DELETED. Each row is loaded into a PostTable and
 *                           deleted individually, so the post-level cascade runs.
 *   #__ats_managernotes     DELETED, unconditionally — the loop at
 *                           TicketTable.php:676-689 is NOT behind an edition check,
 *                           so a Core site that used to run Professional loses the
 *                           private notes it still holds.
 *
 *                           This tool reports HOW MANY such notes will be destroyed,
 *                           and nothing else about them. That is a deliberate,
 *                           narrow exception to this add-on's rule that
 *                           #__ats_managernotes is off limits. The rule exists to
 *                           stop private staff commentary being DISCLOSED; a bare
 *                           COUNT(*) discloses no commentary. And the alternative is
 *                           worse: refusing to count means telling an operator
 *                           "unknown" at the exact moment they are about to
 *                           irreversibly destroy the rows, which protects nobody and
 *                           removes the one number that might stop them. The same
 *                           reasoning already governs check_ats_health, which counts
 *                           orphaned notes so a site learns it is holding data its
 *                           UI will never show.
 *
 *                           The hard line is content: note_html is never selected by
 *                           any query in this add-on, the table stays off
 *                           list_ats_tables, and query_ats_table refuses it by name.
 *   #__contentitem_tag_map  DELETED by Joomla, via TaggableTableTrait.
 *   #__fields_values        DELETED by Joomla's fields plugin on
 *                           onContentAfterDelete.
 *   #__ats_attachments      DELETED ONLY ON PRO. PostTable::onAfterDelete() guards
 *                           the whole attachment sweep behind
 *                           `defined('ATS_PRO') && ATS_PRO` (PostTable.php:244), so
 *                           on a Core install any attachment rows left over from a
 *                           previous Professional licence survive their posts and
 *                           are orphaned. Counted and reported.
 *   #__ats_tickets_users    NOT DELETED. onAfterDelete() handles posts and manager
 *                           notes and nothing else; the invited-collaborator table
 *                           is never mentioned, has no foreign key and no index at
 *                           all. Every invitation on the ticket is orphaned. This
 *                           is verified rather than assumed: the tool counts the
 *                           rows before the delete and counts them again after, and
 *                           reports both numbers. Having no index at all also makes
 *                           each of those counts a full table scan — negligible on a
 *                           small helpdesk, worth knowing on a large one.
 *
 * Not wrapped in withSiteAppContext(): nothing on the delete path sends mail, and
 * running under the real API application keeps canDelete() answering for the
 * authenticated actor rather than for the guest a container-built SiteApplication
 * would otherwise be.
 */
final class DeleteTicketTool extends AbstractTool
{
	use ATSBootTrait;

	public function getName(): string { return 'delete_ats_ticket'; }

	public function getDescription(): string
	{
		return 'PERMANENTLY delete an Akeeba Ticket System ticket. There is no trash state and no '
			. 'undo. Required: id, confirm (must be exactly true). Needs the delete privilege on the '
			. 'ticket — core.delete on its category, or category manager. '
			. 'Consider set_ats_ticket_status with status C instead: closing is reversible by a '
			. 'manager, deleting is not. '
			. 'BLAST RADIUS, reported in the response before and after the delete. Removed with the '
			. 'ticket: every row in #__ats_posts belonging to it (including the opening message); '
			. 'every manager note attached to it; its Joomla custom field values in #__fields_values '
			. '(context com_ats.ticket); and its tag mappings in #__contentitem_tag_map. '
			. 'NOT removed, and this is the part that surprises people: rows in #__ats_tickets_users, '
			. 'the invited-collaborator table. ATS\' cascade handles posts and manager notes and '
			. 'nothing else, and that table has no foreign key and no index, so every invitation on '
			. 'the ticket is left orphaned in the database pointing at a ticket id that no longer '
			. 'exists. The tool counts those rows before and after so the orphaning is demonstrated, '
			. 'not claimed. On ATS Core, attachment rows are also NOT removed — the attachment sweep '
			. 'inside the post cascade is gated on the Professional edition, so leftovers from a '
			. 'former Pro licence survive. MANAGER NOTES: the response tells you how many private '
			. 'staff notes are about to be destroyed, and nothing else about them. ATS deletes them '
			. 'unconditionally, on Core as well as Pro, which means a site that downgraded from '
			. 'Professional still loses notes its own UI will not show it. A bare count discloses no '
			. 'commentary, and withholding it would only leave you blind at the moment of an '
			. 'irreversible delete; the note bodies are never read by this add-on. '
			. 'Call once with confirm omitted or false to see the blast radius without deleting '
			. 'anything.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['id'],
			'properties' => [
				'id' => [
					'type'        => 'integer',
					'description' => 'Ticket id (#__ats_tickets.id).',
				],
				'confirm' => [
					'type'        => 'boolean',
					'description' => 'Must be true for the delete to happen. Omit it or pass false to '
						. 'get the blast radius report without touching anything — the report is '
						. 'identical either way, so this is a safe dry run.',
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

		$id      = $this->requirePositiveInt($arguments, 'id');
		$confirm = ($arguments['confirm'] ?? false) === true;

		$ticket = $this->atsLoadTicket($id);

		if ($ticket === null) {
			return ToolResult::error('Ticket ' . $id . ' does not exist in #__ats_tickets.');
		}

		$privileges = $this->atsTicketPrivileges($ticket, $actor);

		if (empty($privileges['view'])) {
			return ToolResult::error('Ticket ' . $id . ' is not visible to you.');
		}

		if (empty($privileges['delete'])) {
			return ToolResult::error(
				'You do not have permission to delete ticket ' . $id . '. ATS gates this on '
				. 'core.delete for com_ats.category.' . (int) $ticket->catid . ', or on category '
				. 'manager status (TicketModel::canDelete, TicketModel.php:1108-1113).'
			);
		}

		$radius = $this->atsBlastRadius($id);

		$summary = [
			'id'                    => $id,
			'title'                 => (string) $ticket->title,
			'catid'                 => (int) $ticket->catid,
			'status'                => (string) $ticket->status,
			'status_label'          => $this->atsStatusLabel((string) $ticket->status),
			'public'                => (int) $ticket->public,
			'created'               => $ticket->created,
			'created_by'            => (int) $ticket->created_by,
			'posts'                 => $radius['posts'],
			'attachments'           => $radius['attachments'],
			'invited_collaborators' => $radius['invited'],
			'custom_field_values'   => $radius['fields'],
			'tag_mappings'          => $radius['tags'],
			'manager_notes'         => $radius['manager_notes'],
			'manager_notes_note'    => 'A COUNT only — this add-on never reads note content, and '
				. '#__ats_managernotes stays off list_ats_tables and is refused by name in '
				. 'query_ats_table. The count is reported here because ATS deletes these rows '
				. 'regardless of edition (TicketTable.php:676-689), and on a Core site that once ran '
				. 'Professional they are private notes nothing in the UI can show — so this is the '
				. 'only warning anyone gets before they are destroyed.',
		];

		if (!$confirm) {
			return ToolResult::json([
				'ok'           => true,
				'deleted'      => false,
				'dry_run'      => true,
				'blast_radius' => $summary,
				'note'         => 'Nothing was deleted — confirm was not true. Deleting ticket ' . $id
					. ' would permanently remove it, its ' . $radius['posts'] . ' post(s), its '
					. $radius['fields'] . ' custom field value(s), its ' . $radius['tags']
					. ' tag mapping(s) and ' . $radius['manager_notes'] . ' manager note(s)'
					. ($radius['manager_notes'] > 0 && !$this->atsIsPro()
						? ' — which this Core site cannot display at all, so they are almost certainly '
						. 'left over from a Professional install and this is your only notice of them'
						: '')
					. '. It would ORPHAN ' . $radius['invited']
					. ' row(s) in #__ats_tickets_users'
					. ($radius['attachments'] > 0 && !$this->atsIsPro()
						? ' and ' . $radius['attachments'] . ' row(s) in #__ats_attachments, because '
						. 'the attachment cascade only runs on ATS Professional'
						: '')
					. '. Pass confirm: true to proceed. There is no undo; set_ats_ticket_status with '
					. 'status C is the reversible alternative.',
			]);
		}

		$model = $this->atsModel('Ticket');

		if ($model === null) {
			return ToolResult::error('Could not create the ATS TicketModel through com_ats\' MVCFactory.');
		}

		try {
			$pks     = [$id];
			$deleted = (bool) $model->delete($pks);
		} catch (\Throwable $e) {
			return ToolResult::error('Delete failed: ' . $e->getMessage());
		}

		$stillThere = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName('#__ats_tickets'))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadResult();

		if ($stillThere > 0) {
			return ToolResult::error(
				'Ticket ' . $id . ' is still in #__ats_tickets after the delete. '
				. ($this->atsErrorOf($model) ?: 'The model reported no error.')
				. ' Nothing has been lost; investigate before retrying.'
			);
		}

		// The post ids are passed in because the posts themselves are gone by now:
		// counting attachments through a join on #__ats_posts would report zero
		// however many orphans survived.
		$after = $this->atsBlastRadius($id, $radius['post_ids']);

		$result = [
			'ok'      => true,
			'deleted' => true,
			'id'      => $id,
			'removed' => [
				'ticket'              => 1,
				'posts'               => $radius['posts'] - $after['posts'],
				'custom_field_values' => $radius['fields'] - $after['fields'],
				'tag_mappings'        => $radius['tags'] - $after['tags'],
				'attachments'         => $radius['attachments'] - $after['attachments'],
				'manager_notes'       => $radius['manager_notes'] - $after['manager_notes'],
			],
			'orphaned' => [
				'ats_tickets_users' => $after['invited'],
				'ats_attachments'   => $after['attachments'],
			],
			'blast_radius_before' => $summary,
			'model_reported_success' => $deleted,
			'note' => 'The counts above are measured, not predicted: each satellite table was counted '
				. 'before the delete and again afterwards.',
		];

		if ($after['invited'] > 0) {
			$result['orphan_warning'] = $after['invited'] . ' row(s) remain in #__ats_tickets_users '
				. 'for ticket ' . $id . ', which no longer exists. This is expected and is ATS\' '
				. 'behaviour, not a failure of this tool: TicketTable::onAfterDelete() cascades to '
				. '#__ats_posts and #__ats_managernotes only (TicketTable.php:650-697), and '
				. '#__ats_tickets_users carries no foreign key and no index of any kind. The rows are '
				. 'inert — nothing reads them for a ticket id that has gone — but they will '
				. 'accumulate. Clean them up directly if that matters to you.';
		}

		if ($after['attachments'] > 0) {
			$result['attachment_warning'] = $after['attachments'] . ' row(s) remain in '
				. '#__ats_attachments for posts that were just deleted. PostTable::onAfterDelete() '
				. 'guards its attachment sweep behind `defined(\'ATS_PRO\') && ATS_PRO` '
				. '(PostTable.php:244), and this install reports ATS_PRO as '
				. ($this->atsIsPro() ? 'true' : 'false') . '. On a Core install that never ran '
				. 'Professional this table is empty and the warning will not appear; seeing it means '
				. 'the site holds attachment rows from a previous Professional licence.';
		}

		if (!$deleted) {
			$result['post_delete_warning'] = 'The ticket row is confirmed gone, but the model '
				. 'returned false: ' . ($this->atsErrorOf($model) ?: 'no message') . '. AdminModel'
				. '::delete() returns false when a plugin in the delete chain throws even after the '
				. 'row has been removed. Do not retry — verify the satellite counts above instead.';
		}

		return ToolResult::json($result);
	}

	/**
	 * Count everything that hangs off a ticket.
	 *
	 * #__ats_managernotes is counted, and ONLY counted — see the class docblock for
	 * why that narrow exception to the off-limits rule is the right call. No column
	 * of that table other than the row count is ever read here; note_html is not in
	 * the SELECT list, because there is no SELECT list.
	 *
	 * @return array{posts:int, post_ids:int[], attachments:int, invited:int, fields:int, tags:int, manager_notes:int}
	 */
	private function atsBlastRadius(int $id, ?array $postIds = null): array
	{
		$ids = array_map('intval', $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('id'))
				->from($this->db->quoteName('#__ats_posts'))
				->where($this->db->quoteName('ticket_id') . ' = ' . $id)
		)->loadColumn() ?: []);

		// Attachments hang off posts, not off the ticket — so after the delete the
		// posts are gone and a join through them would report zero however many
		// orphans survive. The caller therefore passes the post ids captured BEFORE
		// the delete, and we count against those.
		$attachments = $this->atsCountAttachments($postIds ?? $ids);

		$invited = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName('#__ats_tickets_users'))
				->where($this->db->quoteName('ticket_id') . ' = ' . $id)
		)->loadResult();

		// Custom fields are Joomla-native, context com_ats.ticket, item_id = ticket id.
		$fields = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName('#__fields_values', 'fv'))
				->innerJoin(
					$this->db->quoteName('#__fields', 'f')
					. ' ON ' . $this->db->quoteName('f.id') . ' = ' . $this->db->quoteName('fv.field_id')
				)
				->where($this->db->quoteName('f.context') . ' = ' . $this->db->quote('com_ats.ticket'))
				->where($this->db->quoteName('fv.item_id') . ' = ' . $this->db->quote((string) $id))
		)->loadResult();

		$tags = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName('#__contentitem_tag_map'))
				->where($this->db->quoteName('type_alias') . ' = ' . $this->db->quote('com_ats.ticket'))
				->where($this->db->quoteName('content_item_id') . ' = ' . $id)
		)->loadResult();

		// A bare COUNT(*) on #__ats_managernotes — the single narrow exception to this
		// add-on's rule that the table is off limits. See the class docblock: the rule
		// exists to stop private commentary being disclosed, and a count discloses none
		// of it, while refusing to count would say "unknown" at the exact moment the
		// caller is about to destroy the rows. note_html is never selected.
		$notes = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName($this->db->getPrefix() . 'ats_managernotes'))
				->where($this->db->quoteName('ticket_id') . ' = ' . $id)
		)->loadResult();

		return [
			'posts'         => \count($ids),
			'post_ids'      => $ids,
			'attachments'   => $attachments,
			'invited'       => $invited,
			'fields'        => $fields,
			'tags'          => $tags,
			'manager_notes' => $notes,
		];
	}

	/**
	 * Count attachment rows belonging to a known set of post ids.
	 *
	 * @param int[] $postIds
	 */
	private function atsCountAttachments(array $postIds): int
	{
		if ($postIds === []) {
			return 0;
		}

		return (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName('#__ats_attachments'))
				->whereIn($this->db->quoteName('post_id'), $postIds)
		)->loadResult();
	}

	private function atsErrorOf(object $subject): string
	{
		return method_exists($subject, 'getError') ? (string) ($subject->getError() ?: '') : '';
	}
}
