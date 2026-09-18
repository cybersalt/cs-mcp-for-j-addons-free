<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\Lookups;

\defined('_JEXEC') or die;

use Akeeba\Component\ATS\Administrator\Helper\Permissions;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjatsfree\Tools\ATSBootTrait;
use Joomla\CMS\User\User;

/**
 * The ticket statuses this site actually uses, with real counts behind them.
 *
 * WHERE THE LIST COMES FROM.
 * ---------------------------
 * Permissions::getStatuses() is the only authority, and its return shape is easy
 * to get wrong: it is a FLAT MAP of code => label, not a list of value/text
 * options (Helper/Permissions.php:529-604). It seeds ['O' => …, 'P' => …],
 * appends every custom status parsed out of the `customStatuses` component
 * parameter, and then appends 'C' last — deliberately, with a comment saying
 * "The Closed status must always be AFTER any custom status". That ordering is
 * the component's own presentation order and is preserved here.
 *
 * The custom entries are stored with INTEGER array keys, because the parser
 * casts with `(int)`, while #__ats_tickets.status is an ENUM whose numeric
 * members are the STRINGS '1' through '99'. Anything comparing a status code
 * against that map has to cast, or it silently never matches.
 *
 * WHY WE ALSO COUNT.
 * -------------------
 * "Which statuses does this site use" has two different answers and a caller
 * needs both. getStatuses() says which are CONFIGURED. A GROUP BY over
 * #__ats_tickets says which are IN USE. They disagree in both directions, and
 * each disagreement is worth knowing about:
 *
 *   - A configured status with zero tickets is just unused.
 *   - A status code sitting on real tickets that getStatuses() does not list is
 *     ORPHANED. That happens when a custom status is deleted from the
 *     customStatuses parameter while tickets are still on it. The ENUM accepts
 *     '1'..'99' unconditionally — the whitelist is the component parameter, not
 *     the schema — so the tickets keep the code and lose only the label. They
 *     are still filterable by that code; they just render namelessly.
 *
 * The parameter parse is also reported, because the parser rejects entries
 * silently. An id outside 1..99, a missing label, or — the one that catches
 * people — a label containing an '=' sign, which makes explode('=') return three
 * parts and the line is dropped. The diagnostics below re-run the vendor's rules
 * to say WHICH line was ignored; the labels themselves still come from
 * getStatuses(), never from this parse.
 *
 * COUNTS ARE CATEGORY-SCOPED, NOT TICKET-GATED. Making them per-ticket accurate
 * would mean running the full privilege check over the whole table. Instead they
 * are restricted to the ATS categories the actor can access, which is the same
 * first-pass filter ATS' own ticket list applies, and the response says plainly
 * that a private ticket the actor may not read still contributes to the number.
 */
final class ListStatusesTool extends AbstractTool
{
	use ATSBootTrait;

	public function getName(): string { return 'list_ats_statuses'; }

