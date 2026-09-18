<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Config;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;

/**
 * Eight checks, each for a state ATS tolerates without complaint.
 *
 * Nothing here is a style opinion. Every finding is a condition the component
 * neither prevents on write nor reports on read, and which produces a
 * user-visible wrong answer or a privacy problem later:
 *
 *   1. siteurl empty — notification mail sends with broken ticket links. No
 *      error, no log entry. ATS builds those links from the parameter and an
 *      empty one just yields a bad URL.
 *   2. catid pointing at nothing, or at a non-com_ats category. catid is a
 *      bare bigint; there is no foreign key. The ticket inherits its access
 *      level and language from that category, so a dangling catid means a
 *      ticket with undefined access control.
 *   3. Orphaned #__ats_tickets_users rows. TicketTable::onAfterDelete()
 *      (TicketTable.php:650-697) deletes the ticket's posts and its manager
 *      notes, and does NOT touch the invitations table. Every deleted ticket
 *      leaves its invitations behind, forever.
 *   4. Duplicate (ticket_id, user_id) invitations. The table has no unique
 *      index and, per install.mysql.utf8.sql, no index at all beyond the
 *      primary key. Duplicates are prevented only by a SELECT-then-INSERT in
 *      TicketModel::inviteUser() (TicketModel.php:309-320), which is not
 *      atomic and which a direct INSERT bypasses entirely.
 *   5. Posts whose ticket_id resolves to nothing — unreachable replies.
 *   6. Tickets with zero posts. A ticket's OPENING MESSAGE IS A POST; there is
 *      no body column on #__ats_tickets. A ticket with no posts is a ticket
 *      with no content, and it renders as an empty conversation.
 *   7. #__ats_cannedreplies rows with access = 0. The install SQL declares
 *      `access int(11) NULL DEFAULT '0'`, and 0 is not a valid Joomla view
 *      level. The fix-up exists only in the 5.0.6 update file
 *      (sql/updates/mysql/5.0.6-20220629-0000.sql:9, `UPDATE ... SET access = 1
 *      WHERE access = 0`), so it ran on sites that UPGRADED through 5.0.6 and
 *      never on a site installed fresh after it.
 *   8. #__ats_managernotes non-empty on a Core install. That means the site ran
 *      Professional and was downgraded: the code went, the private staff notes
 *      did not, and TicketTable::managerNotes() now returns [] on the edition
 *      check before it queries, so nobody will ever see them again through the
 *      UI. THIS CHECK RETURNS A COUNT AND NOTHING ELSE. The note bodies are
 *      never read, never returned, and the table is not on this add-on's query
 *      allowlist at all.
 */
final class CheckHealthTool extends AbstractTool
{
	use ATSBootTrait;

	/** How many example ids to include per finding. Enough to act on, not a dump. */
	private const SAMPLE_SIZE = 25;

	public function getName(): string { return 'check_ats_health'; }

