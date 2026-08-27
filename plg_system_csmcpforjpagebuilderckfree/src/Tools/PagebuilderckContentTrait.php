<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools;

\defined('_JEXEC') or die;

/**
 * Parsing, inspection and mutation of Page Builder CK's `htmlcode` column.
 *
 * Page Builder CK does NOT store a JSON tree. A page is raw HTML, and every
 * block option is an HTML ATTRIBUTE on an empty sibling `<div class="ckprops">`.
 * That makes this column far more fragile than SP Page Builder's JSON, because
 * there is no encoder to normalise what we write — whatever bytes we put back
 * are what the renderer parses.
 *
 * ---------------------------------------------------------------------------
 * THE PARSER CONTRACT
 * ---------------------------------------------------------------------------
 *
 * We parse with Page Builder CK's OWN bundled simple_html_dom, not DOMDocument
 * and not a bundled copy. Three reasons: it is GPL and already on disk; it is
 * literally the parser the renderer uses, so we inherit its quirks rather than
 * guessing at them; and DOMDocument normalises attribute quoting and casing,
 * which would rewrite markup we were only asked to read.
 *
 * The flags are not negotiable. Measured against the four real `.pbck` backups
 * shipped inside the Light package:
 *
 *   - `$lowercase = true` (the LIBRARY DEFAULT) rewrites `viewBox` to `viewbox`.
 *     `viewBox` occurs in the Tabler icon SVGs that ship in real pages, and SVG
 *     attribute names are case-sensitive — lowercasing it silently breaks the
 *     icon. This is a data-corruption bug hiding in a default argument.
 *   - `$stripRN = true` (also the LIBRARY DEFAULT) collapses the `\n\t\t`
 *     whitespace between top-level blocks.
 *   - Even at the correct flags the parser drops TRAILING whitespace. Three of
 *     the four real samples end in tabs. So we capture the leading and trailing
 *     whitespace ourselves and re-apply it around the serialised body, which
 *     restores byte-identical output on 4/4 samples.
 *
 * On top of that, `assertLossless()` re-parses and compares before any write.
 * If a page contains markup whose round-trip is not byte-identical, we refuse
 * to write it rather than hand back a subtly different page. Real sites contain
 * markup our four samples do not.
 *
 * ---------------------------------------------------------------------------
 * THE DOCUMENT SHAPE
 * ---------------------------------------------------------------------------
 *
 *   div.googlefontscall                 page-level singleton, consumed at render
 *   div.pagebuilderckparams[data-styles]  page-level singleton — THE style link
 *   div.rowck[id][data-gutter][data-nb]
 *     style.ckcolumnwidth                generated width rules, keyed on attrs
 *     div.inner.animate.clearfix
 *       div.blockck[id][data-width][data-real-width]     a COLUMN
 *         div.ckstyle
 *         div.inner.animate.resizable
 *           div.innercontent
 *             div.cktype[id][data-type]                  a BLOCK
 *               div.ckprops[...attrs][fieldslist]        options, one per tab
 *               div.ckstyle > style                      #id-scoped CSS
 *               div.inner                                the visible payload
 *             div.rowck                                  a NESTED row
 *   div.ckcustomcssfield                page-level singleton
 *
 * `rowinrow` is not a distinct markup shape — a nested row is just a `.rowck`
 * inside `.innercontent`.
 */
trait PagebuilderckContentTrait
{
	/** Page-level singleton classes, in the order Page Builder CK emits them. */
	private const SINGLETONS = ['googlefontscall', 'pagebuilderckparams', 'ckcustomcssfield'];

	/** Block types Page Builder CK handles itself, with no addon plugin. */
	private const CORE_TYPES = ['row', 'rowinrow', 'readmore'];

	/** Guards against pathological input before we hand it to the parser. */
	private const MAX_PARSE_BYTES = 8388608; // 8 MB

	private static ?bool $pbckDomLoaded = null;

	// -----------------------------------------------------------------------
	// Parser
	// -----------------------------------------------------------------------

