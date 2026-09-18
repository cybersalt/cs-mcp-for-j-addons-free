<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;

/**
 * Partial update of an existing ticket through TicketModel::save().
 *
 * WHY THE MODEL AND NOT THE TABLE
 * -------------------------------
 * AdminModel::save() loads the row before it binds, so a payload carrying three
 * keys leaves every other column alone. Tags survive omission too: AdminModel only
 * populates $table->newTags when $data['tags'] is present, and TagsHelper::
 * postStoreProcess() short-circuits when nothing changed. Custom field values
 * survive for the same reason — Joomla's fields plugin skips any field absent from
 * $data['com_fields'].
 *
 * TWO THINGS THIS TOOL HAS TO DEFEND AGAINST, BOTH CONFIRMED IN 5.6.0
 * -------------------------------------------------------------------
 * 1. catid is ALWAYS sent, even when the caller does not change it.
 *    TicketModel::save() computes the category it reasons about as
 *    `($data['catid'] ?? $table->catid) ?: null` (TicketModel.php:749) — and
 *    $table there is a FRESH, unloaded table, so $table->catid is null. Omit catid
 *    and ATS evaluates ats.private, isManager() and the category's forcetype param
 *    against the COMPONENT asset com_ats rather than com_ats.category.N, and reads
 *    an empty Registry for the params. An explicit Deny on the category, or a
 *    forcetype set on it, would simply not be seen. Sending the ticket's existing
 *    catid restores the vendor's intended behaviour.
 *
 * 2. A private ticket can be silently published by an unrelated edit.
 *    TicketModel::save() does `$canDoPrivate = $acls['ats.private'] || $isManager;
 *    if (!$canDoPrivate) { $data['public'] = null; }` (TicketModel.php:796-802) —
 *    note it injects `public` into the payload whether or not the caller mentioned
 *    it. TicketTable::onBeforeCheck() then sees a falsy public and runs its own
 *    test, which is NOT the same one: it asks for ats.private alone, with no
 *    manager fallback (TicketTable.php:890-899). If neither the acting user nor the
 *    ticket's owner holds ats.private on that category, it sets public = 1
 *    (TicketTable.php:904). The net effect is that renaming a private ticket can
 *    expose it. A behavioural probe of that block confirmed $allowed is false off
 *    the preamble in every reachable case, because Joomla's Table::load(null)
 *    returns true without loading (Table.php:793-795), so the ats.private test is
 *    always the deciding factor. This tool refuses rather than take the risk, and
 *    names the privilege to grant.
 *
 * WHAT IT DELIBERATELY DOES NOT ACCEPT
 * ------------------------------------
 *   access, language   No such columns on #__ats_tickets. Both are inherited from
 *                      the category; offering them would be a lie.
 *   assigned_to        Sends mail from inside store(). assign_ats_ticket owns that,
 *                      validates the target against canBeAssignedTickets() first and
 *                      wraps the save so URL building works.
 *   origin             Only 'web' and 'email' survive onBeforeCheck(); rewriting it
 *                      would misrepresent where the ticket came from.
 *   timespent          Derived. PostTable::onAfterStore() recomputes it as
 *                      SUM(timespent) over the ticket's enabled posts on the next
 *                      reply, so any value written here is temporary.
 *   modified,
 *   modified_by        These mean "last reply", not "last edited" — ATS comments say
 *                      so and keeps the assignment commented out in both
 *                      TicketTable::onBeforeStore() and TicketModel::prepareTable().
 *   status             set_ats_ticket_status owns it, and uses a path that cannot
 *                      collaterally rewrite public or priority. It is accepted here
 *                      too, for convenience, with the same warning attached.
 */
final class UpdateTicketTool extends AbstractTool
{
	use ATSBootTrait;

	public function getName(): string { return 'update_ats_ticket'; }

