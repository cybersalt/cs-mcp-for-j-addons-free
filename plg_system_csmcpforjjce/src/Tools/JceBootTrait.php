<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjce\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Plugin\PluginHelper;

/**
 * Shared orientation and encoding for every JCE tool.
 *
 * ---------------------------------------------------------------------------
 * ONE TABLE, ONE ENTITY
 * ---------------------------------------------------------------------------
 *
 * JCE stores exactly one thing in the database: rows in `#__wf_profiles`
 * (`administrator/components/com_jce/sql/mysql.sql:1-22`, 19 columns, primary
 * key only, no other index and no unique constraint on `name`). Everything the
 * editor does — which toolbar buttons appear, which plugins are enabled, which
 * user groups get an editor at all, what may be uploaded and where — is either
 * a column on that table or a key inside its `params` JSON blob.
 *
 * There is no second table under Pro either. `plg_system_jcepro` ships no SQL
 * install file at all, so this add-on covers 100% of JCE's persisted state on
 * both editions.
 *
 * ---------------------------------------------------------------------------
 * THE FIVE ENCODINGS
 * ---------------------------------------------------------------------------
 *
 *   `types`      comma-separated `#__usergroups.id` list
 *   `users`      comma-separated `#__users.id` list
 *   `components` comma-separated `option` strings (`com_content,com_contact`)
 *   `device`     comma-separated tokens from {desktop, tablet, phone}
 *   `plugins`    comma-separated bare plugin names
 *   `rows`       `;`-separated toolbar rows, `,`-separated buttons within a
 *                row, with the literal token `spacer` acting as a group
 *                divider inside a row
 *   `area`       integer enum, 0 = both, 1 = site, 2 = administrator
 *   `params`     JSON keyed by plugin name, plus `editor` and (Pro) `setup`
 *
 * The vendor sanitises `rows` with `preg_replace('#[^\w,;]+#','',$v)` and
 * `plugins` with `preg_replace('#[^\w_,]+#','',$v)` on every save
 * (`models/profile.php:643-647`). Both are lossy — a hyphenated plugin name
 * such as `editor-foo` silently becomes `editorfoo`. We apply the same
 * transform ourselves BEFORE writing so the caller is told what will actually
 * be stored rather than discovering it later.
 *
 * ---------------------------------------------------------------------------
 * `params` MAY BE ENCRYPTED — AND THERE IS NO WAY BACK
 * ---------------------------------------------------------------------------
 *
 * `JceTableProfiles::load()` (`tables/profiles.php:32-44`) pipes `params`
 * through `JceEncryptHelper::decrypt()`, which recognises three 12-character
 * magic prefixes — `###AES128###`, `###CTR128###`, `###DEFUSE###` — keyed off
 * the `WF_SERVERKEY` constant in an unshipped, per-site generated
 * `administrator/components/com_jce/serverkey.php` (`helpers/encrypt.php:26-52`).
 *
 * Two facts govern our behaviour:
 *
 *   1. `json_decode()` on a ciphertext returns `null` with no error. A tool that
 *      does not check the prefix will quietly report "this profile has no
 *      configuration" for a fully configured profile.
 *   2. There is NO `encrypt()` path anywhere in 2.9.99.10 — the only `encrypt`
 *      functions in the tree are inside the bundled Defuse vendor library and
 *      nothing calls them. So any write plaintexts the row permanently.
 *
 * Therefore: we detect the prefix, we refuse, and we say why. We never attempt
 * to decrypt, and we never overwrite a ciphertext with plaintext.
 *
 * ---------------------------------------------------------------------------
 * EDITION
 * ---------------------------------------------------------------------------
 *
 * The Pro gate is `PluginHelper::isEnabled('system', 'jcepro')` and nothing
 * else — one call, in one place (`models/cpanel.php:127-131`). There is no
 * `isPro()` helper, no `JCE_PRO` constant and no runtime licence check. The
 * `updates_key` component param gates downloads from the vendor's update
 * server, never features, so we do not look at it: a Pro site with a lapsed
 * subscription is still fully Pro.
 */
trait JceBootTrait
{
	/** The only JCE table. */
	private const PROFILES_TABLE = '#__wf_profiles';