	/**
	 * Load Page Builder CK's bundled simple_html_dom.
	 *
	 * Returns false when the component is absent, which every caller must treat
	 * as "cannot inspect content" rather than "page is empty".
	 */
	protected function pbckLoadDomLibrary(): bool
	{
		if (self::$pbckDomLoaded !== null) {
			return self::$pbckDomLoaded;
		}

		if (\function_exists('Pagebuilderck\\str_get_html')) {
			return self::$pbckDomLoaded = true;
		}

		$base = $this->pbckAdminBase();

		if ($base === null) {
			return self::$pbckDomLoaded = false;
		}

		$lib = $base . '/helpers/simple_html_dom.php';

		if (!is_file($lib)) {
			return self::$pbckDomLoaded = false;
		}

		require_once $lib;

		return self::$pbckDomLoaded = \function_exists('Pagebuilderck\\str_get_html');
	}

	/**
	 * Parse `htmlcode` under the proven-lossless flag combination.
	 *
	 * @return object|null simple_html_dom, or null if unavailable/unparseable.
	 */
	protected function pbckParse(string $html): ?object
	{
		if (!$this->pbckLoadDomLibrary()) {
			return null;
		}

		if ($html === '' || \strlen($html) > self::MAX_PARSE_BYTES) {
			return null;
		}

		// lowercase=false protects viewBox; stripRN=false protects inter-block
		// whitespace. See the class docblock — these are load-bearing.
		$dom = \Pagebuilderck\str_get_html($html, false, true, 'UTF-8', false);

		return $dom === false ? null : $dom;
	}

	/**
	 * Serialise a parsed document, restoring the edge whitespace the parser drops.
	 *
	 * `$original` is the string the DOM was parsed from; its leading and
	 * trailing whitespace is re-applied verbatim.
	 */
	protected function pbckSerialise(object $dom, string $original): string
	{
		$lead  = substr($original, 0, \strlen($original) - \strlen(ltrim($original)));
		$trail = substr($original, \strlen(rtrim($original)));

		return $lead . trim((string) $dom) . $trail;
	}

	/**
	 * True when parsing and re-serialising `$html` reproduces it byte for byte.
	 *
	 * Call this before writing content that came from a caller, and before
	 * mutating a page we did not author. A false result means this page contains
	 * markup the parser cannot faithfully reproduce, and the only safe response
	 * is to refuse the write.
	 */
	protected function pbckIsLossless(string $html): bool
	{
		if ($html === '') {
			return true;
		}

		$dom = $this->pbckParse($html);

		if ($dom === null) {
			return false;
		}

		$out = $this->pbckSerialise($dom, $html);
		$dom->clear();

		return $out === $html;
	}

	// -----------------------------------------------------------------------
	// URIROOT tokenisation
	// -----------------------------------------------------------------------

	/**
	 * Collapse the live site root to the `|URIROOT|` token.
	 *
	 * Page Builder CK stores every internal URL with the site root replaced by
	 * this token, and expands it on read. Writing an absolute URL straight into
	 * `htmlcode` produces a page that breaks the moment the site moves domain or
	 * subdirectory. Every write path must run through here.
	 */
	protected function pbckCollapseRoot(string $html): string
	{
		$root = $this->pbckSiteRoot();

		if ($root === '') {
			return $html;
		}

		return str_replace($root, '|URIROOT|', $html);
	}

	/** Expand `|URIROOT|` back to the live site root, as the models do on read. */
	protected function pbckExpandRoot(string $html): string
	{
		return str_replace('|URIROOT|', $this->pbckSiteRoot(), $html);
	}

	// -----------------------------------------------------------------------
	// Ids
	// -----------------------------------------------------------------------

	/**
	 * Every id used anywhere in the document.
	 *
	 * Block ids are load-bearing: all `.ckstyle` CSS is `#id`-scoped, and the
	 * tabs/accordion addons build `#id_tabs-N` fragment links. Duplicated ids
	 * cross-contaminate styling between blocks.
	 */
	protected function pbckCollectIds(string $html): array
	{
		if ($html === '' || !preg_match_all('/\sid="([^"]*)"/', $html, $m)) {
			return [];
		}

		return array_values(array_unique(array_filter($m[1], static fn ($v) => $v !== '')));
	}

