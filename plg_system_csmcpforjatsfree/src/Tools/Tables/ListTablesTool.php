<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Tables;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;

/**
 * The allowlist, with live row counts — and the one table that is not on it.
 *
 * This is the inventory half of the escape hatch; query_ats_table is the other
 * half, and it is scoped to exactly the tables listed here. The allowlist lives
 * in ATSBootTrait::ATS_TABLES so both tools read one copy and neither can drift.
 *
 * WHY #__ats_managernotes IS ABSENT, AND WHY THAT IS NOT AN OVERSIGHT.
 * --------------------------------------------------------------------
 * ATS' installer creates every table unconditionally — there is no edition
 * branching in install.mysql.utf8.sql at all — so #__ats_attachments,
 * #__ats_cannedreplies and #__ats_autoreplies all exist on a Core install and
 * are simply empty. For those three, "Pro-only therefore empty" holds, and a
 * SELECT is harmless.
 *
 * #__ats_managernotes is the one table where that reasoning is FALSE. Manager
 * notes are private staff-only commentary about customers, and a site that ran
 * ATS Professional and later dropped to Core keeps every note it ever wrote:
 * the downgrade removes code, not data. ATS itself fails closed there —
 * TicketTable::managerNotes() (TicketTable.php:458-463) returns an empty array
 * on the edition check BEFORE it builds a query, so the notes are invisible in
 * the Core interface. A generic SELECT would NOT fail closed. It would hand
 * private notes to anyone who could name the table, on precisely the installs
 * where the component has decided nobody should see them.
 *
 * So this add-on reads notes only through managerNotes(), the query tool
 * refuses the table by name rather than pretending not to know it, and
 * check_ats_health reports only whether it is non-empty — as a count, never
 * content. That is a privacy decision, made once, and it is the reason the
 * list below has six entries rather than seven.
 *
 * Two further tables are absent for a duller reason: #__ats_credittransactions
 * and #__ats_creditconsumptions are DROPped by ATS 5's own installer
 * (install.mysql.utf8.sql:10-11), so querying them errors rather than returning
 * nothing.
 */
final class ListTablesTool extends AbstractTool
{
	use ATSBootTrait;

	public function getName(): string { return 'list_ats_tables'; }

	public function getDescription(): string
	{
		return 'Inventory of the Akeeba Ticket System tables this add-on can read, with live exact row '
			. 'counts, engine, collation, approximate size and a note on what is surprising about each one. '
			. 'This list is also the allowlist for query_ats_table — that tool can be pointed at these '
			. 'tables and nothing else, not #__users, not #__session, not #__extensions. '
			. 'SIX TABLES: ats_tickets, ats_posts, ats_tickets_users, ats_attachments, ats_cannedreplies, '
			. 'ats_autoreplies. '
			. '#__ats_managernotes IS DELIBERATELY NOT ON THE LIST, and that is a privacy decision rather '
			. 'than an omission. ATS creates every table unconditionally at install time, so the three '
			. 'Pro-only ones above exist and are simply empty on a Core site — harmless to SELECT. Manager '
			. 'notes are the one case where "Pro-only therefore empty" is FALSE: a site downgraded from '
			. 'Professional to Core keeps every private staff note it ever wrote, and ATS fails closed by '
			. 'returning an empty array on the edition check before it even builds a query '
			. '(TicketTable::managerNotes()). A generic SELECT would not fail closed. query_ats_table '
			. 'refuses that table by name with an explanation, and check_ats_health reports only whether it '
			. 'is non-empty, as a count. '
			. 'ALSO ABSENT: #__ats_credittransactions and #__ats_creditconsumptions, which ATS 5\'s own '
			. 'installer DROPs, so they do not exist to query. '
			. 'THE PRO-ONLY TABLES BEING NON-EMPTY ON A CORE SITE IS ITSELF A FINDING — it means the site '
			. 'was downgraded from Professional and is holding data its interface will never show. This '
			. 'tool flags it. '
			. 'Row counts are exact COUNT(*), not information_schema estimates. Pass include_columns to get '
			. 'each table\'s real column list alongside.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'include_columns' => [
					'type' => 'boolean',
					'description' => 'Return each table\'s real column list (name, type, nullability, key, '
						. 'default) read from SHOW FULL COLUMNS. Default false.',
				],
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

