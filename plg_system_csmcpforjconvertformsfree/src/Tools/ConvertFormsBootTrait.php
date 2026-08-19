<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Table\Table;
use Joomla\Event\DispatcherInterface;

/**
 * Bootstrap helpers for talking to Tassos Marinos' Convert Forms
 * (com_convertforms) from inside an MCP tool.
 *
 * Convert Forms 5.x is a HYBRID component: the library classes under
 * `ConvertForms\…` are PSR-4 (registered by the component's own
 * administrator/components/com_convertforms/autoload.php), but the MVC
 * layer is still legacy non-namespaced — `ConvertFormsModelForm`,
 * `ConvertFormsTableForm`, etc. There is no service provider and no
 * MVCFactory, so `bootComponent('com_convertforms')->getMVCFactory()`
 * gives you a LegacyFactory that cannot find these classes on its own.
 *
 * The correct instantiation path is the one Convert Forms itself uses
 * (see ConvertForms\Helper::getCampaign() in 5.2.4):
 *
 *     BaseDatabaseModel::addIncludePath($base . '/models', 'ConvertFormsModel');
 *     BaseDatabaseModel::getInstance('Form', 'ConvertFormsModel', ['ignore_request' => true]);
 *
 * That's what cfModel() does. cfTable() is the Table equivalent.
 *
 * ---------------------------------------------------------------------
 * Landmines this trait exists to encapsulate — all verified against the
 * real 5.2.4 package (free and Pro builds are byte-identical in schema):
 *
 *   - **`#__convertforms.params` is a JSON *string* column that holds
 *     essentially the whole form.** Only `id`, `name`, `state`, `created`
 *     and `ordering` are real columns. Fields, styling, success behaviour,
 *     PHP scripts and (pre-5.x) email notifications all live inside
 *     `params`. Any update is therefore read-modify-write on that blob —
 *     never a partial overwrite. See cfMergeParams().
 *
 *   - **`ConvertFormsModelForm::save()` expects `$data['params']` to be a
 *     JSON STRING, not an array** — it calls json_decode() on it first
 *     thing. Then it does `foreach ($params['fields'] as ...)`, with no
 *     isset() guard, so a params blob without a `fields` key is a fatal
 *     TypeError on PHP 8. cfEncodeParams() always emits a `fields` key
 *     (defaulting to an empty object) to make that impossible.
 *
 *   - **Fields are an ORDERED MAP, not a list.** The shape is
 *     `params.fields = {"fields0": {...}, "fields1": {...}}` where the map
 *     key is the literal string `fields` + the field's own `key` property.
 *     Display order is the map's insertion order — there is no `ordering`
 *     property on a field. Reordering therefore means rebuilding the map
 *     in the new order, and the keys are NOT necessarily contiguous or
 *     sorted (a form whose middle field was deleted keeps the gap).
 *     cfNextFieldKey() allocates a genuinely unused key.
 *
 *   - **Submitted data lives in `#__convertforms_conversions.params`** as a
 *     flat JSON object keyed by the field's `name` (not its key/label).
 *     Convert Forms lowercases those names on read
 *     (ConvertFormsModelConversion::prepare() does array_change_key_case),
 *     so lookups must be case-insensitive. Fields with an empty submitted
 *     value are omitted from the blob entirely rather than stored as "".
 *
 *   - **`#__convertforms_submission_meta` is NOT where field values go.**
 *     It's a side-table for addon metadata (PDF paths, tracking, etc.)
 *     written via ConvertForms\SubmissionMeta::save().
 *
 *   - **`#__convertforms_campaigns` is legacy and absent from a clean
 *     5.x install.** Sites upgraded from 2.x/4.x still have the table and
 *     a `campaign_id` on every conversion. Convert Forms gates the whole
 *     feature on `ConvertForms\Helper::legacyCampaignsEnabled()`, which is
 *     just `file_exists(models/campaign.php)`. Treat campaigns as optional
 *     everywhere — cfLegacyCampaignsEnabled() mirrors the vendor check.
 *
 *   - **`#__convertforms_connections.params` is ENCRYPTED** third-party API
 *     credentials (ConvertForms\Tasks\ConnectionEncryption). This add-on
 *     never decrypts or emits them; connection reads are redacted to
 *     id/app/title/created only.
 *
 *   - **Conversion `state` is the standard Joomla content enum**
 *     (1=published, 0=unpublished, 2=archived, -2=trashed) — the list view
 *     renders it with Joomla's own PublishedButton. A stray `state = 3`
 *     appears in ConvertFormsModelConversion::storeSpamSubmission(), but
 *     that method is dead code in 5.2.4 (it var_dump()s and die()s before
 *     saving), so 3 only shows up on sites carrying old rows.
 */