	public function getDescription(): string
	{
		return 'Audit an Akeeba Ticket System install for data and configuration states that ATS accepts '
			. 'silently and that break something later. Read-only; nothing is repaired. '
			. 'CHECKS: (1) whether the siteurl component parameter is populated. BE PRECISE ABOUT THIS ONE: '
			. 'an empty siteurl does NOT break the mail these MCP tools send. EmailSending.php:703 resolves '
			. 'the {siteurl} token as `$app->isClient(\'site\') ? Uri::base() : $params->get(\'siteurl\')`, '
			. 'and every mail-sending tool here runs inside a site-application context, so the parameter is '
			. 'bypassed and the links come from Uri::base(). It is a latent misconfiguration affecting ATS\' '
			. 'NON-SITE contexts only — the Professional CLI tasks, scheduled auto-close and auto-reply '
			. 'runs, and the mail gateway — none of which ship with Core at all, so on a Core install it may '
			. 'have no practical effect today. Reported as a notice on Core and a warning on Professional; '
			. '(2) tickets whose catid points at a category '
			. 'that does not exist, or at one whose extension is not "com_ats" — catid is a bare bigint with '
			. 'no foreign key, and since a ticket inherits its ACCESS LEVEL and LANGUAGE from its category '
			. '(#__ats_tickets has neither column) a dangling catid is a ticket with undefined access '
			. 'control; (3) orphaned #__ats_tickets_users rows, because TicketTable::onAfterDelete() cleans '
			. 'up posts and manager notes but never the invitations table, so every deleted ticket leaves '
			. 'its collaborator rows behind; (4) duplicate (ticket_id, user_id) invitation rows — that table '
			. 'has NO unique index and no index at all beyond its primary key, and duplicates are prevented '
			. 'only by a non-atomic check in TicketModel::inviteUser() that a direct INSERT bypasses; (5) '
			. 'posts whose ticket_id resolves to no ticket; (6) tickets with zero enabled posts, which are '
			. 'broken tickets rather than quiet ones, because a ticket\'s OPENING MESSAGE IS A POST and '
			. 'there is no body column on #__ats_tickets; (7) #__ats_cannedreplies rows with access = 0, '
			. 'which is not a valid Joomla view level — the install SQL defaults the column to 0 and the '
			. 'fix-up exists only in the 5.0.6 update file, so upgraded sites were corrected and fresh '
			. 'installs were not; (8) whether #__ats_managernotes is non-empty on a CORE install, which '
			. 'means the site was downgraded from Professional and is still holding private staff-only '
			. 'commentary that the interface will never display again; (9) on Joomla 5.4.0 and below, '
			. 'whether any tickets lack a #__ucm_content row — because on those versions '
			. 'TicketTable::onAfterLoad() calls ensureUcmRecord(), which INSERTs one, so simply LOADING a '
			. 'ticket is a database write. The guard only switches off ABOVE 5.4. The tools in this add-on '
			. 'never trigger it (they bind the row instead of calling load()), but ATS\' own screens and any '
			. 'other integration will. '
			. 'CHECK 8 RETURNS A COUNT ONLY. The note bodies are never read and never returned, and '
			. '#__ats_managernotes is deliberately absent from list_ats_tables and unreachable from '
			. 'query_ats_table. '
			. 'Every finding carries a severity (error / warning / notice), the count, up to '
			. self::SAMPLE_SIZE . ' example ids where ids make sense, and a plain-English statement of what '
			. 'goes wrong because of it. A clean install returns findings: [] and a summary saying so. '
			. 'A NOTE ON QUERYING STATUS YOURSELF AFTERWARDS: #__ats_tickets.status is an ENUM, so a '
			. 'site-defined status must be compared as a STRING — `status = \'7\'`, never `status = 7`. An '
			. 'unquoted integer is treated by MySQL as an ENUM ORDINAL and silently matches a different '
			. 'member.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'include_samples' => [
					'type' => 'boolean',
					'description' => 'Include up to ' . self::SAMPLE_SIZE . ' example row ids per finding. '
						. 'Default true. Ids only — no ticket titles, no post bodies, and never any manager '
						. 'note content.',
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

		$samples = (bool) ($arguments['include_samples'] ?? true);
		$prefix  = $this->db->getPrefix();

		$tPosts   = $this->db->quoteName($prefix . 'ats_posts');
		$tInvites = $this->db->quoteName($prefix . 'ats_tickets_users');
		$tCanned  = $this->db->quoteName($prefix . 'ats_cannedreplies');
		$tNotes   = $this->db->quoteName($prefix . 'ats_managernotes');

		$findings = [];
		$errors   = [];

		// --- 1. siteurl -------------------------------------------------------
		// BE PRECISE HERE. The obvious framing — "empty siteurl means broken
		// links in notification mail" — is wrong for most of what this add-on
		// does. EmailSending.php:703 resolves the {siteurl} token as
		// `$app->isClient('site') ? Uri::base() : $params->get('siteurl')`, and
		// every mail-sending tool here runs inside withSiteAppContext(), which
		// makes isClient('site') true. The param is bypassed and the links come
		// from Uri::base(). It is still a real latent misconfiguration for ATS'
		// own non-site contexts, which is what this finding says.
		if (!$this->atsSiteUrlConfigured()) {
			$isPro = $this->atsIsPro();

			$findings[] = [
				'severity' => $isPro ? 'warning' : 'notice',
				'code'     => 'siteurl_not_set',
				'count'    => 1,
				'detail'   => 'The com_ats `siteurl` component parameter is empty. It is a hidden field — '
					. 'not on ATS\' Options form — normally written by the component itself on a front-end '
					. 'request, so a site driven mostly from the back end or over an API can sit in this '
					. 'state indefinitely.',
				'what_it_does_not_affect' => 'It does NOT break the notification mail these MCP tools send. '
					. 'EmailSending.php:703 resolves the {siteurl} token as `$app->isClient(\'site\') ? '
					. 'Uri::base() : $params->get(\'siteurl\')`, and every mail-sending tool in this add-on '
					. 'runs inside withSiteAppContext() — which makes isClient(\'site\') true, so the '
					. 'parameter is bypassed entirely and the links are built from Uri::base(). The same '
					. 'goes for any mail sent by an ordinary front-end request. Do not report an empty '
					. 'siteurl as "the mail you just sent has broken links".',
				'consequence' => 'It affects ATS\' NON-SITE contexts only — the Professional CLI commands, '
					. 'the scheduled auto-close and auto-reply runs, and the mail gateway. Mail generated '
					. 'from one of those builds its ticket links from this parameter, and an empty value '
					. 'yields links that do not resolve. There is no error and no log entry when that '
					. 'happens.'
					. ($isPro
						? ' This site is running Professional, so those contexts exist and this is worth '
							. 'fixing.'
						: ' This site is running ATS CORE, where none of those contexts ship at all — no '
							. 'CLI commands, no scheduled tasks, no mail gateway. So on this install an '
							. 'empty siteurl has no practical effect today. It is recorded as a latent '
							. 'misconfiguration that would start to matter on an upgrade to Professional.'),
				'fix' => 'Load any ATS front-end page once so the component populates it, or set the '
					. 'parameter directly in the params JSON on the com_ats row of #__extensions.',
			];
		}

		// --- 2. catid integrity ----------------------------------------------
		$badCat = $this->rows(
			'SELECT ' . $this->db->quoteName('t.id') . ' AS id, ' . $this->db->quoteName('t.catid') . ' AS catid, '
			. $this->db->quoteName('c.extension') . ' AS extension'
			. ' FROM ' . $this->db->quoteName($prefix . 'ats_tickets', 't')
			. ' LEFT JOIN ' . $this->db->quoteName('#__categories', 'c')
			. ' ON ' . $this->db->quoteName('c.id') . ' = ' . $this->db->quoteName('t.catid')
			. ' WHERE ' . $this->db->quoteName('c.id') . ' IS NULL'
			. ' OR ' . $this->db->quoteName('c.extension') . ' <> ' . $this->db->quote('com_ats')
			. ' ORDER BY ' . $this->db->quoteName('t.id') . ' ASC',
			$errors,
			'ticket catid integrity'
		);

		if ($badCat !== []) {
			$missing = [];
			$wrong   = [];

			foreach ($badCat as $row) {
				if ($row['extension'] === null) {
					$missing[] = (int) $row['id'];
				} else {
					$wrong[] = (int) $row['id'];
				}
			}

			if ($missing !== []) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'ticket_category_missing',
					'count'    => \count($missing),
					'sample_ticket_ids' => $samples ? \array_slice($missing, 0, self::SAMPLE_SIZE) : null,
					'detail'   => \count($missing) . ' ticket(s) have a catid that matches no row in '
						. '#__categories at all. #__ats_tickets.catid is a bare bigint with no foreign key, '
						. 'so deleting a category leaves its tickets pointing at nothing.',
					'consequence' => 'A ticket inherits its ACCESS LEVEL and LANGUAGE from its category — '
						. '#__ats_tickets has neither column. With no category there is no access level to '
						. 'inherit, so Permissions::canAccessCategory() cannot answer and the ticket\'s '
						. 'visibility is undefined. It will also be invisible in every category listing '
						. 'while still existing in the table.',
					'fix' => 'Move the tickets to a real com_ats category, or recreate the category with the '
						. 'same id.',
				];
			}