		$withColumns = (bool) ($arguments['include_columns'] ?? false);
		$prefix      = $this->db->getPrefix();
		$isPro       = $this->atsIsPro();

		$meta = [];

		try {
			$quoted = implode(', ', array_map(
				fn (string $t): string => $this->db->quote($prefix . $t),
				array_keys(self::ATS_TABLES)
			));

			foreach ($this->db->setQuery(
				'SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, TABLE_COLLATION'
				. ' FROM information_schema.TABLES'
				. ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $quoted . ')'
			)->loadAssocList() ?: [] as $row) {
				$meta[(string) $row['TABLE_NAME']] = $row;
			}
		} catch (\Throwable) {
			$meta = [];
		}

		$tables    = [];
		$missing   = [];
		$leftovers = [];
		$proOnly   = ['ats_attachments', 'ats_cannedreplies', 'ats_autoreplies'];

		foreach (self::ATS_TABLES as $suffix => $note) {
			$full   = $prefix . $suffix;
			$exists = isset($meta[$full]);
			$rows   = $this->countRows($full);

			if ($rows === null && !$exists) {
				$missing[] = $suffix;
			}

			$entry = [
				'table'     => $suffix,
				'full_name' => $full,
				'exists'    => $exists || $rows !== null,
				'rows'      => $rows,
				'note'      => $note,
				'pro_only'  => \in_array($suffix, $proOnly, true),
			];

			if ($exists) {
				$collation = (string) ($meta[$full]['TABLE_COLLATION'] ?? '');

				$entry['engine']      = $meta[$full]['ENGINE'] ?? null;
				$entry['collation']   = $collation === '' ? null : $collation;
				$entry['charset']     = $collation === '' ? null : explode('_', $collation)[0];
				$entry['size_bytes']  = (int) ($meta[$full]['DATA_LENGTH'] ?? 0)
					+ (int) ($meta[$full]['INDEX_LENGTH'] ?? 0);
				$entry['rows_approx'] = (int) ($meta[$full]['TABLE_ROWS'] ?? 0);
			}

			if (\in_array($suffix, $proOnly, true) && !$isPro && (int) $rows > 0) {
				$entry['downgrade_evidence'] = 'This is a Professional-only table and it is NOT empty on a '
					. 'site running Core. The install SQL creates it unconditionally, so its existence means '
					. 'nothing — but its CONTENTS mean the site ran Professional and was downgraded. ATS '
					. 'will never read or display these rows again.';
				$leftovers[] = $suffix . ' (' . $rows . ' rows)';
			}

			if ($withColumns) {
				$entry['columns'] = $this->columns($full);
			}

			$tables[] = $entry;
		}