trait ConvertFormsBootTrait
{
	/** Per-request memoisation of require_once'd files and registered paths. */
	private static array $cfLoaded = [];

	/**
	 * Absolute admin component path, or null when Convert Forms isn't installed.
	 */
	protected function cfAdminBase(): ?string
	{
		$path = JPATH_ADMINISTRATOR . '/components/com_convertforms';
		return is_dir($path) ? $path : null;
	}

	/**
	 * Register the `ConvertForms\…` PSR-4 namespace plus the legacy model and
	 * table search paths, and import the vendor's own plugin groups so that
	 * save-time side effects (Tasks engine, PDF addon, conditional logic)
	 * fire the same way they do in the admin UI.
	 *
	 * Returns false when the component isn't on disk — callers should return
	 * notInstalledError() in that case.
	 */
	protected function ensureCfLoaded(): bool
	{
		$base = $this->cfAdminBase();
		if ($base === null) {
			return false;
		}

		if (isset(self::$cfLoaded['__boot'])) {
			return true;
		}

		// Registers the ConvertForms\ PSR-4 root and the legacy class aliases.
		$autoload = $base . '/autoload.php';
		if (is_file($autoload)) {
			require_once $autoload;
		}

		// Both of these are @deprecated 4.3 / to be removed in Joomla 6.0, so they
		// are called defensively: cfModel() and cfTable() instantiate the vendor
		// classes directly and only fall back to the legacy loaders. Registering
		// the paths is still worth doing while they exist, because Convert Forms'
		// OWN internals (ConvertForms\Helper::getCampaign(), the Conversion model's
		// prepareItem()) call BaseDatabaseModel::getInstance() and will resolve
		// against these paths when our tools re-enter vendor code.
		if (method_exists(BaseDatabaseModel::class, 'addIncludePath')) {
			BaseDatabaseModel::addIncludePath($base . '/models', 'ConvertFormsModel');
		}
		if (method_exists(Table::class, 'addIncludePath')) {
			Table::addIncludePath($base . '/tables');
		}

		// 'convertforms' = field/app addons, 'convertformstools' = Tasks, PDF,
		// conditional logic, calculations. Convert Forms imports both itself in
		// ConvertFormsModelConversion::__construct(); doing it here keeps write
		// tools behaviourally identical to a backend save.
		PluginHelper::importPlugin('convertforms');
		PluginHelper::importPlugin('convertformstools');

		self::$cfLoaded['__boot'] = true;

		return true;
	}

	/**
	 * Instantiate a legacy Convert Forms admin model.
	 *
	 * Direct require_once + `new ConvertFormsModelForm(...)` rather than
	 * BaseDatabaseModel::getInstance(): that loader is deprecated since Joomla
	 * 4.3 and marked for removal in 6.0, and this add-on should not stop working
	 * the day it goes. Instantiating the class ourselves is also more
	 * predictable — getInstance() silently returns false when its filename
	 * guess misses, which reads identically to "model does not exist".
	 *
	 * `ignore_request` matters because MCP runs under the API application:
	 * without it, AdminModel populates state from a request that has none of the
	 * expected input.
	 *
	 * @param string $name 'Form' | 'Forms' | 'Conversion' | 'Conversions'
	 */
	protected function cfModel(string $name): ?object
	{
		if (!$this->ensureCfLoaded()) {
			return null;
		}

		$base = $this->cfAdminBase();
		$file = $base . '/models/' . strtolower($name) . '.php';

		if (!is_file($file)) {
			return null;
		}

		if (!isset(self::$cfLoaded[$file])) {
			require_once $file;
			self::$cfLoaded[$file] = true;
		}

		$class  = 'ConvertFormsModel' . ucfirst(strtolower($name));
		$config = ['ignore_request' => true, 'dbo' => $this->db];

		if (class_exists($class)) {
			try {
				$model = new $class($config);
				$this->cfAttachDispatcher($model);

				return $model;
			} catch (\Throwable $e) {
				// Fall through to the legacy loader below.
			}
		}

		if (method_exists(BaseDatabaseModel::class, 'getInstance')) {
			try {
				$model = BaseDatabaseModel::getInstance($name, 'ConvertFormsModel', $config);

				if ($model) {
					$this->cfAttachDispatcher($model);
				}

				return $model ?: null;
			} catch (\Throwable $e) {
				return null;
			}
		}

		return null;
	}