	/**
	 * Mint an id in Page Builder CK's own format, unique within `$taken`.
	 *
	 * The editor uses `ID<epoch-ms>`. We keep the shape (a CSS id selector may
	 * not start with a digit, which is why the `ID` prefix exists) and add a
	 * collision counter, because millisecond stamps collide when a tool creates
	 * several blocks inside one request.
	 *
	 * @param array<int,string> $taken Mutated: the new id is appended.
	 */
	protected function pbckNewId(array &$taken, string $prefix = 'ID'): string
	{
		$stamp = (int) round(microtime(true) * 1000);

		do {
			$candidate = $prefix . $stamp;
			$stamp++;
		} while (\in_array($candidate, $taken, true));

		$taken[] = $candidate;

		return $candidate;
	}

	/**
	 * Rewrite every id in a fragment, preserving internal references.
	 *
	 * Used when duplicating. Page Builder CK's own JS does this properly; naive
	 * markup copying does not, and the result is two blocks sharing one set of
	 * `#id`-scoped CSS rules — the copy silently restyles the original.
	 *
	 * Row and column ids carry `row_`/`block_` prefixes over the same stamp, so
	 * they are remapped as a family.
	 *
	 * @param array<int,string> $taken Ids already in use elsewhere.
	 */
	protected function pbckRegenerateIds(string $fragment, array &$taken): string
	{
		$ids = $this->pbckCollectIds($fragment);

		if ($ids === []) {
			return $fragment;
		}

		// Longest first, so `block_ID123` is replaced before `ID123` would
		// corrupt its suffix.
		usort($ids, static fn ($a, $b) => \strlen($b) <=> \strlen($a));

		$map = [];

		foreach ($ids as $old) {
			if (preg_match('/^(row_|block_)?(ID\d+)$/', $old, $m) !== 1) {
				// Not an editor-generated id — a hand-written anchor, say. Leave
				// it alone; rewriting it would break whatever links to it.
				continue;
			}

			$prefix = $m[1];
			$stem   = $m[2];

			if (!isset($map[$stem])) {
				$fresh = $this->pbckNewId($taken);
				$map[$stem] = $fresh;
			}

			$map[$old] = $prefix . $map[$stem];
		}

		$search  = [];
		$replace = [];

		foreach ($map as $old => $new) {
			if ($old === $new) {
				continue;
			}

			$search[]  = $old;
			$replace[] = $new;
		}

		return $search === [] ? $fragment : str_replace($search, $replace, $fragment);
	}

	// -----------------------------------------------------------------------
	// Page-level singletons
	// -----------------------------------------------------------------------

	/**
	 * Style ids attached to this page.
	 *
	 * There is NO `styles` column on `#__pagebuilderck_pages`, despite the admin
	 * model reading and writing one — that property never exists and the write
	 * is silently dropped. The real association is a comma-separated id list in
	 * `div.pagebuilderckparams[data-styles]` inside `htmlcode`.
	 *
	 * @return array<int,int>
	 */
	protected function pbckGetPageStyleIds(string $html): array
	{
		$dom = $this->pbckParse($html);

		if ($dom === null) {
			return [];
		}

		$ids = [];

		foreach ($dom->find('div.pagebuilderckparams') as $node) {
			$raw = (string) ($node->getAttribute('data-styles') ?? '');

			foreach (explode(',', $raw) as $piece) {
				$piece = trim($piece);

				if ($piece !== '' && ctype_digit($piece)) {
					$ids[] = (int) $piece;
				}
			}
		}

		$dom->clear();

		return array_values(array_unique($ids));
	}

	// -----------------------------------------------------------------------
	// Block options (.ckprops)
	// -----------------------------------------------------------------------

