<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Support\CbMailGuard;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Support\CbRuntime;
use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;

/**
 * Shared orientation and safety rails for every Community Builder tool.
 *
 * Built against Community Builder 2.12.0 (2026-08-28) on Joomla 6.2.0. CB is
 * `com_comprofiler` plus its own framework, CBLib (`libraries/CBLib`), and a set
 * of core CB plugins that its installer unpacks from `pluginsfiles.tgz` into
 * `components/com_comprofiler/plugin/`. Field types, tabs and most behaviour
 * live in those CB plugins, not in the component.
 *
 * ---------------------------------------------------------------------------
 * THE DATA MODEL, IN ONE PARAGRAPH
 * ---------------------------------------------------------------------------
 *
 * A CB member is a `#__users` row plus a `#__comprofiler` row with the SAME id
 * (`#__comprofiler.id = user_id = #__users.id`). Every custom field is a
 * COLUMN on `#__comprofiler` (`cb_<name>`, some field types own several
 * columns, listed in `#__comprofiler_fields.tablecolumns`), a few CB fields map
 * onto `#__users` columns (`#__comprofiler_fields.table`), and some own no column
 * at all (connections, online status, formatted name...). Options for select,
 * radio and checkbox fields live in `#__comprofiler_field_values`; a member's
 * answer is stored as the option's `fieldtitle` text, and multi-valued answers
 * are joined with `|*|`. Moderation state is four independent flags:
 * `#__comprofiler.approved` (0 pending, 1 approved, 2 rejected), `.confirmed`
 * (0 awaiting email confirmation, 1 confirmed), `.banned` (0, 1 banned, 2 unban
 * requested) and Joomla's own `#__users.block`. The whole CB configuration is a
 * single JSON blob in `#__comprofiler_plugin.params` WHERE id = 1.
 *
 * ---------------------------------------------------------------------------
 * HOW WE RUN CB CODE FROM AN MCP REQUEST
 * ---------------------------------------------------------------------------
 *
 * Reads are plain SQL. Writes go through CB's own table classes
 * (`\CB\Database\Table\UserTable`, `FieldTable`, `TabTable`, `FieldValueTable`,
 * `PluginTable`) so CB's plugin events fire and both `#__users` and
 * `#__comprofiler` stay in step. Calling them under Joomla's ApiApplication
 * needs three things, which cbRun() does:
 *
 *   1. AN ADMINISTRATOR APPLICATION in `Factory` for the duration. CB's mail
 *      path always builds the `[CONFIRM]` link (`cbNotification::_replaceVariables()`
 *      calls `viewUrl('confirm')` unconditionally), `viewUrl()` looks up an Itemid
 *      through `CBframework::itemid()`, and that calls
 *      `Factory::getApplication()->getMenu()` — which THROWS under the API
 *      application, because Joomla ships no `ApiMenu`. Left alone, approving a
 *      member would store the approval, unblock them, and then die before the
 *      welcome mail with the work half done. Under an AdministratorApplication
 *      `getMenu()` resolves, and CB boots as its own back end (`getUi() == 2`),
 *      which is exactly the context its admin moderation code is written for:
 *      `confirmUser()` then does not mail moderators (`UserTable.php:1558`), and
 *      `cbSef()` does not try to run the site router (`CBframework.php:667`).
 *
 *   2. BOOT CB ONCE, INSIDE THAT CONTEXT. `CBLib::createApplication()` memoises
 *      its client in a static on the first call of the request, so whichever
 *      application is current at first boot is baked in. We boot inside the
 *      administrator context so CB records 'administrator'. If something else
 *      booted CB earlier in the same request we cannot change it, and every
 *      response says so (`cb_client`).
 *
 *   3. THE MAIL GUARD (Support\CbMailGuard). Every CB email is held back unless
 *      the caller passed `send_email: true`, and every attempted email is
 *      returned in the tool response, sent or not.
 *
 * ---------------------------------------------------------------------------
 * EDITION
 * ---------------------------------------------------------------------------
 *
 * Community Builder is free and GPL. Joomlapolis sells add-ons (CB Paid
 * Subscriptions, GroupJive, CB Gallery, CB Activity, CB Privacy...), each with
 * its own tables and plugins; none of them are covered here and the table
 * reader is scoped to the tables CB core itself creates. The one thing in CB's
 * own configuration that belongs to the paid side is `installFromWebKey` /
 * `installFromWebToken` — the Joomlapolis membership credentials for "Install
 * from web" — and those are never returned or written.
 */
