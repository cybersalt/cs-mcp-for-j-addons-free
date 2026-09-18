<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Posts;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;

/**
 * List #__ats_posts — the replies on a ticket, including the ticket's opening message.
 *
 * WHY THE PERMISSION GATE IS A SECOND PASS, NOT A JOIN
 * ----------------------------------------------------
 * A post carries no access control of its own. Every privilege it has is the
 * parent ticket's: Permissions::getPostPrivileges() opens by calling
 * $post->getTicket() and deriving everything from the ticket's category
 * (Permissions.php:789-805). So the only honest filter is the ticket's own
 * `view` privilege, and that is a PHP predicate — Permissions::getTicketPrivileges()
 * combines ownership, an invitation row, the category's view access level and the
 * ticket's `public` flag (Permissions.php:649-652). It cannot be expressed as a
 * WHERE clause without re-deriving it, and re-deriving it by hand is exactly the
 * bug ATS 5.6.0 shipped to fix: the list query and the single-resource check
 * disagreed, and private tickets leaked through the JSON:API. So we filter in SQL
 * for cheapness and then run every candidate row's ticket through the vendor's
 * own helper, reporting how many rows that withheld so the count stays honest.
 *
 * WHY created_by IS NOT JUST A JOIN
 * ---------------------------------
 * Permissions::getUser(-1) fabricates a user with username 'system'
 * (Permissions.php:1024-1033) — it is not a row in #__users, so the LEFT JOIN
 * produces NULL for it. ATS writes created_by <= 0 for automated notices, and
 * PostTable::onAfterStore() treats those specially (they never move the ticket
 * status). Those rows are labelled here rather than presented as a broken join.
 *
 * ATTACHMENTS ARE DELIBERATELY NOT RESOLVED
 * -----------------------------------------
 * #__ats_posts.attachment_id is a comma-separated list in a singular-named
 * VARCHAR(512), not a foreign key. PostTable::getAttachments() returns [] on a
 * Core install before it looks at anything (PostTable.php:112-115), and
 * #__ats_attachments is never written on Core, so resolving the list would either
 * return nothing or contradict the component. The raw column is returned as-is
 * and the response says why.
 */
final class ListPostsTool extends AbstractTool
{
	use ATSBootTrait;

	private const ORDER_COLUMNS = ['created', 'id', 'timespent', 'modified'];

	public function getName(): string { return 'list_ats_posts'; }

