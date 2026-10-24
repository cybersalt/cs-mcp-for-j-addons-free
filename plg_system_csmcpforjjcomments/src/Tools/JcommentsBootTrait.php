<?php
/**
 * @package     MCP for JComments
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjcomments\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;

/**
 * Shared orientation and safety rails for every JComments tool.
 *
 * Built against JComments 5.0.6 (exstreme/Jcomments-4, 2026-09-30) on Joomla
 * 6.2.0, and diffed against 5.0.5 (the version on the requesting site): the
 * schema and every code path used here are identical.
 *
 * NOT "JComment" (com_jcomment, #__jcomment) by Joomla! Extensions Store. That
 * is a separate commercial fork with its own tables; jcInstalled() names it when
 * it finds it so nobody wonders why the tools say "not installed".
 *
 * ---------------------------------------------------------------------------
 * HOW JCOMMENTS STORES A THREAD (administrator/tables/comment.php:86-125)
 * ---------------------------------------------------------------------------
 *
 *   top level   parent 0, thread_id 0, level 0, path '0'
 *   reply       parent P, thread_id = P.thread_id ?: P.id, level = P.level + 1,
 *               path = P.path . ',' . P.id     (so a 3rd-level reply is '0,12,15')
 *
 * The table's own store() computes all four from the parent row, so replies
 * are written through that store() (see CreateReplyTool), never by hand.
 * `path` is VARCHAR(190) and `level` TINYINT UNSIGNED; JComments never checks
 * either, so a very deep thread would be silently truncated. We refuse first.
 *
 * ---------------------------------------------------------------------------
 * THERE IS NO STORED COMMENT COUNT
 * ---------------------------------------------------------------------------
 *
 * Every count JComments shows is a live COUNT(*) on #__jcomments
 * (site/models/jcomments.php:36-52, getCommentsCountQuery()), so nothing needs
 * incrementing. #__jcomments_objects is a cache of the commented item's title,
 * link, owner and access, keyed by (object_id, object_group, lang). JComments
 * inserts it the first time a page with comments renders, and it matters in
 * two places: the admin list's article column, and subscriber mail, whose
 * recipient query INNER JOINs it (site/helpers/notification.php:452-462).
 *
 * ---------------------------------------------------------------------------
 * MAIL
 * ---------------------------------------------------------------------------
 *
 * JComments mails through its own queue: JCommentsNotification::push() writes
 * #__jcomments_mailq rows and then immediately calls send(), which sends up to
 * ten queued rows (ANY ten, not only the new ones) through Joomla's mailer
 * (site/helpers/notification.php:43-143). Queued rows are also sent on about
 * 30% of page views that render comments (site/jcomments.php show()), so
 * "queued" means "will be sent soon": the only way to send nothing is never
 * to push. The admin "Publish" button pushes
 * a "new comment" mail to every subscriber of the article
 * (administrator/models/comments.php publish()). Every write tool here sends
 * nothing and fires no JComments plugin event unless it is called with
 * notify: true, and then it runs the vendor's own code for that action inside
 * an AdministratorApplication (jcWithAdminApp()).
 */
trait JcommentsBootTrait
{
	/** Short names after `#__jcomments`. */
	private const JC_TABLES = [
		'comments'       => '',
		'objects'        => '_objects',
		'subscriptions'  => '_subscriptions',
		'blacklist'      => '_blacklist',
		'reports'        => '_reports',
		'votes'          => '_votes',
		'mailq'          => '_mailq',
		'smilies'        => '_smilies',
		'custom_bbcodes' => '_custom_bbcodes',
	];

	/**
	 * Columns never returned verbatim.
	 *
	 *   subscriptions.hash  is the unsubscribe secret (anyone with it can cancel
	 *                       that subscription: site/controllers/subscriptions.php)
	 *   mailq.body          embeds those hashes and the quick-moderation command
	 *                       hashes that publish/delete/ban a comment by URL
	 *   mailq.session_id    a live Joomla session id while a send is in progress
	 */
	private const JC_REDACTED = [
		'_subscriptions' => ['hash'],
		'_mailq'         => ['body', 'session_id'],
	];

	private static ?bool $jcInstalledCache = null;

	private static ?array $jcTableListCache = null;

