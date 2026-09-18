<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Tables;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;

/**
 * SELECT-only escape hatch over Akeeba Ticket System's own tables.
 *
 * Modelled on the Events Booking add-on's equivalent, and scoped the same three
 * ways, because a generic query tool holding a live database handle is the most
 * dangerous thing an add-on can ship:
 *
 *   1. THE TABLE is resolved against ATSBootTrait::ATS_TABLES and nothing else.
 *      A name that is not one of com_ats' own tables is refused whether or not
 *      it exists — #__users, #__session, #__extensions and every other table in
 *      the database are unreachable from here.
 *
 *   2. EVERY COLUMN — selected, filtered on, or ordered by — is checked against
 *      the table's real schema as reported by SHOW FULL COLUMNS and then passed
 *      through quoteName(). A column name never reaches SQL as free text.
 *
 *   3. WHERE IS STRUCTURED, NOT SQL. Clauses arrive as {column, op, value}, the
 *      operator comes from a fixed allowlist, and every value is quoted. There
 *      is no raw-SQL parameter, no JOIN, no subquery and no UNION — there is
 *      nowhere for one to go.
 *
 * The tool reads. It has no code path that writes.
 *
 * ONE TABLE IS REFUSED BY NAME RATHER THAN BY IGNORANCE.
 * ------------------------------------------------------
 * #__ats_managernotes is a real com_ats table and this tool knows exactly what
 * it is. It still refuses it, with the reason, instead of returning "unknown
 * table" — because an agent told "unknown" will reasonably try spelling
 * variants, and because the honest answer is more useful than a vague one.
 *
 * The reasoning is not "it is Pro-only". Every other Pro-only ATS table
 * (#__ats_attachments, #__ats_cannedreplies, #__ats_autoreplies) IS on the
 * allowlist, because ATS creates them unconditionally and never writes them
 * without Professional, so on Core they are empty and a SELECT is harmless.
 * Manager notes break that reasoning: they are private staff-only commentary
 * about customers, and a site downgraded from Professional to Core keeps every
 * note it ever wrote. ATS fails closed there — TicketTable::managerNotes()
 * (TicketTable.php:458-463) returns [] on the edition check before it builds a
 * query, so the Core interface cannot display them. A generic SELECT would not
 * fail closed. It would serve private notes on exactly the installs where the
 * component has decided nobody should see them.
 *
 * WHAT THIS TOOL DELIBERATELY DOES NOT DO.
 * -----------------------------------------
 * It does not apply the per-ticket visibility gate. It cannot: the gate is a
 * per-ROW decision that needs a loaded TicketTable, and this tool returns
 * arbitrary columns of arbitrary tables including ones with no ticket context
 * at all. That is why it requires the same 'use' permission as every other read
 * tool AND says plainly in its description that its output is raw and ungated —
 * anything that must respect what a given user may see should go through
 * list_ats_tickets, get_ats_ticket or the report tools, all of which do gate.
 */
final class QueryTableTool extends AbstractTool
{
	use ATSBootTrait;

	private const ALLOWED_OPS = [
		'=', '!=', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IS NULL', 'IS NOT NULL', 'IN', 'NOT IN',
	];

	private const DEFAULT_LIMIT = 50;

	private const MAX_LIMIT = 500;