	public function getDescription(): string
	{
		return 'List Akeeba Ticket System posts (#__ats_posts) — the replies on a ticket. '
			. 'IMPORTANT: a ticket\'s opening message is a POST, not a column on the ticket, so the '
			. 'oldest row for a ticket_id is the customer\'s original question. '
			. 'Returns per row: id, ticket_id, ticket_title, ticket_status, ticket_status_label, '
			. 'content_html (HTML, optionally truncated), content_truncated, content_length, origin '
			. '("web" or "email"), timespent (float, hours/minutes as configured), created, created_by, '
			. 'created_by_name, created_by_username, is_system_post, modified, modified_by, enabled '
			. '(1 = published), and attachment_id (the raw comma-separated string — see below). '
			. 'FILTERS: ticket_id (strongly recommended — this table gets large), created_by (pass a '
			. 'negative value or 0 to find automated/system posts), origin, include_unpublished '
			. '(default false, so unpublished posts are hidden unless you ask), search (content_html '
			. 'LIKE %term%), created_after / created_before (SQL datetime, UTC). '
			. 'ORDERING: order_by one of created, id, timespent, modified (default created), order_dir '
			. 'ASC or DESC (default ASC, i.e. conversation order). Default limit 100, max 500. '
			. 'PERMISSIONS: every row is re-checked against the parent ticket\'s "view" privilege using '
			. 'ATS\' own Permissions::getTicketPrivileges(), because a post has no ACL of its own. That '
			. 'check happens AFTER the SQL limit, so `count` can be lower than `limit` even when more '
			. 'rows exist; `withheld_for_permissions` tells you how many rows this page dropped. '
			. 'ATTACHMENTS: attachment_id is a comma-separated list of ids, not a foreign key, and it is '
			. 'NOT resolved here — attachments are an ATS Professional feature and PostTable::getAttachments() '
			. 'returns an empty array on a Core install, so there would be nothing to resolve. '
			. 'TIMESPENT: the per-post value is authoritative; the parent ticket\'s timespent is a derived '
			. 'SUM over published posts and is only recomputed when the next new reply is saved.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'ticket_id' => [
					'type'        => 'integer',
					'description' => 'Restrict to one ticket. Strongly recommended; without it you get a site-wide slice of the reply table.',
				],
				'created_by' => [
					'type'        => 'integer',
					'description' => 'Exact match on the author user id. Values <= 0 are automated/system posts (ATS fabricates a "system" user for -1); those rows never join to #__users.',
				],
				'origin' => [
					'type'        => 'string',
					'enum'        => ['web', 'email'],
					'description' => '"email" only ever appears on sites running the ATS Professional mail gateway.',
				],
				'include_unpublished' => [
					'type'        => 'boolean',
					'description' => 'Default false. Unpublished posts (enabled = 0) are excluded unless this is true. An unpublished post is excluded from the ticket timespent SUM.',
				],
				'search' => [
					'type'        => 'string',
					'description' => 'content_html LIKE %term%. Matches raw HTML, so a term split by markup will not be found.',
				],
				'created_after' => [
					'type'        => 'string',
					'description' => 'SQL datetime in UTC, e.g. "2026-01-31 00:00:00". Inclusive.',
				],
				'created_before' => [
					'type'        => 'string',
					'description' => 'SQL datetime in UTC. Inclusive.',
				],
				'include_content' => [
					'type'        => 'boolean',
					'description' => 'Default true. Set false for a cheap index of a long thread (content_length is still returned).',
				],
				'content_max_chars' => [
					'type'        => 'integer',
					'description' => 'Truncate content_html to this many characters. Default 4000, 0 means no truncation. Truncation is byte-naive about HTML, so a truncated value may contain an unclosed tag.',
				],
				'order_by'  => ['type' => 'string', 'enum' => self::ORDER_COLUMNS, 'description' => 'Default created.'],
				'order_dir' => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'description' => 'Default ASC (oldest first = conversation order).'],
				'limit'     => ['type' => 'integer', 'description' => 'Default 100, max 500. Applied before the permission gate.'],
				'offset'    => ['type' => 'integer', 'description' => 'Default 0.'],
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

		$db = $this->db;

		$query = $db->getQuery(true)
			->select([
				$db->quoteName('p.id'),
				$db->quoteName('p.ticket_id'),
				$db->quoteName('p.content_html'),
				$db->quoteName('p.origin'),
				$db->quoteName('p.timespent'),
				$db->quoteName('p.email_uid'),
				$db->quoteName('p.attachment_id'),
				$db->quoteName('p.created'),
				$db->quoteName('p.created_by'),
				$db->quoteName('p.modified'),
				$db->quoteName('p.modified_by'),
				$db->quoteName('p.enabled'),
				$db->quoteName('u.name', 'created_by_name'),
				$db->quoteName('u.username', 'created_by_username'),
			])
			->from($db->quoteName('#__ats_posts', 'p'))
			->leftJoin(
				$db->quoteName('#__users', 'u')
				. ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('p.created_by')
			);

		if (!empty($arguments['ticket_id'])) {
			$query->where($db->quoteName('p.ticket_id') . ' = ' . (int) $arguments['ticket_id']);
		}

		if (\array_key_exists('created_by', $arguments) && $arguments['created_by'] !== null) {
			$query->where($db->quoteName('p.created_by') . ' = ' . (int) $arguments['created_by']);
		}

		if (!empty($arguments['origin'])) {
			$query->where($db->quoteName('p.origin') . ' = ' . $db->quote((string) $arguments['origin']));
		}

		$includeUnpublished = (bool) ($arguments['include_unpublished'] ?? false);

		if (!$includeUnpublished) {
			$query->where($db->quoteName('p.enabled') . ' = 1');
		}

		if (!empty($arguments['search'])) {
			$search = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $arguments['search']) . '%';
			$query->where($db->quoteName('p.content_html') . ' LIKE ' . $db->quote($search));
		}

		if (!empty($arguments['created_after'])) {
			$query->where($db->quoteName('p.created') . ' >= ' . $db->quote((string) $arguments['created_after']));
		}

		if (!empty($arguments['created_before'])) {
			$query->where($db->quoteName('p.created') . ' <= ' . $db->quote((string) $arguments['created_before']));
		}

		$orderBy  = (string) ($arguments['order_by'] ?? 'created');
		$orderDir = strtoupper((string) ($arguments['order_dir'] ?? 'ASC'));

		if (!\in_array($orderBy, self::ORDER_COLUMNS, true)) {
			$orderBy = 'created';
		}

		if (!\in_array($orderDir, ['ASC', 'DESC'], true)) {
			$orderDir = 'ASC';
		}

		// `created` is nullable and ATS writes it from PHP, so two posts can share a
		// timestamp. Tie-break on the primary key to keep paging stable.
		$query->order($db->quoteName('p.' . $orderBy) . ' ' . $orderDir)
			->order($db->quoteName('p.id') . ' ' . $orderDir);

		$limit  = max(1, min(500, (int) ($arguments['limit'] ?? 100)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));

		$db->setQuery($query, $offset, $limit);
		$rows = $db->loadAssocList() ?: [];

		$includeContent = (bool) ($arguments['include_content'] ?? true);
		$maxChars       = (int) ($arguments['content_max_chars'] ?? 4000);
		$maxChars       = $maxChars < 0 ? 0 : $maxChars;

		$ticketCache = [];
		$posts       = [];
		$withheld    = 0;

		foreach ($rows as $row) {
			$ticketId = (int) $row['ticket_id'];

			if (!\array_key_exists($ticketId, $ticketCache)) {
				$ticket = $this->atsLoadTicket($ticketId);

				$ticketCache[$ticketId] = [
					'ticket'  => $ticket,
					'canView' => $ticket !== null && $this->atsCanViewTicket($ticket, $actor),
				];
			}

			// An orphaned post (its ticket row is gone) is withheld rather than shown:
			// there is no ticket to derive a privilege from, so there is no basis to
			// return it. check_ats_health is the tool that reports orphans.
			if (!$ticketCache[$ticketId]['canView']) {
				$withheld++;

				continue;
			}

			$posts[] = $this->shapeRow($row, $ticketCache[$ticketId]['ticket'], $includeContent, $maxChars);
		}

		$total = (int) $db->setQuery(
			$db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__ats_posts'))
		)->loadResult();

		return ToolResult::json([
			'ok'                       => true,
			'count'                    => \count($posts),
			'limit'                    => $limit,
			'offset'                   => $offset,
			'withheld_for_permissions' => $withheld,
			'total_unfiltered'         => $total,
			'include_unpublished'      => $includeUnpublished,
			'posts'                    => $posts,
			'note'                     => 'A ticket\'s opening message is the oldest post for that ticket_id, not a ticket column. '
				. 'Rows are gated on the parent ticket\'s "view" privilege AFTER the SQL limit, so count may be '
				. 'lower than limit; withheld_for_permissions is that difference (it also counts posts whose '
				. 'ticket row no longer exists). attachment_id is a raw comma-separated list and is NOT resolved: '
				. 'attachments are an ATS Professional feature and PostTable::getAttachments() returns an empty '
				. 'array on Core, so #__ats_attachments holds no rows to resolve to. created_by <= 0 marks an '
				. 'automated post — ATS fabricates a "system" user for -1, so those rows never join to #__users.',
		]);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function shapeRow(array $row, object $ticket, bool $includeContent, int $maxChars): array
	{
		$content   = (string) ($row['content_html'] ?? '');
		$length    = \strlen($content);
		$truncated = false;

		if ($includeContent && $maxChars > 0 && $length > $maxChars) {
			$content   = substr($content, 0, $maxChars);
			$truncated = true;
		}

		$createdBy = (int) $row['created_by'];
		$status    = (string) $ticket->status;

		return [
			'id'                   => (int) $row['id'],
			'ticket_id'            => (int) $row['ticket_id'],
			'ticket_title'         => (string) $ticket->title,
			'ticket_status'        => $status,
			'ticket_status_label'  => $this->atsStatusLabel($status),
			'content_html'         => $includeContent ? $content : null,
			'content_truncated'    => $includeContent ? $truncated : null,
			'content_length'       => $length,
			'origin'               => $row['origin'] === null ? null : (string) $row['origin'],
			'timespent'            => (float) $row['timespent'],
			'email_uid'            => $row['email_uid'] === null ? null : (string) $row['email_uid'],
			'attachment_id'        => (string) $row['attachment_id'],
			'created'              => $row['created'],
			'created_by'           => $createdBy,
			'created_by_name'      => $this->authorName($createdBy, $row['created_by_name']),
			'created_by_username'  => $createdBy === -1 ? 'system' : ($row['created_by_username'] ?? null),
			'is_system_post'       => $createdBy <= 0,
			'modified'             => $row['modified'],
			'modified_by'          => (int) $row['modified_by'],
			'enabled'              => (int) $row['enabled'],
		];
	}

	private function authorName(int $createdBy, mixed $joined): string
	{
		if ($createdBy === -1) {
			return 'system (automated post)';
		}

		if ($createdBy === 0) {
			return 'guest / unattributed';
		}

		if ($createdBy < 0) {
			return 'system (automated post, created_by ' . $createdBy . ')';
		}

		return $joined === null
			? 'deleted user (id ' . $createdBy . ')'
			: (string) $joined;
	}
}