	/**
	 * Give a hand-constructed model or table the event dispatcher that Joomla's
	 * MVCFactory would normally inject.
	 *
	 * AdminModel::save() calls $this->getDispatcher() to fire
	 * onContentBeforeSave / onContentAfterSave. Joomla 5's
	 * BaseDatabaseModel::getDispatcher() papers over a missing one by pulling
	 * it from the container, but does so with an E_USER_DEPRECATED warning that
	 * says plainly: "It will throw an exception in 6.0". Setting it up front
	 * keeps saves working on Joomla 6 and keeps the deprecation notice out of
	 * the site's error log on Joomla 5.
	 */
	private function cfAttachDispatcher(object $target): void
	{
		if (!method_exists($target, 'setDispatcher')) {
			return;
		}

		try {
			$target->setDispatcher(
				Factory::getContainer()->get(DispatcherInterface::class)
			);
		} catch (\Throwable $e) {
			// A model without a dispatcher still works on Joomla 5; don't fail
			// construction over it.
		}
	}

	/**
	 * Instantiate a legacy Convert Forms JTable, bound to the MCP database.
	 *
	 * Same reasoning as cfModel(): the class is required and constructed
	 * directly, with Table::getInstance() only as a fallback.
	 *
	 * @param string $name 'Form' | 'Conversion' | 'Task' | 'Taskhistory' | 'Campaign'
	 */
	protected function cfTable(string $name): ?Table
	{
		if (!$this->ensureCfLoaded()) {
			return null;
		}

		$base = $this->cfAdminBase();
		$file = $base . '/tables/' . strtolower($name) . '.php';

		if (!is_file($file)) {
			return null;
		}

		if (!isset(self::$cfLoaded[$file])) {
			require_once $file;
			self::$cfLoaded[$file] = true;
		}

		$class = 'ConvertFormsTable' . ucfirst(strtolower($name));

		if (class_exists($class)) {
			try {
				// Every ConvertFormsTable* constructor takes the driver by reference.
				$db    = $this->db;
				$table = new $class($db);

				if ($table instanceof Table) {
					// Table::store() fires onTableBeforeStore / onTableAfterStore
					// through the same dispatcher contract as the models.
					$this->cfAttachDispatcher($table);

					return $table;
				}
			} catch (\Throwable $e) {
				// Fall through.
			}
		}

		try {
			$table = Table::getInstance($name, 'ConvertFormsTable', ['dbo' => $this->db]);

			if ($table instanceof Table) {
				$this->cfAttachDispatcher($table);

				return $table;
			}

			return null;
		} catch (\Throwable $e) {
			return null;
		}
	}

	/** Standard error response for "Convert Forms isn't on this site." */
	protected function notInstalledError(): ToolResult
	{
		return ToolResult::error(
			'Convert Forms (com_convertforms) is not installed on this site, or the install is incomplete.'
		);
	}

