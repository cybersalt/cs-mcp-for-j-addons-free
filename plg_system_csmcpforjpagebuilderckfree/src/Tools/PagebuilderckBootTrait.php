<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

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
			return (int) $raw;
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
