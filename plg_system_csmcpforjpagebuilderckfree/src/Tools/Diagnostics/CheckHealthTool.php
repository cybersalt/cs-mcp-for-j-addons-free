<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckBootTrait;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckContentTrait;
use Joomla\CMS\User\User;

/**
 * Site-wide audit of Page Builder CK's data.
 *
 * Everything checked here is something Page Builder CK will not tell you about.
 * Its admin screens report no warnings, its save path validates nothing, and its
 * worst failure mode — a block whose addon is gone, which renders as inert but
 * perfectly styled static HTML — is invisible from both the front end and the
 * back end.
 *
 * Every check is independently guarded. A missing table or a malformed row
 * downgrades that ONE check to "skipped" with a reason; it never aborts the
 * audit. An install missing half its tables should still get an answer about the
 * half it has.
 *
 * The content checks share one capped, chunked scan of `htmlcode`. Page content
 * is a longtext and a large site's worth would not fit in memory at once, so
 * rows are pulled in batches and released, and the response says plainly when
 * the cap was hit rather than implying the counts are complete.
 */
final class CheckHealthTool extends AbstractTool
{
	use PagebuilderckBootTrait;
	use PagebuilderckContentTrait;

	/** Every check, in report order. */
	private const CHECKS = [
		'version',
		'unsafe_content',
		'duplicate_ids',
		'unrenderable_types',
		'row_geometry',
		'style_size',
		'checked_out',
		'untokenised_root',
	];

	/** Below this, known actively-exploited RCEs are unpatched. */
	private const SECURITY_FLOOR = '3.6.5';

	/** Ceiling on how many page rows the content scan parses. */
	private const CONTENT_SCAN_CAP = 300;

	/** Rows pulled per batch, so one longtext-heavy page cannot blow the heap. */
	private const CONTENT_SCAN_CHUNK = 20;

	/** `text` column ceiling for #__pagebuilderck_styles. */
	private const TEXT_LIMIT = 65535;

	/** Fraction of that ceiling at which a style row is reported. */
	private const TEXT_WARN_AT = 0.9;

	public function getName(): string { return 'check_pagebuilderck_health'; }