		$response = [
			'ok'      => true,
			'prefix'  => $prefix,
			'edition' => $isPro ? 'Professional' : 'Core',
			'count'   => \count($tables),
			'tables'  => $tables,
			'not_listed' => [
				'ats_managernotes' => 'DELIBERATELY EXCLUDED, and not because it is Pro-only. Every other '
					. 'Pro-only table is safe to expose precisely BECAUSE it is empty on Core — ATS creates '
					. 'them unconditionally and never writes them without Professional. Manager notes are '
					. 'the exception: they are private staff-only commentary, and a site downgraded from '
					. 'Professional keeps every note it ever wrote, because a downgrade removes code and not '
					. 'data. ATS fails closed there — TicketTable::managerNotes() returns [] on the edition '
					. 'check before it builds a query, so the Core interface cannot show them. A generic '
					. 'SELECT would not fail closed, and would hand those notes to anyone who could name '
					. 'the table. So this add-on never queries it: query_ats_table refuses it by name, and '
					. 'check_ats_health reports only whether it is non-empty, as a count, never content.',
				'ats_credittransactions' => 'DROPped by ATS 5\'s own installer '
					. '(sql/install.mysql.utf8.sql:10). It does not exist to query.',
				'ats_creditconsumptions' => 'DROPped by ATS 5\'s own installer '
					. '(sql/install.mysql.utf8.sql:11). It does not exist to query.',
				'ats_emailtemplates, ats_customfields, ats_usertags and friends' => 'Also DROPped by the ATS '
					. '5 installer. Email templates became Joomla mail templates, custom fields became '
					. 'Joomla custom fields with context "com_ats.ticket", and ticket tags became Joomla '
					. 'tags with type_alias "com_ats.ticket".',
			],
			'scope' => 'query_ats_table is restricted to exactly the ' . \count(self::ATS_TABLES)
				. ' tables listed above. It accepts no raw SQL, no JOINs and no subqueries, and it cannot '
				. 'be pointed at #__users, #__session, #__extensions or anything else in this database.',
			'schema_notes' => [
				'uniform_charset' => 'Unlike some components in this family, ATS has no charset split — '
					. 'install.mysql.utf8.sql creates every table utf8mb4_unicode_ci on InnoDB, and none of '
					. 'the six shipped update files changes that. 4-byte characters are safe everywhere.',
				'no_access_or_language_on_tickets' => '#__ats_tickets has no access column and no language '
					. 'column. Both are inherited from the category via catid, which is a bare bigint with '
					. 'NO foreign key — so any join to #__categories must also require extension = '
					. '"com_ats" or it will match com_content categories by id collision.',
				'opening_post' => 'A ticket\'s opening message is a row in #__ats_posts, not a column on '
					. '#__ats_tickets. There is no body column on a ticket.',
				'derived_timespent' => '#__ats_tickets.timespent is recomputed by PostTable::onAfterStore() '
					. 'as SUM(timespent) over that ticket\'s posts WHERE enabled = 1, on each new post to a '
					. 'non-closed ticket. Writing it directly is pointless.',
				'modified_means_last_reply' => '#__ats_tickets.modified is written only when someone '
					. 'replies. Editing a ticket does not touch it, and neither does posting to a ticket '
					. 'that is already Closed.',
				'status_is_an_enum' => '#__ats_tickets.status is an ENUM of "O", "P", "C" plus the STRINGS '
					. '"1" through "99". Compare a site-defined status as a string — `status = \'7\'`, '
					. 'never `status = 7` — because MySQL reads an unquoted integer against an ENUM as an '
					. 'ORDINAL and will silently match a different member.',
				'no_index_on_invitations' => '#__ats_tickets_users has no index at all beyond its primary '
					. 'key — no unique constraint on (ticket_id, user_id). Duplicates are prevented only in '
					. 'PHP.',
			],
		];

		if ($missing !== []) {
			$response['missing_tables'] = [
				'tables' => $missing,
				'note'   => 'ATS creates all of its tables with CREATE TABLE IF NOT EXISTS in one install '
					. 'file, with no edition branching, so a missing table means an incomplete install '
					. 'rather than a Core/Pro difference. Reinstalling the component recreates them without '
					. 'touching existing data.',
			];
		}

		if ($leftovers !== []) {
			$response['downgraded_from_pro'] = [
				'detected' => true,
				'evidence' => $leftovers,
				'meaning'  => 'Professional-only tables are not empty on a Core install. The site ran ATS '
					. 'Professional and was downgraded; the data survived. Run check_ats_health — it also '
					. 'reports whether #__ats_managernotes is non-empty, which is the same situation with '
					. 'private staff commentary in it.',
			];
		}

		return ToolResult::json($response);
	}

	/**
	 * Exact COUNT(*). Returns null when the table is not there, which is itself
	 * the answer to "does it exist".
	 */
	private function countRows(string $table): ?int
	{
		try {
			return (int) $this->db->setQuery(
				'SELECT COUNT(*) FROM ' . $this->db->quoteName($table)
			)->loadResult();
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * The table's real columns, read rather than assumed.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private function columns(string $table): ?array
	{
		try {
			$details = $this->db->setQuery(
				'SHOW FULL COLUMNS FROM ' . $this->db->quoteName($table)
			)->loadAssocList() ?: [];
		} catch (\Throwable) {
			return null;
		}

		return array_map(
			static fn (array $c): array => [
				'column'  => $c['Field'] ?? null,
				'type'    => $c['Type'] ?? null,
				'null'    => $c['Null'] ?? null,
				'key'     => $c['Key'] ?? null,
				'default' => $c['Default'] ?? null,
				'extra'   => $c['Extra'] ?? null,
			],
			$details
		);
	}
}
