<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;

/**
 * Change a ticket's status through the vendor's dedicated status path.
 *
 * WHICH VENDOR METHOD, AND WHY NOT THE OTHER TWO
 * ----------------------------------------------
 * ATS offers three ways to change a status. They are not equivalent.
 *
 *   TicketModel::closeOpenTicket()   USED HERE for C and O. It is the vendor's own
 *       method for those two transitions, it applies the right privilege for each
 *       ('close' to close, 'admin' to reopen — TicketModel.php:110-111), it prunes
 *       tickets already in the target state, and it delegates to
 *       TicketTable::changeStatus(), which dispatches onTableBeforeStatusChange and
 *       onTableAfterStatusChange. It refuses anything other than C or O
 *       (TicketModel.php:97-102).
 *
 *   TicketTable::changeStatus()      USED HERE for P and the numeric statuses,
 *       because closeOpenTicket() will not carry them. It issues a targeted UPDATE
 *       of the status column alone and fires the same two events. It performs NO
 *       validation of the value, so this tool validates against
 *       Permissions::getStatuses() first — Joomla connects MySQL with
 *       STRICT_TRANS_TABLES (MysqliDriver.php:111-115), so an out-of-range ENUM
 *       value would raise a database error rather than be quietly stored. It also
 *       performs no ACL check, so this tool applies the same gate batchStatus()
 *       uses: category manager, or core.edit.state on the category.
 *
 *   TicketModel::batchStatus()       DELIBERATELY NOT USED. It sets the property on
 *       a loaded table and then calls check() and store() (TicketModel.php:1077,
 *       1085, 1093) — a FULL save, with TicketTable::onBeforeCheck() in the middle
 *       of it. That method rewrites more than the status: it forces public to 1
 *       when neither the acting user nor the ticket owner holds ats.private
 *       (TicketTable.php:880-906), replaces a falsy priority with 1 or 5
 *       (TicketTable.php:927-939), re-slugs the alias (TicketTable.php:850-877) and
 *       normalises origin (TicketTable.php:921-925). Closing a ticket through it
 *       could therefore publish a private one. The two paths above cannot: they
 *       write a single column.
 *
 * NOT WRAPPED IN withSiteAppContext()
 * -----------------------------------
 * Neither path sends mail. ATS reaches EmailSending only from
 * TicketTable::onChangedValues(), which runs from onAfterStore() and so only on a
 * full save, and from Helper\PostNotification, which only controllers call. A
 * targeted UPDATE never gets there. Running under the real API application also
 * keeps the ACL checks answering for the authenticated actor, which is the safer
 * default — a container-built SiteApplication has no identity and reads as a guest.
 *
 * The response reports the status, public and priority values read back out of the
 * database, so the claim that nothing else moved is demonstrated rather than
 * asserted.
 */
final class SetTicketStatusTool extends AbstractTool
{
	use ATSBootTrait;

	public function getName(): string { return 'set_ats_ticket_status'; }

