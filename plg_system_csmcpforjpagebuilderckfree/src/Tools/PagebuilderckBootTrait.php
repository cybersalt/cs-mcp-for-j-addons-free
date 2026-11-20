<?php
/**
 * @package     MCP for Page Builder CK
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;

/**
 * Shared orientation for the Page Builder CK tools: is it installed, which
 * edition, which addons can actually render, which tables exist, and the
 * guards that stop a well-formed write from quietly destroying data.
 *
 * ---------------------------------------------------------------------------
 * EDITION DETECTION
 * ---------------------------------------------------------------------------
 *
 * Unlike SP Page Builder — whose `isProVersion()` is hardcoded `true` in the
 * FREE build and therefore lies — Page Builder CK's gate is honest and trivial:
 *
 *     file_exists(<admin>/components/com_pagebuilderck/pro)
 *
 * That single directory check IS the whole Pro licence system. There is no key,
 * no download id, no phone-home. It is enforced in controllers and toolbars
 * only, never at the data layer, so writing the database directly would bypass
 * it completely.
 *
 * We deliberately do not do that. Tools that expose a Pro-only capability call
 * `pbckRequireVendorPro()` and refuse when the directory is absent, because
 * shipping a tool that routes around another vendor's paywall is not something
 * we are willing to do — the weakness of a gate is not permission to walk
 * through it.
 *
 * ---------------------------------------------------------------------------
 * THE WRITE HAZARDS
 * ---------------------------------------------------------------------------
 *
 *   1. The editor DESTROYS out-of-band column writes. `controllers/page.php`
 *      hardcodes alias='', ordering=0, state=1, catid='', created_by=0,
 *      access=1 on every save. Anything we set in those columns survives only
 *      until the next human Save. `pbckEditorClobberNotice()` says so in the
 *      response rather than letting it be a surprise.
 *   2. `CKFof::dbStore()` casts numeric-looking values to int on UPDATE, so a
 *      title of "3.6" is stored as 3. `pbckNumericHazard()` detects this.
 *   3. `#__pagebuilderck_styles` uses `text` (64 KB), not `longtext`. Oversized
 *      style content is silently truncated by MySQL in non-strict mode.
 */
trait PagebuilderckBootTrait
{
	/** Columns the page/style editor overwrites unconditionally on every save. */
	private const EDITOR_CLOBBERED = ['alias', 'ordering', 'state', 'catid', 'created_by', 'access'];

	/** `text` columns — 65,535 bytes, not longtext. */
	private const TEXT_COLUMN_LIMIT = 65535;

	private static ?string $pbckBaseCache = null;
	private static ?array $pbckManifestCache = null;
	private static ?array $pbckAddonCache = null;
	private static array $pbckTableCache = [];

	// -----------------------------------------------------------------------
	// Presence
	// -----------------------------------------------------------------------

	public function getCategory(): string
	{
		return 'pagebuilderck';
	}

	// -----------------------------------------------------------------------
	// Permissions and safe messages
	// -----------------------------------------------------------------------

	/**
	 * Page Builder CK's own permissions, on top of MCP for J's Use / Write.
	 *
	 * Page Builder CK opens only with "Access Administration Interface"
	 * (core.manage, com_pagebuilderck/pagebuilderck.php), and every save, copy and
	 * import in its controllers calls CKFof::checkSecurity(), which is "Edit"
	 * (core.edit) on com_pagebuilderck. So every tool needs core.manage, every
	 * write also needs core.edit, and writes that add, delete or publish also name
	 * the standard Joomla action (core.create / core.delete / core.edit.state).
	 * A Super User passes everything.
	 *
	 * @throws \RuntimeException
	 */
	protected function pbckRequire(User $actor, string ...$actions): void
	{
		$labels = [
			'core.manage'     => 'Access Administration Interface',
			'core.create'     => 'Create',
			'core.edit'       => 'Edit',
			'core.edit.state' => 'Edit State',
			'core.delete'     => 'Delete',
			'core.admin'      => 'Configure ACL & Options',
		];

		foreach (array_unique(['core.manage', ...$actions]) as $action) {
			if (!$actor->authorise($action, 'com_pagebuilderck')) {
				throw new \RuntimeException('You need \'' . ($labels[$action] ?? $action) . '\' permission (' . $action
					. ') on Page Builder CK to do this. A Super User can grant it in Page Builder CK\'s Options, on the '
					. 'Permissions tab (or in System → Global Configuration → Permissions).');
			}
		}
	}