	public function getDescription(): string
	{
		return 'List the Akeeba Ticket System ticket statuses configured on this site, in the '
			. 'component\'s own order, cross-referenced with how many tickets actually carry each one. '
			. 'Returns per status: code (the literal value stored in #__ats_tickets.status and the value '
			. 'to pass to list_ats_tickets), label, builtin (true for the three ATS ships with), '
			. 'in_use, ticket_count, published_ticket_count and category_count. '
			. 'THE THREE BUILT-INS are \'O\' Open — waiting on the support team; \'P\' Pending — '
			. 'waiting on the customer, which ONLY a manager\'s reply produces; and \'C\' Closed. '
			. 'Anything else is a SITE-DEFINED status: the status column is an ENUM that also accepts '
			. 'the strings \'1\' through \'99\', and their labels come from the customStatuses component '
			. 'parameter. ATS always sorts Closed last, after the custom ones, and this tool preserves '
			. 'that. '
			. 'ORPHANED STATUSES: a code found on real tickets that the configuration no longer defines '
			. 'is returned with orphaned = true and a null-ish generated label. That is what you get '
			. 'when a custom status is removed from customStatuses while tickets are still sitting on '
			. 'it — the database ENUM permits 1..99 regardless of configuration, so the tickets keep '
			. 'the code and only lose the name. They remain filterable by that code. '
			. '`custom_status_config` reports how the parameter is stored (newline-separated "id=Label" '
			. 'text, or a structured list) and which entries the component\'s parser REJECTED and why — '
			. 'it drops silently on an id outside 1..99, an empty label, or a label containing an "=" '
			. 'sign. '
			. 'COUNTS are SQL aggregates restricted to the ATS categories you can access (unrestricted '
			. 'if you hold core.admin or core.manage on com_ats). Within those categories they include '
			. 'private and unpublished tickets, including ones you personally may not read, so treat '
			. 'them as queue statistics rather than as a preview of list_ats_tickets. Set '
			. 'include_unused to false to return only statuses that at least one ticket carries.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'include_unused' => [
					'type'        => 'boolean',
					'description' => 'Default true. When false, only statuses with at least one ticket are returned.',
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

		$includeUnused = !array_key_exists('include_unused', $arguments) || (bool) $arguments['include_unused'];

		// Configured statuses, in the component's presentation order. Flat map, integer keys
		// for the custom ones — see the class docblock.
		try {
			$configured = Permissions::getStatuses();
		} catch (\Throwable $e) {
			return ToolResult::error(
				'Could not read the ticket statuses from Akeeba Ticket System: ' . $e->getMessage()
			);
		}

		$scope = $this->categoryScope($actor);
		$usage = $this->usageByStatus($scope['category_ids'], $scope['unrestricted']);

		$statuses   = [];
		$seenCodes  = [];

		foreach ($configured as $rawCode => $label) {
			$code        = (string) $rawCode;
			$seenCodes[] = $code;
			$stats       = $usage[$code] ?? null;

			if (!$includeUnused && $stats === null) {
				continue;
			}

			$statuses[] = [
				'code'                   => $code,
				'label'                  => (string) $label,
				'builtin'                => \in_array($code, ['O', 'P', 'C'], true),
				'orphaned'               => false,
				'in_use'                 => $stats !== null,
				'ticket_count'           => (int) ($stats['total'] ?? 0),
				'published_ticket_count' => (int) ($stats['published'] ?? 0),
				'category_count'         => (int) ($stats['categories'] ?? 0),
				'meaning'                => $this->builtinMeaning($code),
			];
		}

		// Codes on real tickets that the configuration does not define.
		$orphaned = 0;

		foreach ($usage as $code => $stats) {
			if (\in_array($code, $seenCodes, true)) {
				continue;
			}

			$orphaned++;

			$statuses[] = [
				'code'                   => $code,
				'label'                  => null,
				'builtin'                => false,
				'orphaned'               => true,
				'in_use'                 => true,
				'ticket_count'           => (int) $stats['total'],
				'published_ticket_count' => (int) $stats['published'],
				'category_count'         => (int) $stats['categories'],
				'meaning'                => 'No label. This code is on live tickets but is not defined in the '
					. 'customStatuses component parameter — normally a custom status that was deleted while '
					. 'tickets still used it. The ENUM permits \'1\'..\'99\' regardless of configuration, so '
					. 'the tickets kept the code. You can still filter list_ats_tickets by it.',
			];
		}

		$builtinCount = \count(array_filter($statuses, static fn(array $s): bool => $s['builtin']));
		$customCount  = \count($statuses) - $builtinCount - $orphaned;

		return ToolResult::json([
			'ok'                   => true,
			'count'                => \count($statuses),
			'builtin_count'        => $builtinCount,
			'custom_count'         => $customCount,
			'orphaned_count'       => $orphaned,
			'statuses'             => $statuses,
			'custom_status_config' => $this->describeCustomStatusParam(),
			'counts_scope'         => $scope['unrestricted']
				? 'All ATS categories: you hold core.admin or core.manage on com_ats.'
				: 'Restricted to the ' . \count($scope['category_ids']) . ' ATS category/categories whose view access level you hold'
					. (empty($scope['category_ids']) ? ' — which is none, so every count is zero.' : '.'),
			'note'                 => [
				'order'      => 'The order here is the component\'s own: Open, Pending, then any site-defined statuses, then Closed. Permissions::getStatuses() appends Closed last on purpose.',
				'types'      => 'O, P and C are built in. Everything else is site-defined and lives in the customStatuses component parameter; the database column is an ENUM of O, P, C and the strings \'1\'..\'99\'.',
				'pending'    => 'Pending means "waiting for the customer" and is set only by a MANAGER\'s reply to someone else\'s ticket. A manager replying to a ticket they opened themselves gets Open, and the whole transition block is skipped when the ticket is already Closed — posting to a closed ticket does not reopen it.',
				'filtering'  => 'Pass `code` verbatim to list_ats_tickets\' status filter, as a string. A numeric status compared as an integer would be read by MySQL as an ENUM ordinal and match the wrong row.',
				'counts'     => 'Counts are SQL aggregates scoped to the categories you can access. Within those they include private and unpublished tickets, including ones you may not personally read.',
				'labels'     => 'Labels are translated through Joomla\'s language layer, so a customStatuses entry whose label happens to be a language key comes back translated rather than literal.',
			],
		]);
	}