	/**
	 * True when the Pro edition is active. Mirrors ConvertForms\Helper::isPro(),
	 * which delegates to \NRFramework\Extension::isPro('com_convertforms').
	 *
	 * Falls back to reading $NR_PRO out of the component's version.php when the
	 * NRFramework plugin isn't loadable, so this never throws.
	 */
	protected function cfIsPro(): bool
	{
		if (array_key_exists('__isPro', self::$cfLoaded)) {
			return (bool) self::$cfLoaded['__isPro'];
		}

		$isPro = false;

		// Helper::isPro() only exists from 5.x — on 4.4.7 the check lived solely
		// in \NRFramework\Extension. Try both, then fall back to version.php.
		if ($this->ensureCfLoaded()) {
			try {
				if (method_exists('\ConvertForms\Helper', 'isPro')) {
					$isPro = (bool) \ConvertForms\Helper::isPro();
				} elseif (method_exists('\NRFramework\Extension', 'isPro')) {
					$isPro = (bool) \NRFramework\Extension::isPro('com_convertforms');
				}
			} catch (\Throwable $e) {
				$isPro = false;
			}
		}

		if (!$isPro) {
			$isPro = $this->cfVersionFileFlag() === '1';
		}

		self::$cfLoaded['__isPro'] = $isPro;

		return $isPro;
	}

	/**
	 * Read $NR_PRO out of the component's version.php without executing it in
	 * a way that pollutes scope. Returns '1', '0', or null when unreadable.
	 */
	private function cfVersionFileFlag(): ?string
	{
		$base = $this->cfAdminBase();
		if ($base === null || !is_file($base . '/version.php')) {
			return null;
		}

		$src = (string) file_get_contents($base . '/version.php');
		if (preg_match('/\$NR_PRO\s*=\s*"?([01])"?/', $src, $m) === 1) {
			return $m[1];
		}

		return null;
	}

	/**
	 * Installed Convert Forms version, read from the component manifest.
	 * Returns null when it can't be determined.
	 */
	protected function cfVersion(): ?string
	{
		$base = $this->cfAdminBase();
		if ($base === null) {
			return null;
		}

		foreach ([$base . '/convertforms.xml', $base . '/com_convertforms.xml'] as $manifest) {
			if (!is_file($manifest)) {
				continue;
			}
			$xml = @simplexml_load_file($manifest);
			if ($xml !== false && isset($xml->version)) {
				return (string) $xml->version;
			}
		}

		return null;
	}

	/**
	 * Mirrors ConvertForms\Helper::legacyCampaignsEnabled() — the vendor's own
	 * test is literally whether models/campaign.php shipped in this build.
	 */
	protected function cfLegacyCampaignsEnabled(): bool
	{
		$base = $this->cfAdminBase();
		return $base !== null && is_file($base . '/models/campaign.php');
	}

	/** Fully-qualified table name for a Convert Forms table. */
	protected function cfTableName(string $suffix = ''): string
	{
		return $this->db->getPrefix() . 'convertforms' . ($suffix === '' ? '' : '_' . $suffix);
	}

	/**
	 * Whether a physical table exists. Needed because `_campaigns` is absent on
	 * clean 5.x installs and `_tasks` / `_connections` only appear from 5.0 up.
	 */
	protected function cfTableExists(string $suffix): bool
	{
		$name = $this->cfTableName($suffix);
		$key  = '__tbl_' . $name;

		if (array_key_exists($key, self::$cfLoaded)) {
			return (bool) self::$cfLoaded[$key];
		}

		try {
			$exists = in_array($name, $this->db->getTableList(), true);
		} catch (\Throwable $e) {
			$exists = false;
		}

		self::$cfLoaded[$key] = $exists;

		return $exists;
	}

	/**
	 * Column names present on a Convert Forms table.
	 *
	 * Needed because `#__convertforms_conversions` grew nine analytics columns
	 * (ip, country_code, user_agent, page_title, source_url, referrer_url,
	 * device, os, browser) in 5.2.0. Selecting them unconditionally is a fatal
	 * SQL error on any site still on 4.x, and Convert Forms is a long-lived
	 * extension with plenty of those around.
	 *
	 * @return array<int, string>
	 */
	protected function cfColumns(string $suffix): array
	{
		$name = $this->cfTableName($suffix);
		$key  = '__cols_' . $name;

		if (array_key_exists($key, self::$cfLoaded)) {
			return self::$cfLoaded[$key];
		}

		try {
			$columns = array_keys($this->db->getTableColumns($name, false));
		} catch (\Throwable $e) {
			$columns = [];
		}

		self::$cfLoaded[$key] = $columns;

		return $columns;
	}