	/** A server path as "[site root]/…", so no reply hands the AI app the absolute path. */
	protected function pbckSitePath(?string $path): ?string
	{
		return $path === null ? null : str_replace(str_replace('\\', '/', JPATH_ROOT), '[site root]', str_replace('\\', '/', $path));
	}

	/** An exception message without SQL or server paths, safe to hand to the AI app. */
	protected function pbckSafeMessage(\Throwable $e): string
	{
		if ($e instanceof \Joomla\Database\Exception\ExecutionFailureException
			|| $e instanceof \Joomla\Database\Exception\ConnectionFailureException
			|| $e instanceof \PDOException || $e instanceof \mysqli_sql_exception) {
			return 'a database error occurred (error ' . (int) $e->getCode() . ')';
		}

		return (string) $this->pbckSitePath($e->getMessage());
	}

	/** Absolute path to the admin component, or null when not installed. */
	protected function pbckAdminBase(): ?string
	{
		if (self::$pbckBaseCache !== null) {
			return self::$pbckBaseCache === '' ? null : self::$pbckBaseCache;
		}

		$path = JPATH_ADMINISTRATOR . '/components/com_pagebuilderck';
		self::$pbckBaseCache = is_dir($path) ? $path : '';

		return self::$pbckBaseCache === '' ? null : self::$pbckBaseCache;
	}

	/** Absolute path to the site component, or null. */
	protected function pbckSiteBase(): ?string
	{
		$path = JPATH_SITE . '/components/com_pagebuilderck';

		return is_dir($path) ? $path : null;
	}

	/** The site root as Page Builder CK tokenises it (`Uri::root(true)`). */
	protected function pbckSiteRoot(): string
	{
		return (string) Uri::root(true);
	}

	protected function pbckNotInstalledError(): ToolResult
	{
		return ToolResult::json([
			'ok'    => false,
			'error' => 'Page Builder CK is not installed on this site. Expected '
				. 'administrator/components/com_pagebuilderck. Install it from '
				. 'https://www.joomlack.fr/en/joomla-extensions/page-builder-ck before using these tools.',
		], true);
	}

	// -----------------------------------------------------------------------
	// Edition
	// -----------------------------------------------------------------------

	/**
	 * True when the vendor's Pro build is installed.
	 *
	 * This mirrors `PagebuilderckHelper::getParams()` exactly — the presence of
	 * the `pro` directory is the entire gate.
	 */
	protected function pbckVendorIsPro(): bool
	{
		$base = $this->pbckAdminBase();

		return $base !== null && is_dir($base . '/pro');
	}

	/**
	 * Refuse a Pro-only capability on a site running the Light build.
	 *
	 * We could implement most of these against the database regardless — the
	 * helpers Page Builder CK uses for export live in the free build too. We
	 * don't, on purpose. See the class docblock.
	 */
	protected function pbckRequireVendorPro(string $capability): ?ToolResult
	{
		if ($this->pbckVendorIsPro()) {
			return null;
		}

		return ToolResult::json([
			'ok'         => false,
			'error'      => sprintf(
				'%s is a Page Builder CK PRO feature and this site is running the Light (free) build.',
				$capability
			),
			'edition'    => 'light',
			'why'        => 'Page Builder CK gates this behind the presence of its `pro` directory. This '
				. 'add-on honours that gate rather than writing the database directly to route around '
				. 'it, even though it technically could.',
			'resolution' => 'Purchase and install Page Builder CK Pro from '
				. 'https://www.joomlack.fr/en/joomla-extensions/page-builder-ck',
		], true);
	}

	/** Parsed component manifest, or null. */
	protected function pbckManifest(): ?array
	{
		if (self::$pbckManifestCache !== null) {
			return self::$pbckManifestCache === [] ? null : self::$pbckManifestCache;
		}

		self::$pbckManifestCache = [];

		$base = $this->pbckAdminBase();

		if ($base === null) {
			return null;
		}

		$file = $base . '/pagebuilderck.xml';

		if (!is_file($file)) {
			return null;
		}

		$xml = @simplexml_load_file($file);

		if ($xml === false) {
			return null;
		}

		self::$pbckManifestCache = [
			'version' => trim((string) ($xml->version ?? '')),
			'variant' => trim((string) ($xml->variant ?? '')),
			'ckpro'   => trim((string) ($xml->ckpro ?? '')),
		];

		return self::$pbckManifestCache;
	}