	/** Short plain-English meaning for the three ATS ships with. */
	private function builtinMeaning(string $code): ?string
	{
		return [
			'O' => 'Open — waiting on the support team. Any non-manager reply, and a manager\'s reply to their own ticket, lands here.',
			'P' => 'Pending — waiting on the customer. Only a manager\'s reply to someone else\'s ticket sets this.',
			'C' => 'Closed. While a ticket is closed, non-managers keep view and lose everything else, and a new post does not reopen it, does not touch the last-reply timestamp and does not recompute timespent.',
		][$code] ?? null;
	}

	/**
	 * Which ATS categories the counts may look at.
	 *
	 * Global staff (core.admin or core.manage on com_ats) are unrestricted, matching
	 * Permissions::getManagerCategories() and getPostableCategories(), which both skip
	 * the view-level filter for exactly that case.
	 *
	 * @return array{unrestricted: bool, category_ids: array<int, int>}
	 */
	private function categoryScope(User $actor): array
	{
		if ($actor->authorise('core.admin', 'com_ats') || $actor->authorise('core.manage', 'com_ats')) {
			return ['unrestricted' => true, 'category_ids' => []];
		}

		$db = $this->db;

		$ids = $db->setQuery(
			$db->getQuery(true)
				->select($db->quoteName('id'))
				->from($db->quoteName('#__categories'))
				->where($db->quoteName('extension') . ' = ' . $db->quote('com_ats'))
		)->loadColumn() ?: [];

		$allowed = [];

		foreach ($ids as $id) {
			// Memoised by the helper, and fails closed on a category that does not resolve.
			if (Permissions::canAccessCategory((int) $id, $actor)) {
				$allowed[] = (int) $id;
			}
		}

		return ['unrestricted' => false, 'category_ids' => $allowed];
	}

	/**
	 * Tickets per status code, within scope.
	 *
	 * @param array<int, int> $categoryIds
	 *
	 * @return array<string, array{total: int, published: int, categories: int}>
	 */
	private function usageByStatus(array $categoryIds, bool $unrestricted): array
	{
		if (!$unrestricted && empty($categoryIds)) {
			return [];
		}

		$db = $this->db;

		$query = $db->getQuery(true)
			->select([
				$db->quoteName('status'),
				'COUNT(*) AS ' . $db->quoteName('total'),
				'SUM(CASE WHEN ' . $db->quoteName('enabled') . ' = 1 THEN 1 ELSE 0 END) AS ' . $db->quoteName('published'),
				'COUNT(DISTINCT ' . $db->quoteName('catid') . ') AS ' . $db->quoteName('categories'),
			])
			->from($db->quoteName('#__ats_tickets'))
			->group($db->quoteName('status'));

		if (!$unrestricted) {
			$query->where($db->quoteName('catid') . ' IN (' . implode(',', array_map('intval', $categoryIds)) . ')');
		}

		$out = [];

		foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
			$out[(string) $row['status']] = [
				'total'      => (int) $row['total'],
				'published'  => (int) $row['published'],
				'categories' => (int) $row['categories'],
			];
		}

