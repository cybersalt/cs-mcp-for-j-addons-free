<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;

/**
 * One Akeeba Ticket System ticket, in full, without writing anything.
 *
 * WHY THIS TOOL DOES NOT GO ANYWHERE NEAR TicketModel::getForm().
 * ---------------------------------------------------------------
 * The obvious way to read a ticket's Joomla custom fields is to ask the model
 * for the edit form and read the com_fields group off it. On ATS that is a
 * mutation. TicketModel::getForm() ends with
 *
 *     if ($loadData && is_array($data) && isset($data['params']) && !$newTicket)
 *         $this->migrateLegacyCustomFieldsData($data['params'], $form, $item);
 *
 * (Model/TicketModel.php:601-604), and migrateLegacyCustomFieldsData() — which
 * exists to lift custom-field values out of the pre-5.0 `params` JSON blob and
 * into Joomla's own storage — finishes by calling TicketModel::setFieldValue()
 * for every migrated field (TicketModel.php:1244-1334). setFieldValue() is a
 * hand-rolled reimplementation of Joomla's FieldModel::setFieldValue, DELETEing
 * and then INSERTing rows in #__fields_values directly (TicketModel.php:1522-1596),
 * written that way precisely so it can bypass the ACL check and write on behalf
 * of whoever happens to be looking. The vendor's own comment says so: "I need to
 * migrate the data regardless of the current user. If a public ticket is accessed
 * by a guest I still need to migrate its data."
 *
 * That is fine for a component rendering a page. It is not fine for a tool whose
 * contract is "get". An MCP client that reads a ticket twice must not change the
 * database on the way past, and an agent must never be the thing that silently
 * triggers a data migration on a production site. So this tool reads
 * #__fields / #__fields_values directly and never instantiates the form. The
 * consequence is deliberate and worth stating in the output: on a site that was
 * upgraded from ATS 4 and has never opened a given ticket in the UI since, the
 * legacy values still sitting in the ticket's `params` blob will NOT have been
 * migrated, so they appear under `params` and not under `custom_fields`. We
 * surface both and let the caller see the discrepancy rather than "fixing" it.
 *
 * READING THE TICKET ITSELF IS ALSO NOT QUITE FREE.
 * -------------------------------------------------
 * TicketTable::onAfterLoad() calls ensureUcmRecord(), which INSERTs a
 * #__ucm_content row when one is missing (Table/TicketTable.php:710-719 and
 * 1008-1039). It self-disables above Joomla 5.4, but rather than depend on the
 * host's Joomla version we SELECT the row ourselves and hand it to the vendor's
 * bindAsLoadEquivalent(), which sets up the table exactly as load() would minus
 * the UCM write. The TicketTable is still needed, because
 * Permissions::getTicketPrivileges() is typed to take one.
 *
 * The visibility check is the vendor's, not ours, and a refusal deliberately
 * does not distinguish "no such ticket" from "not yours" — saying which would
 * turn this tool into an existence oracle for private tickets.
 */
final class GetTicketTool extends AbstractTool
{
	use ATSBootTrait;

	public function getName(): string { return 'get_ats_ticket'; }