	protected function pbckVersion(): ?string
	{
		$manifest = $this->pbckManifest();
		$version  = $manifest['version'] ?? '';

		return $version === '' ? null : $version;
	}

	/**
	 * Edition summary for inclusion in tool responses.
	 *
	 * Reports the manifest's own claim AND the filesystem check, because they
	 * are independent signals and a mismatch is worth surfacing (it means a Pro
	 * build was installed over a Light one, or vice versa, leaving stale files).
	 */
	protected function pbckEditionNotice(): array
	{
		$manifest = $this->pbckManifest() ?? [];
		$dirPro   = $this->pbckVendorIsPro();
		$claimPro = ($manifest['ckpro'] ?? '') === '1' || strtolower($manifest['variant'] ?? '') === 'pro';

		$notice = [
			'name'             => 'Page Builder CK',
			'version'          => $manifest['version'] ?? null,
			'edition'          => $dirPro ? 'pro' : 'light',
			'manifest_variant' => $manifest['variant'] ?? null,
			'pro_dir_present'  => $dirPro,
		];

		if ($claimPro !== $dirPro) {
			$notice['warning'] = 'The manifest and the filesystem disagree about the edition. The '
				. 'manifest says ' . ($claimPro ? 'Pro' : 'Light') . ' but the `pro` directory is '
				. ($dirPro ? 'present' : 'absent') . '. Page Builder CK trusts the directory, so that '
				. 'is what actually governs behaviour. This usually means one edition was installed '
				. 'over the other and left stale files behind.';
		}

		return $notice;
	}

	// -----------------------------------------------------------------------
	// Addons
	// -----------------------------------------------------------------------

	/**
	 * Every `pagebuilderck` plugin, with its enabled state.
	 *
	 * The plugin's element name IS the block `data-type` — the renderer gates on
	 * `PluginHelper::isEnabled('pagebuilderck', $type)` — so this table is the
	 * authority on which block types will render with their real behaviour.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function pbckAddonInventory(): array
	{
		if (self::$pbckAddonCache !== null) {
			return self::$pbckAddonCache;
		}

		$query = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('element'),
				$this->db->quoteName('name'),
				$this->db->quoteName('enabled'),
			])
			->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
			->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('pagebuilderck'))
			->order($this->db->quoteName('element') . ' ASC');

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$out[] = [
				'type'    => (string) $row['element'],
				'name'    => (string) $row['name'],
				'enabled' => (int) $row['enabled'] === 1,
			];
		}

		return self::$pbckAddonCache = $out;
	}

	/**
	 * `data-type` values that will render with their real behaviour.
	 *
	 * @return array<int,string>
	 */
	protected function pbckEnabledAddonTypes(): array
	{
		$types = [];

		foreach ($this->pbckAddonInventory() as $addon) {
			if ($addon['enabled']) {
				$types[] = $addon['type'];
			}
		}

		return $types;
	}

	/**
	 * Block types handled by the core component, with no plugin.
	 *
	 * `rowinrow` only appears in the editor menu when the `nestedrows` component
	 * param is on, but existing nested rows still render regardless.
	 *
	 * @return array<int,string>
	 */
	protected function pbckCoreTypes(): array
	{
		return ['row', 'rowinrow', 'readmore'];
	}

	protected function pbckNestedRowsEnabled(): bool
	{
		return (string) ComponentHelper::getParams('com_pagebuilderck')->get('nestedrows', '0') === '1';
	}

	// -----------------------------------------------------------------------
	// Tables
	// -----------------------------------------------------------------------

	protected function pbckTable(string $unprefixed): string
	{
		return '#__pagebuilderck_' . $unprefixed;
	}

	/**
	 * True when the table exists.
	 *
	 * `options` is a special case: it is NOT created by the installer. Page
	 * Builder CK creates it lazily on first read/write and swallows any failure,
	 * so on a fresh site it legitimately does not exist yet.
	 */
	protected function pbckTableExists(string $unprefixed): bool
	{
		if (\array_key_exists($unprefixed, self::$pbckTableCache)) {
			return self::$pbckTableCache[$unprefixed];
		}

		$prefixed = $this->db->replacePrefix($this->pbckTable($unprefixed));
		$tables   = $this->db->getTableList() ?: [];

		return self::$pbckTableCache[$unprefixed] = \in_array($prefixed, $tables, true);
	}