	public function getDescription(): string
	{
		return 'Update an existing Akeeba Ticket System ticket. Partial by design: only the keys you '
			. 'pass are changed, because the vendor model loads the row before binding. Fields you '
			. 'may safely omit — and which are guaranteed untouched when you do — are title, alias, '
			. 'catid, status, public, priority, enabled, created_by, custom_fields, tags and any '
			. 'column this tool does not expose. Required: id. '
			. 'FIELDS THIS TOOL REFUSES AND WHY: `access` and `language` are not columns on '
			. '#__ats_tickets at all — a ticket inherits both from its category, so set them on the '
			. 'category instead. `assigned_to` belongs to assign_ats_ticket, which validates the '
			. 'target and handles the notification mail the save would otherwise fire. `origin`, '
			. '`timespent`, `modified` and `modified_by` are vendor-managed: timespent is recomputed '
			. 'from the ticket\'s posts on every reply, and modified/modified_by record the last '
			. 'REPLY rather than the last edit. '
			. 'TWO VENDOR BEHAVIOURS YOU NEED TO KNOW ABOUT. (1) This tool always sends the ticket\'s '
			. 'category id with the save even if you did not ask to change it, because '
			. 'TicketModel::save() otherwise evaluates privacy rules and the category forcetype '
			. 'param against the com_ats component asset instead of the category asset, missing any '
			. 'Deny or forcetype set on the category. (2) If the ticket is currently PRIVATE and '
			. 'neither you nor the ticket\'s owner holds the ats.private privilege on its category, '
			. 'ANY full save publishes it — ATS injects public into the payload and then forces it to '
			. '1, whether or not you mentioned public. Being a category manager does not prevent '
			. 'this; the check tests ats.private alone. This tool refuses such an update outright '
			. 'rather than expose the ticket, and tells you which privilege to grant. '
			. 'Everything is reported back as READ FROM THE DATABASE after the save, with a warning '
			. 'for each field whose stored value differs from what you asked for — public, status, '
			. 'priority and alias can all be rewritten by TicketTable::onBeforeCheck().';
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
				'title' => [
					'type'        => 'string',
					'description' => 'New subject. Trimmed; must not be empty.',
				],
				'alias' => [
					'type'        => 'string',
					'description' => 'New URL slug. A colliding alias is given a numeric suffix by '
						. 'ATS; the stored value is reported back.',
				],
				'catid' => [
					'type'        => 'integer',
					'description' => 'Move the ticket to another ATS category. Validated against '
						. '#__categories WHERE extension = \'com_ats\'. Omit to keep the current one '
						. '— the tool sends it either way, for the reason given in the description.',
				],
				'status' => [
					'type'        => 'string',
					'description' => 'O, P, C, or a site-defined numeric status "1".."99". Prefer '
						. 'set_ats_ticket_status: it changes only the status column and cannot '
						. 'collaterally rewrite public or priority, which a full save can.',
				],
				'public' => [
					'type'        => 'integer',
					'enum'        => [0, 1],
					'description' => '1 = public, 0 = private. Overridden by the category\'s '
						. 'forcetype param when that is set. Prefer set_ats_ticket_public, which '
						. 'uses the vendor path that writes the column directly.',
				],
				'priority' => [
					'type'        => 'integer',
					'description' => 'TINYINT. 0 is not storable — it is falsy, and ATS replaces a '
						. 'falsy priority with 1 (private) or 5 (public). The save path applies no '
						. 'upper clamp, unlike the batch path which clamps to 0-10.',
				],
				'enabled' => [
					'type'        => 'integer',
					'enum'        => [0, 1],
					'description' => 'The publish flag. The column is named `enabled`; TicketTable '
						. 'aliases `published` onto it.',
				],
				'created_by' => [
					'type'        => 'integer',
					'description' => 'Change the recorded ticket owner. Restricted by this tool to '
						. 'callers with the ticket admin privilege, because ATS applies no check of '
						. 'its own on an update — TicketModel::prepareTable() only touches '
						. 'created_by when the record is new. Changing the owner changes who can see '
						. 'a private ticket.',
				],
				'custom_fields' => [
					'type'        => 'object',
					'description' => 'Joomla custom field values for context com_ats.ticket, keyed by '
						. 'field NAME. Fields you omit keep their current values.',
					'additionalProperties' => true,
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
		$ticket = $this->atsLoadTicket($id);

		if ($ticket === null) {
			return ToolResult::error('Ticket ' . $id . ' does not exist in #__ats_tickets.');
		}

		$privileges = $this->atsTicketPrivileges($ticket, $actor);

		if (empty($privileges['view'])) {
			return ToolResult::error('Ticket ' . $id . ' is not visible to you.');
		}

		if (empty($privileges['edit'])) {
			return ToolResult::error(
				'You do not have permission to edit ticket ' . $id . '. ATS grants edit through '
				. 'core.edit on the category, through ats.edit.own.ticket for the ticket owner, or '
				. 'for any owner within the component\'s editeableforxminutes grace window.'
			);
		}

		$currentCatid  = (int) $ticket->catid;
		$currentPublic = (int) $ticket->public;
		$targetCatid   = (int) ($arguments['catid'] ?? 0) ?: $currentCatid;

		$category = $this->atsCategory($targetCatid);

		if ($category === null) {
			return ToolResult::error(
				'Category ' . $targetCatid . ' is not an Akeeba Ticket System category. catid is a '
				. 'bare bigint with no foreign key, so a com_content category id would be stored '
				. 'happily and leave the ticket unlistable.'
			);
		}

		$catParams = new \Joomla\Registry\Registry((string) ($category['params'] ?? '{}'));
		$forceType = strtoupper(trim((string) $catParams->get('forcetype', '')));

		$requestedPublic = null;

		if (\array_key_exists('public', $arguments) && $arguments['public'] !== null) {
			$requestedPublic = (int) $arguments['public'] === 1 ? 1 : 0;
		}

		// --------------------------------------------------- the publish hazard
		// Resulting privacy if we save: the caller's value, or the current one.
		$effectivePrivate = ($requestedPublic ?? $currentPublic) === 0;

		if ($effectivePrivate && $forceType !== 'PUB') {
			$actorPrivate = (bool) \Akeeba\Component\ATS\Administrator\Helper\Permissions::getAclPrivileges(
				$targetCatid,
				(int) $actor->id
			)['ats.private'];

			$ownerPrivate = (bool) \Akeeba\Component\ATS\Administrator\Helper\Permissions::getAclPrivileges(
				$targetCatid,
				(int) $ticket->created_by
			)['ats.private'];

			if (!$actorPrivate && !$ownerPrivate) {
				return ToolResult::error(
					'Refusing to save ticket ' . $id . ', because the save would make a private '
					. 'ticket public. Neither you (user ' . (int) $actor->id . ') nor the ticket\'s '
					. 'owner (user ' . (int) $ticket->created_by . ') holds the ats.private '
					. 'privilege on com_ats.category.' . $targetCatid . '. ATS reacts to that in two '
					. 'places: TicketModel::save() injects public into the payload regardless of what '
					. 'you sent (TicketModel.php:796-802), and TicketTable::onBeforeCheck() then '
					. 'rewrites a falsy public to 1 (TicketTable.php:880-906). Being a category '
					. 'manager does not help — that second check tests ats.private alone, with no '
					. 'manager fallback. Grant ats.private on the category to whichever of those two '
					. 'users should have it, and retry. If you only want to change the ticket\'s '
					. 'visibility, set_ats_ticket_public writes the column directly and is not '
					. 'affected by this.'
				);
			}
		}

		// ---------------------------------------------------------------- status
		$requestedStatus = null;

		if (\array_key_exists('status', $arguments) && trim((string) $arguments['status']) !== '') {
			$requestedStatus = $this->atsNormaliseStatus((string) $arguments['status']);

			if ($requestedStatus === null) {
				return ToolResult::error(
					'"' . (string) $arguments['status'] . '" is not a status this site defines. Valid '
					. 'values are O, P, C and whichever of "1".."99" appear in the component\'s '
					. 'customStatuses param. ATS would not reject it — onBeforeCheck() silently '
					. 'rewrites an unknown status to O.'
				);
			}

			if (empty($privileges['edit.state'])) {
				return ToolResult::error(
					'Changing the status of ticket ' . $id . ' needs the edit.state privilege '
					. '(core.edit.state on the category, or category manager).'
				);
			}
		}

		// ------------------------------------------------------------ created_by
		$requestedOwner = (int) ($arguments['created_by'] ?? 0);

		if ($requestedOwner > 0) {
			if (empty($privileges['admin'])) {
				return ToolResult::error(
					'Changing created_by needs the ticket admin privilege (core.manage on the '
					. 'category). ATS performs no check of its own on an update — '
					. 'TicketModel::prepareTable() only sets created_by for a new record — so this '
					. 'tool imposes one. Reassigning ownership changes who can read a private ticket.'
				);
			}

			if (!$this->atsUserExists($requestedOwner)) {
				return ToolResult::error('created_by ' . $requestedOwner . ' is not a user in #__users.');
			}
		}

		// ---------------------------------------------------------------- payload
		$data = [
			'id'    => $id,
			// Always sent. See the class docblock, point 1.
			'catid' => $targetCatid,
		];

		if (\array_key_exists('title', $arguments)) {
			$title = trim((string) $arguments['title']);

			if ($title === '') {
				return ToolResult::error('title cannot be set to an empty string — ATS rejects it.');
			}

			$data['title'] = $title;
		}

		if (\array_key_exists('alias', $arguments)) {
			$data['alias'] = (string) $arguments['alias'];
		}

		if ($requestedStatus !== null) {
			$data['status'] = $requestedStatus;
		}

		if ($requestedPublic !== null) {
			$data['public'] = $requestedPublic;
		}

		$requestedPriority = null;

		if (\array_key_exists('priority', $arguments) && $arguments['priority'] !== null) {
			$requestedPriority = (int) $arguments['priority'];
			$data['priority']  = $requestedPriority;
		}

		if (\array_key_exists('enabled', $arguments) && $arguments['enabled'] !== null) {
			$data['enabled'] = (int) $arguments['enabled'] === 0 ? 0 : 1;
		}

		if ($requestedOwner > 0) {
			$data['created_by'] = $requestedOwner;
		}

		if (!empty($arguments['custom_fields']) && \is_array($arguments['custom_fields'])) {
			$data['com_fields'] = $arguments['custom_fields'];
		}

		if (\count($data) === 2 && empty($data['com_fields'])) {
			return ToolResult::error(
				'Nothing to update: only `id` was supplied. Pass at least one of title, alias, catid, '
				. 'status, public, priority, enabled, created_by or custom_fields.'
			);
		}

		$model = $this->atsModel('Ticket');

		if ($model === null) {
			return ToolResult::error('Could not create the ATS TicketModel through com_ats\' MVCFactory.');
		}

		$before = $this->atsReadTicketRow($id);

		// Wrapped with $actor: TicketTable::onChangedValues() can reach
		// EmailSending::sendAssignedEmails() from inside store() if assigned_to ends
		// up differing from what was loaded, and that builds a front-end URL. The ACL
		// checks and the mail send are the same call, so the wrapper has to carry the
		// identity — a container-built SiteApplication is a guest otherwise.
		$saved = $this->withSiteAppContext(
			fn(): array => $this->saveAdminModel($model, $data),
			$actor
		);

		$row = $this->atsReadTicketRow($id);

		if ($row === null) {
			return ToolResult::error(
				'Ticket ' . $id . ' is no longer in #__ats_tickets after the save attempt. Do not '
				. 'retry — inspect the table first.'
			);
		}

		if ((int) $saved['id'] <= 0 && $saved['ok'] === false && $before === $row) {
			return ToolResult::error(
				'The update did not take effect: ' . ($saved['error'] ?: 'TicketModel::save() reported '
				. 'no error.') . ' The row is unchanged, so retrying is safe.'
			);
		}

		$result = [
			'ok'       => true,
			'id'       => $id,
			'ticket'   => $row,
			'category' => [
				'id'        => (int) $category['id'],
				'title'     => (string) $category['title'],
				'forcetype' => $forceType === '' ? null : $forceType,
			],
			'note'     => 'Values are read back from #__ats_tickets after the save, not echoed from '
				. 'the request. Columns you did not pass are untouched: AdminModel::save() loads the '
				. 'row before binding, tags are only rewritten when a `tags` key is present, and '
				. 'Joomla\'s fields plugin skips any custom field absent from the payload. '
				. '`modified` / `modified_by` still hold the last REPLY, not this edit — ATS leaves '
				. 'them alone on an update by design.',
		];

		$warnings = [];

		if ($requestedPublic !== null && $row['public'] !== $requestedPublic) {
			$warnings[] = 'public was requested as ' . $requestedPublic . ' but stored as '
				. $row['public'] . '.'
				. ($forceType !== ''
					? ' The category\'s forcetype param is "' . $forceType . '", and '
					. 'TicketModel::save() overwrites public from it unconditionally '
					. '(TicketModel.php:803-806).'
					: '');
		}

		if ($requestedStatus !== null && (string) $row['status'] !== $requestedStatus) {
			$warnings[] = 'status was requested as "' . $requestedStatus . '" but stored as "'
				. $row['status'] . '". onBeforeCheck() rewrites an unrecognised status to O '
				. '(TicketTable.php:916-919), and the comparison is case-sensitive.';
		}

		if ($requestedPriority !== null && $row['priority'] !== $requestedPriority) {
			$warnings[] = 'priority was requested as ' . $requestedPriority . ' but stored as '
				. $row['priority'] . '. A falsy priority becomes 1 on a private ticket and 5 on a '
				. 'public one (TicketTable.php:927-939).';
		}

		if (\array_key_exists('alias', $arguments)
			&& (string) $row['alias'] !== (string) $arguments['alias']) {
			$warnings[] = 'alias was requested as "' . (string) $arguments['alias'] . '" but stored as "'
				. $row['alias'] . '" — ATS re-slugs it and appends a numeric suffix on collision.';
		}

		if ($before !== null && $before['public'] !== $row['public'] && $requestedPublic === null) {
			$warnings[] = 'public changed from ' . $before['public'] . ' to ' . $row['public']
				. ' even though it was not part of this request. That is the TicketModel/'
				. 'onBeforeCheck interaction described in the tool description — report this, it '
				. 'should have been caught before the save.';
		}

		if ($targetCatid !== $currentCatid) {
			$result['moved'] = 'Ticket moved from category ' . $currentCatid . ' to ' . $targetCatid
				. '. The ticket\'s effective access level and language changed with it — both are '
				. 'inherited from the category, not stored on the ticket.';
		}

		if ($saved['ok'] === false && $saved['error'] !== '') {
			$result['post_save_warning'] = 'The row was written but the model reported: '
				. $saved['error'] . '. AdminModel::save() returns false when any plugin in the '
				. 'onContentBeforeSave / onContentAfterSave chain throws, even though store() has '
				. 'already succeeded — which is why this tool trusts the row, not the return value. '
				. 'Do not retry on the strength of that message alone.';
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

		$all = \Akeeba\Component\ATS\Administrator\Helper\Permissions::getStatuses();

		return \array_key_exists($numeric, $all) ? (string) $numeric : null;
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