	public function getDescription(): string
	{
		return 'Get one Akeeba Ticket System ticket (#__ats_tickets) in full. Required: id. Returns '
			. 'every column on the ticket (id, catid, title, alias, status + status_label, public, '
			. 'priority, origin, assigned_to, timespent, created, created_by, modified, modified_by, '
			. 'enabled, params) plus: the resolved category (title, alias, path, level, parent, access '
			. 'and access_title, language, published, and the ATS-specific category params that govern '
			. 'the ticket); the author and the assignee as id/name/username/email; `privileges`, the '
			. 'actor\'s full privilege array straight from the component\'s own '
			. 'Permissions::getTicketPrivileges() (view, post, edit, edit.state, delete, admin, close, '
			. 'private, attachment, the five notes.* keys, ticket.assign, ticket.assignee, '
			. 'ticket.invite); `invited` collaborators from #__ats_tickets_users; `custom_fields`, the '
			. 'Joomla custom fields for context com_ats.ticket with their stored values; `tags`; and '
			. '`posts_summary` with post_count, published_post_count, first_post_at, last_post_at and '
			. 'the authors of the first and last post. '
			. 'THINGS THAT WILL OTHERWISE MISLEAD YOU: (1) The ticket\'s OPENING MESSAGE is a row in '
			. '#__ats_posts, not a column here — there is no body field on a ticket, and post_count of '
			. '1 means "opened, never answered". (2) `modified` means LAST REPLY; ATS writes it only '
			. 'when a post is saved, never on a plain edit, so it is also returned as `last_reply`. '
			. '(3) `timespent` is derived — PostTable::onAfterStore() recomputes it as the SUM over the '
			. 'ticket\'s PUBLISHED posts, so unpublishing a post does not reduce it until the next '
			. 'reply is saved. (4) A ticket has NO access and NO language column; both come from the '
			. 'category. (5) attachment privileges are always false on ATS Core regardless of ACL '
			. 'setup, because the component forces them off. (6) On a site upgraded from ATS 4, custom '
			. 'field values may still be sitting unmigrated in `params` rather than in `custom_fields`; '
			. 'this tool reports both and deliberately does not trigger the vendor\'s migration, which '
			. 'is a database write hidden inside its edit-form code path. '
			. 'ACCESS: the actor must pass the component\'s own per-ticket view check. A refusal does '
			. 'not say whether the ticket exists. Since ATS 5.6.0 the category\'s view access level '
			. 'binds managers and Super Users too, so a privileged account can be refused a ticket in a '
			. 'category whose view level it does not hold.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['id'],
			'properties' => [
				'id' => ['type' => 'integer', 'description' => 'The #__ats_tickets id.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');

		if (!$this->atsBoot()) {
			return $this->notInstalledError();
		}

		$db = $this->db;

		$row = $db->setQuery(
			$db->getQuery(true)
				->select($db->quoteName('t') . '.*')
				->from($db->quoteName('#__ats_tickets', 't'))
				->where($db->quoteName('t.id') . ' = ' . $id)
		)->loadAssoc();

		if (!$row) {
			return $this->refusal($id);
		}

		// The vendor's gate. See the class docblock for why we bind rather than load().
		$table = $this->atsTable('Ticket');

		if ($table === null) {
			return $this->notInstalledError();
		}

		if (method_exists($table, 'bindAsLoadEquivalent')) {
			$table->bindAsLoadEquivalent($row);
		} else {
			$table->bind($row);
		}

		$privileges = $this->atsTicketPrivileges($table, $actor);

		if (empty($privileges['view'])) {
			return $this->refusal($id);
		}

		$catId    = (int) $row['catid'];
		$category = $this->loadCategory($catId);

		$status = (string) $row['status'];

		$ticket = [
			'id'           => (int) $row['id'],
			'catid'        => $catId,
			'title'        => (string) $row['title'],
			'alias'        => (string) $row['alias'],
			'status'       => $status,
			'status_label' => $this->atsStatusLabel($status),
			'public'       => (int) $row['public'],
			'priority'     => (int) $row['priority'],
			'origin'       => (string) $row['origin'],
			'assigned_to'  => (int) $row['assigned_to'],
			'timespent'    => (float) $row['timespent'],
			'created'      => $row['created'],
			'created_by'   => (int) $row['created_by'],
			'modified'     => $row['modified'],
			'last_reply'   => $row['modified'],
			'modified_by'  => (int) $row['modified_by'],
			'enabled'      => (int) $row['enabled'],
			'params'       => $this->decodeParams($row['params'] ?? null),
		];

		$customFields = $this->readCustomFields($id, $catId, $actor);

		return ToolResult::json([
			'ok'            => true,
			'id'            => $id,
			'ticket'        => $ticket,
			'category'      => $category,
			'author'        => $this->describeUser((int) $row['created_by']),
			'assignee'      => (int) $row['assigned_to'] > 0 ? $this->describeUser((int) $row['assigned_to']) : null,
			'last_modified_by' => (int) $row['modified_by'] > 0 ? $this->describeUser((int) $row['modified_by']) : null,
			'privileges'    => array_map(static fn($v): bool => (bool) $v, $privileges),
			'invited'       => $this->readInvited($id),
			'custom_fields' => $customFields['fields'],
			'tags'          => $this->readTags($id),
			'posts_summary' => $this->readPostsSummary($id),
			'note'          => [
				'opening_post'    => 'A ticket has no body column. Its opening message is the earliest row in #__ats_posts for this ticket id, which is why post_count includes it.',
				'modified'        => '`modified` / `last_reply` is written only when a post is saved — TicketTable::onBeforeStore() and TicketModel::prepareTable() both leave the usual assignment commented out. NULL means nobody has replied yet.',
				'timespent'       => 'Derived. PostTable::onAfterStore() sets it to SUM(timespent) over this ticket\'s posts WHERE enabled = 1, so unpublishing a post does not reduce the figure until the next reply is saved. Writing the ticket column directly is pointless.',
				'no_access_col'   => 'Tickets carry no access and no language column; the category block above is where those actually live.',
				'privileges'      => 'Straight from Permissions::getTicketPrivileges(). On ATS Core `attachment` is forced false whatever the ACL says. `close` is true only for the ticket owner on a non-closed ticket, or for a manager.',
				'custom_fields'   => $customFields['note'],
				'invited'         => '#__ats_tickets_users has no unique index and no index at all; duplicates are prevented only in PHP, and TicketTable::onAfterDelete() does not clean it, so rows can be orphaned by a deleted ticket.',
				'manager_notes'   => 'Private manager notes (#__ats_managernotes) are deliberately out of scope for this add-on. ATS fails closed on them via the edition check in TicketTable::managerNotes(); a direct query would not, so we do not make one.',
			],
		]);
	}

	/** Same message whether the ticket is missing or merely invisible — do not build an existence oracle. */
	private function refusal(int $id): ToolResult
	{
		return ToolResult::error(
			'Ticket ' . $id . ' does not exist, or you are not allowed to view it. '
			. 'Akeeba Ticket System decides this with Permissions::getTicketPrivileges(); since 5.6.0 '
			. 'the category\'s view access level applies to managers and Super Users as well as to '
			. 'ordinary users, so holding core.admin is not on its own enough.'
		);
	}

	/**
	 * The ticket's category, including the ATS-specific params that change how the
	 * ticket behaves. The extension predicate is mandatory: catid is a bare bigint
	 * with no foreign key, so without it a com_content category of the same id matches.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadCategory(int $catId): ?array
	{
		$db = $this->db;

		$cat = $db->setQuery(
			$db->getQuery(true)
				->select([
					$db->quoteName('c.id'),
					$db->quoteName('c.title'),
					$db->quoteName('c.alias'),
					$db->quoteName('c.path'),
					$db->quoteName('c.level'),
					$db->quoteName('c.parent_id'),
					$db->quoteName('c.published'),
					$db->quoteName('c.access'),
					$db->quoteName('c.language'),
					$db->quoteName('c.params'),
					$db->quoteName('v.title', 'access_title'),
				])
				->from($db->quoteName('#__categories', 'c'))
				->join('LEFT', $db->quoteName('#__viewlevels', 'v'), $db->quoteName('v.id') . ' = ' . $db->quoteName('c.access'))
				->where($db->quoteName('c.id') . ' = ' . $catId)
				->where($db->quoteName('c.extension') . ' = ' . $db->quote('com_ats'))
		)->loadAssoc();

		if (!$cat) {
			return null;
		}

		$params = $this->decodeParams($cat['params'] ?? null);

		return [
			'id'           => (int) $cat['id'],
			'title'        => (string) $cat['title'],
			'alias'        => (string) $cat['alias'],
			'path'         => (string) $cat['path'],
			'level'        => (int) $cat['level'],
			'parent_id'    => (int) $cat['parent_id'],
			'published'    => (int) $cat['published'],
			'access'       => (int) $cat['access'],
			'access_title' => $cat['access_title'] !== null ? (string) $cat['access_title'] : null,
			'language'     => (string) $cat['language'],
			'ats_params'   => [
				'forcetype'        => $params['forcetype'] ?? '',
				'defaultprivate'   => $params['defaultprivate'] ?? null,
				'default_priority' => $params['default_priority'] ?? '',
				'defposttext'      => $params['defposttext'] ?? '',
				'category_email'   => $params['category_email'] ?? '',
				'notify_managers'  => $params['notify_managers'] ?? null,
				'exclude_managers' => $params['exclude_managers'] ?? null,
			],
		];
	}

	/**
	 * Joomla custom fields for context com_ats.ticket, read straight out of the
	 * tables. See the class docblock for why we do not go through getForm().
	 *
	 * Field-to-category assignment follows Joomla's own rule (FieldsModel.php:194-265):
	 * a field with no row in #__fields_categories applies everywhere, otherwise it
	 * applies to the assigned categories AND their descendants — so the ticket's
	 * category matches if it, or any of its ancestors, is assigned. Ancestors come
	 * from the nested-set columns rather than a recursive walk.
	 *
	 * @return array{fields: array<int, array<string, mixed>>, note: string}
	 */
	private function readCustomFields(int $ticketId, int $catId, User $actor): array
	{
		$db = $this->db;

		$fields = $db->setQuery(
			$db->getQuery(true)
				->select([
					$db->quoteName('f.id'),
					$db->quoteName('f.name'),
					$db->quoteName('f.title'),
					$db->quoteName('f.label'),
					$db->quoteName('f.type'),
					$db->quoteName('f.state'),
					$db->quoteName('f.required'),
					$db->quoteName('f.access'),
					$db->quoteName('f.group_id'),
					$db->quoteName('f.language'),
					$db->quoteName('f.default_value'),
				])
				->from($db->quoteName('#__fields', 'f'))
				->where($db->quoteName('f.context') . ' = ' . $db->quote('com_ats.ticket'))
				->order($db->quoteName('f.ordering') . ' ASC')
				->order($db->quoteName('f.id') . ' ASC')
		)->loadAssocList() ?: [];

		if (empty($fields)) {
			return [
				'fields' => [],
				'note'   => 'No Joomla custom fields are defined for the com_ats.ticket context on this site. '
					. 'ATS custom fields are Joomla-native (#__fields / #__fields_values); they are not an '
					. 'ATS table. Read directly here rather than through TicketModel::getForm(), which '
					. 'writes to #__fields_values as a side effect.',
			];
		}

		$fieldIds = array_map(static fn(array $f): int => (int) $f['id'], $fields);
		$in       = implode(',', $fieldIds);

		// Which categories each field is pinned to, if any.
		$assignments = [];

		foreach ($db->setQuery(
			$db->getQuery(true)
				->select([$db->quoteName('field_id'), $db->quoteName('category_id')])
				->from($db->quoteName('#__fields_categories'))
				->where($db->quoteName('field_id') . ' IN (' . $in . ')')
		)->loadAssocList() ?: [] as $assignment) {
			$assignments[(int) $assignment['field_id']][] = (int) $assignment['category_id'];
		}

		$applicableCategories = $this->categoryAndAncestors($catId);

		// Values. item_id is a varchar, so it must be quoted as a string. A field can
		// legitimately have several rows here (checkboxes, multi-selects).
		$values = [];

		foreach ($db->setQuery(
			$db->getQuery(true)
				->select([$db->quoteName('field_id'), $db->quoteName('value')])
				->from($db->quoteName('#__fields_values'))
				->where($db->quoteName('field_id') . ' IN (' . $in . ')')
				->where($db->quoteName('item_id') . ' = ' . $db->quote((string) $ticketId))
		)->loadAssocList() ?: [] as $valueRow) {
			$values[(int) $valueRow['field_id']][] = $valueRow['value'];
		}

		$viewLevels  = array_map('intval', $actor->getAuthorisedViewLevels());
		$out         = [];
		$withheld    = 0;
		$unpublished = 0;

		foreach ($fields as $field) {
			$fieldId = (int) $field['id'];

			// Not assigned to this ticket's category tree: it does not apply here at all.
			if (isset($assignments[$fieldId])
				&& empty(array_intersect($assignments[$fieldId], $applicableCategories))) {
				continue;
			}

			if (!\in_array((int) $field['access'], $viewLevels, true)) {
				$withheld++;

				continue;
			}

			if ((int) $field['state'] !== 1) {
				$unpublished++;
			}

			$raw = $values[$fieldId] ?? [];

			$out[] = [
				'id'            => $fieldId,
				'name'          => (string) $field['name'],
				'title'         => (string) $field['title'],
				'label'         => (string) $field['label'],
				'type'          => (string) $field['type'],
				'state'         => (int) $field['state'],
				'required'      => (int) $field['required'],
				'access'        => (int) $field['access'],
				'group_id'      => (int) $field['group_id'],
				'language'      => (string) $field['language'],
				'default_value' => $field['default_value'],
				'has_value'     => !empty($raw),
				'value'         => \count($raw) === 1 ? $raw[0] : ($raw === [] ? null : $raw),
			];
		}

		$note = 'Read directly from #__fields / #__fields_values (context com_ats.ticket, item_id = the '
			. 'ticket id), NOT through TicketModel::getForm(). getForm() calls '
			. 'migrateLegacyCustomFieldsData(), which DELETEs and INSERTs rows in #__fields_values as a '
			. 'side effect, so a "get" must not use it. Consequence: on a site upgraded from ATS 4 where '
			. 'this ticket has not been opened in the UI since, legacy values may still be in the '
			. 'ticket\'s `params` blob and will show there rather than here. A field with several rows '
			. 'in #__fields_values (checkbox / multi-select) returns `value` as an array.';

		if ($withheld > 0) {
			$note .= ' ' . $withheld . ' field(s) were withheld because their view access level is not one '
				. 'you hold.';
		}

		if ($unpublished > 0) {
			$note .= ' ' . $unpublished . ' field(s) have state != 1; ATS does not render those, but their '
				. 'stored values are still shown here.';
		}

		return ['fields' => $out, 'note' => $note];
	}

	/**
	 * The category id plus every ancestor id, from the nested-set columns.
	 *
	 * @return array<int, int>
	 */
	private function categoryAndAncestors(int $catId): array
	{
		if ($catId <= 0) {
			return [];
		}

		$db = $this->db;

		$node = $db->setQuery(
			$db->getQuery(true)
				->select([$db->quoteName('lft'), $db->quoteName('rgt')])
				->from($db->quoteName('#__categories'))
				->where($db->quoteName('id') . ' = ' . $catId)
				->where($db->quoteName('extension') . ' = ' . $db->quote('com_ats'))
		)->loadAssoc();

		if (!$node) {
			return [$catId];
		}

		$ancestors = $db->setQuery(
			$db->getQuery(true)
				->select($db->quoteName('id'))
				->from($db->quoteName('#__categories'))
				->where($db->quoteName('extension') . ' = ' . $db->quote('com_ats'))
				->where($db->quoteName('lft') . ' < ' . (int) $node['lft'])
				->where($db->quoteName('rgt') . ' > ' . (int) $node['rgt'])
		)->loadColumn() ?: [];

		return array_map('intval', array_merge([$catId], $ancestors));
	}

	/**
	 * Invited collaborators. Core feature, despite how much of ATS' collaboration
	 * surface is Pro.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function readInvited(int $ticketId): array
	{
		$db = $this->db;

		$rows = $db->setQuery(
			$db->getQuery(true)
				->select([
					$db->quoteName('iu.user_id'),
					$db->quoteName('u.name'),
					$db->quoteName('u.username'),
					$db->quoteName('u.email'),
					$db->quoteName('u.block'),
				])
				->from($db->quoteName('#__ats_tickets_users', 'iu'))
				->join('LEFT', $db->quoteName('#__users', 'u'), $db->quoteName('u.id') . ' = ' . $db->quoteName('iu.user_id'))
				->where($db->quoteName('iu.ticket_id') . ' = ' . $ticketId)
				->order($db->quoteName('iu.id') . ' ASC')
		)->loadAssocList() ?: [];

		return array_map(static function (array $r): array {
			return [
				'user_id'  => (int) $r['user_id'],
				'name'     => $r['name'] !== null ? (string) $r['name'] : null,
				'username' => $r['username'] !== null ? (string) $r['username'] : null,
				'email'    => $r['email'] !== null ? (string) $r['email'] : null,
				'blocked'  => $r['block'] !== null ? (int) $r['block'] : null,
				// A NULL name means the invitation outlived the user account: nothing
				// deletes these rows when a user goes away.
				'orphaned' => $r['username'] === null,
			];
		}, $rows);
	}

	/**
	 * Ticket tags. Joomla-native, type_alias com_ats.ticket. ATS Professional also
	 * has a "user tags" feature — a completely different thing, not these.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function readTags(int $ticketId): array
	{
		$db = $this->db;

		$rows = $db->setQuery(
			$db->getQuery(true)
				->select([
					$db->quoteName('tg.id'),
					$db->quoteName('tg.title'),
					$db->quoteName('tg.alias'),
					$db->quoteName('tg.path'),
				])
				->from($db->quoteName('#__contentitem_tag_map', 'm'))
				->join('INNER', $db->quoteName('#__tags', 'tg'), $db->quoteName('tg.id') . ' = ' . $db->quoteName('m.tag_id'))
				->where($db->quoteName('m.type_alias') . ' = ' . $db->quote('com_ats.ticket'))
				->where($db->quoteName('m.content_item_id') . ' = ' . $ticketId)
				->order($db->quoteName('tg.title') . ' ASC')
		)->loadAssocList() ?: [];

		return array_map(static fn(array $r): array => [
			'id'    => (int) $r['id'],
			'title' => (string) $r['title'],
			'alias' => (string) $r['alias'],
			'path'  => (string) $r['path'],
		], $rows);
	}

	/**
	 * Post counts and the first/last post timestamps. The first post IS the ticket's
	 * opening message.
	 *
	 * @return array<string, mixed>
	 */
	private function readPostsSummary(int $ticketId): array
	{
		$db = $this->db;

		$summary = $db->setQuery(
			$db->getQuery(true)
				->select([
					'COUNT(*) AS ' . $db->quoteName('total'),
					'SUM(CASE WHEN ' . $db->quoteName('enabled') . ' = 1 THEN 1 ELSE 0 END) AS ' . $db->quoteName('published'),
					'MIN(' . $db->quoteName('created') . ') AS ' . $db->quoteName('first_post_at'),
					'MAX(' . $db->quoteName('created') . ') AS ' . $db->quoteName('last_post_at'),
					'SUM(' . $db->quoteName('timespent') . ') AS ' . $db->quoteName('timespent_all_posts'),
				])
				->from($db->quoteName('#__ats_posts'))
				->where($db->quoteName('ticket_id') . ' = ' . $ticketId)
		)->loadAssoc() ?: [];

		$edge = static function (string $direction) use ($db, $ticketId): ?array {
			$row = $db->setQuery(
				$db->getQuery(true)
					->select([$db->quoteName('id'), $db->quoteName('created'), $db->quoteName('created_by'), $db->quoteName('enabled')])
					->from($db->quoteName('#__ats_posts'))
					->where($db->quoteName('ticket_id') . ' = ' . $ticketId)
					->order($db->quoteName('id') . ' ' . $direction),
				0,
				1
			)->loadAssoc();

			return $row ?: null;
		};

		$first = $edge('ASC');
		$last  = $edge('DESC');

		$describeEdge = function (?array $post): ?array {
			if ($post === null) {
				return null;
			}

			return [
				'post_id'    => (int) $post['id'],
				'created'    => $post['created'],
				'created_by' => (int) $post['created_by'],
				'enabled'    => (int) $post['enabled'],
				'author'     => $this->describeUser((int) $post['created_by']),
			];
		};

		return [
			'post_count'           => (int) ($summary['total'] ?? 0),
			'published_post_count' => (int) ($summary['published'] ?? 0),
			'first_post_at'        => $summary['first_post_at'] ?? null,
			'last_post_at'         => $summary['last_post_at'] ?? null,
			'timespent_all_posts'  => (float) ($summary['timespent_all_posts'] ?? 0),
			'first_post'           => $describeEdge($first),
			'last_post'            => $describeEdge($last),
			'note'                 => 'first_post is the ticket\'s opening message. timespent_all_posts sums '
				. 'EVERY post including unpublished ones, which is why it can differ from the ticket\'s '
				. 'own timespent column — that one counts published posts only, and only as of the last '
				. 'reply. created_by of 0 or less is not a real account: ATS fabricates a "system" user '
				. 'for id -1 (Permissions::getUser(-1)) and those rows do not join to #__users.',
		];
	}

	/**
	 * Resolve a user id for output, without pretending an automated post has an account.
	 *
	 * @return array<string, mixed>
	 */
	private function describeUser(int $userId): array
	{
		if ($userId <= 0) {
			return [
				'id'       => $userId,
				'name'     => null,
				'username' => null,
				'email'    => null,
				'system'   => true,
				'note'     => 'created_by <= 0 marks an automated/system entry. ATS fabricates a user with '
					. 'username "system" for id -1; there is no matching #__users row.',
			];
		}

		$db = $this->db;

		$row = $db->setQuery(
			$db->getQuery(true)
				->select([$db->quoteName('id'), $db->quoteName('name'), $db->quoteName('username'), $db->quoteName('email'), $db->quoteName('block')])
				->from($db->quoteName('#__users'))
				->where($db->quoteName('id') . ' = ' . $userId)
		)->loadAssoc();

		if (!$row) {
			return [
				'id'       => $userId,
				'name'     => null,
				'username' => null,
				'email'    => null,
				'system'   => false,
				'note'     => 'No #__users row with this id — the account was deleted after the ticket was created.',
			];
		}

		return [
			'id'       => (int) $row['id'],
			'name'     => (string) $row['name'],
			'username' => (string) $row['username'],
			'email'    => (string) $row['email'],
			'blocked'  => (int) $row['block'],
			'system'   => false,
		];
	}

	/** @return array<string, mixed> */
	private function decodeParams(?string $params): array
	{
		if ($params === null || trim($params) === '') {
			return [];
		}

		$decoded = json_decode($params, true);

		return \is_array($decoded) ? $decoded : [];
	}
}