	/** @return array<int,string> */
	protected function pbckColumns(string $unprefixed): array
	{
		if (!$this->pbckTableExists($unprefixed)) {
			return [];
		}

		return array_keys($this->db->getTableColumns($this->pbckTable($unprefixed)) ?: []);
	}

	protected function pbckMissingTableError(string $unprefixed): ToolResult
	{
		$extra = $unprefixed === 'options'
			? ' #__pagebuilderck_options is not created by the installer — Page Builder CK creates it '
				. 'lazily the first time a content type or plugin option is saved, and silently ignores '
				. 'failure. On a site where that has never happened, its absence is normal.'
			: '';

		return ToolResult::json([
			'ok'    => false,
			'error' => sprintf('Table %s does not exist on this site.%s', $this->pbckTable($unprefixed), $extra),
		], true);
	}

	// -----------------------------------------------------------------------
	// Write guards
	// -----------------------------------------------------------------------

	/**
	 * Warning for any response that wrote a column the editor overwrites.
	 *
	 * @param array<int,string> $written Columns this call actually set.
	 */
	protected function pbckEditorClobberNotice(array $written): ?string
	{
		$at_risk = array_values(array_intersect($written, self::EDITOR_CLOBBERED));

		if ($at_risk === []) {
			return null;
		}

		return sprintf(
			'Page Builder CK\'s own editor hardcodes %s on every save, so the value(s) written here '
				. 'for %s will be reset the next time someone opens this item in the builder and clicks '
				. 'Save. This is a quirk of the vendor\'s save controller, not of this tool.',
			implode(', ', array_map(
				static fn ($c) => $c . "=" . match ($c) {
					'alias', 'catid' => "''",
					'ordering', 'created_by' => '0',
					'state', 'access' => '1',
					default => '?',
				},
				self::EDITOR_CLOBBERED
			)),
			implode(', ', $at_risk)
		);
	}

	/**
	 * Detect values that Page Builder CK's own storage layer will mangle.
	 *
	 * `CKFof::dbStore()` does `is_numeric($v) ? (int) $v : quote($v)` on UPDATE,
	 * so "3.6" becomes 3, "007" becomes 7 and "1e3" becomes 1000 — in varchar
	 * columns.
	 *
	 * @param array<string,mixed> $data
	 * @return array<int,string>
	 */
	protected function pbckNumericHazards(array $data): array
	{
		$hazards = [];

		foreach ($data as $column => $value) {
			if (!\is_string($value) || $value === '' || !is_numeric($value)) {
				continue;
			}

			if ((string) (int) $value === $value) {
				continue; // survives the cast unchanged
			}

			$hazards[] = sprintf(
				'%s: "%s" is numeric-looking, and Page Builder CK\'s storage layer casts such values '
					. 'to int on UPDATE — it would be stored as %d.',
				$column,
				$value,
				(int) $value
			);
		}

		return $hazards;
	}

	/**
	 * Refuse content too large for a `text` column.
	 *
	 * MySQL truncates silently in non-strict mode, which corrupts CSS or markup
	 * at an arbitrary byte with no error anywhere.
	 */
	protected function pbckAssertFitsText(string $value, string $columnLabel): ?ToolResult
	{
		$bytes = \strlen($value);

		if ($bytes <= self::TEXT_COLUMN_LIMIT) {
			return null;
		}

		return ToolResult::json([
			'ok'    => false,
			'error' => sprintf(
				'%s is %d bytes, over the %d-byte limit of its `text` column. MySQL truncates silently '
					. 'in non-strict mode, so writing this would corrupt the value at an arbitrary byte '
					. 'with no error. Refusing.',
				$columnLabel,
				$bytes,
				self::TEXT_COLUMN_LIMIT
			),
		], true);
	}

	// -----------------------------------------------------------------------
	// Small shared helpers
	// -----------------------------------------------------------------------

	protected function pbckLimit(array $arguments, int $default = 50, int $max = 200): int
	{
		$limit = (int) ($arguments['limit'] ?? $default);

		return max(1, min($max, $limit));
	}