	public function getDescription(): string
	{
		return 'Set the status of an Akeeba Ticket System ticket. Required: id, status. Accepts O '
			. '(Open — waiting on the support team), P (Pending — waiting on the customer), C '
			. '(Closed), or a site-defined numeric status "1".."99" whose labels live in the '
			. 'component\'s customStatuses param. The value is validated against '
			. 'Permissions::getStatuses() before anything is written, which matters because the '
			. 'vendor would not validate it for you: a full ticket save silently rewrites an '
			. 'unrecognised status to O, and the check there is case-sensitive, so a lowercase "c" '
			. 'would reopen a ticket rather than close it. This tool upper-cases the letter forms and '
			. 'refuses a numeric status the site has not defined. '
			. 'HOW IT WRITES: O and C go through TicketModel::closeOpenTicket(), the vendor\'s own '
			. 'method for those transitions, which needs the close privilege to close and the admin '
			. 'privilege to reopen. P and the numeric statuses go through '
			. 'TicketTable::changeStatus() behind an edit.state check, because closeOpenTicket() '
			. 'refuses to carry them. Both paths UPDATE the status column only and fire ATS\' '
			. 'onTableBeforeStatusChange / onTableAfterStatusChange events. The tool deliberately '
			. 'avoids the batch path, which performs a full save and could, as a side effect of '
			. 'changing the status, publish a private ticket and rewrite its priority and alias. '
			. 'WHAT THIS DOES NOT DO: it does not add a post. A status set this way leaves no trace '
			. 'in the ticket thread and does not update the ticket\'s modified / modified_by columns, '
			. 'which record the last REPLY. Note also that posting a reply to a ticket that is NOT '
			. 'closed rewrites the status again — O for anyone who is not a category manager, P for a '
			. 'manager replying on someone else\'s ticket — so a status set here can be superseded by '
			. 'the next reply. Posting to a ticket that IS closed changes nothing: ATS skips that '
			. 'whole block, so a closed ticket is not reopened by a reply. '
			. 'The response reports status, public and priority as read back from the database.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['id', 'status'],
			'properties' => [
				'id' => [
					'type'        => 'integer',
					'description' => 'Ticket id (#__ats_tickets.id).',
				],
				'status' => [
					'type'        => 'string',
					'description' => 'O = Open, P = Pending, C = Closed, or "1".."99" for a '
						. 'site-defined status. Letter forms are upper-cased for you. Anything the '
						. 'site does not define is refused rather than silently turned into O.',
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

		$id     = $this->requirePositiveInt($arguments, 'id');
		$raw    = $this->requireString($arguments, 'status');
		$status = $this->atsNormaliseStatus($raw);

		if ($status === null) {
			return ToolResult::error(
				'"' . $raw . '" is not a status this site defines. Valid values are O, P, C and '
				. 'whichever of "1".."99" appear in the component\'s customStatuses param: '
				. $this->atsStatusSummary() . '. Refusing rather than writing it, because ATS would '
				. 'not tell you — a full save rewrites an unrecognised status to O, and '
				. 'TicketTable::changeStatus() would push the value straight at an ENUM column under '
				. 'STRICT_TRANS_TABLES.'
			);
		}

		$ticket = $this->atsLoadTicket($id);

		if ($ticket === null) {
			return ToolResult::error('Ticket ' . $id . ' does not exist in #__ats_tickets.');
		}

		$privileges    = $this->atsTicketPrivileges($ticket, $actor);
		$currentStatus = (string) $ticket->status;

		if (empty($privileges['view'])) {
			return ToolResult::error('Ticket ' . $id . ' is not visible to you.');
		}

		if ($currentStatus === $status) {
			$row = $this->atsReadStatusRow($id) ?? [
				'status'       => $currentStatus,
				'status_label' => $this->atsStatusLabel($currentStatus),
				'public'       => (int) $ticket->public,
				'priority'     => (int) $ticket->priority,
			];

			return ToolResult::json([
				'ok'              => true,
				'id'              => $id,
				'changed'         => false,
				'status'          => $row['status'],
				'status_label'    => $row['status_label'],
				'public'          => $row['public'],
				'priority'        => $row['priority'],
				'note'            => 'Ticket ' . $id . ' was already at status "' . $status
					. '". Nothing was written and no events fired.',
			]);
		}

		// Privilege gates, matching the vendor's own for each path.
		if ($status === 'C' && empty($privileges['close'])) {
			return ToolResult::error(
				'You do not have permission to close ticket ' . $id . '. ATS grants the close '
				. 'privilege to category managers and to the ticket owner while the ticket is not '
				. 'already closed (Permissions.php:773).'
			);
		}

		if ($status === 'O' && $currentStatus === 'C' && empty($privileges['admin'])) {
			return ToolResult::error(
				'You do not have permission to reopen ticket ' . $id . '. '
				. 'TicketModel::closeOpenTicket() requires the admin privilege (core.manage on the '
				. 'category) to move a ticket back to Open (TicketModel.php:110-111). Note that a '
				. 'non-manager loses every privilege but `view` on a closed ticket '
				. '(Permissions.php:714-727), which is why this cannot be done by the ticket owner.'
			);
		}

		if (!\in_array($status, ['O', 'C'], true) && empty($privileges['edit.state'])) {
			return ToolResult::error(
				'Setting ticket ' . $id . ' to status "' . $status . '" needs the edit.state '
				. 'privilege (core.edit.state on the category, or category manager). That is the '
				. 'same gate ATS applies in batchStatus() (TicketModel.php:1067-1075).'
			);
		}

		$usedPath = '';

		try {
			if (\in_array($status, ['O', 'C'], true)) {
				$model = $this->atsModel('Ticket');

				if ($model === null) {
					return ToolResult::error('Could not create the ATS TicketModel through com_ats\' MVCFactory.');
				}

				$pks      = [$id];
				$usedPath = 'TicketModel::closeOpenTicket()';
				$ok       = (bool) $model->closeOpenTicket($pks, $status);

				if (!$ok) {
					return ToolResult::error(
						'TicketModel::closeOpenTicket() refused the change: '
						. ($this->atsErrorOf($model) ?: 'no error reported. It returns false when the '
						. 'privilege check fails for any ticket in the batch.')
					);
				}
			} else {
				$usedPath = 'TicketTable::changeStatus()';
				$ok       = (bool) $ticket->changeStatus([$id], $status, (int) $actor->id);

				if (!$ok) {
					return ToolResult::error(
						'TicketTable::changeStatus() failed: '
						. ($this->atsErrorOf($ticket) ?: 'no error reported.')
					);
				}
			}
		} catch (\Throwable $e) {
			return ToolResult::error('Status change failed: ' . $e->getMessage());
		}

		$row = $this->atsReadStatusRow($id);

		if ($row === null) {
			return ToolResult::error(
				'Ticket ' . $id . ' is not in #__ats_tickets after the status change. Inspect the '
				. 'table before retrying.'
			);
		}

		$result = [
			'ok'                => true,
			'id'                => $id,
			'changed'           => $row['status'] !== $currentStatus,
			'previous_status'   => $currentStatus,
			'status'            => $row['status'],
			'status_label'      => $row['status_label'],
			'public'            => $row['public'],
			'priority'          => $row['priority'],
			'vendor_path'       => $usedPath,
			'note'              => 'status, public and priority are read back from #__ats_tickets. '
				. 'public and priority are shown to demonstrate that only the status column moved: '
				. 'this tool avoids the batch path precisely because that one runs a full save, and '
				. 'TicketTable::onBeforeCheck() would then be free to publish a private ticket and '
				. 'rewrite the priority as a side effect of a status change. No post was added and '
				. 'modified / modified_by are untouched — those record the last reply.',
		];

		if ($row['status'] !== $status) {
			$result['ok']      = false;
			$result['warning'] = 'The status was requested as "' . $status . '" but the row now reads "'
				. $row['status'] . '". Investigate before retrying.';
		}

		if ($status !== 'C') {
			$result['volatility_note'] = 'This status is not final. The next reply to the ticket will '
				. 'rewrite it — O for a poster who is not a category manager, P for a manager '
				. 'replying on someone else\'s ticket (PostTable.php:327-330). Only Closed is stable, '
				. 'because ATS skips that whole block for a closed ticket.';
		}

		return ToolResult::json($result);
	}

	/**
	 * getError() is deprecated in Joomla 5 and gone in 6, and ATS' own
	 * setErrorOrThrow() throws instead when it is missing — so probe for it.
	 */
	private function atsErrorOf(object $subject): string
	{
		return method_exists($subject, 'getError') ? (string) ($subject->getError() ?: '') : '';
	}

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

		// getStatuses() keys the custom entries with integer ids; the ENUM stores
		// them as the strings '1'..'99'.
		$all = \Akeeba\Component\ATS\Administrator\Helper\Permissions::getStatuses();

		return \array_key_exists($numeric, $all) ? (string) $numeric : null;
	}

	/** Human-readable list of the statuses this site actually defines. */
	private function atsStatusSummary(): string
	{
		try {
			$all = \Akeeba\Component\ATS\Administrator\Helper\Permissions::getStatuses();
		} catch (\Throwable) {
			return 'O, P, C';
		}

		$parts = [];

		foreach ($all as $value => $label) {
			$parts[] = (string) $value . ' = ' . (string) $label;
		}

		return implode('; ', $parts);
	}

	/** @return array<string, mixed>|null */
	private function atsReadStatusRow(int $id): ?array
	{
		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName(['status', 'public', 'priority']))
				->from($this->db->quoteName('#__ats_tickets'))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();

		if (!$row) {
			return null;
		}

		return [
			'status'       => (string) $row['status'],
			'status_label' => $this->atsStatusLabel((string) $row['status']),
			'public'       => (int) $row['public'],
			'priority'     => (int) $row['priority'],
		];
	}
}