	// -----------------------------------------------------------------------
	// Presence and identity
	// -----------------------------------------------------------------------

	/**
	 * One category for every tool. Deriving it from the namespace would give
	 * `comments`, `lists` and the like, which collide with sibling add-ons.
	 */
	public function getCategory(): string
	{
		return 'jcomments';
	}

	protected function jcAdminBase(): string
	{
		return JPATH_ADMINISTRATOR . '/components/com_jcomments';
	}

	protected function jcSiteBase(): string
	{
		return JPATH_SITE . '/components/com_jcomments';
	}

	protected function jcInstalled(): bool
	{
		if (self::$jcInstalledCache !== null) {
			return self::$jcInstalledCache;
		}

		self::$jcInstalledCache = is_dir($this->jcAdminBase())
			&& is_file($this->jcAdminBase() . '/tables/comment.php')
			&& $this->jcTableExists('');

		return self::$jcInstalledCache;
	}

	protected function jcNotInstalledError(): ToolResult
	{
		$payload = [
			'ok'    => false,
			'error' => 'JComments (com_jcomments) is not installed on this site. Expected '
				. 'administrator/components/com_jcomments and the #__jcomments tables.',
			'note'  => 'JComments is free: github.com/exstreme/Jcomments-4 (also on the JED). This add-on was built '
				. 'against 5.0.6.',
		];

		if (is_dir(JPATH_ADMINISTRATOR . '/components/com_jcomment')) {
			$payload['different_product_found'] = 'This site has "JComment" (com_jcomment, tables #__jcomment) by '
				. 'Joomla! Extensions Store. That is a different product with its own schema; this add-on does not '
				. 'support it.';
		}

		return ToolResult::json($payload, true);
	}

	/** @return array{component:?string,package:?string} */
	protected function jcVersions(): array
	{
		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([$this->db->quoteName('element'), $this->db->quoteName('manifest_cache')])
				->from($this->db->quoteName('#__extensions'))
				->whereIn($this->db->quoteName('element'), ['com_jcomments', 'pkg_jcomments'], \Joomla\Database\ParameterType::STRING)
		)->loadAssocList('element') ?: [];

		$get = static function (?array $row): ?string {
			$decoded = json_decode((string) ($row['manifest_cache'] ?? ''), true);

			return \is_array($decoded) && !empty($decoded['version']) ? (string) $decoded['version'] : null;
		};

