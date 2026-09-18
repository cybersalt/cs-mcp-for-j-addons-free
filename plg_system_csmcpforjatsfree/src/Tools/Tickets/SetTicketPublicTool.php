<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;

/**
 * Set a ticket public or private through TicketModel::makepublic().
 *
 * WHY makepublic() AND NOT A SAVE
 * -------------------------------
 * TicketModel::makepublic() checks canEditState() on the loaded row, prunes any
 * ticket already in the target state, and delegates to TicketTable::makepublic(),
 * which UPDATEs the `public` column alone and dispatches onTableBeforePublicChange
 * and onTableAfterPublicChange (TicketTable.php:334-447). A full save would be
 * strictly worse in both directions:
 *
 *   - Going private, a save usually cannot do it at all. TicketModel::save()
 *     blanks $data['public'] when the actor lacks ats.private and is not a manager
 *     (TicketModel.php:798-802), and TicketTable::onBeforeCheck() then forces the
 *     value back to 1 unless the actor or the ticket owner holds ats.private —
 *     manager status does not count in that second test (TicketTable.php:890-899,
 *     904). makepublic() runs neither of those, so it is the only path on which a
 *     legitimately-authorised caller can reliably make a ticket private.
 *
 *   - Going either way, a save is a blunt instrument: onBeforeCheck() also
 *     re-slugs the alias, normalises origin, and replaces a falsy priority with 1
 *     or 5. Changing one boolean should not move four columns.
 *
 * THE forcetype PARAM DOES NOT APPLY ON THIS PATH — AND THAT IS THE CATCH
 * -----------------------------------------------------------------------
 * A category's `forcetype` param ('PUB' / 'PRIV' / empty) pins ticket visibility.
 * ATS applies it in exactly two places, both of which this path bypasses:
 * TicketModel::save() overwrites $data['public'] from it (TicketModel.php:803-806),
 * and TicketModel::getForm() disables the form field (TicketModel.php:482-493).
 * TicketModel::makepublic() consults neither, so the value you ask for is the value
 * that lands — which means a ticket can be left disagreeing with its own category
 * until the next full save, at which point ATS will silently pull it back into
 * line. That is why this tool reads the column back rather than echoing the
 * request, and why it warns when the category forces the opposite value.
 *
 * makepublic() ALSO REPORTS SUCCESS WHEN IT DID NOTHING
 * -----------------------------------------------------
 * A ticket already in the requested state is unset from $pks, and the method then
 * returns true from `if (!count($pks)) { return true; }` (TicketModel.php:719-722).
 * The return value alone therefore cannot distinguish "changed" from "no-op", which
 * is a second reason the response is built from a fresh SELECT.
 *
 * Not wrapped in withSiteAppContext(): a column UPDATE reaches no mail path, and
 * running under the real API application keeps canEditState() answering for the
 * authenticated actor instead of the guest a container-built SiteApplication is.
 */
final class SetTicketPublicTool extends AbstractTool
{
	use ATSBootTrait;

	public function getName(): string { return 'set_ats_ticket_public'; }