	/**
	 * The 12-character magic prefixes `JceEncryptHelper::decrypt()` recognises.
	 *
	 * `helpers/encrypt.php:63-100`. Anything else is passed through as plaintext.
	 */
	private const ENCRYPTED_PREFIXES = ['###AES128###', '###CTR128###', '###DEFUSE###'];

	/**
	 * Columns that only exist on a fresh install, or on an upgraded MySQL one.
	 *
	 * `install.pkg.php:498-517` back-fills these with ALTER TABLE only when
	 * `strpos($db->getName(), 'mysql') !== false`, and `checkTableUpdate()`
	 * early-returns for non-MySQL at `:489-491`. A long-lived PostgreSQL or SQL
	 * Server site legitimately does not have them.
	 */
	private const DRIFTING_COLUMNS = ['created', 'created_by', 'modified', 'modified_by'];

	/** Valid `device` tokens. */
	private const DEVICE_TOKENS = ['desktop', 'tablet', 'phone'];

	private static ?string $jceBaseCache = null;

	private static ?string $jceVersionCache = null;

	private static ?bool $jceProCache = null;

	private static ?array $jceCatalogCache = null;

	private static ?bool $jceTableCache = null;

	private static ?array $jceColumnCache = null;

	// -----------------------------------------------------------------------
	// Presence
	// -----------------------------------------------------------------------

	public function getCategory(): string
	{
		return 'jce';
	}

	/** Absolute path to the admin component, or null when JCE is not installed. */
	protected function jceAdminBase(): ?string
	{
		if (self::$jceBaseCache !== null) {
			return self::$jceBaseCache === '' ? null : self::$jceBaseCache;
		}

		$path = JPATH_ADMINISTRATOR . '/components/com_jce';
		self::$jceBaseCache = is_dir($path) ? $path : '';

		return self::$jceBaseCache === '' ? null : self::$jceBaseCache;
	}

	protected function jceSiteBase(): ?string
	{
		$path = JPATH_SITE . '/components/com_jce';

		return is_dir($path) ? $path : null;
	}

	protected function jceNotInstalledError(): ToolResult
	{
		return ToolResult::json([
			'ok'    => false,
			'error' => 'JCE (Joomla Content Editor) is not installed on this site. Expected '
				. 'administrator/components/com_jce. Install it from '
				. 'https://www.joomlacontenteditor.net/ before using these tools. The free edition is '
				. 'enough for everything in this add-on.',
		], true);
	}

	// -----------------------------------------------------------------------
	// Version and edition
	// -----------------------------------------------------------------------

	/**
	 * Installed JCE version, from the component manifest, falling back to the
	 * `WF_VERSION` constant.
	 *
	 * The manifest (`administrator/components/com_jce/jce.xml`) is authoritative
	 * because it is what the Joomla installer wrote. `includes/constants.php:16`
	 * defines the same string and is the value JCE's own code compares against,
	 * so it is a good cross-check when the manifest is missing.
	 */
	protected function jceVersion(): ?string
	{
		if (self::$jceVersionCache !== null) {
			return self::$jceVersionCache === '' ? null : self::$jceVersionCache;
		}

		self::$jceVersionCache = '';

		$base = $this->jceAdminBase();

		if ($base === null) {
			return null;
		}

		$manifest = $base . '/jce.xml';

		if (is_file($manifest)) {
			$xml = @simplexml_load_file($manifest);

			if ($xml !== false) {
				self::$jceVersionCache = trim((string) ($xml->version ?? ''));
			}
		}

		if (self::$jceVersionCache === '') {
			// constants.php defines WF_VERSION as a literal; read it without
			// including the file, which would fire JCE's own bootstrap.
			$constants = $base . '/includes/constants.php';

			if (is_file($constants)) {
				$body = (string) @file_get_contents($constants);

				if (preg_match("/define\(\s*'WF_VERSION'\s*,\s*'([^']+)'/", $body, $m) === 1) {
					self::$jceVersionCache = $m[1];
				}
			}
		}

		return self::$jceVersionCache === '' ? null : self::$jceVersionCache;
	}

	/**
	 * True when the Pro system plugin is installed AND enabled.
	 *
	 * This mirrors `models/cpanel.php:127` exactly. It is deliberately an
	 * "enabled" check, not a "present" check: a disabled `plg_system_jcepro`
	 * contributes nothing at runtime because every Pro capability is delivered
	 * through `onWf*` event listeners that never fire.
	 */
	protected function jceIsPro(): bool
	{
		if (self::$jceProCache !== null) {
			return self::$jceProCache;
		}

		return self::$jceProCache = PluginHelper::isEnabled('system', 'jcepro');
	}