	/**
	 * Read one block's options out of its `.ckprops` children.
	 *
	 * Conventions, all of which a writer must honour:
	 *
	 *   - The attribute NAME is the id of the input in the options popup, not
	 *     its name. Radios share a name but have distinct ids.
	 *   - Checkbox and radio values are the literal string `"checked"`, never
	 *     `"1"` or `"true"`.
	 *   - The value `"default"` means unset; such a field is removed entirely.
	 *   - Absence is the only representation of "no value" — empty strings are
	 *     removed too.
	 *   - `fieldslist` is a comma-separated index of which attributes are
	 *     options. It is built from the LIVE popup inputs while the attributes
	 *     are written separately, so the two legitimately disagree in real data.
	 *     We report both rather than reconciling them.
	 *   - The div's second class is the tab id (`tab_blocstyles`, …).
	 *
	 * @return array<string,array<string,mixed>> Keyed by tab id.
	 */
	protected function pbckReadProps(object $blockNode): array
	{
		$tabs = [];

		foreach ($blockNode->children() as $child) {
			if ($child->tag !== 'div') {
				continue;
			}

			$classes = preg_split('/\s+/', trim((string) ($child->getAttribute('class') ?? ''))) ?: [];

			if (!\in_array('ckprops', $classes, true)) {
				continue;
			}

			$tabId = '';

			foreach ($classes as $class) {
				if ($class !== 'ckprops' && $class !== 'ckresponsive' && $class !== '') {
					$tabId = $class;
					break;
				}
			}

			$attrs = [];

			foreach (($child->getAllAttributes() ?: []) as $name => $value) {
				if ($name === 'class' || $name === 'fieldslist') {
					continue;
				}

				$attrs[$name] = $value;
			}

			$fieldslist = (string) ($child->getAttribute('fieldslist') ?? '');
			$declared   = $fieldslist === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $fieldslist))));

			$entry = [
				'tab'        => $tabId,
				'options'    => $attrs,
				'fieldslist' => $declared,
			];

			// A .ckprops carrying children is not stripped at render — the strip
			// regex is `>[^<]*</div>` — so it leaks raw into the front end.
			if (trim((string) $child->innertext) !== '') {
				$entry['warning'] = 'This .ckprops div is not empty. Page Builder CK only strips '
					. 'EMPTY ckprops divs at render, so its contents will leak into the rendered page.';
			}

			if (\in_array('ckresponsive', $classes, true)) {
				$entry['responsive'] = true;
			}

			$missing = array_values(array_diff($declared, array_keys($attrs)));
			$extra   = array_values(array_diff(array_keys($attrs), $declared));

			if ($missing !== []) {
				$entry['declared_but_absent'] = $missing;
			}

			if ($extra !== []) {
				$entry['set_but_undeclared'] = $extra;
			}

			$key = $tabId !== '' ? $tabId : ('ckprops_' . \count($tabs));
			$tabs[$key] = $entry;
		}

		return $tabs;
	}

	// -----------------------------------------------------------------------
	// Outline
	// -----------------------------------------------------------------------

	/**
	 * A structural tree of the page: rows, their columns, and the blocks inside.
	 *
	 * Deliberately does NOT include block payload markup — an outline must stay
	 * cheap enough to call on a large page. Use the block-level tools for
	 * content.
	 */
	protected function pbckOutline(string $html, array $enabledTypes = []): array
	{
		$dom = $this->pbckParse($html);

		if ($dom === null) {
			return ['ok' => false, 'reason' => 'content could not be parsed', 'rows' => []];
		}

		$rows = [];

		foreach ($dom->find('div.rowck') as $rowNode) {
			// Nested rows are reached through their parent column, so skip any
			// row that already sits inside another row.
			if ($this->pbckAncestorMatches($rowNode, 'rowck')) {
				continue;
			}

			$rows[] = $this->pbckDescribeRow($rowNode, $enabledTypes);
		}

		$singletons = [];

		foreach (self::SINGLETONS as $class) {
			$found = $dom->find('div.' . $class);
			$singletons[$class] = \count($found);
		}

		$dom->clear();

		return [
			'ok'         => true,
			'rows'       => $rows,
			'row_count'  => \count($rows),
			'singletons' => $singletons,
		];
	}

	/** @return array<string,mixed> */
	private function pbckDescribeRow(object $rowNode, array $enabledTypes): array
	{
		$gutter = (string) ($rowNode->getAttribute('data-gutter') ?? '');
		$nb     = (string) ($rowNode->getAttribute('data-nb') ?? '');

		$columns = [];

		foreach ($rowNode->find('div.blockck') as $colNode) {
			if ($this->pbckNearestAncestorRow($colNode) !== $rowNode) {
				continue; // belongs to a nested row
			}

			$columns[] = $this->pbckDescribeColumn($colNode, $rowNode, $enabledTypes);
		}

		$row = [
			'id'           => (string) ($rowNode->getAttribute('id') ?? ''),
			'gutter'       => $gutter,
			'declared_nb'  => $nb === '' ? null : (int) $nb,
			'column_count' => \count($columns),
			'columns'      => $columns,
		];

		// data-nb drives the generated width selectors. If it disagrees with the
		// real column count, no width rule matches and the columns collapse.
		if ($nb !== '' && (int) $nb !== \count($columns)) {
			$row['warning'] = sprintf(
				'data-nb is %s but this row has %d columns. The generated '
				. '[data-gutter][data-nb][data-width] width rules will not match, so the columns '
				. 'render with no width at all.',
				$nb,
				\count($columns)
			);
		}

		if (trim((string) $rowNode->getAttribute('data-parallax')) !== '') {
			$row['parallax'] = true;
		}

		return $row;
	}

	/** @return array<string,mixed> */
	private function pbckDescribeColumn(object $colNode, object $rowNode, array $enabledTypes): array
	{
		$blocks = [];
		$nested = [];

		foreach ($colNode->find('div.cktype') as $blockNode) {
			if ($this->pbckNearestAncestorColumn($blockNode) !== $colNode) {
				continue;
			}

			// A .cktype inside another .cktype is that block's business — the
			// renderer only recurses one level, and not at all inside `text`.
			if ($this->pbckAncestorMatches($blockNode, 'cktype')) {
				continue;
			}

			$blocks[] = $this->pbckDescribeBlock($blockNode, $enabledTypes);
		}

		foreach ($colNode->find('div.rowck') as $nestedRow) {
			if ($this->pbckNearestAncestorColumn($nestedRow) !== $colNode) {
				continue;
			}

			$nested[] = $this->pbckDescribeRow($nestedRow, $enabledTypes);
		}

		$column = [
			'id'          => (string) ($colNode->getAttribute('id') ?? ''),
			'width'       => (string) ($colNode->getAttribute('data-width') ?? ''),
			'real_width'  => (string) ($colNode->getAttribute('data-real-width') ?? ''),
			'block_count' => \count($blocks),
			'blocks'      => $blocks,
		];

		if ($nested !== []) {
			$column['nested_rows'] = $nested;
		}

		return $column;
	}

	/** @return array<string,mixed> */
	private function pbckDescribeBlock(object $blockNode, array $enabledTypes): array
	{
		$type = (string) ($blockNode->getAttribute('data-type') ?? '');
		$id   = (string) ($blockNode->getAttribute('id') ?? '');

		$block = [
			'id'   => $id,
			'type' => $type,
		];

		if ($type === '') {
			$block['error'] = 'This block has no data-type. Page Builder CK renders a literal red '
				. '"ELEMENT TYPE NOT FOUND" paragraph in the page for it.';

			return $block;
		}

		if ($enabledTypes !== [] && !\in_array($type, $enabledTypes, true) && !\in_array($type, self::CORE_TYPES, true)) {
			// Fails OPEN, not blank — the inner markup renders as static HTML.
			// The rendered page therefore cannot tell you the addon is missing,
			// which is exactly why this has to be reported here.
			$block['addon_missing'] = sprintf(
				'No enabled `pagebuilderck` plugin provides type "%s". Page Builder CK does NOT '
				. 'blank the block — it renders the inner markup as static HTML with its CSS still '
				. 'applied, with no error and no log entry. Interactive behaviour is lost.',
				$type
			);
		}

		$props = $this->pbckReadProps($blockNode);

		if ($props !== []) {
			$block['option_tabs'] = array_keys($props);
		}

		$acl = (string) ($blockNode->getAttribute('data-acl-view') ?? '');

		if ($acl !== '') {
			// Reads like an allow list. It is not.
			$block['acl_view_denied_groups'] = array_values(array_filter(array_map('trim', explode(',', $acl))));
			$block['acl_note'] = 'data-acl-view is a DENY list — these group ids are the ones that '
				. 'CANNOT see the block.';
		}

		return $block;
	}

	// -----------------------------------------------------------------------
	// Validation
	// -----------------------------------------------------------------------

	/**
	 * Check content for the things that silently break a rendered page.
	 *
	 * Severity `fatal` means the page will visibly break or leak markup; write
	 * tools refuse on any fatal. `warning` means it will render but something is
	 * wrong or lossy.
	 *
	 * `$requireLossless` exists for the one legitimate exception: a write path
	 * that copies bytes without ever parsing them — `pbckRegenerateIds()` is
	 * regex plus `str_replace`, so the result is exactly as faithful as its
	 * source and the round-trip property does not apply. Such a caller passes
	 * false. Every path that actually re-serialises a parsed document must leave
	 * it true. This is a parameter rather than something callers filter out of
	 * the result afterwards, because matching on the wording of a message is
	 * brittle — it breaks silently the moment the prose is reworded.
	 *
	 * @param array<int,string> $enabledTypes data-type values with an enabled plugin.
	 * @return array<int,array<string,string>>
	 */
	protected function pbckValidate(string $html, array $enabledTypes = [], bool $requireLossless = true): array
	{
		$problems = [];

		if ($html === '') {
			return $problems;
		}

		if ($requireLossless && !$this->pbckIsLossless($html)) {
			$problems[] = $this->pbckProblem(
				'fatal',
				'document',
				'This content does not survive a parse/serialise round-trip byte for byte, so any '
					. 'write would silently alter markup that was not asked to change. Refusing to '
					. 'treat it as safe to edit.'
			);
		}

		$dom = $this->pbckParse($html);

		if ($dom === null) {
			$problems[] = $this->pbckProblem('fatal', 'document', 'Content could not be parsed.');

			return $problems;
		}

		// --- duplicate ids ---------------------------------------------------
		if (preg_match_all('/\sid="([^"]*)"/', $html, $m)) {
			$counts = array_count_values(array_filter($m[1], static fn ($v) => $v !== ''));

			foreach ($counts as $id => $count) {
				if ($count > 1) {
					$problems[] = $this->pbckProblem(
						'fatal',
						'#' . $id,
						sprintf(
							'id "%s" occurs %d times. All Page Builder CK block CSS is #id-scoped, so '
								. 'duplicated ids cross-apply styling between blocks.',
							$id,
							$count
						)
					);
				}
			}
		}

		// --- blocks ----------------------------------------------------------
		foreach ($dom->find('div.cktype') as $blockNode) {
			$id   = (string) ($blockNode->getAttribute('id') ?? '');
			$type = (string) ($blockNode->getAttribute('data-type') ?? '');
			$path = $id !== '' ? '#' . $id : 'div.cktype';

			if ($type === '') {
				$problems[] = $this->pbckProblem(
					'fatal',
					$path,
					'Block has no data-type. Page Builder CK renders a literal red "ELEMENT TYPE NOT '
						. 'FOUND" paragraph into the page for this.'
				);
			} elseif ($enabledTypes !== [] && !\in_array($type, $enabledTypes, true) && !\in_array($type, self::CORE_TYPES, true)) {
				$problems[] = $this->pbckProblem(
					'warning',
					$path,
					sprintf(
						'No enabled plugin provides type "%s". The block will render its inner markup '
							. 'as static HTML with no error — it will look almost right, but any '
							. 'interactive behaviour is gone.',
						$type
					)
				);
			}

			if ($id === '') {
				$problems[] = $this->pbckProblem(
					'warning',
					'div.cktype[data-type=' . $type . ']',
					'Block has no id, so its .ckstyle CSS cannot be scoped to it.'
				);
			}
		}

		// --- non-empty ckprops -----------------------------------------------
		foreach ($dom->find('div.ckprops') as $node) {
			if (trim((string) $node->innertext) === '') {
				continue;
			}

			$problems[] = $this->pbckProblem(
				'fatal',
				'div.ckprops',
				'A .ckprops div has child content. Page Builder CK strips only EMPTY ckprops divs at '
					. 'render (the regex is `>[^<]*</div>`), so this one leaks verbatim into the page.'
			);
		}

		// --- row/column geometry ---------------------------------------------
		foreach ($dom->find('div.rowck') as $rowNode) {
			$nb = (string) ($rowNode->getAttribute('data-nb') ?? '');
			$rowId = (string) ($rowNode->getAttribute('id') ?? '');

			$owned = 0;

			foreach ($rowNode->find('div.blockck') as $colNode) {
				if ($this->pbckNearestAncestorRow($colNode) === $rowNode) {
					$owned++;
				}
			}

			if ($nb !== '' && (int) $nb !== $owned) {
				$problems[] = $this->pbckProblem(
					'fatal',
					$rowId !== '' ? '#' . $rowId : 'div.rowck',
					sprintf(
						'data-nb is "%s" but the row owns %d columns. Width rules are keyed on '
							. '[data-gutter][data-nb][data-width]; when data-nb is wrong no rule matches '
							. 'and every column in the row renders with no width.',
						$nb,
						$owned
					)
				);
			}

			if ($nb === '' && $owned > 0) {
				$problems[] = $this->pbckProblem(
					'warning',
					$rowId !== '' ? '#' . $rowId : 'div.rowck',
					'Row has no data-nb attribute, so the generated width selectors cannot match.'
				);
			}
		}

		// --- URIROOT ---------------------------------------------------------
		$root = $this->pbckSiteRoot();

		if ($root !== '' && str_contains($html, $root)) {
			$problems[] = $this->pbckProblem(
				'warning',
				'document',
				sprintf(
					'Content contains the literal site root "%s". Page Builder CK stores internal URLs '
						. 'tokenised as |URIROOT|; untokenised absolute URLs break when the site moves '
						. 'domain or subdirectory.',
					$root
				)
			);
		}

		$dom->clear();

		return $problems;
	}

	/** True when any problem would visibly break the page. */
	protected function pbckHasFatal(array $problems): bool
	{
		foreach ($problems as $problem) {
			if (($problem['severity'] ?? '') === 'fatal') {
				return true;
			}
		}

		return false;
	}

	/** @return array<string,string> */
	private function pbckProblem(string $severity, string $path, string $problem): array
	{
		return ['severity' => $severity, 'path' => $path, 'problem' => $problem];
	}

	// -----------------------------------------------------------------------
	// DOM navigation helpers
	// -----------------------------------------------------------------------

	/** True when any ancestor carries `$class`. */
	private function pbckAncestorMatches(object $node, string $class): bool
	{
		$parent = $node->parent();

		while ($parent !== null && $parent->tag !== 'root') {
			$classes = preg_split('/\s+/', trim((string) ($parent->getAttribute('class') ?? ''))) ?: [];

			if (\in_array($class, $classes, true)) {
				return true;
			}

			$parent = $parent->parent();
		}

		return false;
	}

	/** The nearest ancestor `.rowck`, or null. */
	private function pbckNearestAncestorRow(object $node): ?object
	{
		return $this->pbckNearestAncestorWithClass($node, 'rowck');
	}

	/** The nearest ancestor `.blockck`, or null. */
	private function pbckNearestAncestorColumn(object $node): ?object
	{
		return $this->pbckNearestAncestorWithClass($node, 'blockck');
	}

	/**
	 * Protected rather than private because PageContentEditTrait depends on it.
	 * Traits flatten into one class so a private call would work today, but only
	 * for as long as every consumer happens to `use` both traits — and it would
	 * fail at runtime, not at lint, for the first one that doesn't.
	 */
	protected function pbckNearestAncestorWithClass(object $node, string $class): ?object
	{
		$parent = $node->parent();

		while ($parent !== null && $parent->tag !== 'root') {
			$classes = preg_split('/\s+/', trim((string) ($parent->getAttribute('class') ?? ''))) ?: [];

			if (\in_array($class, $classes, true)) {
				return $parent;
			}

			$parent = $parent->parent();
		}

		return null;
	}
}