trait CommunitybuilderBootTrait
{
	/** Tables `administrator/components/com_comprofiler/database/database.cbcore.xml` creates. */
	private const CB_CORE_TABLES = [
		'comprofiler', 'comprofiler_field_values', 'comprofiler_fields', 'comprofiler_lists',
		'comprofiler_members', 'comprofiler_plugin', 'comprofiler_tabs', 'comprofiler_userreports',
		'comprofiler_views', 'comprofiler_sessions', 'comprofiler_ratings',
	];

	/** Tables created by the core CB plugins shipped inside the CB package (CB Blogs, the PMS). */
	private const CB_CORE_PLUGIN_TABLES = [
		'comprofiler_plugin_blogs', 'comprofiler_plugin_messages', 'comprofiler_plugin_messages_read',
	];

	/** Tables the generic reader refuses outright, with the reason. */
	private const CB_READ_DENYLIST = [
		'comprofiler_sessions'             => 'CB\'s own session store: session ids, serialized session data and IP addresses.',
		'comprofiler_plugin_messages'      => 'private messages between members (the CB private message system).',
		'comprofiler_plugin_messages_read' => 'read receipts for private messages between members.',
	];

	/**
	 * Columns never returned in cleartext, per table.
	 *
	 * `#__comprofiler.cbactivation` is the email-confirmation code: whoever holds it
	 * can confirm the account (`UserTable::checkActivationCode()`), and the code
	 * also encodes the user id scrambled with the site secret
	 * (`UserTable::_setActivationCode()`). `registeripaddr`, `views.lastip` and
	 * `ratings.ip_address` are personal data. `#__comprofiler_plugin.params` holds
	 * every CB plugin's settings, which is where third-party API keys live (and,
	 * for row 1, the Joomlapolis install-from-web key); get_cb_config is the
	 * place to read the core configuration, with the secrets masked.
	 */
	private const CB_REDACTED_COLUMNS = [
		'comprofiler'        => ['cbactivation', 'registeripaddr'],
		'comprofiler_plugin' => ['params'],
		'comprofiler_views'  => ['lastip'],
		'comprofiler_ratings' => ['ip_address'],
	];

	/** CB configuration keys that are secrets: the Joomlapolis membership key and token for "Install from web". */
	private const CB_SECRET_CONFIG_KEYS = ['installFromWebKey', 'installFromWebToken'];

	/** `#__users` columns we return. Never password, otpKey, otep, activation. */
	private const CB_USERS_COLUMNS = [
		'id', 'name', 'username', 'email', 'block', 'sendEmail', 'registerDate', 'lastvisitDate',
		'lastResetTime', 'resetCount', 'requireReset',
	];

	/**
	 * Field types whose stored value update_cb_user_fields may write, and how the
	 * value is validated. Everything else (image, file, video, audio, password,
	 * terms, rating, points, counter, connections, status, formatname, pm,
	 * userparams, delimiter, primaryemailaddress, joomla) either owns files, is
	 * computed, or belongs to `#__users` and Joomla's own user tools.
	 */
	private const CB_WRITABLE_TYPES = [
		'text'          => 'string',
		'textarea'      => 'string',
		'editorta'      => 'string',
		'webaddress'    => 'string',
		'color'         => 'string',
		'emailaddress'  => 'email',
		'integer'       => 'int',
		'float'         => 'float',
		'date'          => 'date',
		'datetime'      => 'datetime',
		'time'          => 'time',
		'checkbox'      => 'bool',
		'select'        => 'option',
		'radio'         => 'option',
		'multiselect'   => 'options',
		'multicheckbox' => 'options',
		'tag'           => 'tags',
		'predefined'    => 'string',
	];