	protected function pbckOffset(array $arguments): int
	{
		return max(0, (int) ($arguments['offset'] ?? 0));
	}

	protected function pbckStateLabel(int $state): string
	{
		return match ($state) {
			1       => 'published',
			0       => 'unpublished',
			-2      => 'trashed',
			2       => 'archived',
			default => 'unknown(' . $state . ')',
		};
	}

	/** @return int|null Null means "no state filter". */
	protected function pbckNormaliseState(mixed $raw): ?int
	{
		if ($raw === null || $raw === '') {
			return null;
		}

		if (is_numeric($raw)) {
			// Only the four states Page Builder CK's screens can show and undo.
			return \in_array((int) $raw, [-2, 0, 1, 2], true) && (string) (int) $raw === trim((string) $raw) ? (int) $raw : null;
		}

		return match (strtolower(trim((string) $raw))) {
			'published'   => 1,
			'unpublished' => 0,
			'trashed'     => -2,
			'archived'    => 2,
			default       => null,
		};
	}

	/**
	 * Checked-out state, which Page Builder CK never releases automatically.
	 *
	 * `checked_out` is a varchar holding the user id. A row left checked out by
	 * an abandoned session stays uneditable in the front-end editor until it is
	 * cleared by hand.
	 */
	protected function pbckCheckedOutBy(array $row): ?int
	{
		$raw = trim((string) ($row['checked_out'] ?? ''));

		return $raw === '' || $raw === '0' ? null : (int) $raw;
	}

	/**
	 * A page title as Page Builder CK's own save stores it: its controller reads
	 * the title with the "string" input filter, which strips tags, and the title
	 * is printed unescaped in its Pages list and the front-end heading.
	 */
	protected function pbckCleanTitle(string $title): string
	{
		return trim((string) InputFilter::getInstance()->clean($title, 'string'));
	}

	/**
	 * Refuse page options Page Builder CK would print as markup: `titletag` becomes
	 * the heading element on the front end (`<{titletag} class="pagebuilderck_title">`).
	 */
	protected function pbckCheckParams(string $json): ?ToolResult
	{
		$params = $json === '' ? [] : json_decode($json, true);
		$tag    = \is_array($params) ? ($params['titletag'] ?? null) : null;

		if ($tag === null || $tag === '' || \in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'p', 'span'], true)) {
			return null;
		}

		return ToolResult::json([
			'ok'    => false,
			'error' => 'params.titletag must be one of h1, h2, h3, h4, h5, h6, div, p or span: Page Builder CK prints it '
				. 'as the page heading\'s HTML element. Nothing was written.',
		], true);
	}

	/** Input-schema entry for the check-out override shared by the content writes. */
	protected function pbckForceCheckedOutSchema(): array
	{
		return ['type' => 'boolean', 'description' => 'Write even when the page is checked out by another user. Default false.'];
	}

	/**
	 * Refuse a write to a page someone else has checked out, as Page Builder CK's
	 * own screens do (Save is hidden and the front-end editor won't open it).
	 * Null when the write may go ahead; a dry run writes nothing, so it passes.
	 */
	protected function pbckRefuseCheckedOut(array $row, User $actor, array $arguments): ?ToolResult
	{
		$checkedOut = $this->pbckCheckedOutBy($row);

		if ($checkedOut === null || $checkedOut === (int) $actor->id || !empty($arguments['force_checked_out'])
			|| !empty($arguments['dry_run'])) {
			return null;
		}

		return ToolResult::json([
			'ok'             => false,
			'error'          => 'Refusing to change page ' . (int) ($row['id'] ?? 0) . ': it is checked out by user '
				. $checkedOut . '. That user may have it open in Page Builder CK, and their next Save would '
				. 'overwrite anything written now. Nothing was written.',
			'checked_out_by' => $checkedOut,
			'resolution'     => 'Wait for them to save or cancel, or pass force_checked_out: true to write anyway. '
				. 'Page Builder CK has no global check-in, so a lock left by an abandoned session never clears on its own.',
		], true);
	}

	protected function pbckPreview(?string $value, int $max = 400): array
	{
		$value = (string) $value;
		$bytes = \strlen($value);

		return [
			'bytes'     => $bytes,
			'truncated' => $bytes > $max,
			'preview'   => $bytes > $max ? substr($value, 0, $max) . '…' : $value,
		];
	}
}