		return [
			'component' => $get($rows['com_jcomments'] ?? null),
			'package'   => $get($rows['pkg_jcomments'] ?? null),
		];
	}

	/**
	 * Component options. Read from #__extensions directly rather than through
	 * ComponentHelper, whose per-request cache would hide a change made earlier
	 * in the same request and which logs a warning when the component is off.
	 */
	protected function jcParams(): Registry
	{
		$raw = (string) $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('params'))
				->from($this->db->quoteName('#__extensions'))
				->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
				->where($this->db->quoteName('element') . ' = ' . $this->db->quote('com_jcomments'))
		)->loadResult();

		return new Registry($raw !== '' ? $raw : '{}');
	}

	// -----------------------------------------------------------------------
	// Tables
	// -----------------------------------------------------------------------

	protected function jcTable(string $suffix): string
	{
		return '#__jcomments' . $suffix;
	}

	/** @return string[] */
	protected function jcAllTables(): array
	{
		if (self::$jcTableListCache === null) {
			self::$jcTableListCache = $this->db->getTableList();
		}

		return self::$jcTableListCache;
	}

	protected function jcTableExists(string $suffix): bool
	{
		return \in_array($this->db->getPrefix() . 'jcomments' . $suffix, $this->jcAllTables(), true);
	}

	protected function jcComment(int $id): ?array
	{
		$row = $this->db->setQuery(
			'SELECT * FROM ' . $this->db->quoteName($this->jcTable('')) . ' WHERE id = ' . $id
		)->loadAssoc();

		return \is_array($row) ? $row : null;
	}

	protected function jcCount(string $suffix, string $where = ''): int
	{
		return (int) $this->db->setQuery(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName($this->jcTable($suffix)) . ($where !== '' ? ' WHERE ' . $where : '')
		)->loadResult();
	}

	// -----------------------------------------------------------------------
	// Arguments
	// -----------------------------------------------------------------------

	protected function jcLimit(array $arguments, int $default = 50, int $max = 200): int
	{
		return max(1, min($max, (int) ($arguments['limit'] ?? $default)));
	}

	protected function jcOffset(array $arguments): int
	{
		return max(0, (int) ($arguments['offset'] ?? 0));
	}

	protected function jcNow(): string
	{
		return Factory::getDate()->toSql();
	}

	protected function jcLike(string $value): string
	{
		return $this->db->quote('%' . $this->db->escape($value, true) . '%', false);
	}

	/** Ids from an `ids` array or a single `id`, deduplicated, positive only. */
	protected function jcIds(array $arguments, int $max = 200): array
	{
		$raw = $arguments['ids'] ?? (isset($arguments['id']) ? [$arguments['id']] : []);
		$ids = array_values(array_unique(array_filter(array_map('intval', (array) $raw), static fn (int $i): bool => $i > 0)));

		if ($ids === []) {
			throw new \InvalidArgumentException('ids is required: one or more comment ids.');
		}

		if (\count($ids) > $max) {
			throw new \InvalidArgumentException('At most ' . $max . ' ids per call; got ' . \count($ids) . '.');
		}

		return $ids;
	}

	/**
	 * A UTC datetime argument, or null for none. Accepts anything strtotime()
	 * reads. JComments stores `date` as UTC (Factory::getDate()->toSql()).
	 */
	protected function jcDate(mixed $value, string $field): ?string
	{
		if ($value === null || $value === '') {
			return null;
		}

		$value = (string) $value;
		$ts    = strtotime($value . (preg_match('/(Z|[+-]\d{2}:?\d{2}|UTC)$/i', $value) ? '' : ' UTC'));

		if ($ts === false) {
			throw new \InvalidArgumentException($field . ' "' . $value . '" is not a date. Use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS (UTC).');
		}

		return gmdate('Y-m-d H:i:s', $ts);
	}

	// -----------------------------------------------------------------------
	// Access control
	// -----------------------------------------------------------------------

	/**
	 * Non-guest, `core.manage` on com_jcomments (what its administrator
	 * entry point checks), then the specific action. The admin list's publish
	 * and delete check core.edit.state and core.delete (models/modellist.php,
	 * models/comments.php); replying checks the front-end rules
	 * comment.comment + comment.reply (site/classes/acl.php canReply()).
	 */
	protected function jcRequireAccess(User $actor, string ...$actions): void
	{
		if ($actor->guest || (int) $actor->id === 0) {
			throw new \RuntimeException('JComments tools require an authenticated user.');
		}

		if (!$actor->authorise('core.manage', 'com_jcomments')) {
			throw new \RuntimeException('This user lacks core.manage on com_jcomments, which the JComments administrator requires.');
		}

		foreach ($actions as $action) {
			if (!$actor->authorise($action, 'com_jcomments')) {
				throw new \RuntimeException('This user is not granted ' . $action . ' on com_jcomments.');
			}
		}
	}

	// -----------------------------------------------------------------------
	// Presentation
	// -----------------------------------------------------------------------

	/** published | unpublished | deleted (JComments' "mark as deleted" mode keeps the row with deleted = 1). */
	protected function jcStateLabel(array $row): string
	{
		if ((int) ($row['deleted'] ?? 0) === 1) {
			return 'deleted';
		}

		return (int) ($row['published'] ?? 0) === 1 ? 'published' : 'unpublished';
	}

	/**
	 * Stored text to plain text. JComments stores the visitor's text with
	 * line breaks as `<br />` (JCommentsText::nl2br) and markup as BBCode;
	 * HTML tags are stripped on the way in, so anything else is literal.
	 */
	protected function jcPlainText(?string $stored): string
	{
		$text = str_replace(['<br />', '<br/>', '<br>'], "\n", (string) $stored);

		return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	}

	protected function jcExcerpt(?string $stored, int $max = 200): string
	{
		$plain = preg_replace('/\s+/u', ' ', $this->jcPlainText($stored)) ?? '';

		return mb_strlen($plain) > $max ? rtrim(mb_substr($plain, 0, $max - 1)) . '…' : $plain;
	}

	/**
	 * Titles and links for the commented items, from JComments' own objects
	 * cache, falling back to #__content for com_content.
	 *
	 * @param array<int,array{object_id:int|string,object_group:string,lang?:string}> $rows
	 * @return array<string,array{title:?string,link:?string,cached:bool}> keyed "group:id:lang" and "group:id"
	 */
	protected function jcObjectInfo(array $rows): array
	{
		$pairs = [];

		foreach ($rows as $row) {
			$pairs[(string) $row['object_group'] . ':' . (int) $row['object_id']] = [(string) $row['object_group'], (int) $row['object_id']];
		}

		if ($pairs === []) {
			return [];
		}

		$db    = $this->db;
		$where = [];

		foreach ($pairs as [$group, $id]) {
			$where[] = '(object_group = ' . $db->quote($group) . ' AND object_id = ' . $id . ')';
		}

		$out = [];

		if ($this->jcTableExists('_objects')) {
			$objs = $db->setQuery(
				'SELECT object_id, object_group, lang, title, link FROM ' . $db->quoteName($this->jcTable('_objects'))
				. ' WHERE ' . implode(' OR ', $where)
			)->loadAssocList() ?: [];

			foreach ($objs as $o) {
				$info = ['title' => (string) $o['title'], 'link' => (string) $o['link'], 'cached' => true];

				$out[$o['object_group'] . ':' . (int) $o['object_id'] . ':' . $o['lang']] = $info;
				$out[$o['object_group'] . ':' . (int) $o['object_id']] ??= $info;
			}
		}

		$missingContent = [];

		foreach ($pairs as $key => [$group, $id]) {
			if (!isset($out[$key]) && $group === 'com_content') {
				$missingContent[] = $id;
			}
		}

		if ($missingContent !== []) {
			$titles = $db->setQuery(
				$db->getQuery(true)
					->select(['id', 'title'])
					->from($db->quoteName('#__content'))
					->whereIn($db->quoteName('id'), $missingContent)
			)->loadAssocList('id') ?: [];

			foreach ($missingContent as $id) {
				$out['com_content:' . $id] = [
					'title'  => isset($titles[$id]) ? (string) $titles[$id]['title'] : null,
					'link'   => isset($titles[$id]) ? 'index.php?option=com_content&view=article&id=' . $id : null,
					'cached' => false,
				];
			}
		}

		return $out;
	}

	/**
	 * One comment for output. `$full` adds the whole text and the threading
	 * columns; the list view gets an excerpt.
	 */
	protected function jcFormatComment(array $row, array $objects, bool $full = false): array
	{
		$key   = $row['object_group'] . ':' . (int) $row['object_id'];
		$obj   = $objects[$key . ':' . $row['lang']] ?? $objects[$key] ?? null;
		$out   = [
			'id'      => (int) $row['id'],
			'date'    => (string) $row['date'],
			'state'   => $this->jcStateLabel($row),
			'object'  => [
				'id'    => (int) $row['object_id'],
				'group' => (string) $row['object_group'],
				'title' => $obj['title'] ?? null,
			],
			'author'  => [
				'name'     => (string) $row['name'],
				'user_id'  => (int) $row['userid'],
				'guest'    => (int) $row['userid'] === 0,
				'email'    => (string) $row['email'],
				'homepage' => (string) $row['homepage'],
				'ip'       => (string) $row['ip'],
			],
			'title'   => (string) $row['title'],
			'parent'  => (int) $row['parent'],
			'level'   => (int) $row['level'],
		];

		if ($full) {
			$out['text']        = $this->jcPlainText($row['comment']);
			$out['stored_text'] = (string) $row['comment'];
			$out['thread_id']   = (int) $row['thread_id'];
			$out['path']        = (string) $row['path'];
			$out['lang']        = (string) $row['lang'];
			$out['votes']       = ['good' => (int) $row['isgood'], 'poor' => (int) $row['ispoor']];
			$out['subscribed']  = (int) $row['subscribe'] === 1;
			$out['source']      = (string) $row['source'];
			$out['object']['link'] = $obj['link'] ?? null;
			$out['object']['objects_row_exists'] = (bool) ($obj['cached'] ?? false);

			if (!empty($row['checked_out'])) {
				$out['checked_out_by'] = (int) $row['checked_out'];
				$out['checked_out_time'] = $row['checked_out_time'];
			}
		} else {
			$out['excerpt'] = $this->jcExcerpt($row['comment']);
		}

		return $out;
	}

	// -----------------------------------------------------------------------
	// Threads
	// -----------------------------------------------------------------------

	/**
	 * Every descendant of a comment, found the way the vendor's own delete
	 * finds them: parent links among all comments on the same object
	 * (administrator/tables/comment.php:151-163, JoomlaTuneTree::descendants()).
	 *
	 * @return int[]
	 */
	protected function jcDescendantIds(array $row): array
	{
		$rows = $this->db->setQuery(
			'SELECT id, parent FROM ' . $this->db->quoteName($this->jcTable(''))
			. ' WHERE object_group = ' . $this->db->quote((string) $row['object_group'])
			. ' AND object_id = ' . (int) $row['object_id']
		)->loadAssocList() ?: [];

		$children = [];

		foreach ($rows as $r) {
			$children[(int) $r['parent']][] = (int) $r['id'];
		}

		$out   = [];
		$stack = $children[(int) $row['id']] ?? [];
		$seen  = [(int) $row['id'] => true];

		while ($stack !== []) {
			$id = array_shift($stack);

			if (isset($seen[$id])) {
				continue;
			}

			$seen[$id] = true;
			$out[]     = $id;

			foreach ($children[$id] ?? [] as $c) {
				$stack[] = $c;
			}
		}

		return $out;
	}

	/** True when someone other than the actor has the comment open in the editor (site/classes/acl.php isLocked()). */
	protected function jcLockedByOther(array $row, User $actor): bool
	{
		return !empty($row['checked_out']) && (int) $row['checked_out'] !== (int) $actor->id;
	}

	// -----------------------------------------------------------------------
	// Running JComments' own code
	// -----------------------------------------------------------------------

	/** Register JComments' legacy class autoloader (site/classes/bootstrap.php). Idempotent. */
	protected function jcLegacyBoot(): void
	{
		$bootstrap = $this->jcSiteBase() . '/classes/bootstrap.php';

		if (!is_file($bootstrap)) {
			throw new \RuntimeException('JComments\' site/classes/bootstrap.php is missing; reinstall JComments.');
		}

		require_once $bootstrap;

		\JCommentsLegacyBootstrap::register();

		// The vendor's own entry points include these; JComments classes
		// reference the _JC_REGEXP_* constants defined in jcomments.php.
		require_once $this->jcSiteBase() . '/jcomments.php';
	}

	/**
	 * Run $fn with an AdministratorApplication installed as the global
	 * application and the MCP actor as its identity, then restore.
	 *
	 * Why: JComments' notification code builds unsubscribe links with
	 * Route::_() unless it sees the administrator client
	 * (site/helpers/notification.php:376-392), and Route::_() under Joomla's
	 * API application has no router to build with. Its comment table also
	 * loads the site language file itself only in the administrator client
	 * (tables/comment.php:67-70), which the "RE: <title>" reply title needs.
	 * In the administrator client both behave exactly as when an admin clicks
	 * the button.
	 *
	 * @template T
	 * @param  callable():T $fn
	 * @return T
	 */
	protected function jcWithAdminApp(User $actor, callable $fn)
	{
		$original = Factory::$application;
		$obLevel  = ob_get_level();

		try {
			// Built the way Joomla's own Application service provider builds an
			// AdministratorApplication, but as JcommentsAdminApplication (see
			// that class for the one method it changes, and why).
			$container = Factory::getContainer();
			$admin     = new JcommentsAdminApplication($original->getInput(), $container->get('config'), null, $container);
			$admin->setDispatcher($container->get(\Joomla\Event\DispatcherInterface::class));
			$admin->setLogger($container->get(\Psr\Log\LoggerInterface::class));
			$admin->setSession($container->get(\Joomla\Session\SessionInterface::class));
			$admin->setUserFactory($container->get(\Joomla\CMS\User\UserFactoryInterface::class));
			$admin->setMenuFactory($container->get(\Joomla\CMS\Menu\MenuFactoryInterface::class));
			$admin->loadLanguage();
			$admin->loadIdentity($actor);
			Factory::$application = $admin;

			$this->jcLegacyBoot();

			return $fn();
		} finally {
			Factory::$application = $original;

			// JComments wraps much of its code in ob_start()/ob_end_clean()
			// (factory.php getTemplate(), text.php censor()...); an exception
			// in between leaves a buffer open that would swallow our response.
			while (ob_get_level() > $obLevel) {
				ob_end_clean();
			}
		}
	}

	/** What the vendor's models do after every write: clean the `com_jcomments` cache group. */
	protected function jcCleanCache(): void
	{
		try {
			Factory::getContainer()->get(CacheControllerFactoryInterface::class)
				->createCacheController('callback', ['defaultgroup' => 'com_jcomments'])
				->cache->clean();
		} catch (\Throwable $e) {
			// Best effort, exactly like BaseDatabaseModel::cleanCache().
		}
	}

	protected function jcMailQueueCount(): int
	{
		return $this->jcTableExists('_mailq') ? $this->jcCount('_mailq') : 0;
	}

	/** Mask a value that must not be shown. */
	protected function jcRedact(string $suffix, array $row): array
	{
		foreach (self::JC_REDACTED[$suffix] ?? [] as $col) {
			if (\array_key_exists($col, $row) && $row[$col] !== null && $row[$col] !== '') {
				$row[$col] = '[redacted]';
			}
		}

		return $row;
	}

	/** Whether comments on the site are language-filtered (site/classes/factory.php getLanguageFilter(), admin branch). */
	protected function jcLanguageFilterOn(): bool
	{
		return (int) $this->db->setQuery(
			"SELECT enabled FROM " . $this->db->quoteName('#__extensions')
			. " WHERE type = 'plugin' AND folder = 'system' AND element = 'languagefilter'"
		)->loadResult() === 1;
	}

	/** Comment component params used in many places, plus the delete mode as a word. */
	protected function jcDeleteMode(Registry $params): string
	{
		return (int) $params->get('delete_mode', 0) === 1 ? 'mark_deleted' : 'delete';
	}

	/** True when the given component params enable the blacklist. */
	protected function jcBlacklistEnabled(Registry $params): bool
	{
		return (int) $params->get('enable_blacklist', 0) === 1;
	}

	/**
	 * Is this IP already covered by a blacklist row? Same matching as
	 * JCommentsSecurity::checkBlacklist() (site/classes/security.php:170-225):
	 * the exact address, or an a.b.c.* / a.b.*.* / a.*.*.* wildcard row.
	 */
	protected function jcIpBlacklisted(string $ip): bool
	{
		if ($ip === '' || !$this->jcTableExists('_blacklist')) {
			return false;
		}

		$db    = $this->db;
		$parts = explode('.', $ip);
		$conds = [$db->quoteName('ip') . ' = ' . $db->quote($ip)];

		if (\count($parts) === 4) {
			$conds[] = $db->quoteName('ip') . ' = ' . $db->quote(sprintf('%s.%s.%s.*', $parts[0], $parts[1], $parts[2]));
			$conds[] = $db->quoteName('ip') . ' = ' . $db->quote(sprintf('%s.%s.*.*', $parts[0], $parts[1]));
			$conds[] = $db->quoteName('ip') . ' = ' . $db->quote(sprintf('%s.*.*.*', $parts[0]));
		}

		return (int) $db->setQuery(
			'SELECT COUNT(*) FROM ' . $db->quoteName($this->jcTable('_blacklist')) . ' WHERE ' . implode(' OR ', $conds)
		)->loadResult() > 0;
	}

	protected function jcUserBlacklisted(int $userId): bool
	{
		if ($userId <= 0 || !$this->jcTableExists('_blacklist')) {
			return false;
		}

		return $this->jcCount('_blacklist', 'userid = ' . $userId) > 0;
	}

	/** Refuse a value that would not fit a column, rather than let MySQL truncate it. */
	protected function jcRequireFits(string $field, string $value, int $max, bool $bytes = false): void
	{
		$len = $bytes ? \strlen($value) : mb_strlen($value);

		if ($len > $max) {
			throw new \InvalidArgumentException(
				$field . ' is ' . $len . ($bytes ? ' bytes' : ' characters') . '; the column holds ' . $max
				. '. Refusing rather than truncating. Nothing was written.'
			);
		}
	}
}