		return $out;
	}

	/**
	 * Describe the customStatuses parameter and, crucially, report what the vendor's
	 * parser threw away.
	 *
	 * This re-runs the vendor's acceptance rules (Permissions.php:541-598) purely to
	 * explain rejections. The labels shown to the caller always come from
	 * getStatuses() itself — this parse is never used as a source of truth.
	 *
	 * @return array<string, mixed>
	 */
	private function describeCustomStatusParam(): array
	{
		$raw = $this->atsParams()->get('customStatuses', '');

		if ($raw === null || (\is_string($raw) && trim($raw) === '') || (!\is_string($raw) && empty((array) $raw))) {
			return [
				'configured' => false,
				'format'     => 'empty',
				'accepted'   => [],
				'rejected'   => [],
				'note'       => 'No site-defined statuses. Only O, P and C are in play.',
			];
		}

		$accepted = [];
		$rejected = [];

		if (\is_string($raw)) {
			$normalised = str_replace("\n\n", "\n", str_replace("\r", "\n", str_replace('\\n', "\n", $raw)));

			foreach (explode("\n", $normalised) as $lineNo => $line) {
				if (trim($line) === '') {
					continue;
				}

				$parts = explode('=', $line);

				if (\count($parts) !== 2) {
					$rejected[] = [
						'line'   => $lineNo + 1,
						'value'  => $line,
						'reason' => \count($parts) > 2
							? 'The parser uses explode("=") and requires exactly two parts, so a label containing an "=" sign is dropped silently.'
							: 'No "=" separator. The expected form is id=Label.',
					];

					continue;
				}

				$id    = trim($parts[0]);
				$label = trim($parts[1]);

				if (!is_numeric($id)) {
					$rejected[] = ['line' => $lineNo + 1, 'value' => $line, 'reason' => 'The id part is not numeric.'];

					continue;
				}

				if ((int) $id <= 0 || (int) $id > 99) {
					$rejected[] = ['line' => $lineNo + 1, 'value' => $line, 'reason' => 'The id must be between 1 and 99; the ENUM has no member outside that range.'];

					continue;
				}

				if ($label === '') {
					$rejected[] = ['line' => $lineNo + 1, 'value' => $line, 'reason' => 'Empty label.'];

					continue;
				}

				$accepted[] = ['code' => (string) (int) $id, 'raw_label' => $label];
			}

			return [
				'configured' => true,
				'format'     => 'text',
				'accepted'   => $accepted,
				'rejected'   => $rejected,
				'note'       => 'Stored as newline-separated "id=Label" lines. Each label is passed through '
					. 'Joomla\'s translator, so a label that happens to be a language key is translated.',
			];
		}

		// The structured shape: an object/array of {id, label} entries.
		foreach ((array) $raw as $entry) {
			$id    = \is_object($entry) ? ($entry->id ?? null) : ($entry['id'] ?? null);
			$label = \is_object($entry) ? ($entry->label ?? null) : ($entry['label'] ?? null);

			if (empty($id) || (int) $id < 1 || (int) $id > 99 || empty($label)) {
				$rejected[] = [
					'value'  => json_encode($entry),
					'reason' => 'Rejected: id must be 1..99 and label must be non-empty.',
				];

				continue;
			}

			$accepted[] = ['code' => (string) (int) $id, 'raw_label' => (string) $label];
		}

		return [
			'configured' => true,
			'format'     => 'structured',
			'accepted'   => $accepted,
			'rejected'   => $rejected,
			'note'       => 'Stored as a structured list of {id, label} entries. Labels are translated through '
				. 'Joomla\'s language layer.',
		];
	}
}