	/**
	 * Intersect a wish-list of columns with what the table actually has, so a
	 * SELECT never references a column this Convert Forms version lacks.
	 *
	 * @param array<int, string> $wanted
	 * @return array<int, string>
	 */
	protected function cfExistingColumns(string $suffix, array $wanted): array
	{
		$have = $this->cfColumns($suffix);

		return array_values(array_intersect($wanted, $have));
	}

	/**
	 * Decode a `params` blob into an array. Convert Forms writes these as JSON
	 * strings, but Joomla's Table layer sometimes hands back an already-decoded
	 * array or a stdClass, so accept all three.
	 */
	protected function cfDecodeParams(mixed $raw): array
	{
		if (is_array($raw)) {
			return $raw;
		}
		if (is_object($raw)) {
			return json_decode((string) json_encode($raw), true) ?: [];
		}
		if (!is_string($raw) || trim($raw) === '') {
			return [];
		}

		$decoded = json_decode($raw, true);

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Encode a form params array back to the JSON string the Form model expects.
	 *
	 * Always emits a `fields` key: ConvertFormsModelForm::save() iterates
	 * $params['fields'] with no isset() guard, so omitting it is a fatal on
	 * PHP 8, and an empty PHP array would otherwise serialise to `[]` rather
	 * than `{}`.
	 *
	 * The fields map is cast to an object rather than encoded with
	 * JSON_FORCE_OBJECT, because that flag recurses: it would also rewrite the
	 * genuinely-list-shaped structures nested inside a field (most visibly
	 * `choices.choices`, which Convert Forms writes as a JSON array) into
	 * `{"0":…,"1":…}` objects. Both decode to the same PHP array, but the
	 * stored JSON would no longer match what the form builder round-trips.
	 */
	protected function cfEncodeParams(array $params): string
	{
		if (!isset($params['fields']) || !is_array($params['fields'])) {
			$params['fields'] = [];
		}

		// Object cast pins ONLY the top-level fields map to object semantics.
		$params['fields'] = (object) $params['fields'];

		$encoded = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		if ($encoded === false) {
			throw new \RuntimeException('Could not encode form params as JSON: ' . json_last_error_msg());
		}

		return $encoded;
	}

	/**
	 * Recursive merge for a params patch: scalar values replace, nested arrays
	 * merge key-by-key, and an explicit null deletes the key.
	 *
	 * This is what makes update_convertforms_form safe — the caller sends only
	 * the keys they want changed and everything else in the blob survives.
	 */
	protected function cfMergeParams(array $base, array $patch): array
	{
		foreach ($patch as $key => $value) {
			if ($value === null) {
				unset($base[$key]);
				continue;
			}

			if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
				$base[$key] = $this->cfMergeParams($base[$key], $value);
				continue;
			}

			$base[$key] = $value;
		}

		return $base;
	}

	/**
	 * Save a form through ConvertFormsModelForm so the vendor's per-field
	 * onBeforeFormSave() hooks and the onContentAfterSave plugin chain run.
	 *
	 * $data must carry the real columns only (id / name / state / ordering);
	 * $params is encoded here. The model's own validate() is deliberately
	 * bypassed — it re-derives the column split by running `SHOW COLUMNS` and
	 * folding every unrecognised key into params, which is right for a POSTed
	 * admin form but would silently swallow typos coming from a tool call.
	 *
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $params
	 * @return array{ok: bool, id: int, error: string}
	 */
	protected function cfSaveForm(array $data, array $params): array
	{
		$model = $this->cfModel('Form');
		if ($model === null) {
			throw new \RuntimeException('Could not instantiate ConvertFormsModelForm.');
		}

		$data['params'] = $this->cfEncodeParams($params);

		return $this->saveAdminModel($model, $data);
	}