	/**
	 * Edition and version summary. Include this in every response.
	 *
	 * @return array<string,mixed>
	 */
	protected function jceEditionNotice(): array
	{
		$version = $this->jceVersion();
		$pro     = $this->jceIsPro();

		$notice = [
			'name'      => 'JCE (Joomla Content Editor)',
			'version'   => $version,
			'edition'   => $pro ? 'pro' : 'free',
			'pro_gate'  => "PluginHelper::isEnabled('system','jcepro')",
			'pro_note'  => $pro
				? 'Pro adds no new table and no new stored entity. It adds more keys inside the same '
					. '#__wf_profiles.params blob (notably the editor.upload_*, editor.watermark_* and '
					. 'setup.custom families) and more valid names for rows/plugins. Everything Pro '
					. 'persists is covered by these tools.'
				: 'The free edition is installed. Profiles may still reference Pro-only plugin names '
					. '(imgmanager_ext, filemanager, mediamanager, templatemanager, caption, columns, '
					. 'microdata, iframe, source, textpattern) — the seeded Default profile does. Under '
					. 'the free edition those names are inert: no button, no error, no log line.',
		];

		if ($version === null) {
			$notice['version_note'] = 'The version could not be determined from '
				. 'administrator/components/com_jce/jce.xml or includes/constants.php, so the '
				. 'CVE-2026-48907 safety gate could not be evaluated. Writes will be refused.';
		}

		return $notice;
	}

	// -----------------------------------------------------------------------
	// The table
	// -----------------------------------------------------------------------

	protected function jceTable(): string
	{
		return self::PROFILES_TABLE;
	}

	protected function jceTableExists(): bool
	{
		if (self::$jceTableCache !== null) {
			return self::$jceTableCache;
		}

		$prefixed = $this->db->replacePrefix(self::PROFILES_TABLE);
		$tables   = $this->db->getTableList() ?: [];

		return self::$jceTableCache = \in_array($prefixed, $tables, true);
	}

	/**
	 * Columns that actually exist, not the ones the SQL file declares.
	 *
	 * @return array<int,string>
	 */
	protected function jceColumns(): array
	{
		if (self::$jceColumnCache !== null) {
			return self::$jceColumnCache;
		}

		if (!$this->jceTableExists()) {
			return self::$jceColumnCache = [];
		}

		return self::$jceColumnCache = array_keys(
			$this->db->getTableColumns(self::PROFILES_TABLE) ?: []
		);
	}

	/** @return array<int,string> Tracking columns absent on this install. */
	protected function jceMissingDriftColumns(): array
	{
		return array_values(array_diff(self::DRIFTING_COLUMNS, $this->jceColumns()));
	}

	protected function jceMissingTableError(): ToolResult
	{
		return ToolResult::json([
			'ok'    => false,
			'error' => 'Table ' . self::PROFILES_TABLE . ' does not exist on this site. It is the only '
				. 'table JCE has, and the installer creates it with CREATE TABLE IF NOT EXISTS, so its '
				. 'absence means an incomplete or rolled-back installation. Reinstall com_jce — existing '
				. 'data is not touched by the installer.',
		], true);
	}

	/**
	 * A single profile row, or null.
	 *
	 * `SELECT *` rather than an explicit column list, because the tracking
	 * columns may not exist on an upgraded non-MySQL install.
	 *
	 * @return array<string,mixed>|null
	 */
	protected function jceProfileRow(int $id): ?array
	{
		$query = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName(self::PROFILES_TABLE))
			->where($this->db->quoteName('id') . ' = ' . (int) $id);

		$row = $this->db->setQuery($query)->loadAssoc();