	/** System `predefined` fields that live on `#__comprofiler` and are plain names. */
	private const CB_WRITABLE_SYSTEM_FIELDS = ['firstname', 'middlename', 'lastname'];

	/** Field types that store option text from `#__comprofiler_field_values`. */
	private const CB_OPTION_TYPES = ['select', 'radio', 'multiselect', 'multicheckbox', 'tag'];

	/** CB's multi-value separator. */
	private const CB_MULTI_SEPARATOR = '|*|';

	private static ?bool $cbInstalledCache = null;

	private static ?array $cbTableListCache = null;

	// -----------------------------------------------------------------------
	// Presence and identity
	// -----------------------------------------------------------------------

	public function getCategory(): string
	{
		return 'communitybuilder';
	}

	protected function cbAdminBase(): string
	{
		return JPATH_ADMINISTRATOR . '/components/com_comprofiler';
	}

	protected function cbInstalled(): bool
	{
		if (self::$cbInstalledCache !== null) {
			return self::$cbInstalledCache;
		}

		self::$cbInstalledCache = is_dir($this->cbAdminBase())
			&& is_file(JPATH_SITE . '/libraries/CBLib/CB/Application/CBApplication.php')
			&& $this->cbTableExists('comprofiler')
			&& $this->cbTableExists('comprofiler_fields');

		return self::$cbInstalledCache;
	}

	protected function cbNotInstalledError(): ToolResult
	{
		return ToolResult::json([
			'ok'    => false,
			'error' => 'Community Builder (com_comprofiler) is not installed on this site. Expected '
				. 'administrator/components/com_comprofiler, libraries/CBLib and the #__comprofiler tables.',
			'note'  => 'Community Builder is free from https://www.joomlapolis.com. Install the package '
				. '(pkg_communitybuilder) before using these tools.',
		], true);
	}

	/** Installed CB version, from the package (or component) manifest cache. */
	protected function cbVersion(): ?string
	{
		foreach (['pkg_communitybuilder', 'com_comprofiler'] as $element) {
			$cache = (string) ($this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName('manifest_cache'))
					->from($this->db->quoteName('#__extensions'))
					->where($this->db->quoteName('element') . ' = ' . $this->db->quote($element))
			)->loadResult() ?? '');

			$decoded = json_decode($cache, true);