			if ($wrong !== []) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'ticket_category_wrong_extension',
					'count'    => \count($wrong),
					'sample_ticket_ids' => $samples ? \array_slice($wrong, 0, self::SAMPLE_SIZE) : null,
					'detail'   => \count($wrong) . ' ticket(s) have a catid pointing at a category whose '
						. '`extension` is NOT "com_ats" — a com_content or other component\'s category that '
						. 'happens to share the id. Because catid has no foreign key and category ids are '
						. 'global across every extension, this is an ordinary consequence of a bad import or '
						. 'a hand-edited row.',
					'consequence' => 'The ticket takes its access level and language from a category that '
						. 'belongs to a different component, so its ACL is whatever that unrelated category '
						. 'happens to say. It will not appear in any ATS category listing. This is also why '
						. 'every query joining catid to #__categories must ALSO require extension = '
						. '"com_ats" — otherwise it matches these rows.',
					'fix' => 'Reassign the tickets to a real com_ats category.',
				];
			}
		}

		// --- 3. orphaned invitations ------------------------------------------
		$orphanInvites = $this->rows(
			'SELECT ' . $this->db->quoteName('i.id') . ' AS id, ' . $this->db->quoteName('i.ticket_id') . ' AS ticket_id'
			. ' FROM ' . $this->db->quoteName($prefix . 'ats_tickets_users', 'i')
			. ' LEFT JOIN ' . $this->db->quoteName($prefix . 'ats_tickets', 't')
			. ' ON ' . $this->db->quoteName('t.id') . ' = ' . $this->db->quoteName('i.ticket_id')
			. ' WHERE ' . $this->db->quoteName('t.id') . ' IS NULL'
			. ' ORDER BY ' . $this->db->quoteName('i.id') . ' ASC',
			$errors,
			'orphaned invitations'
		);

		if ($orphanInvites !== []) {
			$findings[] = [
				'severity' => 'warning',
				'code'     => 'orphaned_invitations',
				'count'    => \count($orphanInvites),
				'sample_row_ids' => $samples
					? array_map(static fn (array $r): int => (int) $r['id'], \array_slice($orphanInvites, 0, self::SAMPLE_SIZE))
					: null,
				'detail'   => \count($orphanInvites) . ' row(s) in #__ats_tickets_users reference a ticket '
					. 'that no longer exists. This is not an accident of this site — it is what ATS does. '
					. 'TicketTable::onAfterDelete() (TicketTable.php:650-697) deletes the ticket\'s posts '
					. 'and its manager notes and never touches the invitations table. There is no Table '
					. 'class for #__ats_tickets_users and no events; it is written and deleted by raw '
					. 'INSERT/DELETE in TicketModel::inviteUser() and removeInvite().',
				'consequence' => 'Dead rows that accumulate for the life of the site. Harmless to ATS, but '
					. 'they inflate the per-ticket invite count if a ticket id is ever REUSED (it will not '
					. 'be, with AUTO_INCREMENT, unless the table is reset or rows are imported with explicit '
					. 'ids) and they are a small privacy residue: they record that a named user was once '
					. 'given access to something.',
				'fix' => 'DELETE the rows whose ticket_id has no ticket. Nothing in ATS reads them.',
			];
		}

		// --- 4. duplicate invitations -----------------------------------------
		$dupInvites = $this->rows(
			'SELECT ' . $this->db->quoteName('ticket_id') . ', ' . $this->db->quoteName('user_id')
			. ', COUNT(*) AS c FROM ' . $tInvites
			. ' GROUP BY ' . $this->db->quoteName('ticket_id') . ', ' . $this->db->quoteName('user_id')
			. ' HAVING c > 1 ORDER BY c DESC',
			$errors,
			'duplicate invitations'
		);

		if ($dupInvites !== []) {
			$extra = 0;

			foreach ($dupInvites as $row) {
				$extra += (int) $row['c'] - 1;
			}

			$findings[] = [
				'severity' => 'warning',
				'code'     => 'duplicate_invitations',
				'count'    => \count($dupInvites),
				'redundant_rows' => $extra,
				'sample_pairs' => $samples
					? array_map(
						static fn (array $r): array => [
							'ticket_id' => (int) $r['ticket_id'],
							'user_id'   => (int) $r['user_id'],
							'rows'      => (int) $r['c'],
						],
						\array_slice($dupInvites, 0, self::SAMPLE_SIZE)
					)
					: null,
				'detail'   => \count($dupInvites) . ' (ticket_id, user_id) pair(s) appear more than once in '
					. '#__ats_tickets_users, for ' . $extra . ' redundant row(s). The table has NO unique '
					. 'index — per install.mysql.utf8.sql it has no index at all beyond its primary key — '
					. 'so the database will not stop this. The only guard is a SELECT-then-INSERT in '
					. 'TicketModel::inviteUser() (TicketModel.php:309-320), which is not atomic and which '
					. 'any direct INSERT bypasses.',
				'consequence' => 'The invite_limit parameter is enforced by COUNTING rows in this table, so '
					. 'duplicates consume a ticket\'s invitation quota and a manager hits "too many '
					. 'invitations" earlier than the setting says. Removing an invitation deletes by '
					. '(ticket_id, user_id), so a single removal may or may not clear every copy depending '
					. 'on how the DELETE is written.',
				'fix' => 'Keep the lowest id per pair and delete the rest. Consider adding a UNIQUE index on '
					. '(ticket_id, user_id) afterwards — ATS does not ship one, so that is a local change '
					. 'the vendor may overwrite on a future schema update.',
			];
		}

		// --- 5. orphaned posts -------------------------------------------------
		$orphanPosts = $this->rows(
			'SELECT ' . $this->db->quoteName('p.id') . ' AS id, ' . $this->db->quoteName('p.ticket_id') . ' AS ticket_id'
			. ' FROM ' . $this->db->quoteName($prefix . 'ats_posts', 'p')
			. ' LEFT JOIN ' . $this->db->quoteName($prefix . 'ats_tickets', 't')
			. ' ON ' . $this->db->quoteName('t.id') . ' = ' . $this->db->quoteName('p.ticket_id')
			. ' WHERE ' . $this->db->quoteName('t.id') . ' IS NULL'
			. ' ORDER BY ' . $this->db->quoteName('p.id') . ' ASC',
			$errors,
			'orphaned posts'
		);

		if ($orphanPosts !== []) {
			$findings[] = [
				'severity' => 'warning',
				'code'     => 'orphaned_posts',
				'count'    => \count($orphanPosts),
				'sample_post_ids' => $samples
					? array_map(static fn (array $r): int => (int) $r['id'], \array_slice($orphanPosts, 0, self::SAMPLE_SIZE))
					: null,
				'detail'   => \count($orphanPosts) . ' row(s) in #__ats_posts reference a ticket that does '
					. 'not exist. ATS normally prevents this — TicketTable::onAfterDelete() deletes a '
					. 'ticket\'s posts through PostTable so the per-post hooks run — so orphans here mean '
					. 'the ticket row was removed some other way: a direct DELETE, a partial restore, or an '
					. 'import.',
				'consequence' => 'Unreachable conversation content sitting in the database. It is not '
					. 'rendered anywhere, it is not counted anywhere, and if it contains anything a customer '
					. 'wrote it is still personal data under whatever retention policy applies.',
				'fix' => 'Delete them, after checking none of the parent tickets are recoverable.',
			];
		}

		// --- 6. postless tickets ----------------------------------------------
		$postless = $this->rows(
			'SELECT ' . $this->db->quoteName('t.id') . ' AS id, ' . $this->db->quoteName('t.enabled') . ' AS enabled,'
			. ' (SELECT COUNT(*) FROM ' . $tPosts . ' WHERE '
			. $this->db->quoteName('ticket_id') . ' = ' . $this->db->quoteName('t.id') . ') AS total_posts'
			. ' FROM ' . $this->db->quoteName($prefix . 'ats_tickets', 't')
			. ' WHERE NOT EXISTS (SELECT 1 FROM ' . $tPosts . ' WHERE '
			. $this->db->quoteName('ticket_id') . ' = ' . $this->db->quoteName('t.id')
			. ' AND ' . $this->db->quoteName('enabled') . ' = 1)'
			. ' ORDER BY ' . $this->db->quoteName('t.id') . ' ASC',
			$errors,
			'postless tickets'
		);

		if ($postless !== []) {
			$noneAtAll = 0;
			$allHidden = 0;

			foreach ($postless as $row) {
				if ((int) $row['total_posts'] === 0) {
					$noneAtAll++;
				} else {
					$allHidden++;
				}
			}

			$findings[] = [
				'severity' => 'warning',
				'code'     => 'tickets_without_posts',
				'count'    => \count($postless),
				'with_no_posts_at_all' => $noneAtAll,
				'with_all_posts_unpublished' => $allHidden,
				'sample_ticket_ids' => $samples
					? array_map(static fn (array $r): int => (int) $r['id'], \array_slice($postless, 0, self::SAMPLE_SIZE))
					: null,
				'detail'   => \count($postless) . ' ticket(s) have no ENABLED posts. Of those, ' . $noneAtAll
					. ' have no post rows whatsoever and ' . $allHidden . ' have posts that are all '
					. 'unpublished. A TICKET\'S OPENING MESSAGE IS A POST — there is no body column on '
					. '#__ats_tickets — so a ticket in this state has no content at all.',
				'consequence' => 'The ticket renders as an empty conversation. It also has no computable '
					. '"last activity", because that is MAX(created) over the enabled posts, so it will be '
					. 'invisible to stale-ticket triage unless specifically asked for. And because '
					. 'PostTable::onAfterStore() recomputes timespent as a SUM over enabled posts, a ticket '
					. 'whose posts are all unpublished keeps whatever timespent it had until the next reply.',
				'fix' => 'For the all-unpublished ones, republish the opening post if it was hidden by '
					. 'mistake. For the truly empty ones, the ticket is a shell — usually a failed create '
					. 'where the ticket row was written and the post was not.',
			];
		}

		// --- 7. canned replies with access = 0 --------------------------------
		$cannedTotal = $this->countOf($tCanned, '', $errors, 'canned replies total');
		$cannedZero  = $this->countOf(
			$tCanned,
			' WHERE ' . $this->db->quoteName('access') . ' = 0 OR ' . $this->db->quoteName('access') . ' IS NULL',
			$errors,
			'canned replies with access 0'
		);

		if ($cannedZero !== null && $cannedZero > 0) {
			$findings[] = [
				'severity' => 'warning',
				'code'     => 'cannedreplies_invalid_access',
				'count'    => $cannedZero,
				'of_total' => $cannedTotal,
				'detail'   => $cannedZero . ' row(s) in #__ats_cannedreplies have access = 0 (or NULL). '
					. 'ZERO IS NOT A VALID JOOMLA VIEW LEVEL — #__viewlevels starts at 1 (Public). The '
					. 'install SQL declares the column as `access int(11) NULL DEFAULT \'0\'`, and the only '
					. 'correction ATS ever shipped is one line in the 5.0.6 update file '
					. '(sql/updates/mysql/5.0.6-20220629-0000.sql:9 — `UPDATE #__ats_cannedreplies SET '
					. 'access = 1 WHERE access = 0`). Update files run on UPGRADE, not on a fresh install, '
					. 'so a site installed after 5.0.6 gets the broken default and is never fixed.',
				'consequence' => 'Canned replies are a Professional feature. On a Pro site, a reply with '
					. 'access = 0 matches no view level and is therefore offered to nobody — it exists in '
					. 'the manager but never appears in the reply screen. '
					. ($this->atsIsPro()
						? 'This site IS running Professional, so these rows are actively unusable.'
						: 'This site is running Core, where canned replies are not used at all, so the rows '
							. 'are inert today — but they will be broken the moment the site is upgraded to '
							. 'Professional.'),
				'fix' => 'Set access to a real view level id, normally 1 (Public) for staff-facing canned '
					. 'replies, or whichever level your support group holds.',
			];
		}

		// --- 8. manager notes on Core — COUNT ONLY ----------------------------
		$notesCount = $this->countOf($tNotes, '', $errors, 'manager notes');

		if (!$this->atsIsPro() && $notesCount !== null && $notesCount > 0) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'managernotes_present_on_core',
				'count'    => $notesCount,
				'detail'   => '#__ats_managernotes holds ' . $notesCount . ' row(s) on a site running ATS '
					. 'CORE. Manager notes are a Professional feature, so this site ran Professional at some '
					. 'point and was downgraded. A Pro-to-Core downgrade removes code, not data.',
				'consequence' => 'Those rows are private, staff-only commentary about customers and their '
					. 'tickets, and they are now invisible to everyone: TicketTable::managerNotes() '
					. '(TicketTable.php:458-463) returns an empty array on the edition check BEFORE it '
					. 'builds a query, so the interface cannot show them and nobody can review, redact or '
					. 'delete them through ATS. They remain in every database backup and in any data-subject '
					. 'export built by querying the schema directly.',
				'privacy_note' => 'THIS TOOL REPORTS THE COUNT AND NOTHING ELSE. It does not read the note '
					. 'bodies, does not return ids, and does not join the table to anything. '
					. '#__ats_managernotes is deliberately absent from list_ats_tables and query_ats_table '
					. 'refuses it by name — because "Pro-only, therefore empty" is false for exactly this '
					. 'table, and a generic SELECT would hand private notes to anyone who could name it.',
				'fix' => 'Decide deliberately: restore Professional to make them visible and manageable '
					. 'again, or delete them from the database as a considered retention decision. Do not '
					. 'leave them as an invisible liability.',
			];
		}

		// --- 9. reading a ticket writes, on Joomla 5.4 and below ---------------
		// TicketTable::onAfterLoad() calls ensureUcmRecord(), which INSERTs a
		// #__ucm_content row when one is missing. It returns early only when
		// version_compare(JVERSION, '5.4.0', 'gt') — so on 5.4.0 and below, a
		// plain load() is a write. Worth reporting, because it makes "read one
		// ticket" a non-idempotent operation for anything that uses load().
		$ucmNerfed = version_compare(JVERSION, '5.4.0', 'gt');

		if (!$ucmNerfed) {
			$missingUcm = $this->countOf(
				$this->db->quoteName($prefix . 'ats_tickets', 't'),
				' WHERE NOT EXISTS (SELECT 1 FROM ' . $this->db->quoteName($prefix . 'ucm_content', 'u')
				. ' WHERE ' . $this->db->quoteName('u.core_type_alias') . ' = '
				. $this->db->quote('com_ats.ticket')
				. ' AND ' . $this->db->quoteName('u.core_content_item_id') . ' = '
				. $this->db->quoteName('t.id') . ')',
				$errors,
				'tickets without a UCM record'
			);

			if ($missingUcm === null || $missingUcm > 0) {
				$findings[] = [
					'severity' => 'notice',
					'code'     => 'ticket_load_writes_ucm_rows',
					'count'    => $missingUcm ?? 0,
					'detail'   => 'This site runs Joomla ' . JVERSION . '. On Joomla 5.4.0 and below, '
						. 'LOADING a ticket is not a read-only operation: TicketTable::onAfterLoad() calls '
						. 'ensureUcmRecord() (TicketTable.php:710-719 and 1008-1039), which INSERTs a '
						. '#__ucm_content row whenever the ticket does not already have one. The guard is '
						. '`version_compare(JVERSION, \'5.4.0\', \'gt\')`, so it only switches itself off '
						. 'ABOVE 5.4. '
						. ($missingUcm === null
							? 'The number of tickets currently lacking a UCM record could not be counted.'
							: $missingUcm . ' ticket(s) on this site currently lack one, so that many rows '
								. 'will be written the first time each is loaded.'),
					'consequence' => 'Anything that calls TicketTable::load() — ATS\' own admin screens, any '
						. 'integration, any tool that has not been written around this — writes rows as a '
						. 'side effect of reading. The data itself is harmless (UCM records exist so tickets '
						. 'can be tagged), but it makes a read non-idempotent, it dirties a database you may '
						. 'be diffing, and it surprises anyone auditing writes.',
					'this_addon' => 'The read tools in this add-on do NOT trigger it. They SELECT the ticket '
						. 'row themselves and hand it to the vendor\'s own bindAsLoadEquivalent(), which '
						. 'sets the table up exactly as load() would minus the UCM write. So nothing here '
						. 'contributes to the count above.',
					'fix' => 'Nothing to fix on the ATS side — this is Joomla-version behaviour, and it '
						. 'disappears on Joomla 5.5 and later. Just know that a "read" through load() is a '
						. 'write on this host.',
				];
			}
		}

		// --- summary ------------------------------------------------------------
		$bySeverity = ['error' => 0, 'warning' => 0, 'notice' => 0];

		foreach ($findings as $f) {
			$bySeverity[$f['severity']] = ($bySeverity[$f['severity']] ?? 0) + 1;
		}

		$response = [
			'ok'      => true,
			'edition' => $this->atsIsPro() ? 'Professional' : 'Core',
			'version' => $this->atsVersion(),
			'findings' => $findings,
			'summary' => [
				'total'   => \count($findings),
				'errors'  => $bySeverity['error'],
				'warnings' => $bySeverity['warning'],
				'notices' => $bySeverity['notice'],
				'verdict' => $findings === []
					? 'Clean. None of the eight checked states is present on this install.'
					: \count($findings) . ' finding(s). Each carries its own consequence and fix.',
			],
			'checks_performed' => [
				'siteurl_populated',
				'ticket_category_integrity',
				'orphaned_invitations',
				'duplicate_invitations',
				'orphaned_posts',
				'tickets_without_posts',
				'cannedreplies_access_zero',
				'managernotes_on_core',
				'ticket_load_writes_ucm_rows',
			],
			'counts' => [
				'managernotes_rows' => $notesCount,
				'cannedreplies_rows' => $cannedTotal,
			],
			'joomla_version' => JVERSION,
			'ucm_side_effect' => $ucmNerfed
				? 'Joomla ' . JVERSION . ' is above 5.4, so ATS\' ensureUcmRecord() self-disables and '
					. 'TicketTable::load() is genuinely read-only here.'
				: 'Joomla ' . JVERSION . ' is 5.4.0 or below, so TicketTable::load() INSERTs a '
					. '#__ucm_content row for any ticket that lacks one. The tools in this add-on avoid '
					. 'load() entirely and bind the row instead, so they never trigger it.',
			'scope_note' => 'These are SITE-WIDE integrity checks over the raw tables. They are not filtered '
				. 'to what the calling user may read, because an integrity problem does not belong to a '
				. 'viewer. No ticket titles, no post bodies and no manager-note content are returned '
				. 'anywhere in this response — only ids and counts.',
			'nothing_was_repaired' => 'This tool only reports. ATS has no repair action of its own for any '
				. 'of these, and the fixes are DELETEs and UPDATEs against live support data — that is a '
				. 'decision to make deliberately, with a backup, not something to hand to an automated call.',
		];

		if ($errors !== []) {
			$response['check_errors'] = [
				'failed' => $errors,
				'note'   => 'One or more checks could not run — usually a missing table on a partial '
					. 'install. The findings above are therefore incomplete.',
			];
		}

		return ToolResult::json($response);
	}

	/**
	 * Run a query, returning rows, and record the failure instead of throwing
	 * so one missing table does not abort the whole audit.
	 *
	 * @param array<int, string> $errors
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(string $sql, array &$errors, string $label): array
	{
		try {
			return $this->db->setQuery($sql)->loadAssocList() ?: [];
		} catch (\Throwable $e) {
			$errors[] = $label . ': ' . $e->getMessage();

			return [];
		}
	}

	/**
	 * COUNT(*) with the same fail-soft behaviour. Returns null when the table is
	 * absent, which is itself information.
	 *
	 * @param array<int, string> $errors
	 */
	private function countOf(string $table, string $where, array &$errors, string $label): ?int
	{
		try {
			return (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $table . $where)->loadResult();
		} catch (\Throwable $e) {
			$errors[] = $label . ': ' . $e->getMessage();

			return null;
		}
	}
}