		return \is_array($row) ? $row : null;
	}

	protected function jceProfileNotFoundError(int $id): ToolResult
	{
		return ToolResult::json([
			'ok'    => false,
			'error' => sprintf('No JCE profile with id %d. Call list_jce_profiles for the ids.', $id),
		], true);
	}

	// -----------------------------------------------------------------------
	// `params` — decode, encode, dotted paths
	// -----------------------------------------------------------------------

	/**
	 * Decode a raw `params` column value, refusing rather than guessing.
	 *
	 * @return array{ok:bool,encrypted:bool,algorithm:?string,params:array<string,mixed>,error:?string,bytes:int}
	 */
	protected function jceDecodeParams(mixed $raw): array
	{
		$raw   = (string) $raw;
		$bytes = \strlen($raw);

		$out = [
			'ok'        => true,
			'encrypted' => false,
			'algorithm' => null,
			'params'    => [],
			'error'     => null,
			'bytes'     => $bytes,
		];

		if (trim($raw) === '') {
			return $out;
		}

		$prefix = substr($raw, 0, 12);

		if (\in_array($prefix, self::ENCRYPTED_PREFIXES, true)) {
			$out['ok']        = false;
			$out['encrypted'] = true;
			$out['algorithm'] = $prefix;
			$out['error']     = sprintf(
				'This profile\'s params column is ENCRYPTED with the legacy %s scheme. The key lives in '
					. 'administrator/components/com_jce/serverkey.php as WF_SERVERKEY, a per-site generated '
					. 'file that is not shipped in any package. This add-on does not decrypt it: reading it '
					. 'wrongly would report an empty configuration for a fully configured profile, and '
					. 'writing it would replace the ciphertext with plaintext permanently, because JCE '
					. '2.9.99.10 has no encrypt path left anywhere in its codebase — only decrypt. Open and '
					. 'save this profile once in the JCE admin UI; that decrypts it in place through '
					. 'JceTableProfiles::load() + store(), after which these tools work on it normally.',
				$prefix
			);

			return $out;
		}

		$decoded = json_decode($raw, true);

		if (!\is_array($decoded)) {
			$out['ok']    = false;
			$out['error'] = 'params is neither valid JSON nor a recognised JCE ciphertext ('
				. json_last_error_msg() . '). The column has been corrupted by something other than JCE. '
				. 'Refusing to interpret it.';

			return $out;
		}

		$out['params'] = $decoded;

		return $out;
	}

	protected function jceEncryptedParamsError(int $id, array $decoded): ToolResult
	{
		return ToolResult::json([
			'ok'        => false,
			'error'     => $decoded['error'],
			'profile_id' => $id,
			'encrypted' => true,
			'algorithm' => $decoded['algorithm'],
			'component' => $this->jceEditionNotice(),
		], true);
	}

	/**
	 * Flatten a params tree into dotted paths, e.g. `editor.toolbar_theme`.
	 *
	 * Lists (`setup.custom` is a list of {name,value} objects) are left whole at
	 * their own path rather than exploded into numeric segments, because JCE
	 * itself addresses them as a unit.
	 *
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	protected function jceFlattenParams(array $params, string $prefix = ''): array
	{
		$flat = [];

		foreach ($params as $key => $value) {
			$path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

			if (\is_array($value) && $value !== [] && !array_is_list($value)) {
				$flat += $this->jceFlattenParams($value, $path);

				continue;
			}

			$flat[$path] = $value;
		}

		return $flat;
	}

	/**
	 * Read a dotted path out of a params tree. Returns $default when absent.
	 *
	 * This deliberately does NOT reproduce `WFApplication::getParam()`, which
	 * has the surprising contract of returning `''` when the resolved value
	 * equals the supplied default (`classes/application.php:583`).
	 *
	 * @param array<string,mixed> $params
	 */
	protected function jceGetParamPath(array $params, string $path, mixed $default = null): mixed
	{
		$node = $params;

		foreach (explode('.', $path) as $segment) {
			if (!\is_array($node) || !\array_key_exists($segment, $node)) {
				return $default;
			}

			$node = $node[$segment];
		}

		return $node;
	}

	/**
	 * Write a dotted path into a params tree, creating intermediate objects.
	 *
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	protected function jceSetParamPath(array $params, string $path, mixed $value): array
	{
		$segments = explode('.', $path);
		$node     = &$params;

		foreach ($segments as $i => $segment) {
			if ($i === \count($segments) - 1) {
				$node[$segment] = $value;

				break;
			}

			if (!isset($node[$segment]) || !\is_array($node[$segment])) {
				$node[$segment] = [];
			}

			$node = &$node[$segment];
		}

		unset($node);

		return $params;
	}

	/**
	 * Remove a dotted path from a params tree.
	 *
	 * True deletion is only possible when writing the `params` column directly —
	 * `JceModelProfile::save()` MERGES incoming params with the stored ones
	 * (`models/profile.php:908`, `WFUtility::array_merge_recursive_distinct`),
	 * so nothing can be unset through the vendor's own save path.
	 *
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	protected function jceUnsetParamPath(array $params, string $path): array
	{
		$segments = explode('.', $path);
		$last     = array_pop($segments);
		$node     = &$params;

		foreach ($segments as $segment) {
			if (!isset($node[$segment]) || !\is_array($node[$segment])) {
				unset($node);

				return $params;
			}

			$node = &$node[$segment];
		}

		unset($node[$last], $node);

		return $params;
	}

	/**
	 * Encode a params tree for storage.
	 *
	 * An empty tree becomes `{}` and not `[]`: PHP would otherwise serialise an
	 * empty array as a JSON array, and `models/profile.php:874` casts the stored
	 * value with `(array) json_decode(..., true)` expecting an object.
	 */
	protected function jceEncodeParams(array $params): string
	{
		if ($params === []) {
			return '{}';
		}

		return (string) json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	// -----------------------------------------------------------------------
	// Comma lists and the `rows` grid
	// -----------------------------------------------------------------------

	/**
	 * Split a comma list, dropping empties and preserving order.
	 *
	 * @return array<int,string>
	 */
	protected function jceSplitList(mixed $raw): array
	{
		$out = [];

		foreach (explode(',', (string) $raw) as $piece) {
			$piece = trim($piece);

			if ($piece !== '') {
				$out[] = $piece;
			}
		}

		return $out;
	}

	/** @return array<int,int> */
	protected function jceSplitIntList(mixed $raw): array
	{
		$out = [];

		foreach ($this->jceSplitList($raw) as $piece) {
			if (preg_match('/^-?\d+$/', $piece) === 1) {
				$out[] = (int) $piece;
			}
		}

		return array_values(array_unique($out));
	}

	/** @param array<int,mixed> $items */
	protected function jceJoinList(array $items): string
	{
		$clean = [];

		foreach ($items as $item) {
			$item = trim((string) $item);

			if ($item !== '') {
				$clean[] = $item;
			}
		}

		return implode(',', $clean);
	}

	/**
	 * Expand the `rows` column into a list of rows of button tokens.
	 *
	 * `spacer` is kept as a literal token: it is a real entry in the stored
	 * string and a group divider within a row, not a structural separator.
	 * Dropping it here and re-adding it on write would reorder the toolbar.
	 *
	 * @return array<int,array<int,string>>
	 */
	protected function jceParseRows(mixed $raw): array
	{
		$rows = [];

		foreach (explode(';', (string) $raw) as $rowString) {
			$rowString = trim($rowString);

			if ($rowString === '') {
				continue;
			}

			$buttons = $this->jceSplitList($rowString);

			if ($buttons !== []) {
				$rows[] = $buttons;
			}
		}

		return $rows;
	}

	/**
	 * Collapse a list of rows back into the stored `rows` string.
	 *
	 * @param array<int,array<int,string>> $rows
	 */
	protected function jceBuildRows(array $rows): string
	{
		$parts = [];

		foreach ($rows as $buttons) {
			$joined = $this->jceJoinList((array) $buttons);

			if ($joined !== '') {
				$parts[] = $joined;
			}
		}

		return implode(';', $parts);
	}

	/**
	 * Mirror of the vendor's `rows` sanitiser, `models/profile.php:646`.
	 *
	 * We apply it ourselves so that a write can report the exact string that
	 * will be stored, and refuse when sanitising would change it into something
	 * the caller did not ask for.
	 */
	protected function jceSanitiseRows(string $value): string
	{
		return (string) preg_replace('#[^\w,;]+#', '', $value);
	}

	/** Mirror of the vendor's `plugins` sanitiser, `models/profile.php:643`. */
	protected function jceSanitisePlugins(string $value): string
	{
		return (string) preg_replace('#[^\w_,]+#', '', $value);
	}

	// -----------------------------------------------------------------------
	// area / device
	// -----------------------------------------------------------------------

	/**
	 * `area` is an enum where 0 means "any", and the runtime test is
	 * `if (!empty($item->area) && (int) $item->area != $vars['area']) continue;`
	 * (`classes/application.php:444`) — so 0 short-circuits the comparison.
	 */
	protected function jceAreaLabel(int $area): string
	{
		return match ($area) {
			0       => 'both (site and administrator)',
			1       => 'site (front end) only',
			2       => 'administrator (back end) only',
			default => 'unknown(' . $area . ')',
		};
	}

	/** @return int|null Null when the input is not a recognised area. */
	protected function jceNormaliseArea(mixed $raw): ?int
	{
		if ($raw === null || $raw === '') {
			return null;
		}

		if (is_numeric($raw) && \in_array((int) $raw, [0, 1, 2], true)) {
			return (int) $raw;
		}

		return match (strtolower(trim((string) $raw))) {
			'both', 'any', 'all'            => 0,
			'site', 'front', 'frontend'     => 1,
			'admin', 'administrator', 'back', 'backend' => 2,
			default                         => null,
		};
	}

	/** @return array<int,string> */
	protected function jceDeviceTokens(): array
	{
		return self::DEVICE_TOKENS;
	}

	/**
	 * Devices this profile actually matches.
	 *
	 * An empty `device` column is treated by the runtime as all three
	 * (`classes/application.php:434-441`), which means the stored value and the
	 * effective value differ. Callers need the effective one.
	 *
	 * @return array<int,string>
	 */
	protected function jceEffectiveDevices(mixed $raw): array
	{
		$tokens = $this->jceSplitList($raw);

		return $tokens === [] ? self::DEVICE_TOKENS : $tokens;
	}

	// -----------------------------------------------------------------------
	// com_jce component params
	// -----------------------------------------------------------------------

	/**
	 * Group ids from the `profile_groups_whitelist` component param.
	 *
	 * Added in 2.9.99.7 as part of the CVE-2026-48907 remediation. When
	 * non-empty it is a hard cap: intersected with the user's groups during
	 * profile matching (`classes/application.php:386-387`) AND intersected with
	 * incoming `types` on save (`models/profile.php:612-616`), silently.
	 *
	 * @return array<int,int>
	 */
	protected function jceGroupsWhitelist(): array
	{
		$raw = ComponentHelper::getParams('com_jce')->get('profile_groups_whitelist', []);

		if (\is_string($raw)) {
			$raw = $this->jceSplitList($raw);
		}

		return array_values(array_filter(array_map('intval', (array) $raw)));
	}

	protected function jceAllowProfileGuests(): bool
	{
		return (int) ComponentHelper::getParams('com_jce')->get('allow_profile_guests', 0) === 1;
	}

	// -----------------------------------------------------------------------
	// Plugin and command catalogues
	// -----------------------------------------------------------------------

	/**
	 * Every valid token for `rows` and `plugins`, merged from four sources.
	 *
	 * Core plugins are not database records — they are a static JSON manifest at
	 * `helpers/plugins.json` (45 entries), and `JcePluginsHelper::getPlugins()`
	 * only lists one if `media/com_jce/editor/tinymce/plugins/<name>/plugin.js`
	 * exists on disk (`helpers/plugins.php:71-73`). Toolbar commands that are
	 * not plugins live in `helpers/commands.json` (20 entries). Pro injects
	 * `plugins/system/jcepro/editor/pro.json` (12 entries) via
	 * `onWfPluginsHelperGetPlugins` (`EditorTrait.php:106-149`), assigning into
	 * `$plugins[$name]` unconditionally — so `clipboard` and `styleselect` are
	 * REPLACED, not added.
	 *
	 * Third-party plugins live in the Joomla `jce` plugin group and are
	 * distinguished from JCE "extensions" (filesystem/link/popup adapters) purely
	 * by an `^editor[-_]` name prefix (`helpers/plugins.php:102`). The token that
	 * goes into `plugins` is the BARE name with that 7-character prefix stripped
	 * (`helpers/plugins.php:127`).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function jcePluginCatalog(): array
	{
		if (self::$jceCatalogCache !== null) {
			return self::$jceCatalogCache;
		}

		$catalog = [];
		$base    = $this->jceAdminBase();

		if ($base !== null) {
			foreach ($this->jceReadJson($base . '/helpers/commands.json') as $name => $meta) {
				$catalog[(string) $name] = $this->jceCatalogEntry((string) $name, (array) $meta, 'command', 'core');
			}

			foreach ($this->jceReadJson($base . '/helpers/plugins.json') as $name => $meta) {
				$catalog[(string) $name] = $this->jceCatalogEntry((string) $name, (array) $meta, 'plugin', 'core');
			}
		}

		if ($this->jceIsPro()) {
			$proJson = JPATH_PLUGINS . '/system/jcepro/editor/pro.json';

			foreach ($this->jceReadJson($proJson) as $name => $meta) {
				$existing = $catalog[(string) $name] ?? null;

				$entry = $this->jceCatalogEntry((string) $name, (array) $meta, 'plugin', 'pro');

				if ($existing !== null) {
					// EditorTrait.php:144 overwrites the core definition outright.
					$entry['overrides_core'] = true;
				}

				$catalog[(string) $name] = $entry;
			}
		}

		foreach ($this->jceInstalledJcePlugins() as $plugin) {
			if (!$plugin['is_editor_plugin']) {
				continue;
			}

			$name = $plugin['button_name'];

			$catalog[$name] = ($catalog[$name] ?? []) + [
				'name'   => $name,
				'kind'   => 'plugin',
				'source' => 'third_party',
				'icon'   => '',
				'row'    => null,
			];

			// A name already claimed by the core or Pro catalogue keeps that
			// source — Pro assigns over core unconditionally (EditorTrait.php:144)
			// and an installed row does not change which definition is in force.
			if (!\in_array($catalog[$name]['source'], ['core', 'pro'], true)) {
				$catalog[$name]['source'] = 'third_party';
			}

			$catalog[$name]['installed_element'] = $plugin['element'];
			$catalog[$name]['installed_enabled'] = $plugin['enabled'];
		}

		// The literal group divider. It is a real token in `rows`, valid
		// anywhere, and it is not a plugin.
		$catalog['spacer'] = [
			'name'   => 'spacer',
			'kind'   => 'separator',
			'source' => 'core',
			'icon'   => '',
			'row'    => null,
			'note'   => 'Group divider inside a toolbar row. Valid in `rows`, never in `plugins`.',
		];

		ksort($catalog);

		return self::$jceCatalogCache = $catalog;
	}

	/**
	 * Joomla plugins in the `jce` group, split into editor plugins and
	 * JCE "extensions".
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function jceInstalledJcePlugins(): array
	{
		$query = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('element'),
				$this->db->quoteName('name'),
				$this->db->quoteName('enabled'),
			])
			->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
			->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('jce'))
			->order($this->db->quoteName('element') . ' ASC');

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$element  = (string) $row['element'];
			$isEditor = preg_match('/^editor[-_]/', $element) === 1;

			$out[] = [
				'element'          => $element,
				'name'             => (string) $row['name'],
				'enabled'          => (int) $row['enabled'] === 1,
				'is_editor_plugin' => $isEditor,
				// helpers/plugins.php:127 strips the 7-character `editor_` prefix.
				'button_name'      => $isEditor ? substr($element, 7) : $element,
				'extension_type'   => $isEditor ? null : (explode('_', $element)[0] ?: null),
			];
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $meta
	 * @return array<string,mixed>
	 */
	private function jceCatalogEntry(string $name, array $meta, string $kind, string $source): array
	{
		return [
			'name'     => $name,
			'kind'     => $kind,
			'source'   => $source,
			'title_key' => (string) ($meta['title'] ?? ''),
			'icon'     => (string) ($meta['icon'] ?? ''),
			'row'      => isset($meta['row']) ? (int) $meta['row'] : null,
			'editable' => (int) ($meta['editable'] ?? 0) === 1,
		];
	}

	/** @return array<string,mixed> */
	private function jceReadJson(string $path): array
	{
		if (!is_file($path)) {
			return [];
		}

		$decoded = json_decode((string) @file_get_contents($path), true);

		return \is_array($decoded) ? $decoded : [];
	}

	// -----------------------------------------------------------------------
	// Small shared helpers
	// -----------------------------------------------------------------------

	protected function jceLimit(array $arguments, int $default = 50, int $max = 200): int
	{
		$limit = (int) ($arguments['limit'] ?? $default);

		return max(1, min($max, $limit));
	}

	protected function jceOffset(array $arguments): int
	{
		return max(0, (int) ($arguments['offset'] ?? 0));
	}

	/**
	 * `modified` / `modified_by` assignments, but only for columns that exist.
	 *
	 * See DRIFTING_COLUMNS: these are back-filled onto an upgraded install by
	 * ALTER TABLE only on MySQL, so a long-lived PostgreSQL or SQL Server site
	 * genuinely does not have them and an UPDATE naming them would fail.
	 *
	 * @return array<string,mixed>
	 */
	protected function jceModifiedColumns(int $userId): array
	{
		$columns = $this->jceColumns();
		$out     = [];

		if (\in_array('modified', $columns, true)) {
			$out['modified'] = \Joomla\CMS\Factory::getDate()->toSql();
		}

		if (\in_array('modified_by', $columns, true)) {
			$out['modified_by'] = $userId;
		}

		return $out;
	}

	/**
	 * Keep only group ids that exist in `#__usergroups`.
	 *
	 * JCE does the same on its own saves, silently — `prepareTable()` cleans
	 * `types` to INT and intersects it with the component whitelist
	 * (`models/profile.php:608-621`). We report what was dropped instead.
	 *
	 * @param array<int,mixed> $ids
	 * @return array{valid:array<int,int>,invalid:array<int,int>}
	 */
	protected function jceValidateGroupIds(array $ids): array
	{
		return $this->jceValidateIds($ids, '#__usergroups');
	}

	/**
	 * Keep only user ids that exist in `#__users`.
	 *
	 * Mirrors the live-DB validation JCE performs at `models/profile.php:622-641`,
	 * which drops unknown ids without a word.
	 *
	 * @param array<int,mixed> $ids
	 * @return array{valid:array<int,int>,invalid:array<int,int>}
	 */
	protected function jceValidateUserIds(array $ids): array
	{
		return $this->jceValidateIds($ids, '#__users');
	}

	/**
	 * @param array<int,mixed> $ids
	 * @return array{valid:array<int,int>,invalid:array<int,int>}
	 */
	private function jceValidateIds(array $ids, string $table): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

		if ($ids === []) {
			return ['valid' => [], 'invalid' => []];
		}

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName('id'))
			->from($this->db->quoteName($table))
			->whereIn($this->db->quoteName('id'), $ids);

		$found = array_map('intval', $this->db->setQuery($query)->loadColumn() ?: []);

		return [
			'valid'   => array_values(array_intersect($ids, $found)),
			'invalid' => array_values(array_diff($ids, $found)),
		];
	}

	/**
	 * Validate a device token list.
	 *
	 * `WFDeviceDetect` resolves every request to exactly one of desktop, tablet
	 * or phone (`classes/application.php:181-191`), so any other token is dead
	 * weight that would never match.
	 *
	 * @return array{tokens:array<int,string>,unknown:array<int,string>}
	 */
	protected function jceNormaliseDevices(mixed $raw): array
	{
		$tokens  = [];
		$unknown = [];

		foreach ((array) $raw as $token) {
			$token = strtolower(trim((string) $token));

			if ($token === '') {
				continue;
			}

			if (\in_array($token, self::DEVICE_TOKENS, true)) {
				$tokens[] = $token;
			} else {
				$unknown[] = $token;
			}
		}

		return [
			'tokens'  => array_values(array_unique($tokens)),
			'unknown' => array_values(array_unique($unknown)),
		];
	}

	protected function jceUnknownDeviceError(array $unknown): ToolResult
	{
		return ToolResult::json([
			'ok'    => false,
			'error' => 'Unrecognised device token(s): ' . implode(', ', $unknown)
				. '. Valid tokens are desktop, tablet and phone — WFDeviceDetect resolves every request '
				. 'to exactly one of those three (classes/application.php:181-191), so any other value '
				. 'would simply never match.',
		], true);
	}

	/**
	 * Human-readable group titles for a set of ids.
	 *
	 * @param array<int,int> $ids
	 * @return array<int,array<string,mixed>>
	 */
	protected function jceDescribeGroups(array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		$query = $this->db->getQuery(true)
			->select([$this->db->quoteName('id'), $this->db->quoteName('title')])
			->from($this->db->quoteName('#__usergroups'))
			->whereIn($this->db->quoteName('id'), array_map('intval', $ids));

		$rows  = $this->db->setQuery($query)->loadAssocList('id') ?: [];
		$known = [];

		foreach ($ids as $id) {
			$known[] = [
				'id'      => (int) $id,
				'title'   => isset($rows[$id]) ? (string) $rows[$id]['title'] : null,
				'exists'  => isset($rows[$id]),
			];
		}

		return $known;
	}
}