			if (\is_array($decoded) && !empty($decoded['version'])) {
				return (string) $decoded['version'];
			}
		}

		return null;
	}

	// -----------------------------------------------------------------------
	// Tables
	// -----------------------------------------------------------------------

	/** `#__comprofiler` for '' or 'comprofiler', else `#__comprofiler_<short>`. */
	protected function cbT(string $short = ''): string
	{
		$short = preg_replace('/^comprofiler_?/', '', $short);

		return $short === '' ? '#__comprofiler' : '#__comprofiler_' . $short;
	}

	/** @return string[] every table name on this database, real prefix included. */
	protected function cbAllTables(): array
	{
		if (self::$cbTableListCache === null) {
			self::$cbTableListCache = $this->db->getTableList();
		}

		return self::$cbTableListCache;
	}

	/** $short like 'comprofiler' or 'comprofiler_fields'. */
	protected function cbTableExists(string $short): bool
	{
		return \in_array($this->db->getPrefix() . $short, $this->cbAllTables(), true);
	}

	/** @return string[] live column names of `#__comprofiler`. */
	protected function cbProfileColumns(): array
	{
		return array_keys($this->db->getTableColumns('#__comprofiler', false) ?: []);
	}

	// -----------------------------------------------------------------------
	// Arguments
	// -----------------------------------------------------------------------

	protected function cbLimit(array $arguments, int $default = 50, int $max = 200): int
	{
		return max(1, min($max, (int) ($arguments['limit'] ?? $default)));
	}

	protected function cbOffset(array $arguments): int
	{
		return max(0, (int) ($arguments['offset'] ?? 0));
	}

	protected function cbLike(string $value): string
	{
		return $this->db->quote('%' . $this->db->escape($value, true) . '%', false);
	}

	protected function cbBool(array $arguments, string $key, bool $default = false): bool
	{
		if (!\array_key_exists($key, $arguments)) {
			return $default;
		}

		$v = $arguments[$key];

		if (\is_string($v)) {
			return \in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
		}

		return (bool) $v;
	}

	protected function cbRequireFits(string $field, string $value, int $max): void
	{
		if (mb_strlen($value) > $max) {
			throw new \InvalidArgumentException(
				$field . ' is ' . mb_strlen($value) . ' characters; the column holds ' . $max
				. '. Refusing rather than truncating. Nothing was written.'
			);
		}
	}

	// -----------------------------------------------------------------------
	// Access control
	// -----------------------------------------------------------------------

	/**
	 * CB's back end gates each screen on an action against a CB pseudo-asset
	 * (`xmlcb/controllers/frontcontroller.xml`): users on `core.manage`
	 * `com_users`; fields, tabs, lists, plugins and tools on `core.manage`
	 * `com_comprofiler.<area>`; configuration on `core.admin` `com_comprofiler`.
	 * Joomla resolves those pseudo-assets to the component's own rules.
	 */
	protected function cbRequireAccess(User $actor, string $action, string $asset): void
	{
		if ($actor->guest || (int) $actor->id === 0) {
			throw new \RuntimeException('Community Builder tools require an authenticated user.');
		}

		if (!$actor->authorise($action, $asset)) {
			throw new \RuntimeException(
				'This user is not granted ' . $action . ' on ' . $asset . ', which Community Builder\'s own '
				. 'back end requires for this screen.'
			);
		}
	}

	/**
	 * The rule CB's back end applies before toggling a member's approval,
	 * confirmation, block or ban (`UserTable::canToggle()`): a Super User may;
	 * otherwise `core.manage` + `core.edit.state` on com_users AND the target is
	 * not a Super User. We also apply Joomla's "no editing someone at or above your
	 * own level" rule, which the core MCP user tools enforce.
	 */
	protected function cbRequireCanModerate(User $actor, int $targetId): void
	{
		$this->cbRequireAccess($actor, 'core.manage', 'com_users');

		if (!$actor->authorise('core.admin')) {
			if (!$actor->authorise('core.edit.state', 'com_users')) {
				throw new \RuntimeException('This user lacks core.edit.state on com_users, which Community Builder requires to moderate members.');
			}

			$target = Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($targetId);

			if ($target->authorise('core.admin')) {
				throw new \RuntimeException('Only a Super User may moderate a Super User account (Community Builder\'s own rule).');
			}
		}

		$this->requireCanEditTargetUser($actor, $targetId);
	}

	// -----------------------------------------------------------------------
	// Booting and running CB code
	// -----------------------------------------------------------------------

	/**
	 * Run $fn with an AdministratorApplication installed as the global
	 * application and the MCP actor as its identity. See the trait docblock,
	 * point 1. Restores the original application whatever happens.
	 *
	 * @template T
	 * @param  callable():T $fn
	 * @return T
	 */
	protected function cbWithAdminApp(User $actor, callable $fn)
	{
		$original = Factory::$application;

		try {
			$admin = Factory::getContainer()->get(AdministratorApplication::class);
			$admin->loadLanguage();
			$admin->loadIdentity($actor);
			Factory::$application = $admin;

			return $fn();
		} finally {
			Factory::$application = $original;
		}
	}

	/** Boot CB (once per request). Must be called inside cbWithAdminApp(). */
	protected function cbBoot(): void
	{
		if (CbRuntime::$bootAttempted) {
			if (CbRuntime::$bootError !== null) {
				throw new \RuntimeException(CbRuntime::$bootError);
			}

			return;
		}

		CbRuntime::$bootAttempted = true;

		$lib        = JPATH_SITE . '/libraries/CBLib/CB/Application/CBApplication.php';
		$foundation = $this->cbAdminBase() . '/plugin.foundation.php';

		if (!is_readable($lib) || !is_readable($foundation)) {
			CbRuntime::$bootError = 'Community Builder\'s libraries are missing (libraries/CBLib or '
				. 'plugin.foundation.php). Reinstall the Community Builder package.';

			throw new \RuntimeException(CbRuntime::$bootError);
		}

		if (!is_dir(JPATH_SITE . '/components/com_comprofiler/plugin/user/plug_cbcore')) {
			CbRuntime::$bootError = 'Community Builder\'s core plugins are not unpacked (no '
				. 'components/com_comprofiler/plugin/user/plug_cbcore). The second installation step did not '
				. 'run: open Components -> Community Builder in the administrator to finish it.';

			throw new \RuntimeException(CbRuntime::$bootError);
		}

		CbRuntime::$preBooted = class_exists('CB\\Legacy\\LegacyFoundationFunctions', false);

		try {
			// plugin.foundation.php calls CBApplication::init() with
			// Factory::getApplication()->getName(), which is 'administrator' here.
			include_once $foundation;

			\cbimport('cb.html');
			\cbimport('language.all');

			global $_PLUGINS;
			$_PLUGINS->loadPluginGroup('user');

			CbRuntime::$client = (string) \CBLib\Application\Application::Application()->getClientName();
		} catch (\Throwable $e) {
			CbRuntime::$bootError = 'Community Builder failed to boot: ' . $e->getMessage();

			throw new \RuntimeException(CbRuntime::$bootError, 0, $e);
		}

		$this->cbRegisterMailGuard();
	}

	/**
	 * Register CbMailGuard on CB's `onBeforeSendMail` event.
	 *
	 * `cbPluginHandler::registerFunction()` files the listener under the plugin
	 * currently loading (`$_PLUGINS->_loading`), and `call()` only runs a listener
	 * whose plugin is published. So we register it under plugin 1, CB Core, which
	 * `PluginTable::check()` refuses to unpublish — the guard can never be
	 * silently switched off by someone disabling a plugin.
	 */
	private function cbRegisterMailGuard(): void
	{
		if (CbRuntime::$mailGuardRegistered) {
			return;
		}

		global $_PLUGINS;

		$previous           = $_PLUGINS->_loading;
		$_PLUGINS->_loading = 1;

		try {
			$_PLUGINS->registerFunction('onBeforeSendEmailMSG', [CbMailGuard::class, 'beforeMessageListener']);
			$_PLUGINS->registerFunction('onBeforeSendMail', [CbMailGuard::class, 'listener']);
			$_PLUGINS->registerFunction('onAfterSendEmailMSG', [CbMailGuard::class, 'afterListener']);
		} finally {
			$_PLUGINS->_loading = $previous;
		}

		CbRuntime::$mailGuardRegistered = true;
	}

	/** The real site URL (no trailing slash), even though Uri::root() under the API app ends in /api/. */
	protected function cbSiteBase(): string
	{
		$root = rtrim(Uri::root(), '/');

		return preg_replace('#/api$#', '', $root);
	}

	/**
	 * Run CB code: admin context, CB booted, mail guard armed, output buffered,
	 * and CB's sideways error channels collected.
	 *
	 * @param  callable():mixed $fn
	 * @return array{result:mixed, messages:array, plugin_errors:array, stray_output:string, mail:array}
	 */
	protected function cbRun(User $actor, callable $fn, bool $sendEmail = false): array
	{
		return $this->cbWithAdminApp($actor, function () use ($fn, $sendEmail): array {
			$this->cbBoot();

			global $_PLUGINS;

			$app    = Factory::getApplication();
			$before = \count($app->getMessageQueue());

			// CB's plugin handler accumulates error messages for the whole request
			// (the array is private, with no reset), so remember where we start.
			$errorsBefore        = \count((array) $_PLUGINS->getErrorMSG(false));
			$_PLUGINS->_iserror  = false;

			CbMailGuard::arm($sendEmail ? 'send' : 'block', $this->cbSiteBase());
			ob_start();

			try {
				$result = $fn();
			} finally {
				$stray = (string) ob_get_clean();
				$mail  = CbMailGuard::disarm();
			}

			$messages = [];

			foreach (\array_slice($app->getMessageQueue(), $before) as $entry) {
				$messages[] = [
					'type'    => (string) ($entry['type'] ?? 'message'),
					'message' => strip_tags((string) ($entry['message'] ?? '')),
				];
			}

			$pluginErrors = array_values(array_map(
				static fn ($e): string => strip_tags((string) $e),
				\array_slice((array) $_PLUGINS->getErrorMSG(false), $errorsBefore)
			));

			return [
				'result'        => $result,
				'messages'      => $messages,
				'plugin_errors' => $pluginErrors,
				'stray_output'  => trim(strip_tags($stray)),
				'mail'          => $mail,
			];
		});
	}

	/** Standard context block for write responses. */
	protected function cbContextNote(): array
	{
		$out = ['cb_client' => CbRuntime::$client];

		if (CbRuntime::$preBooted) {
			$out['cb_preboot_note'] = 'Community Builder had already been booted earlier in this request by '
				. 'another extension, so it kept that extension\'s context ("' . (string) CbRuntime::$client . '").';
		}

		return $out;
	}

	/** Mail summary for a response. */
	protected function cbMailReport(array $mail, bool $sendEmail): array
	{
		return [
			'send_email'    => $sendEmail,
			'mail_attempts' => \count($mail),
			'mail'          => $mail,
			'mail_note'     => $sendEmail
				? 'Every email Community Builder sent during this call is listed. Links pointing at /api/ were '
					. 'rewritten to the site URL before sending (api_links_repaired). "sent" means it was not held '
					. 'back; "mailer_accepted" is what Joomla\'s mailer returned. Delivery beyond that is up to the '
					. 'site\'s mail settings. Confirmation codes are masked in this record (not in the email).'
				: 'send_email was false, so every email Community Builder tried to send during this call was '
					. 'held back and is listed here as it would have gone out (confirmation codes masked).',
		];
	}

	// -----------------------------------------------------------------------
	// Configuration
	// -----------------------------------------------------------------------

	/** CB configuration straight from the database (never CB's cached Config object). */
	protected function cbRawConfig(): array
	{
		$raw = (string) ($this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('params'))
				->from($this->db->quoteName('#__comprofiler_plugin'))
				->where($this->db->quoteName('id') . ' = 1')
		)->loadResult() ?? '');

		$decoded = json_decode($raw === '' ? '{}' : $raw, true);

		return \is_array($decoded) ? $decoded : [];
	}

	/** Mask secret configuration keys in a config array. */
	protected function cbMaskConfig(array $config): array
	{
		foreach (self::CB_SECRET_CONFIG_KEYS as $key) {
			if (\array_key_exists($key, $config) && (string) $config[$key] !== '') {
				$config[$key] = '[redacted]';
			}
		}

		return $config;
	}

	/** Joomla's own com_users params (registration switches). */
	protected function cbJoomlaUsersParams(): array
	{
		$raw = (string) ($this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('params'))
				->from($this->db->quoteName('#__extensions'))
				->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
				->where($this->db->quoteName('element') . ' = ' . $this->db->quote('com_users'))
		)->loadResult() ?? '');

		$decoded = json_decode($raw === '' ? '{}' : $raw, true);

		return \is_array($decoded) ? $decoded : [];
	}

	// -----------------------------------------------------------------------
	// Fields
	// -----------------------------------------------------------------------

	/** @return array<int,array<string,mixed>> every field row keyed by fieldid. */
	protected function cbFieldRows(): array
	{
		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName('#__comprofiler_fields'))
				->order($this->db->quoteName('tabid') . ', ' . $this->db->quoteName('ordering'))
		)->loadAssocList('fieldid') ?: [];

		return $rows;
	}

	/** @return array<string,mixed>|null field row by id or by name. */
	protected function cbFindField(mixed $idOrName): ?array
	{
		$q = $this->db->getQuery(true)->select('*')->from($this->db->quoteName('#__comprofiler_fields'));

		if (\is_int($idOrName) || ctype_digit((string) $idOrName)) {
			$q->where($this->db->quoteName('fieldid') . ' = ' . (int) $idOrName);
		} else {
			$q->where($this->db->quoteName('name') . ' = ' . $this->db->quote((string) $idOrName));
		}

		$row = $this->db->setQuery($q)->loadAssoc();

		return $row ?: null;
	}

	/** @return string[] the columns a field row owns. */
	protected function cbFieldColumns(array $field): array
	{
		$cols = trim((string) ($field['tablecolumns'] ?? ''));

		return $cols === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $cols))));
	}

	/** @return array<int,array<string,mixed>> option rows of one field, in order. */
	protected function cbFieldValueRows(int $fieldId): array
	{
		return $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName('#__comprofiler_field_values'))
				->where($this->db->quoteName('fieldid') . ' = ' . $fieldId)
				->order($this->db->quoteName('ordering'))
		)->loadAssocList() ?: [];
	}

	/** Columns on `#__comprofiler` owned by CB fields of type password: never returned. */
	protected function cbSecretProfileColumns(): array
	{
		$out = [];

		foreach ($this->cbFieldRows() as $f) {
			if ((string) $f['type'] === 'password' && (string) $f['table'] === '#__comprofiler') {
				$out = array_merge($out, $this->cbFieldColumns($f));
			}
		}

		return array_values(array_unique($out));
	}

	/** Redact one row of a CB table. $short like 'comprofiler'. */
	protected function cbRedact(string $short, array $row): array
	{
		$redact = self::CB_REDACTED_COLUMNS[$short] ?? [];

		if ($short === 'comprofiler') {
			$redact = array_merge($redact, $this->cbSecretProfileColumns());
		}

		foreach ($redact as $column) {
			if (\array_key_exists($column, $row) && $row[$column] !== null && $row[$column] !== '') {
				$row[$column] = '[redacted]';
			}
		}

		return $row;
	}

	/** Translate a CB language key or title the way CB displays it, when CB is booted. */
	protected function cbText(?string $text): string
	{
		$text = (string) $text;

		if ($text === '' || !class_exists('CBLib\\Language\\CBTxt', false)) {
			return $text;
		}

		try {
			return (string) \CBLib\Language\CBTxt::T($text);
		} catch (\Throwable $e) {
			return $text;
		}
	}

	// -----------------------------------------------------------------------
	// Members
	// -----------------------------------------------------------------------

	/** Human status label from the four moderation flags. */
	protected function cbStatus(array $row): array
	{
		$hasProfile = $row['cb_id'] !== null;
		$approved   = $hasProfile ? (int) $row['approved'] : null;
		$confirmed  = $hasProfile ? (int) $row['confirmed'] : null;
		$banned     = $hasProfile ? (int) $row['banned'] : null;
		$block      = (int) $row['block'];

		$flags = [];

		if (!$hasProfile) {
			$flags[] = 'no_cb_profile';
		} else {
			if ($confirmed === 0) {
				$flags[] = 'awaiting_email_confirmation';
			}

			if ($approved === 0) {
				$flags[] = 'awaiting_approval';
			} elseif ($approved === 2) {
				$flags[] = 'rejected';
			}

			if ($banned === 1) {
				$flags[] = 'banned';
			} elseif ($banned === 2) {
				$flags[] = 'unban_requested';
			}
		}

		if ($block === 1) {
			$flags[] = 'blocked';
		}

		return [
			'approved'    => $approved,
			'confirmed'   => $confirmed,
			'banned'      => $banned,
			'block'       => $block,
			// CB's login (CBAuthentication) refuses rejected, unconfirmed and unapproved members; a ban
			// hides the profile but does not stop login (a banned member logs in to request an unban).
			'can_log_in'  => $hasProfile ? ($block === 0 && $approved === 1 && $confirmed === 1) : ($block === 0),
			'flags'       => $flags === [] ? ['active'] : $flags,
		];
	}

	/** One member's joined row, raw. */
	protected function cbUserRow(int $userId): ?array
	{
		$cols = array_map(fn (string $c): string => 'u.' . $this->db->quoteName($c), self::CB_USERS_COLUMNS);

		$row = $this->db->setQuery(
			'SELECT ' . implode(', ', $cols) . ', c.*, c.id AS cb_id'
			. ' FROM ' . $this->db->quoteName('#__users') . ' AS u'
			. ' LEFT JOIN ' . $this->db->quoteName('#__comprofiler') . ' AS c ON c.id = u.id'
			. ' WHERE u.id = ' . $userId
		)->loadAssoc();

		if (!$row) {
			return null;
		}

		// c.* contains an `id` that is NULL when there is no CB row; restore the user id.
		$row['id'] = $userId;

		return $row;
	}

	/** Snapshot both rows of a member, raw, for before/after diffs. */
	protected function cbSnapshot(int $userId): array
	{
		$u = $this->db->setQuery(
			$this->db->getQuery(true)->select('*')->from($this->db->quoteName('#__users'))
				->where($this->db->quoteName('id') . ' = ' . $userId)
		)->loadAssoc() ?: [];

		$c = $this->db->setQuery(
			$this->db->getQuery(true)->select('*')->from($this->db->quoteName('#__comprofiler'))
				->where($this->db->quoteName('id') . ' = ' . $userId)
		)->loadAssoc() ?: [];

		return ['users' => $u, 'comprofiler' => $c];
	}

	/**
	 * Diff two snapshots. Secret columns are reported as changed/unchanged only,
	 * never with their values.
	 *
	 * @return array<string,array{before:mixed,after:mixed}>
	 */
	protected function cbDiff(array $before, array $after): array
	{
		$secret = ['users.password', 'users.otpKey', 'users.otep', 'users.activation', 'comprofiler.cbactivation', 'comprofiler.registeripaddr'];

		foreach ($this->cbSecretProfileColumns() as $c) {
			$secret[] = 'comprofiler.' . $c;
		}

		$out = [];

		foreach (['users', 'comprofiler'] as $table) {
			$keys = array_unique(array_merge(array_keys($before[$table] ?? []), array_keys($after[$table] ?? [])));

			foreach ($keys as $k) {
				$b = $before[$table][$k] ?? null;
				$a = $after[$table][$k] ?? null;

				if ((string) $b === (string) $a && ($b === null) === ($a === null)) {
					continue;
				}

				$name = $table . '.' . $k;

				$out[$name] = \in_array($name, $secret, true)
					? ['before' => '[changed]', 'after' => '[changed]']
					: ['before' => $b, 'after' => $a];
			}
		}

		return $out;
	}

	/** Load a CB UserTable for $userId. Must run inside cbRun(). */
	protected function cbLoadUserTable(int $userId): object
	{
		$user = new \CB\Database\Table\UserTable();

		if (!$user->load($userId) || (int) $user->get('id') !== $userId) {
			throw new \RuntimeException('Community Builder could not load user ' . $userId . '.');
		}

		return $user;
	}

	protected function cbRequireUserExists(int $userId): array
	{
		$row = $this->cbUserRow($userId);

		if ($row === null) {
			throw new \InvalidArgumentException('No Joomla user with id ' . $userId . '.');
		}

		return $row;
	}
}