	/**
	 * Tables this tool knows about and refuses anyway, with the reason. Refusing
	 * by name beats a generic "unknown table", which invites an agent to go
	 * looking for a spelling that works.
	 *
	 * @var array<string, string>
	 */
	private const REFUSED = [
		'ats_managernotes' => 'REFUSED: #__ats_managernotes holds MANAGER NOTES — private, staff-only '
			. 'commentary about customers and their tickets. This is a real Akeeba Ticket System table and '
			. 'this tool knows it; the refusal is deliberate, not a gap in the allowlist. '
			. 'It is NOT refused for being a Professional feature. The other Pro-only ATS tables — '
			. 'ats_attachments, ats_cannedreplies, ats_autoreplies — ARE queryable here, because ATS '
			. 'creates every table unconditionally at install time and never writes those three without '
			. 'Professional, so on a Core site they are simply empty and reading them is harmless. Manager '
			. 'notes are the one place that reasoning fails: a site that ran ATS Professional and later '
			. 'dropped to Core keeps every note it ever wrote, because a downgrade removes code, not data. '
			. 'ATS itself fails closed there — TicketTable::managerNotes() returns an empty array on the '
			. 'edition check BEFORE it builds a query, so those notes are invisible in the Core interface. '
			. 'A generic SELECT would not fail closed; it would hand private notes to anyone who could name '
			. 'the table, on precisely the installs where the component has decided nobody should see them. '
			. 'There is no argument to this tool that overrides this, and there will not be one. '
			. 'check_ats_health will tell you whether the table is non-empty — as a COUNT, never content — '
			. 'which is the diagnostic worth having. On a Professional site, read notes through ATS itself.',
		'ats_credittransactions' => 'REFUSED, and it also does not exist: ATS 5\'s installer DROPs '
			. '#__ats_credittransactions (sql/install.mysql.utf8.sql:10). The credits feature was removed '
			. 'in ATS 5. Querying it would error rather than return nothing.',
		'ats_creditconsumptions' => 'REFUSED, and it also does not exist: ATS 5\'s installer DROPs '
			. '#__ats_creditconsumptions (sql/install.mysql.utf8.sql:11).',
		'ats_emailtemplates' => 'Does not exist: DROPped by ATS 5\'s installer. Notification templates are '
			. 'now Joomla mail templates in #__mail_templates.',
		'ats_customfields' => 'Does not exist: DROPped by ATS 5\'s installer. Ticket custom fields are now '
			. 'Joomla custom fields — #__fields / #__fields_values with context "com_ats.ticket" and '
			. 'item_id = the ticket id.',
		'ats_usertags' => 'Does not exist: DROPped by ATS 5\'s installer. User tags are a Professional '
			. 'feature held elsewhere, and are NOT the same thing as ticket tags, which are Joomla-native '
			. '(type_alias "com_ats.ticket").',
	];

	/**
	 * Columns large enough that SELECT * over more than a handful of rows buries
	 * the response. Warned about, not blocked.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const HEAVY_COLUMNS = [
		'ats_posts'         => ['content_html'],
		'ats_tickets'       => ['params'],
		'ats_cannedreplies' => ['reply'],
		'ats_autoreplies'   => ['reply', 'keywords_title', 'keywords_text', 'params'],
	];

	/**
	 * Per-table handling advice returned alongside the rows.
	 *
	 * @var array<string, string>
	 */
	private const HANDLING = [
		'ats_tickets' => 'Support data. Ticket TITLES are written by customers and routinely contain '
			. 'personal information, order numbers and account details. Note also that this table has no '
			. 'access and no language column — both are inherited from the category via catid — and that '
			. 'the rows you get back here are NOT filtered by who may view them.',
		'ats_posts' => 'PERSONAL DATA. content_html is the full body of every message a customer or an '
			. 'agent has written, including anything they pasted into it. Select only the columns you need '
			. 'and do not dump the table into a response somebody will read.',
		'ats_tickets_users' => 'Records that a named user was granted access to a specific ticket. Minor '
			. 'privacy residue, and it outlives the ticket: TicketTable::onAfterDelete() does not clean '
			. 'this table, so rows here may reference tickets that no longer exist.',
	];

	public function getName(): string { return 'query_ats_table'; }