	public function getDescription(): string
	{
		return 'Make an Akeeba Ticket System ticket public or private. Required: id, public (1 = '
			. 'public, 0 = private). Needs the edit.state privilege on the ticket — core.edit.state '
			. 'on its category, or category manager. '
			. 'WHY THIS TOOL RATHER THAN update_ats_ticket: it goes through '
			. 'TicketModel::makepublic(), which UPDATEs the public column alone and fires ATS\' '
			. 'onTableBeforePublicChange / onTableAfterPublicChange events. A full ticket save cannot '
			. 'be trusted with this field. Going private, a save is usually refused invisibly: ATS '
			. 'blanks the value when the caller lacks the ats.private privilege, then forces it back '
			. 'to 1 unless the caller OR the ticket owner holds ats.private — and being a category '
			. 'manager does not satisfy that second check. A save also re-slugs the alias and '
			. 'rewrites a falsy priority on the way past. '
			. 'THE ONE THING TO WATCH: a category can carry a `forcetype` param of PUB or PRIV that '
			. 'pins the visibility of its tickets. This path does NOT honour it — ATS applies '
			. 'forcetype only in TicketModel::save() and when building the edit form. So a value set '
			. 'here takes effect immediately but is not durable: the next full save of that ticket '
			. 'will silently pull it back to whatever forcetype dictates. The response reports the '
			. 'category\'s forcetype and warns when the value you asked for contradicts it. '
			. 'Everything reported is READ BACK FROM #__ats_tickets after the write, never echoed '
			. 'from the request — necessary because makepublic() also returns true when it changed '
			. 'nothing, having pruned a ticket that was already in the requested state. '
			. 'Note that a ticket has no access or language column of its own; making a ticket public '
			. 'still does not expose it to anyone who cannot see its category, because the category\'s '
			. 'view access level is applied on top.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['id', 'public'],
			'properties' => [
				'id' => [
					'type'        => 'integer',
					'description' => 'Ticket id (#__ats_tickets.id).',
				],
				'public' => [
					'type'        => 'integer',
					'enum'        => [0, 1],
					'description' => '1 = public (visible to anyone who can see the category), '
						. '0 = private (visible to the owner, invited collaborators, and holders of '
						. 'ats.private.read or category manager).',
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

		if (!\array_key_exists('public', $arguments) || $arguments['public'] === null) {
			return ToolResult::error('public is required and must be 0 (private) or 1 (public).');
		}

		$requested = (int) $arguments['public'] === 1 ? 1 : 0;

		$ticket = $this->atsLoadTicket($id);

		if ($ticket === null) {
			return ToolResult::error('Ticket ' . $id . ' does not exist in #__ats_tickets.');
		}

		$privileges = $this->atsTicketPrivileges($ticket, $actor);

		if (empty($privileges['view'])) {
			return ToolResult::error('Ticket ' . $id . ' is not visible to you.');
		}

		if (empty($privileges['edit.state'])) {
			return ToolResult::error(
				'You do not have permission to change the visibility of ticket ' . $id . '. '
				. 'TicketModel::makepublic() gates on canEditState(), which is the edit.state '
				. 'privilege — core.edit.state on com_ats.category.' . (int) $ticket->catid . ', or '
				. 'category manager.'
			);
		}

		$before    = (int) $ticket->public;
		$catid     = (int) $ticket->catid;
		$forceType = $this->atsCategoryForceType($catid);

		$model = $this->atsModel('Ticket');

		if ($model === null) {
			return ToolResult::error('Could not create the ATS TicketModel through com_ats\' MVCFactory.');
		}

		if ($before !== $requested) {
			try {
				$pks = [$id];
				$ok  = (bool) $model->makepublic($pks, $requested);
			} catch (\Throwable $e) {
				return ToolResult::error('Visibility change failed: ' . $e->getMessage());
			}

			if (!$ok) {
				return ToolResult::error(
					'TicketModel::makepublic() refused the change: '
					. ($this->atsErrorOf($model) ?: 'no error reported. It returns false as soon as '
					. 'canEditState() fails for any ticket in the batch.')
				);
			}
		}

		$row = $this->atsReadPublicRow($id);

		if ($row === null) {
			return ToolResult::error(
				'Ticket ' . $id . ' is not in #__ats_tickets after the write. Inspect the table '
				. 'before retrying.'
			);
		}

		$effective = $row['public'];

		$result = [
			'ok'                => true,
			'id'                => $id,
			'requested_public'  => $requested,
			'effective_public'  => $effective,
			'previous_public'   => $before,
			'changed'           => $effective !== $before,
			'visibility'        => $effective === 1 ? 'public' : 'private',
			'catid'             => $catid,
			'category_forcetype' => $forceType === '' ? null : $forceType,
			'status'            => $row['status'],
			'priority'          => $row['priority'],
			'alias'             => $row['alias'],
			'note'              => 'effective_public is read back from #__ats_tickets, not echoed '
				. 'from the request — makepublic() returns true even when it pruned the ticket as '
				. 'already being in the requested state. status, priority and alias are shown '
				. 'unchanged to demonstrate that only the public column moved; a full ticket save '
				. 'would have been free to rewrite all three.',
		];

		if ($effective !== $requested) {
			$result['ok']      = false;
			$result['warning'] = 'public was requested as ' . $requested . ' but the column now reads '
				. $effective . '. Investigate before retrying.';
		}

		if ($forceType === 'PUB' && $effective === 0) {
			$result['forcetype_warning'] = 'Category ' . $catid . ' has forcetype = PUB, meaning ATS '
				. 'considers every ticket in it public. This path does not consult forcetype, so the '
				. 'ticket is private right now — but the change is NOT durable. The next full save of '
				. 'this ticket (update_ats_ticket, a batch operation, or an edit in the ATS admin UI) '
				. 'will silently set public back to 1, because TicketModel::save() overwrites the '
				. 'value from forcetype unconditionally (TicketModel.php:803-806). Change the '
				. 'category param if you want this to stick.';
		}

		if ($forceType === 'PRIV' && $effective === 1) {
			$result['forcetype_warning'] = 'Category ' . $catid . ' has forcetype = PRIV, meaning ATS '
				. 'considers every ticket in it private. This path does not consult forcetype, so the '
				. 'ticket is public right now — a visibility change ATS itself would not have allowed '
				. 'through its own forms. The next full save will set public back to 0. If this '
				. 'ticket genuinely should be public, move it to a category that permits it rather '
				. 'than leaving it in this state.';
		}

		if ($effective === 1 && $before === 0) {
			$result['exposure_note'] = 'Ticket ' . $id . ' is now readable by anyone who can see '
				. 'category ' . $catid . '. A ticket has no access column of its own, so the '
				. 'category\'s view access level is the only remaining restriction.';
		}

		return ToolResult::json($result);
	}

	/** The category's forcetype param: 'PUB', 'PRIV' or ''. */
	private function atsCategoryForceType(int $catid): string
	{
		$params = $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('params'))
				->from($this->db->quoteName('#__categories'))
				->where($this->db->quoteName('id') . ' = ' . $catid)
				->where($this->db->quoteName('extension') . ' = ' . $this->db->quote('com_ats'))
		)->loadResult();

		if ($params === null) {
			return '';
		}

		$registry = new \Joomla\Registry\Registry((string) $params);

		return strtoupper(trim((string) $registry->get('forcetype', '')));
	}

	private function atsErrorOf(object $subject): string
	{
		return method_exists($subject, 'getError') ? (string) ($subject->getError() ?: '') : '';
	}

	/** @return array<string, mixed>|null */
	private function atsReadPublicRow(int $id): ?array
	{
		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName(['public', 'status', 'priority', 'alias']))
				->from($this->db->quoteName('#__ats_tickets'))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();

		if (!$row) {
			return null;
		}

		return [
			'public'   => (int) $row['public'],
			'status'   => (string) $row['status'],
			'priority' => (int) $row['priority'],
			'alias'    => (string) $row['alias'],
		];
	}
}