	/**
	 * Fetch one raw form row with `params` decoded, or null when absent.
	 *
	 * @return array{id:int,name:string,state:int,created:string,ordering:int,params:array}|null
	 */
	protected function cfFetchForm(int $id): ?array
	{
		$q = $this->db->getQuery(true)
			->select(['id', 'name', 'state', 'created', 'ordering', 'params'])
			->from($this->db->quoteName($this->cfTableName()))
			->where($this->db->quoteName('id') . ' = ' . (int) $id);

		$row = $this->db->setQuery($q)->loadAssoc();
		if (!$row) {
			return null;
		}

		return [
			'id'       => (int) $row['id'],
			'name'     => (string) $row['name'],
			'state'    => (int) $row['state'],
			'created'  => (string) $row['created'],
			'ordering' => (int) $row['ordering'],
			'params'   => $this->cfDecodeParams($row['params']),
		];
	}

	/**
	 * The fields map out of a decoded params blob, in display order.
	 *
	 * @return array<string, array<string,mixed>>
	 */
	protected function cfFields(array $params): array
	{
		if (!isset($params['fields']) || !is_array($params['fields'])) {
			return [];
		}

		return array_filter($params['fields'], 'is_array');
	}

	/**
	 * Locate a field within the fields map by map key ("fields3"), by its own
	 * `key` property ("3"), or by its `name` ("email", case-insensitive).
	 *
	 * Returns the map key, or null when nothing matches.
	 */
	protected function cfFindFieldKey(array $fields, string $needle): ?string
	{
		$needle = trim($needle);
		if ($needle === '') {
			return null;
		}

		if (isset($fields[$needle])) {
			return $needle;
		}

		// Bare numeric key, e.g. "3" meaning map key "fields3".
		if (isset($fields['fields' . $needle])) {
			return 'fields' . $needle;
		}

		$lower = strtolower($needle);
		foreach ($fields as $mapKey => $field) {
			if (isset($field['name']) && strtolower((string) $field['name']) === $lower) {
				return (string) $mapKey;
			}
		}

		return null;
	}

	/**
	 * Allocate a field key that is unused by BOTH the map keys and the `key`
	 * properties. Convert Forms' React builder assigns these; gaps are normal
	 * after deletions, so "count of fields" is not a safe next key.
	 */
	protected function cfNextFieldKey(array $fields): int
	{
		$used = [];

		foreach ($fields as $mapKey => $field) {
			if (preg_match('/^fields(\d+)$/', (string) $mapKey, $m) === 1) {
				$used[(int) $m[1]] = true;
			}
			if (isset($field['key']) && is_numeric($field['key'])) {
				$used[(int) $field['key']] = true;
			}
		}

		$next = 0;
		while (isset($used[$next])) {
			$next++;
		}

		return $next;
	}

	/**
	 * Field types this install can actually render, discovered from the
	 * ConvertForms/Field directory rather than hard-coded — the free build
	 * ships a strict subset of Pro's and the list moves between releases.
	 *
	 * @return array<int, string> lowercase type names, sorted
	 */
	protected function cfAvailableFieldTypes(): array
	{
		$base = $this->cfAdminBase();
		if ($base === null) {
			return [];
		}

		$dir = $base . '/ConvertForms/Field';
		if (!is_dir($dir)) {
			return [];
		}

		$types = [];
		foreach ((array) glob($dir . '/*.php') as $file) {
			$types[] = strtolower(basename((string) $file, '.php'));
		}

		sort($types);

		return $types;
	}

	/**
	 * The vendor's full field-type registry (ConvertForms\FieldsHelper::getFieldTypes()),
	 * grouped as the form builder shows them.
	 *
	 * IMPORTANT: this registry lists Pro-only types too — the builder renders
	 * them with a padlock. A type is only usable when its
	 * `ConvertForms\Field\<Ucfirst>` class exists on disk, which is what
	 * cfAvailableFieldTypes() reports. Cross-reference the two to tell a
	 * genuinely available type from a locked upsell.
	 *
	 * @return array<string, array<int, string>> group => type names
	 */
	protected function cfFieldTypeRegistry(): array
	{
		if (!$this->ensureCfLoaded() || !class_exists('\ConvertForms\FieldsHelper')) {
			return [];
		}

		try {
			$raw = \ConvertForms\FieldsHelper::getFieldTypes();
		} catch (\Throwable $e) {
			return [];
		}

		$out = [];
		foreach ((array) $raw as $group => $types) {
			$names = [];
			foreach ((array) $types as $key => $type) {
				// The registry is shaped differently across releases: sometimes a
				// flat list of names, sometimes name => metadata.
				$names[] = is_string($type) ? $type : (string) $key;
			}
			$out[(string) $group] = $names;
		}

		return $out;
	}