	public function getDescription(): string
	{
		return 'Audit Page Builder CK site-wide and report findings grouped by severity, each with the '
			. 'page ids affected and a concrete remedy. Read-only — it changes nothing. '
			. 'Checks: (1) the installed version, flagging anything below ' . self::SECURITY_FLOOR
			. ' as carrying known, actively exploited remote code execution; (2) pages whose htmlcode is '
			. 'not LOSSLESS — it does not survive a parse and re-serialise byte for byte, so no write '
			. 'tool in this add-on will touch it and any edit would silently alter markup nobody asked '
			. 'to change; (3) duplicate block ids within a single page, which matter because all Page '
			. 'Builder CK block CSS is #id-scoped, so a duplicate cross-applies one block\'s styling to '
			. 'another; (4) blocks whose data-type has no enabled `pagebuilderck` plugin — these do NOT '
			. 'render blank, they render their inner markup as static HTML with the CSS still applied '
			. 'and no error anywhere (site/models/page.php:645-646 then :575), so the page looks almost '
			. 'right while being inert; (5) rows whose data-nb attribute disagrees with their real '
			. 'column count, which stops the generated [data-gutter][data-nb][data-width] width rules '
			. 'matching so every column in that row renders with no width; (6) #__pagebuilderck_styles '
			. 'rows approaching the 65,535-byte ceiling of their `text` columns, which MySQL truncates '
			. 'SILENTLY in non-strict mode, corrupting CSS at an arbitrary byte; (7) rows left checked '
			. 'out — checked_out is a varchar holding a user id and Page Builder CK never releases it '
			. 'automatically, so one abandoned editing session locks an item permanently; (8) pages '
			. 'containing the literal site root instead of the |URIROOT| token, which break the moment '
			. 'the site changes domain or subdirectory. '
			. 'Pass `checks` to run a subset and `limit` to cap how many example ids each finding lists; '
			. 'counts are exact regardless. The content checks share one capped scan of the '
			. self::CONTENT_SCAN_CAP . ' most recently modified pages — when that cap is reached the '
			. 'response says so and their counts are lower bounds.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'checks' => [
					'type'        => 'array',
					'items'       => ['type' => 'string', 'enum' => self::CHECKS],
					'description' => 'Subset of checks to run. Omit for all of them.',
				],
				'limit' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => 'Maximum example ids listed per finding. Default 10. Counts are always exact.',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->pbckAdminBase() === null) {
			return $this->pbckNotInstalledError();
		}

		$limit = max(1, min(100, (int) ($arguments['limit'] ?? 10)));

		try {
			$requested = $this->requestedChecks($arguments);
		} catch (\InvalidArgumentException $e) {
			return ToolResult::json(['ok' => false, 'error' => $e->getMessage()], true);
		}

		$contentChecks = ['unsafe_content', 'duplicate_ids', 'unrenderable_types', 'row_geometry', 'untokenised_root'];
		$scan          = null;

		if (array_intersect($requested, $contentChecks) !== []) {
			try {
				$scan = $this->scanPages();
			} catch (\Throwable $e) {
				$scan = ['__error' => $e->getMessage()];
			}
		}

		$findings = [];

		foreach ($requested as $check) {
			$findings[] = $this->guard($check, fn (): array => match ($check) {
				'version'            => $this->checkVersion(),
				'unsafe_content'     => $this->checkUnsafeContent($scan ?? [], $limit),
				'duplicate_ids'      => $this->checkDuplicateIds($scan ?? [], $limit),
				'unrenderable_types' => $this->checkUnrenderableTypes($scan ?? [], $limit),
				'row_geometry'       => $this->checkRowGeometry($scan ?? [], $limit),
				'style_size'         => $this->checkStyleSize($limit),
				'checked_out'        => $this->checkCheckedOut($limit),
				'untokenised_root'   => $this->checkUntokenisedRoot($scan ?? [], $limit),
				default              => throw new \RuntimeException('Unknown check.'),
			});
		}

		$summary    = ['critical' => 0, 'warning' => 0, 'notice' => 0, 'ok' => 0, 'skipped' => 0];
		$bySeverity = ['critical' => [], 'warning' => [], 'notice' => []];

		foreach ($findings as $finding) {
			if ($finding['status'] === 'skipped') {
				$summary['skipped']++;

				continue;
			}

			if ($finding['status'] === 'ok') {
				$summary['ok']++;

				continue;
			}

			$severity = (string) $finding['severity'];
			$summary[$severity] = ($summary[$severity] ?? 0) + 1;

			$bySeverity[$severity][] = [
				'check' => $finding['check'],
				'title' => $finding['title'],
				'count' => $finding['count'],
				'ids'   => $finding['ids'] ?? [],
			];
		}

		$response = [
			'ok'          => true,
			'checked'     => \count($findings),
			'summary'     => $summary,
			'by_severity' => $bySeverity,
			'findings'    => $findings,
			'component'   => $this->pbckEditionNotice(),
		];

		if (\is_array($scan) && isset($scan['__error'])) {
			$response['scan_error'] = 'The content scan failed, so every content-based check was '
				. 'skipped: ' . (string) $scan['__error'];
		} elseif (\is_array($scan) && ($scan['truncated'] ?? false)) {
			$response['scan_note'] = sprintf(
				'The content checks parsed the %d most recently modified pages of %d and stopped there. '
					. 'htmlcode is a longtext and an unbounded scan risks exhausting memory, so counts '
					. 'for unsafe_content, duplicate_ids, unrenderable_types, row_geometry and '
					. 'untokenised_root are LOWER BOUNDS, not totals.',
				(int) $scan['scanned'],
				(int) $scan['total']
			);
		}

		return ToolResult::json($response);
	}

	// -----------------------------------------------------------------------
	// Plumbing
	// -----------------------------------------------------------------------

	/**
	 * @return array<int,string>
	 */
	private function requestedChecks(array $arguments): array
	{
		$raw = $arguments['checks'] ?? null;

		if (!\is_array($raw) || $raw === []) {
			return self::CHECKS;
		}

		$wanted = [];

		foreach ($raw as $value) {
			$name = strtolower(trim((string) $value));

			if (!\in_array($name, self::CHECKS, true)) {
				throw new \InvalidArgumentException(
					'Unknown check "' . $name . '". Available: ' . implode(', ', self::CHECKS) . '.'
				);
			}

			$wanted[$name] = true;
		}

		return array_values(array_filter(self::CHECKS, static fn (string $c): bool => isset($wanted[$c])));
	}

	/**
	 * Run one check inside its own error boundary.
	 *
	 * @param  callable(): array<string,mixed> $callback
	 * @return array<string,mixed>
	 */
	private function guard(string $name, callable $callback): array
	{
		try {
			return ['check' => $name] + $callback();
		} catch (\Throwable $e) {
			return ['check' => $name, 'status' => 'skipped', 'reason' => $e->getMessage()];
		}
	}

	/** @return array<string,mixed> */
	private function clear(string $title): array
	{
		return ['status' => 'ok', 'title' => $title, 'count' => 0];
	}

	/**
	 * @param  array<int,mixed>    $ids
	 * @param  array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function finding(string $severity, string $title, int $count, array $ids, string $remedy, array $extra = []): array
	{
		return [
			'status'   => 'finding',
			'severity' => $severity,
			'title'    => $title,
			'count'    => $count,
			'ids'      => $ids,
			'remedy'   => $remedy,
		] + $extra;
	}

	/**
	 * One capped, chunked pass over page content.
	 *
	 * Each page is parsed once and reduced to a small per-page record. The
	 * markup itself is never retained — only the findings — so peak memory is
	 * one chunk of pages, not the whole table.
	 *
	 * @return array<string,mixed>
	 */
	private function scanPages(): array
	{
		if (!$this->pbckTableExists('pages')) {
			throw new \RuntimeException('The ' . $this->pbckTable('pages') . ' table does not exist on this site.');
		}

		$table = $this->db->quoteName($this->pbckTable('pages'));
		$where = ' WHERE ' . $this->db->quoteName('state') . ' <> -2';

		$total = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $table . $where)->loadResult();

		$enabledTypes = $this->pbckEnabledAddonTypes();
		$root         = $this->pbckSiteRoot();

		$pages   = [];
		$scanned = 0;
		$offset  = 0;

		while ($scanned < min($total, self::CONTENT_SCAN_CAP)) {
			$rows = $this->db->setQuery(
				'SELECT ' . $this->db->quoteName('id') . ', ' . $this->db->quoteName('title') . ', '
				. $this->db->quoteName('htmlcode') . ' FROM ' . $table . $where
				. ' ORDER BY ' . $this->db->quoteName('modified') . ' DESC, ' . $this->db->quoteName('id') . ' DESC',
				$offset,
				self::CONTENT_SCAN_CHUNK
			)->loadAssocList() ?: [];

			if ($rows === []) {
				break;
			}

			foreach ($rows as $row) {
				$pages[] = $this->scanOnePage($row, $enabledTypes, $root);
				$scanned++;

				if ($scanned >= self::CONTENT_SCAN_CAP) {
					break;
				}
			}

			unset($rows);

			$offset += self::CONTENT_SCAN_CHUNK;
		}

		return [
			'pages'     => $pages,
			'scanned'   => $scanned,
			'total'     => $total,
			'truncated' => $scanned < $total,
		];
	}

	/**
	 * Reduce one page's markup to the facts the checks need.
	 *
	 * Deliberately built from the content trait's primitives rather than from
	 * pbckValidate()'s prose, because an audit that pattern-matched on English
	 * sentences would break the first time one was reworded.
	 *
	 * @param  array<string,mixed> $row
	 * @param  array<int,string>   $enabledTypes
	 * @return array<string,mixed>
	 */
	private function scanOnePage(array $row, array $enabledTypes, string $root): array
	{
		$id    = (int) $row['id'];
		$title = (string) ($row['title'] ?? '');
		$html  = (string) ($row['htmlcode'] ?? '');

		$record = [
			'id'            => $id,
			'title'         => $title,
			'bytes'         => \strlen($html),
			'empty'         => $html === '',
			'lossless'      => true,
			'duplicate_ids' => [],
			'missing_types' => [],
			'bad_rows'      => [],
			'untokenised'   => false,
			'parse_failed'  => false,
		];

		if ($html === '') {
			return $record;
		}

		$record['lossless'] = $this->pbckIsLossless($html);

		// Same id-harvesting expression the content trait uses; counted rather
		// than uniqued, because duplication is the thing being looked for.
		if (preg_match_all('/\sid="([^"]*)"/', $html, $m)) {
			foreach (array_count_values(array_filter($m[1], static fn ($v) => $v !== '')) as $foundId => $count) {
				if ($count > 1) {
					$record['duplicate_ids'][] = ['id' => (string) $foundId, 'occurrences' => $count];
				}
			}
		}

		if ($root !== '' && str_contains($html, $root)) {
			$record['untokenised'] = true;
		}

		$outline = $this->pbckOutline($html, $enabledTypes);

		if (($outline['ok'] ?? false) !== true) {
			$record['parse_failed'] = true;

			return $record;
		}

		$missing = [];
		$badRows = [];

		$walk = function (array $rowList) use (&$walk, &$missing, &$badRows): void {
			foreach ($rowList as $rowNode) {
				if (isset($rowNode['warning'])) {
					$badRows[] = [
						'row_id'       => (string) ($rowNode['id'] ?? ''),
						'declared_nb'  => $rowNode['declared_nb'] ?? null,
						'column_count' => (int) ($rowNode['column_count'] ?? 0),
					];
				}

				foreach ($rowNode['columns'] ?? [] as $column) {
					foreach ($column['blocks'] ?? [] as $block) {
						$type = (string) ($block['type'] ?? '');

						if (isset($block['error'])) {
							// No data-type at all — the one case that renders a
							// visible red error paragraph.
							$missing[''] = ($missing[''] ?? 0) + 1;

							continue;
						}

						if (isset($block['addon_missing']) && $type !== '') {
							$missing[$type] = ($missing[$type] ?? 0) + 1;
						}
					}

					if (($column['nested_rows'] ?? []) !== []) {
						$walk($column['nested_rows']);
					}
				}
			}
		};

		$walk($outline['rows'] ?? []);

		$record['missing_types'] = $missing;
		$record['bad_rows']      = $badRows;

		return $record;
	}

	/**
	 * The scanned pages, or an exception if the scan never ran.
	 *
	 * @param  array<string,mixed> $scan
	 * @return array<int,array<string,mixed>>
	 */
	private function scannedPages(array $scan): array
	{
		if (isset($scan['__error'])) {
			throw new \RuntimeException('The content scan failed: ' . (string) $scan['__error']);
		}

		if (!isset($scan['pages'])) {
			throw new \RuntimeException('The content scan did not run.');
		}

		return $scan['pages'];
	}

	// -----------------------------------------------------------------------
	// Checks
	// -----------------------------------------------------------------------

	/** @return array<string,mixed> */
	private function checkVersion(): array
	{
		$version = $this->pbckVersion();

		if ($version === null) {
			throw new \RuntimeException(
				'The component manifest could not be read, so the installed version is unknown. Expected '
				. 'administrator/components/com_pagebuilderck/pagebuilderck.xml.'
			);
		}

		if (version_compare($version, self::SECURITY_FLOOR, '>=')) {
			return $this->clear('Page Builder CK ' . $version . ' is at or above ' . self::SECURITY_FLOOR . '.');
		}

		return $this->finding(
			'critical',
			'Page Builder CK ' . $version . ' carries known, actively exploited remote code execution.',
			1,
			[],
			'Update to ' . self::SECURITY_FLOOR . ' or later immediately, and treat this site as '
			. 'potentially ALREADY COMPROMISED rather than merely at risk. Versions below '
			. self::SECURITY_FLOOR . ' expose an unauthenticated arbitrary file upload in the font '
			. 'handling path (CVE-2026-56290, CVSS 10.0) that is listed on CISA\'s Known Exploited '
			. 'Vulnerabilities catalogue and has public mass-exploitation tooling. After updating, audit '
			. 'administrator/components/com_pagebuilderck/fonts/ for files that are not fonts, and audit '
			. 'the site for web shells and unexpected Super Users.',
			['installed_version' => $version, 'minimum_safe_version' => self::SECURITY_FLOOR]
		);
	}

	/**
	 * @param  array<string,mixed> $scan
	 * @return array<string,mixed>
	 */
	private function checkUnsafeContent(array $scan, int $limit): array
	{
		$pages   = $this->scannedPages($scan);
		$hits    = [];
		$unparsed = [];

		foreach ($pages as $page) {
			if ($page['parse_failed']) {
				$unparsed[] = $page['id'];

				continue;
			}

			if (!$page['lossless']) {
				$hits[] = ['id' => $page['id'], 'title' => $page['title'], 'bytes' => $page['bytes']];
			}
		}

		if ($hits === [] && $unparsed === []) {
			return $this->clear('Every scanned page\'s content round-trips byte for byte and is safe to edit.');
		}

		$ids = array_map(static fn (array $h): int => $h['id'], $hits);

		return $this->finding(
			'critical',
			'Pages whose content is not lossless — unsafe to edit programmatically.',
			\count($hits) + \count($unparsed),
			\array_slice(array_merge($ids, $unparsed), 0, $limit),
			'These pages contain markup that does not survive a parse and re-serialise byte for byte '
			. 'under Page Builder CK\'s own bundled simple_html_dom. Every write tool in this add-on '
			. 'refuses them, because a write would silently rewrite markup nobody asked to change. They '
			. 'render fine and are safe to READ. To make one editable, open it in the builder and save '
			. 'it — that normalises the markup through the vendor\'s own serialiser — then re-run this '
			. 'check. Do not hand-edit the column to "fix" it.',
			[
				'examples'      => \array_slice($hits, 0, $limit),
				'unparseable'   => $unparsed === [] ? null : \array_slice($unparsed, 0, $limit),
				'unparseable_note' => $unparsed === [] ? null : 'These page ids could not be parsed at '
					. 'all — either the content exceeds the 8 MB parse ceiling, or the component\'s '
					. 'bundled parser is missing from disk.',
			]
		);
	}

	/**
	 * @param  array<string,mixed> $scan
	 * @return array<string,mixed>
	 */
	private function checkDuplicateIds(array $scan, int $limit): array
	{
		$pages = $this->scannedPages($scan);
		$hits  = [];

		foreach ($pages as $page) {
			if ($page['duplicate_ids'] === []) {
				continue;
			}

			$hits[] = [
				'id'         => $page['id'],
				'title'      => $page['title'],
				'duplicates' => \array_slice($page['duplicate_ids'], 0, 10),
			];
		}

		if ($hits === []) {
			return $this->clear('No scanned page contains a duplicated id.');
		}

		return $this->finding(
			'critical',
			'Pages containing duplicated block ids.',
			\count($hits),
			\array_slice(array_map(static fn (array $h): int => $h['id'], $hits), 0, $limit),
			'All Page Builder CK block styling is #id-scoped: each block carries a .ckstyle > style '
			. 'element whose rules are written against its own id. Two blocks sharing an id therefore '
			. 'cross-apply each other\'s CSS, and the tabs and accordion addons additionally build '
			. '#id_tabs-N fragment links that will target the wrong block. This is almost always the '
			. 'result of markup copied between pages, or of a duplicate action that did not regenerate '
			. 'ids. Fix by opening the page in the builder and re-saving the affected block, or by using '
			. 'this add-on\'s block tools, which mint fresh ids.',
			['examples' => \array_slice($hits, 0, $limit)]
		);
	}

	/**
	 * @param  array<string,mixed> $scan
	 * @return array<string,mixed>
	 */
	private function checkUnrenderableTypes(array $scan, int $limit): array
	{
		$pages     = $this->scannedPages($scan);
		$hits      = [];
		$typeTally = [];
		$noType    = [];

		foreach ($pages as $page) {
			if ($page['missing_types'] === []) {
				continue;
			}

			$types = $page['missing_types'];

			if (isset($types[''])) {
				$noType[] = $page['id'];
			}

			foreach ($types as $type => $count) {
				$typeTally[$type === '' ? '(no data-type)' : $type] =
					($typeTally[$type === '' ? '(no data-type)' : $type] ?? 0) + $count;
			}

			$hits[] = [
				'id'    => $page['id'],
				'title' => $page['title'],
				'types' => $types,
			];
		}

		if ($hits === []) {
			return $this->clear('Every block on every scanned page has an enabled plugin for its data-type.');
		}

		arsort($typeTally);

		$extra = [
			'examples'    => \array_slice($hits, 0, $limit),
			'types_total' => $typeTally,
		];

		if ($noType !== []) {
			$extra['blocks_with_no_data_type'] = \array_slice($noType, 0, $limit);
			$extra['no_data_type_note'] = 'These pages contain a block with NO data-type at all. That is '
				. 'the one case Page Builder CK renders loudly: a literal red "ERROR - PAGEBUILDER CK '
				. 'DEBUG : ELEMENT TYPE NOT FOUND" paragraph appears in the live page '
				. '(site/models/page.php:575-577). Fix these first — they are visible to visitors.';
		}

		return $this->finding(
			'warning',
			'Pages containing blocks whose data-type has no enabled pagebuilderck plugin.',
			\count($hits),
			\array_slice(array_map(static fn (array $h): int => $h['id'], $hits), 0, $limit),
			'Page Builder CK does NOT blank these blocks. renderElement() returns \'\' '
			. '(site/models/page.php:645-646) and replaceElement() falls through to $e->innertext (:575), '
			. 'so the block\'s inner markup renders as static HTML with its CSS still applied — no error '
			. 'in the page, no log entry, nothing in the admin UI. The page looks almost right and every '
			. 'behaviour the addon provided is gone: sliders do not slide, tabs do not switch, forms do '
			. 'not submit. Check the types listed against list_pagebuilderck_addons: if the plugin is '
			. 'installed but disabled, enable it; if it was never installed, install the addon package; '
			. 'if the addon is genuinely gone, replace those blocks rather than leaving inert markup on '
			. 'the site. This is severity `warning` and not `critical` because the content is intact and '
			. 'nothing is destroyed — but it is the single most under-noticed problem in this component.',
			$extra
		);
	}

	/**
	 * @param  array<string,mixed> $scan
	 * @return array<string,mixed>
	 */
	private function checkRowGeometry(array $scan, int $limit): array
	{
		$pages = $this->scannedPages($scan);
		$hits  = [];

		foreach ($pages as $page) {
			if ($page['bad_rows'] === []) {
				continue;
			}

			$hits[] = [
				'id'    => $page['id'],
				'title' => $page['title'],
				'rows'  => \array_slice($page['bad_rows'], 0, 10),
			];
		}

		if ($hits === []) {
			return $this->clear('Every row\'s data-nb matches its real column count.');
		}

		return $this->finding(
			'critical',
			'Rows whose data-nb disagrees with their actual column count.',
			\count($hits),
			\array_slice(array_map(static fn (array $h): int => $h['id'], $hits), 0, $limit),
			'Page Builder CK generates its column width rules as selectors keyed on '
			. '[data-gutter][data-nb][data-width], emitted into a style.ckcolumnwidth element inside the '
			. 'row. When data-nb does not match the number of .blockck columns the row actually owns, no '
			. 'generated rule matches any column, and every column in that row renders with no width at '
			. 'all — typically stacking full width. Fix by opening the page in the builder and adding or '
			. 'removing a column, which rewrites data-nb, or by using this add-on\'s row tools, which '
			. 'keep the attribute and the column count in step.',
			['examples' => \array_slice($hits, 0, $limit)]
		);
	}

	/** @return array<string,mixed> */
	private function checkStyleSize(int $limit): array
	{
		if (!$this->pbckTableExists('styles')) {
			throw new \RuntimeException('The ' . $this->pbckTable('styles') . ' table does not exist on this site.');
		}

		$threshold = (int) (self::TEXT_LIMIT * self::TEXT_WARN_AT);
		$table     = $this->db->quoteName($this->pbckTable('styles'));

		$rows = $this->db->setQuery(
			'SELECT ' . $this->db->quoteName('id') . ', ' . $this->db->quoteName('title') . ', '
			. 'LENGTH(' . $this->db->quoteName('htmlcode') . ') AS htmlcode_bytes, '
			. 'LENGTH(' . $this->db->quoteName('stylecode') . ') AS stylecode_bytes FROM ' . $table
			. ' WHERE LENGTH(' . $this->db->quoteName('htmlcode') . ') >= ' . $threshold
			. ' OR LENGTH(' . $this->db->quoteName('stylecode') . ') >= ' . $threshold
			. ' ORDER BY GREATEST(LENGTH(' . $this->db->quoteName('htmlcode') . '), LENGTH('
			. $this->db->quoteName('stylecode') . ')) DESC'
		)->loadAssocList() ?: [];

		if ($rows === []) {
			return $this->clear(
				'No style row is within ' . (int) ((1 - self::TEXT_WARN_AT) * 100) . '% of the '
				. self::TEXT_LIMIT . '-byte text column ceiling.'
			);
		}

		$examples = [];
		$atLimit  = 0;

		foreach ($rows as $row) {
			$html  = (int) $row['htmlcode_bytes'];
			$style = (int) $row['stylecode_bytes'];

			if ($html >= self::TEXT_LIMIT || $style >= self::TEXT_LIMIT) {
				$atLimit++;
			}

			$examples[] = [
				'id'              => (int) $row['id'],
				'title'           => (string) ($row['title'] ?? ''),
				'htmlcode_bytes'  => $html,
				'stylecode_bytes' => $style,
				'at_ceiling'      => $html >= self::TEXT_LIMIT || $style >= self::TEXT_LIMIT,
			];
		}

		return $this->finding(
			$atLimit > 0 ? 'critical' : 'warning',
			'Style rows approaching or at the 65,535-byte text column ceiling.',
			\count($rows),
			\array_slice(array_map(static fn (array $r): int => (int) $r['id'], $rows), 0, $limit),
			'#__pagebuilderck_styles.htmlcode and .stylecode are `text` columns, not longtext, so they '
			. 'hold 65,535 bytes. MySQL running in non-strict mode TRUNCATES an oversized value silently '
			. 'at an arbitrary byte and reports success, which corrupts CSS mid-rule with no error '
			. 'anywhere. Split large styles across several rows, or trim them. Any row already at the '
			. 'ceiling should be assumed to have lost content already — compare it against a .pbck '
			. 'snapshot before editing it further.',
			[
				'examples'       => \array_slice($examples, 0, $limit),
				'rows_at_ceiling' => $atLimit,
				'byte_limit'     => self::TEXT_LIMIT,
			]
		);
	}

	/** @return array<string,mixed> */
	private function checkCheckedOut(int $limit): array
	{
		$locked  = [];
		$skipped = [];

		foreach (['pages', 'elements', 'categories', 'styles'] as $name) {
			if (!$this->pbckTableExists($name)) {
				$skipped[] = $this->pbckTable($name);

				continue;
			}

			if (!\in_array('checked_out', $this->pbckColumns($name), true)) {
				continue;
			}

			$columns = $this->pbckColumns($name);
			$label   = \in_array('title', $columns, true) ? 'title' : (\in_array('name', $columns, true) ? 'name' : null);

			$select = $this->db->quoteName('id') . ', ' . $this->db->quoteName('checked_out')
				. ($label === null ? '' : ', ' . $this->db->quoteName($label) . ' AS label');

			// checked_out is a varchar(10). '' is free and '0' is what a
			// well-meaning integer write leaves behind; neither is a real lock.
			$rows = $this->db->setQuery(
				'SELECT ' . $select . ' FROM ' . $this->db->quoteName($this->pbckTable($name))
				. ' WHERE ' . $this->db->quoteName('checked_out') . ' <> ' . $this->db->quote('')
				. ' AND ' . $this->db->quoteName('checked_out') . ' <> ' . $this->db->quote('0')
				. ' ORDER BY ' . $this->db->quoteName('id') . ' ASC'
			)->loadAssocList() ?: [];

			foreach ($rows as $row) {
				$locked[] = [
					'table'          => $this->pbckTable($name),
					'id'             => (int) $row['id'],
					'title'          => (string) ($row['label'] ?? ''),
					'checked_out_by' => (int) $row['checked_out'],
				];
			}
		}

		if ($locked === []) {
			return $this->clear('Nothing is left checked out.');
		}

		$extra = ['examples' => \array_slice($locked, 0, $limit)];

		if ($skipped !== []) {
			$extra['tables_skipped'] = $skipped;
		}

		return $this->finding(
			'warning',
			'Rows left checked out.',
			\count($locked),
			\array_slice(array_map(static fn (array $r): int => $r['id'], $locked), 0, $limit),
			'checked_out on every Page Builder CK table is a varchar(10) holding a user id, and Page '
			. 'Builder CK never releases it automatically — there is no global check-in, no session '
			. 'timeout and no age-based sweep. A browser closed mid-edit therefore locks the item '
			. 'PERMANENTLY, for everyone including the user who holds it, until the column is cleared by '
			. 'hand. Clear it by setting checked_out to the EMPTY STRING, not to 0 and not to NULL: the '
			. 'column is a varchar and NOT NULL, and Page Builder CK\'s own storage layer casts '
			. 'numeric-looking values to int, so \'\' is the only value that reliably means free. Confirm '
			. 'nobody is genuinely editing first.',
			$extra
		);
	}

	/**
	 * @param  array<string,mixed> $scan
	 * @return array<string,mixed>
	 */
	private function checkUntokenisedRoot(array $scan, int $limit): array
	{
		$root = $this->pbckSiteRoot();

		if ($root === '') {
			// Uri::root(true) is '' for a site at a domain root, so there is no
			// token to look for and nothing to find. Say so rather than reporting
			// a clean result we did not actually establish.
			return [
				'status' => 'skipped',
				'reason' => 'This site is installed at a domain root, so Uri::root(true) is an empty '
					. 'string and there is no site-root prefix to search for. The |URIROOT| token still '
					. 'matters if the site is later moved into a subdirectory, but nothing can be '
					. 'detected from here today.',
			];
		}

		$pages = $this->scannedPages($scan);
		$hits  = [];

		foreach ($pages as $page) {
			if ($page['untokenised']) {
				$hits[] = ['id' => $page['id'], 'title' => $page['title']];
			}
		}

		if ($hits === []) {
			return $this->clear('No scanned page contains an untokenised absolute site root.');
		}

		return $this->finding(
			'warning',
			'Pages containing the literal site root instead of the |URIROOT| token.',
			\count($hits),
			\array_slice(array_map(static fn (array $h): int => $h['id'], $hits), 0, $limit),
			'Page Builder CK stores internal URLs with the site root replaced by |URIROOT| and expands '
			. 'it on read, so that a site can move domain or subdirectory without rewriting every page. '
			. 'These pages have the literal root "' . $root . '" baked in, which will break the moment '
			. 'the site moves — and will keep working until then, which is why it goes unnoticed. It '
			. 'usually comes from markup pasted in from another install or written by a tool that did '
			. 'not tokenise. Re-saving the page in the builder tokenises it, as does any content write '
			. 'through this add-on.',
			['site_root' => $root, 'examples' => \array_slice($hits, 0, $limit)]
		);
	}
}