	public function getDescription(): string
	{
		return 'Read rows from an Akeeba Ticket System table with column selection, structured filters, '
			. 'ordering and pagination. SELECT ONLY — there is no code path here that writes, and no raw '
			. 'SQL is accepted anywhere. '
			. 'SCOPED TO com_ats. The table must be one of the six this add-on allows: ats_tickets, '
			. 'ats_posts, ats_tickets_users, ats_attachments, ats_cannedreplies, ats_autoreplies (run '
			. 'list_ats_tables for the live inventory). This tool cannot be pointed at #__users, '
			. '#__session, #__extensions, #__categories or anything else in the database, and asking it to '
			. 'is refused rather than worked around. '
			. '#__ats_managernotes IS REFUSED BY NAME. It is a real ATS table and this tool knows it; the '
			. 'refusal is deliberate. It is not about Professional — the other three Pro-only tables above '
			. 'ARE queryable, because ATS creates them unconditionally and never writes them on Core, so '
			. 'they are empty and harmless. Manager notes are the exception: they are private staff-only '
			. 'commentary, a site downgraded from Professional to Core KEEPS them, and ATS fails closed by '
			. 'returning an empty array on the edition check before it builds a query. A generic SELECT '
			. 'would not fail closed. No argument overrides this. Use check_ats_health, which reports only '
			. 'whether the table is non-empty, as a count. '
			. 'THIS TOOL\'S OUTPUT IS UNGATED. It returns raw rows and does NOT apply the per-ticket '
			. 'visibility check that list_ats_tickets, get_ats_ticket and the report tools apply, because '
			. 'the gate is a per-row decision that needs a loaded TicketTable and this tool returns '
			. 'arbitrary columns of arbitrary tables. Use the purpose-built tools whenever the answer has '
			. 'to respect what a given user may see. '
			. 'WHERE conditions are structured clauses {column, op, value} combined with AND. Allowed '
			. 'operators: =, !=, <, <=, >, >=, LIKE, NOT LIKE, IS NULL, IS NOT NULL, IN, NOT IN. No JOINs, '
			. 'no subqueries, no UNION, no OR. Every column named — selected, filtered or ordered — is '
			. 'validated against the table\'s real schema first; pass include_schema to get that schema '
			. 'back with the rows. '
			. 'READING #__ats_tickets: `status` is an ENUM of "O" (Open, waiting on support), "P" (Pending, '
			. 'waiting on the customer) and "C" (Closed), PLUS the strings "1".."99" for site-defined '
			. 'statuses whose labels live in the customStatuses component parameter. A site-defined status '
			. 'must be matched as a STRING — pass "7", not 7. MySQL reads an unquoted integer against an '
			. 'ENUM as an ORDINAL and would silently match a different member; this tool quotes every '
			. 'filter value, so passing the string is what you want and passing a JSON number still ends up '
			. 'quoted. `enabled` is the '
			. 'publish flag. `modified` means LAST REPLY, not last edited — only PostTable::onAfterStore() '
			. 'writes it, and only for a new post on a non-closed ticket. `timespent` is DERIVED: '
			. 'recomputed as SUM over the ticket\'s posts WHERE enabled = 1 on every new reply, so writing '
			. 'it means nothing. `priority` is TINYINT with NO DEFAULT (0 High / 5 Normal / 10 Low) and is '
			. 'hidden in the UI unless ticketPriorities is 1. `catid` is a bare bigint with NO foreign key '
			. 'and you cannot join from here, so remember that a matching #__categories row is only an ATS '
			. 'category if its extension is "com_ats". There is NO access and NO language column. '
			. 'READING #__ats_posts: the ticket\'s OPENING MESSAGE is a row here, not a column on the '
			. 'ticket. `created_by` <= 0 means ATS\' synthetic "system" user (an automated notice) and does '
			. 'NOT join to #__users. `attachment_id` is a VARCHAR holding a COMMA-SEPARATED LIST of '
			. 'attachment ids, not a foreign key, and it defaults to the string "0". `email_uid` is the '
			. 'Professional mail gateway\'s dedupe key and is always NULL on Core. ALWAYS pass `columns` '
			. 'here — content_html is a LONGTEXT holding full message bodies. '
			. 'READING #__ats_tickets_users: invited collaborators, a CORE feature. No unique index and no '
			. 'index at all beyond the primary key, so duplicate (ticket_id, user_id) rows are possible and '
			. 'orphaned rows are normal — TicketTable::onAfterDelete() never cleans this table. '
			. 'Default limit ' . self::DEFAULT_LIMIT . ', maximum ' . self::MAX_LIMIT . '. Returns the '
			. 'filtered total alongside the page so pagination has an end in sight.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['table'],
			'properties' => [
				'table' => [
					'type' => 'string',
					'description' => 'Table name, with or without the database prefix and with or without '
						. 'the "ats_" prefix: "ats_tickets", "tickets" and "jos_ats_tickets" all resolve to '
						. 'the same table. Must be one of Akeeba Ticket System\'s own allowlisted tables.',
				],
				'columns' => [
					'type'  => 'array',
					'items' => ['type' => 'string'],
					'description' => 'Columns to select. Default: all (SELECT *) — avoid that on ats_posts, '
						. 'whose content_html is a LONGTEXT holding full message bodies.',
				],
				'where' => [
					'type'  => 'array',
					'items' => [
						'type'     => 'object',
						'required' => ['column', 'op'],
						'properties' => [
							'column' => ['type' => 'string', 'description' => 'Must be a real column on the table.'],
							'op'     => ['type' => 'string', 'enum' => self::ALLOWED_OPS],
							'value'  => [
								'description' => 'Required for every operator except IS NULL / IS NOT NULL. '
									. 'For IN and NOT IN, pass an array.',
							],
						],
					],
					'description' => 'Clauses combined with AND. Structured only — raw SQL is not accepted, '
						. 'and there is no OR. Note that ATS datetime columns are genuinely NULLable, so IS '
						. 'NULL is the right test for "never happened" here (unlike some Joomla schemas '
						. 'where it is the zero datetime).',
				],
				'order_by'  => ['type' => 'string', 'description' => 'Column to ORDER BY. Must be a real column.'],
				'order_dir' => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'description' => 'Default ASC.'],
				'limit'     => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => self::MAX_LIMIT,
					'description' => 'Default ' . self::DEFAULT_LIMIT . ', max ' . self::MAX_LIMIT . '.',
				],
				'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Default 0.'],
				'include_schema' => [
					'type' => 'boolean',
					'description' => 'Return the table\'s columns (name, type, nullability, key, default) '
						. 'alongside the rows. Default false.',
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

		$name  = $this->resolveTable($this->requireString($arguments, 'table'));
		$full  = $this->db->getPrefix() . $name;
		$table = $this->db->quoteName($full);

		// The real schema, not an assumed one. Every column the caller names is
		// checked against this before it goes anywhere near the query.
		try {
			$details = $this->db->setQuery('SHOW FULL COLUMNS FROM ' . $table)->loadAssocList() ?: [];
		} catch (\Throwable $e) {
			return ToolResult::error(
				$full . ' is one of Akeeba Ticket System\'s tables but is not present in this database. ATS '
				. 'creates all of its tables from one install file with CREATE TABLE IF NOT EXISTS and no '
				. 'edition branching, so a missing table means an incomplete or partially-rolled-back '
				. 'install rather than a Core/Pro difference. Reinstalling com_ats recreates it without '
				. 'touching existing data. Run list_ats_tables to see what is actually here. ('
				. $e->getMessage() . ')'
			);
		}

		$schema = array_column($details, 'Field');

		if ($schema === []) {
			return ToolResult::error(
				'Could not read the schema of ' . $full . '. Without it no column name can be validated, '
				. 'and this tool will not build a query it cannot validate.'
			);
		}

		$where = $this->buildWhere($arguments['where'] ?? null, $schema, $full);

		$select   = ['*'];
		$selected = null;

		if (!empty($arguments['columns']) && \is_array($arguments['columns'])) {
			$selected = [];

			foreach ($arguments['columns'] as $column) {
				$column = (string) $column;

				if (!\in_array($column, $schema, true)) {
					return ToolResult::error(
						'Unknown column "' . $column . '" on ' . $full . '. Real columns: '
						. implode(', ', $schema) . '.'
					);
				}

				$selected[] = $column;
			}

			if ($selected === []) {
				return ToolResult::error('columns was supplied but empty. Omit it entirely for SELECT *.');
			}

			$select = array_map(fn (string $c): string => $this->db->quoteName($c), $selected);
		}

		$order = '';

		if (!empty($arguments['order_by'])) {
			$orderBy = (string) $arguments['order_by'];

			if (!\in_array($orderBy, $schema, true)) {
				return ToolResult::error(
					'Unknown ORDER BY column "' . $orderBy . '" on ' . $full . '. Real columns: '
					. implode(', ', $schema) . '.'
				);
			}

			$dir   = strtoupper(trim((string) ($arguments['order_dir'] ?? 'ASC')));
			$dir   = \in_array($dir, ['ASC', 'DESC'], true) ? $dir : 'ASC';
			$order = ' ORDER BY ' . $this->db->quoteName($orderBy) . ' ' . $dir;
		}

		$limit = isset($arguments['limit'])
			? max(1, min(self::MAX_LIMIT, (int) $arguments['limit']))
			: self::DEFAULT_LIMIT;

		$offset = isset($arguments['offset']) ? max(0, (int) $arguments['offset']) : 0;

		$total = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $table . $where)->loadResult();

		$rows = $this->db->setQuery(
			'SELECT ' . implode(', ', $select) . ' FROM ' . $table . $where . $order,
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$response = [
			'ok'        => true,
			'table'     => $name,
			'full_name' => $full,
			'total'     => $total,
			'count'     => \count($rows),
			'limit'     => $limit,
			'offset'    => $offset,
			'columns'   => $selected ?? 'all',
			'rows'      => $rows,
			'ungated'   => 'These rows are RAW. This tool does not apply the per-ticket visibility check '
				. 'that list_ats_tickets, get_ats_ticket and the report tools apply — that gate needs a '
				. 'loaded TicketTable per row and cannot be expressed over arbitrary columns of arbitrary '
				. 'tables. Do not present this output as "what user X can see".',
		];

		if (!empty($arguments['include_schema'])) {
			$response['schema'] = array_map(
				static fn (array $c): array => [
					'column'    => $c['Field'] ?? null,
					'type'      => $c['Type'] ?? null,
					'collation' => $c['Collation'] ?? null,
					'null'      => $c['Null'] ?? null,
					'key'       => $c['Key'] ?? null,
					'default'   => $c['Default'] ?? null,
					'extra'     => $c['Extra'] ?? null,
				],
				$details
			);
		}

		$heavy = array_values(array_intersect(
			self::HEAVY_COLUMNS[$name] ?? [],
			$selected ?? $schema
		));

		if ($heavy !== [] && $rows !== []) {
			$response['payload_warning'] = 'This result includes ' . implode(', ', $heavy)
				. ', which are TEXT/LONGTEXT columns and can be very large per row — '
				. ($name === 'ats_posts'
					? 'ats_posts.content_html is the full HTML body of a customer or agent message.'
					: 'pass `columns` to exclude them unless you specifically need the text.');
		}

		if (isset(self::HANDLING[$name])) {
			$response['handling'] = self::HANDLING[$name];
		}

		if (isset(self::ATS_TABLES[$name])) {
			$response['about_this_table'] = self::ATS_TABLES[$name];
		}

		$response['reading_this_table'] = $this->readingNotes($name);

		if (\in_array($name, ['ats_attachments', 'ats_cannedreplies', 'ats_autoreplies'], true)) {
			$response['edition_note'] = $this->atsIsPro()
				? 'This is a Professional-only table and this site IS running Professional, so rows here '
					. 'are live.'
				: 'This is a Professional-only table and this site is running ATS CORE. The table exists '
					. 'because ATS\' installer creates every table unconditionally, with no edition '
					. 'branching. ATS never reads or writes it on Core, so an empty result is expected — '
					. 'and a NON-empty one means this site was downgraded from Professional and is still '
					. 'holding data the interface will never show.';
		}

		return ToolResult::json($response);
	}

	/**
	 * Resolve a caller-supplied table name to one of ATS' allowlisted tables.
	 *
	 * Accepts the unprefixed name ("ats_tickets"), the fully-prefixed name
	 * ("jos_ats_tickets") or the short form ("tickets"). Refuses everything
	 * else — including real ATS tables that are deliberately off limits, which
	 * are named and explained rather than lumped in with "unknown".
	 */
	private function resolveTable(string $name): string
	{
		$input = trim($name);

		// Character set first: the candidates below are built by concatenation, so
		// anything exotic has to be rejected before it reaches that step.
		if (preg_match('/^[A-Za-z0-9_]+$/', $input) !== 1) {
			throw new \InvalidArgumentException(
				'Invalid table name "' . $input . '". Use only [A-Za-z0-9_].'
			);
		}

		$prefix = $this->db->getPrefix();
		$lower  = strtolower($input);

		if ($prefix !== '' && str_starts_with($lower, strtolower($prefix))) {
			$lower = substr($lower, \strlen($prefix));
		}

		$candidates = [$lower];

		if (!str_starts_with($lower, 'ats_')) {
			$candidates[] = 'ats_' . $lower;
		}

		// Named refusals come FIRST, so a deliberately-excluded table gets its
		// real explanation rather than a generic "not one of ours".
		foreach ($candidates as $candidate) {
			if (isset(self::REFUSED[$candidate])) {
				throw new \InvalidArgumentException(self::REFUSED[$candidate]);
			}
		}

		foreach ($candidates as $candidate) {
			if (isset(self::ATS_TABLES[$candidate])) {
				return $candidate;
			}
		}

		$hint = '';

		if (\in_array($lower, ['users', 'user_usergroup_map', 'usergroups', 'user_profiles'], true)) {
			$hint = ' Joomla user tables are deliberately out of reach here: this tool exists to read '
				. 'Akeeba Ticket System\'s own data, and widening it to the user tables would turn it into '
				. 'an arbitrary database reader. #__ats_tickets carries created_by, modified_by and '
				. 'assigned_to if you need to correlate, and cs-mcp-for-j has its own user tools.';
		} elseif (\in_array($lower, ['categories', 'ats_categories'], true)) {
			$hint = ' Ticket categories are not an ATS table — they are ordinary Joomla categories in '
				. '#__categories with extension = "com_ats", and #__categories is core Joomla rather than '
				. 'ATS\' own. get_ats_component_info returns the full ticket-category inventory with ticket '
				. 'counts, access levels and languages.';
		} elseif (\in_array($lower, ['fields', 'fields_values', 'ats_fields'], true)) {
			$hint = ' Ticket custom fields are Joomla-native: #__fields and #__fields_values with context '
				. '"com_ats.ticket" and item_id = the ticket id. Those are core Joomla tables, not ATS\' '
				. 'own, so they are out of scope here.';
		} elseif (\in_array($lower, ['tags', 'contentitem_tag_map', 'ats_tags'], true)) {
			$hint = ' Ticket tags are Joomla-native too — #__contentitem_tag_map with type_alias '
				. '"com_ats.ticket". (Do not confuse them with ATS\' USER tags, which are a separate '
				. 'Professional feature.) Core Joomla tables are out of scope for this add-on.';
		} elseif (\in_array($lower, ['extensions', 'session', 'assets', 'menu', 'modules', 'viewlevels'], true)) {
			$hint = ' Core Joomla tables are out of scope for this add-on; cs-mcp-for-j has purpose-built '
				. 'tools for them.';
		}

		throw new \InvalidArgumentException(
			'Refused: "' . $input . '" is not one of Akeeba Ticket System\'s allowlisted tables. This tool '
			. 'is scoped to the ' . \count(self::ATS_TABLES) . ' tables listed by list_ats_tables and '
			. 'cannot be used to reach the rest of the database.' . $hint
			. ' Run list_ats_tables for the full list.'
		);
	}

	/** Per-table gotchas worth stating with the rows rather than in a manual. */
	private function readingNotes(string $name): string
	{
		return match ($name) {
			'ats_tickets' => 'ENUM GOTCHA FIRST: `status` is an ENUM, so a site-defined status must be '
				. 'compared as a STRING ("7", not 7) — MySQL treats an unquoted integer as the ENUM '
				. 'ORDINAL and matches the wrong member. Every value this tool builds into SQL is quoted, '
				. 'so filters here are safe; hand-written SQL elsewhere is not. '
				. 'There is NO access column and NO language column — a ticket inherits both '
				. 'from its category, and catid is a bare bigint with no foreign key (a matching '
				. '#__categories row is only an ATS category if extension = "com_ats"). `enabled` is the '
				. 'publish flag; TicketTable aliases it as `published`. `status` is an ENUM: "O" Open '
				. '(waiting on support), "P" Pending (waiting on the CUSTOMER — only a manager\'s reply '
				. 'sets it), "C" Closed, plus the strings "1".."99" for site-defined statuses labelled in '
				. 'the customStatuses parameter. `modified` / `modified_by` mean LAST REPLY, not last '
				. 'edited: only PostTable::onAfterStore() writes them, only for a new post, and only when '
				. 'the ticket is not already Closed — so an edit never moves them and a reply to a closed '
				. 'ticket never moves them either. `timespent` is DERIVED (SUM over the ticket\'s posts '
				. 'WHERE enabled = 1, recomputed on each new reply to a non-closed ticket), so writing it '
				. 'is pointless. `priority` is TINYINT with NO DEFAULT — an INSERT omitting it errors under '
				. 'STRICT_TRANS_TABLES — with 0 High, 5 Normal, 10 Low, and the field is hidden in the UI '
				. 'unless ticketPriorities is 1. `origin` only ever holds "web" or "email"; anything else '
				. 'is rewritten by onBeforeCheck(). `assigned_to` is NOT NULL DEFAULT 0, so 0 means '
				. 'unassigned — test for 0, not for NULL. Tickets cannot be checked out: there is no '
				. 'checked_out column.',
			'ats_posts' => 'The ticket\'s OPENING MESSAGE is a row here — there is no body column on '
				. '#__ats_tickets. `created_by` <= 0 is ATS\' synthetic "system" user (an automated '
				. 'notice); Permissions::getUser(-1) fabricates a User with username "system", and those '
				. 'rows do NOT join to #__users. A system post never changes the ticket status. '
				. '`attachment_id` is a VARCHAR(512) holding a COMMA-SEPARATED LIST of attachment ids with '
				. 'the default string "0" — it is not a foreign key and will not join. `email_uid` belongs '
				. 'to the Professional mail gateway and is always NULL on Core. `enabled` is the publish '
				. 'flag, and it matters more than usual: the parent ticket\'s timespent is SUM(timespent) '
				. 'over posts WHERE enabled = 1, so unpublishing a post reduces the ticket total only on '
				. 'the NEXT reply. `content_html` is a LONGTEXT — always name your columns.',
			'ats_tickets_users' => 'Invited collaborators. A CORE feature, so rows here are normal on a '
				. 'free install. Three columns, no index at all beyond the primary key: NO unique '
				. 'constraint on (ticket_id, user_id), so duplicates are possible and are prevented only by '
				. 'a non-atomic check in TicketModel::inviteUser(). There is no Table class and no events — '
				. 'the rows are written and deleted by raw INSERT/DELETE. TicketTable::onAfterDelete() does '
				. 'NOT clean this table, so orphaned rows pointing at deleted tickets are the normal state '
				. 'of a long-lived site rather than a fault. An invitation grants view AND post on that one '
				. 'ticket, overriding the category\'s access level — getTicketPrivileges() sets both to '
				. 'true for an invited user. check_ats_health reports orphans and duplicates.',
			'ats_attachments' => 'Professional only. `post_id` links to #__ats_posts.id, but note that the '
				. 'post side of that relationship is the comma-separated attachment_id VARCHAR, not a key. '
				. '`mangled_filename` is the name on disk under the attachments_folder parameter; '
				. '`original_filename` is what the uploader called it. On Core this table is empty and '
				. 'Permissions::getTicketPrivileges() hard-sets the attachment privilege to false '
				. 'regardless of ACL.',
			'ats_cannedreplies' => 'Professional only. WATCH THE `access` COLUMN: the install SQL declares '
				. 'it `int(11) NULL DEFAULT \'0\'`, and 0 is NOT a valid Joomla view level (#__viewlevels '
				. 'starts at 1). The only correction ATS ever shipped is one line in the 5.0.6 update file, '
				. 'which runs on UPGRADE and not on a fresh install — so sites installed after 5.0.6 carry '
				. 'the broken default and the affected replies are offered to nobody. check_ats_health '
				. 'reports them.',
			'ats_autoreplies' => 'Professional only. NO COLUMN HAS A DEFAULT except the primary key, so a '
				. 'partial INSERT errors under STRICT_TRANS_TABLES. `keywords_title` and `keywords_text` '
				. 'are the trigger terms, `num_posts` and `min_after` the throttles, `run_after_manager` '
				. 'whether the rule may fire on a manager\'s reply.',
			default => 'See list_ats_tables for this table\'s note.',
		};
	}

	/**
	 * Build the WHERE fragment from structured clauses.
	 *
	 * Returns a fully-quoted SQL string beginning with ' WHERE ', or ''. Column
	 * names are validated against the real schema and quoted, operators come
	 * from a fixed allowlist, and every value is quoted. Nothing the caller
	 * sends reaches SQL unescaped.
	 *
	 * @param array<int, string> $schema
	 */
	private function buildWhere(mixed $clauses, array $schema, string $full): string
	{
		if (!\is_array($clauses) || $clauses === []) {
			return '';
		}

		$parts = [];

		foreach ($clauses as $index => $clause) {
			if (!\is_array($clause)) {
				throw new \InvalidArgumentException(
					'where[' . $index . '] must be an object of {column, op, value}, not a raw SQL string. '
					. 'This tool accepts no raw SQL.'
				);
			}

			$column = (string) ($clause['column'] ?? '');
			$op     = strtoupper(trim((string) ($clause['op'] ?? '=')));

			if (!\in_array($column, $schema, true)) {
				throw new \InvalidArgumentException(
					'Unknown WHERE column "' . $column . '" at where[' . $index . '] on ' . $full
					. '. Real columns: ' . implode(', ', $schema) . '.'
				);
			}

			if (!\in_array($op, self::ALLOWED_OPS, true)) {
				throw new \InvalidArgumentException(
					'Disallowed WHERE operator "' . $op . '" at where[' . $index . ']. Allowed: '
					. implode(', ', self::ALLOWED_OPS) . '.'
				);
			}

			$quoted = $this->db->quoteName($column);

			if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
				$parts[] = $quoted . ' ' . $op;

				continue;
			}

			if ($op === 'IN' || $op === 'NOT IN') {
				$values = $clause['value'] ?? [];

				if (!\is_array($values) || $values === []) {
					throw new \InvalidArgumentException(
						$op . ' at where[' . $index . '] requires a non-empty array of values.'
					);
				}

				$list = [];

				foreach ($values as $value) {
					if (\is_array($value) || \is_object($value)) {
						throw new \InvalidArgumentException(
							$op . ' at where[' . $index . '] accepts only scalar values.'
						);
					}

					$list[] = $this->db->quote((string) $value);
				}

				$parts[] = $quoted . ' ' . $op . ' (' . implode(', ', $list) . ')';

				continue;
			}

			if (!\array_key_exists('value', $clause) || $clause['value'] === null) {
				throw new \InvalidArgumentException(
					'Operator ' . $op . ' at where[' . $index . '] requires a value. Use IS NULL to test '
					. 'for null — ATS\' datetime columns are genuinely NULLable, so IS NULL really is the '
					. 'right test for "never happened" in this schema.'
				);
			}

			$value = $clause['value'];

			if (\is_array($value) || \is_object($value)) {
				throw new \InvalidArgumentException(
					'Operator ' . $op . ' at where[' . $index . '] accepts only a scalar value.'
				);
			}

			if (\is_bool($value)) {
				$value = $value ? '1' : '0';
			}

			$parts[] = $quoted . ' ' . $op . ' ' . $this->db->quote((string) $value);
		}

		return $parts === [] ? '' : ' WHERE ' . implode(' AND ', $parts);
	}
}