	/**
	 * Field types that carry no submitted value, so they never appear as a key
	 * in a submission's params blob and don't need a `name` property.
	 *
	 * Mirrors the $excludeFields declarations on the vendor's Field classes and
	 * the `hasInput` list in the builder's admin.js.
	 *
	 * @return array<int, string>
	 */
	protected function cfNoInputFieldTypes(): array
	{
		return [
			'submit', 'html', 'heading', 'divider', 'emptyspace',
			'captcha', 'recaptchaaio', 'recaptcha', 'recaptchav2invisible',
			'hcaptcha', 'turnstile', 'altcha', 'signature',
		];
	}

	/**
	 * Whether the documented third-party API surface is present.
	 * ConvertForms\Api describes itself as "meant to be used ONLY by 3rd party
	 * developers", which is exactly this add-on's position, so submission
	 * delete/patch go through it when available.
	 */
	protected function cfApiAvailable(): bool
	{
		return $this->ensureCfLoaded() && class_exists('\ConvertForms\Api');
	}

	/**
	 * Drop Convert Forms' in-request form cache.
	 *
	 * ConvertForms\Form::load() memoises into NRFramework\Cache under
	 * 'convertforms_<id>_<only_inputs>' and the component never invalidates it —
	 * there is no cleanCache() override anywhere in com_convertforms. Any tool
	 * that writes a form and then reads it back in the same request would
	 * otherwise see the pre-write state. Tools in this add-on read back through
	 * direct SQL (cfFetchForm) for that reason; this is here for the cases where
	 * vendor code must be re-entered after a write.
	 */
	protected function cfClearFormCache(): void
	{
		if (!class_exists('\NRFramework\Cache')) {
			return;
		}

		try {
			if (method_exists('\NRFramework\Cache', 'clear')) {
				\NRFramework\Cache::clear();
			}
		} catch (\Throwable $e) {
			// Best-effort only — a stale cache is not worth failing a write over.
		}
	}

	/** Standard Joomla content-item state enum. */
	protected function contentStateLabel(int $state): string
	{
		return match ($state) {
			1  => 'published',
			0  => 'unpublished',
			2  => 'archived',
			-2 => 'trashed',
			3  => 'spam',
			default => 'unknown(' . $state . ')',
		};
	}

	/**
	 * Normalise a friendly state string into the integer enum for filtering.
	 * Returns null when the input isn't a recognisable state.
	 */
	protected function normaliseContentStateFilter(mixed $raw): ?int
	{
		if ($raw === null || $raw === '') {
			return null;
		}

		if (is_int($raw) || (is_string($raw) && ctype_digit(ltrim((string) $raw, '-')))) {
			return (int) $raw;
		}

		return match (strtolower(trim((string) $raw))) {
			'published', 'pub'     => 1,
			'unpublished', 'unpub' => 0,
			'archived'             => 2,
			'trashed', 'trash'     => -2,
			'spam'                 => 3,
			default                => null,
		};
	}

	/**
	 * Escape a user-supplied search term for a LIKE comparison and wrap it in
	 * wildcards. Returns the fully quoted literal, ready to concatenate.
	 */
	protected function cfLikeTerm(string $term): string
	{
		return $this->db->quote('%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%');
	}

	/**
	 * Clamp a limit argument into a sane page size.
	 */
	protected function cfLimit(array $arguments, int $default = 50, int $max = 200): int
	{
		return max(1, min($max, (int) ($arguments['limit'] ?? $default)));
	}

	/** Clamp an offset argument. */
	protected function cfOffset(array $arguments): int
	{
		return max(0, (int) ($arguments['offset'] ?? 0));
	}
}
