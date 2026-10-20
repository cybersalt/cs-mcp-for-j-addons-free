<?php
/**
 * @package     MCP for AcyMailing
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

/**
 * Shared helpers for every AcyMailing tool in the free (Starter) add-on.
 *
 * WHAT SHAPE THIS COMPONENT IS
 * ----------------------------
 * AcyMailing 10/11 (com_acym, Acyba) is not Joomla MVC. It ships its own
 * CMS-agnostic framework: a procedural glue layer (acym_* functions) loaded by
 * administrator/components/com_acym/Core/init.php, plus namespaced
 * AcyMailing\Classes\* "classes" (one per table) and AcyMailing\Helpers\*.
 * Booting it is one include; there is no service provider and no MVCFactory.
 *
 * THE EDITION GATE IS ONE CONFIG ROW, AND WE READ IT THE VENDOR'S WAY
 * -------------------------------------------------------------------
 * Starter, Essential and Enterprise are three different downloads, but the code
 * is ~99% identical. The only build-time difference that matters is
 * UpdateHelper::$level, which the installer writes into
 * #__acym_configuration.level ('Starter' | 'Essential' | 'Enterprise'). Every
 * feature gate in the product is acym_level(ACYM_ESSENTIAL|ACYM_ENTERPRISE)
 * (Helpers/global/version.php), which reads that row and additionally
 * downgrades an EXPIRED TRIAL to Starter. acyLevel() calls acym_level()
 * itself, so this add-on agrees with the admin UI by construction.
 * set_acym_config refuses to write `level` (or anything licence-shaped):
 * the gate is weak, and that is not permission to walk through it.
 *
 * THE /api/ URL GUARD
 * -------------------
 * init.php defines ACYM_LIVE, ACYM_MEDIA_URL and friends as CONSTANTS from
 * Uri::root() the first time it is included, so whatever root is current at
 * boot is baked into every unsubscribe link, tracking pixel and image URL for
 * the rest of the request, and a constant cannot be fixed afterwards. The
 * EasyBlog add-on observed Uri::root() resolving to https://host/api/ under
 * the ApiApplication; on the Joomla 6.2 test site (2026-10-19) it was already
 * the site root. ensureAcyLoaded() therefore boots inside withSiteRoot(),
 * which strips a trailing /api with Joomla's own public setter
 * Uri::root(false, $path) and restores it afterwards — a no-op where the root
 * is already right. get_acym_component_info reports the resolved ACYM_LIVE.
 *
 * THINGS THE TOOLS ABSORB (all read out of the 10.11.1 / 11.1.0 packages)
 * -----------------------------------------------------------------------
 *  - Mail name/subject/body/preheader are stored DOUBLE-ENCODED:
 *    MailClass::encode() runs mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1')
 *    on save and decode() reverses it on read. A raw SQL read of a subject
 *    containing "café" returns "cafÃ©". Every read here decodes those columns
 *    (acyDecodeMail), and every write goes through MailClass so it encodes.
 *  - UserClass::save() ends with sendConfirmation(), and under the API app
 *    acym_isAdmin() is false, so the "admin saves never send" exemption does
 *    not apply: creating an unconfirmed subscriber through the class mails a
 *    double-opt-in email. subscribe() mails the list's welcome email,
 *    unsubscribe() mails the goodbye email AND the admin unsubscribe
 *    notification. acyUserClass() switches all of that off by default; each
 *    write tool exposes an explicit opt-in.
 *  - #__acym_list.subscribers / unsubscribed_users are a CACHE refreshed only
 *    by an admin AJAX call (Controllers/Lists/Ajax.php). They are routinely
 *    wrong. Every count here is computed live from #__acym_user_has_list.
 *  - A campaign's recipients are deduplicated by the queue's PRIMARY KEY
 *    (mail_id, user_id) via INSERT IGNORE in CampaignClass::send(): someone on
 *    three of the selected lists is queued once. That is why AcyMailing is the
 *    standing workaround for Events Booking's per-event Mass Mail.
 *  - Recipient predicate (CampaignClass::send): user.active = 1 AND
 *    user_has_list.status = 1, plus user.confirmed = 1 when
 *    require_confirmation = 1. Counting anything else disagrees with the queue.
 */
trait AcymailingBootTrait
{
	/** Every #__acym_* table in 10.11.1 + 11.1.0 (mail_stat_detail is 11.x only). */
	protected const ACYM_TABLES = [
		'action', 'automation', 'campaign', 'condition', 'configuration', 'custom_zone',
		'field', 'followup', 'followup_has_mail', 'form', 'history', 'list', 'mail',
		'mail_archive', 'mail_has_list', 'mail_override', 'mail_stat', 'mail_stat_detail',
		'mailbox_action', 'plugin', 'queue', 'rule', 'scenario', 'scenario_history_line',
		'scenario_process', 'scenario_queue', 'scenario_step', 'segment', 'step', 'tag',
		'url', 'url_click', 'user', 'user_has_field', 'user_has_list', 'user_stat',
	];

	/** Mail columns MailClass stores through utf8Encode (MailClass::FIELDS_ENCODING). */
	protected const ACYM_ENCODED_MAIL_FIELDS = ['name', 'subject', 'body', 'autosave', 'preheader'];

	/**
	 * Config keys that are never written by set_acym_config. Licence/edition
	 * state first: writing `level` would hand out Essential/Enterprise for free.
	 */
	protected const ACYM_CONFIG_WRITE_BLOCKED = [
		'level', 'version', 'previous_version', 'Starter', 'Essential', 'Enterprise',
		'isTrial', 'expirationdate', 'lastlicensecheck', 'latestversion', 'license_key',
		'installcomplete', 'install_date', 'migration', 'walk_through',
		// Saving these makes ConfigurationClass::saveConfig() call acymailing.com
		// (ConfigurationController::modifyCron) — an outward side effect.
		'active_cron', 'cron_security', 'cron_key',
		// Runtime bookkeeping written by the cron, not settings.
		'cron_last', 'cron_next', 'cron_fromip', 'cron_report',
	];

	private static array $acyState = [];

	/** All tools in this add-on share one category so they never collide with core or siblings. */
	public function getCategory(): string
	{
		return 'acymailing';
	}

	// ------------------------------------------------------------------ boot

	protected function acyAdminBase(): ?string
	{
		$path = JPATH_ADMINISTRATOR . '/components/com_acym';

		return is_file($path . '/Core/init.php') ? $path : null;
	}

	protected function notInstalledError(): ToolResult
	{
		return ToolResult::error('AcyMailing (com_acym) is not installed on this site, or its install is incomplete (administrator/components/com_acym/Core/init.php is missing).');
	}

	/**
	 * Include AcyMailing's bootstrap exactly once, with Uri::root() pointing at
	 * the site rather than /api/ so the URL constants it defines are correct.
	 */
	protected function ensureAcyLoaded(): bool
	{
		if (\function_exists('acym_level') && \defined('ACYM_LIVE')) {
			return true;
		}

		if (array_key_exists('loaded', self::$acyState)) {
			return self::$acyState['loaded'];
		}

		$base = $this->acyAdminBase();
		if ($base === null) {
			return self::$acyState['loaded'] = false;
		}

		try {
			$this->withSiteRoot(static function () use ($base): void {
				include_once $base . '/Core/init.php';
			});
		} catch (\Throwable $e) {
			self::$acyState['boot_error'] = $e->getMessage();

			return self::$acyState['loaded'] = false;
		}

		return self::$acyState['loaded'] = \function_exists('acym_level');
	}

	/**
	 * Run $fn with Uri::root() resolving to the SITE root instead of /api/.
	 * Uses Joomla's public setter (Uri::root($pathonly, $path)) and restores
	 * the original path afterwards so core tools later in the request are
	 * unaffected.
	 */
	protected function withSiteRoot(callable $fn)
	{
		$original = (string) Uri::root(true);
		$fixed    = preg_replace('#/api$#', '', rtrim($original, '/'));

		if ($fixed === $original) {
			return $fn();
		}

		Uri::root(false, $fixed);

		try {
			return $fn();
		} finally {
			Uri::root(false, $original);
		}
	}

	/** The site root URL as a browser sees it (never the /api/ variant). */
	protected function acySiteRoot(): string
	{
		return $this->withSiteRoot(static fn (): string => Uri::root());
	}

	/**
	 * Collect and clear whatever AcyMailing pushed onto the application message
	 * queue (it reports most errors through acym_enqueueMessage()).
	 *
	 * @return array<int, string>
	 */
	protected function acyDrainMessages(): array
	{
		$out = [];

		try {
			$app = Factory::getApplication();
			if (method_exists($app, 'getMessageQueue')) {
				foreach ((array) $app->getMessageQueue(true) as $m) {
					$msg = trim(strip_tags((string) ($m['message'] ?? '')));
					if ($msg !== '') {
						$out[] = '[' . ($m['type'] ?? 'message') . '] ' . $msg;
					}
				}
			}
		} catch (\Throwable) {
			// Diagnostic only.
		}

		return $out;
	}

	// --------------------------------------------------------------- edition

	/**
	 * starter | essential | enterprise | unknown — decided by acym_level(), the
	 * vendor's own gate (which also demotes an expired trial to Starter).
	 */
	protected function acyLevel(): string
	{
		if (!$this->ensureAcyLoaded()) {
			return 'unknown';
		}

		try {
			if (acym_level(ACYM_ENTERPRISE)) {
				return 'enterprise';
			}
			if (acym_level(ACYM_ESSENTIAL)) {
				return 'essential';
			}
		} catch (\Throwable) {
			return 'unknown';
		}

		return 'starter';
	}

	/** Numeric rank for comparisons: starter 0, essential 1, enterprise 2. */
	protected function acyLevelRank(string $level): int
	{
		return ['starter' => 0, 'essential' => 1, 'enterprise' => 2][$level] ?? -1;
	}

	// ---------------------------------------------------------------- tables

	protected function acyTable(string $short): string
	{
		return $this->db->getPrefix() . 'acym_' . $short;
	}

	/**
	 * @param string|array<int,string> $name
	 * @return string|array<int,string>
	 */
	protected function acyQn(string|array $name, ?string $as = null): string|array
	{
		return $this->db->quoteName($name, $as);
	}

	protected function acyTableExists(string $short): bool
	{
		$key = 'tbl_' . $short;
		if (!array_key_exists($key, self::$acyState)) {
			try {
				self::$acyState[$key] = \in_array($this->acyTable($short), $this->db->getTableList(), true);
			} catch (\Throwable) {
				self::$acyState[$key] = false;
			}
		}

		return self::$acyState[$key];
	}

	// ---------------------------------------------------------------- config

	/** Live config map, read with SQL (acym_config() memoises in a static). */
	protected function acyConfigAll(): array
	{
		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->acyQn(['name', 'value']))
				->from($this->acyQn($this->acyTable('configuration')))
		)->loadAssocList() ?: [];

		$out = [];
		foreach ($rows as $r) {
			$out[(string) $r['name']] = (string) $r['value'];
		}

		return $out;
	}

	protected function acyConfig(string $key, string $default = ''): string
	{
		$all = $this->acyConfigAll();

		return array_key_exists($key, $all) ? $all[$key] : $default;
	}

	/** Does this config key (or column name) hold a credential? */
	protected function acyIsSecretName(string $name): bool
	{
		return (bool) preg_match('/(password|passwd|secret|token|apikey|api_key|_key$|^key$|license|oauth|credential|client_secret)/i', $name);
	}

	/** Should require_confirmation gate the recipient set? */
	protected function acyRequiresConfirmation(): bool
	{
		return $this->acyConfig('require_confirmation', '1') === '1';
	}

	// ------------------------------------------------------------- subscribers

	/**
	 * A UserClass with every mail-sending side effect OFF unless asked for.
	 *
	 * @param array{send_confirmation?: bool, send_welcome?: bool, send_unsubscribe?: bool, fire_triggers?: bool, notify_admins?: bool} $opts
	 */
	protected function acyUserClass(array $opts = []): object
	{
		// UserClass::delete() first runs onlyManageableUsers(), which under a
		// non-admin client narrows the ids to subscribers of the current Joomla
		// user's front-end management lists. The admin client skips it; so do we.
		$c = new class () extends \AcyMailing\Classes\UserClass {
			public function onlyManageableUsers(array &$elements): void
			{
			}
		};
		$c->checkVisitor         = false;
		$c->sendConf              = (bool) ($opts['send_confirmation'] ?? false);
		$c->sendWelcomeEmail      = (bool) ($opts['send_welcome'] ?? false);
		$c->sendUnsubscribeEmail  = (bool) ($opts['send_unsubscribe'] ?? false);
		$c->triggers              = (bool) ($opts['fire_triggers'] ?? false);
		$c->blockNotifications    = !($opts['notify_admins'] ?? false);

		return $c;
	}

	/**
	 * AcyMailing's default subscriber language, never the MCP token owner's.
	 * acym_getDefaultUserLanguage() is 11.x only; 10.x falls back to the
	 * newsletter default, then to the site's default language.
	 */
	protected function acyDefaultLanguage(): string
	{
		try {
			if (\function_exists('acym_getDefaultUserLanguage')) {
				return (string) acym_getDefaultUserLanguage();
			}
			if (\function_exists('acym_getDefaultNewsletterLanguage')) {
				return (string) acym_getDefaultNewsletterLanguage();
			}
		} catch (\Throwable) {
		}

		return (string) \Joomla\CMS\Component\ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
	}

	/**
	 * CampaignClass with the admin-client behaviour: delete() otherwise runs
	 * onlyManageableCampaigns(), which off the admin client keeps only
	 * campaigns whose mail.creator_id is the CURRENT Joomla user — so under MCP
	 * a campaign written by anyone but the token owner silently "deletes" 0 rows.
	 */
	protected function acyCampaignClass(): object
	{
		return new class () extends \AcyMailing\Classes\CampaignClass {
			public function onlyManageableCampaigns(array &$elements): void
			{
			}
		};
	}

	/** Find a subscriber row by id or email (email match is case-insensitive like the unique index). */
	protected function acyFindSubscriber(?int $id, ?string $email): ?array
	{
		$q = $this->db->getQuery(true)->select('*')->from($this->acyQn($this->acyTable('user')));

		if ($id !== null && $id > 0) {
			$q->where($this->acyQn('id') . ' = ' . (int) $id);
		} elseif ($email !== null && trim($email) !== '') {
			$q->where('LOWER(' . $this->acyQn('email') . ') = ' . $this->db->quote(strtolower(trim($email))));
		} else {
			return null;
		}

		$row = $this->db->setQuery($q, 0, 1)->loadAssoc();

		return $row ?: null;
	}

	/** Remove the per-subscriber secret before returning a row. */
	protected function acyPublicSubscriber(array $row): array
	{
		if (array_key_exists('key', $row)) {
			$row['has_key'] = !empty($row['key']);
			unset($row['key']);
		}

		foreach (['id', 'active', 'cms_id', 'confirmed', 'tracking'] as $k) {
			if (isset($row[$k])) {
				$row[$k] = (int) $row[$k];
			}
		}

		return $row;
	}

	/**
	 * Resolve subscriber identifiers (ids and/or emails) to ids.
	 *
	 * @return array{ids: array<int,int>, missing: array<int,string>}
	 */
	protected function acyResolveSubscribers(array $arguments): array
	{
		$ids     = [];
		$missing = [];

		foreach ((array) ($arguments['subscriber_ids'] ?? []) as $v) {
			if ((int) $v > 0) {
				$ids[] = (int) $v;
			}
		}
		if (isset($arguments['subscriber_id']) && (int) $arguments['subscriber_id'] > 0) {
			$ids[] = (int) $arguments['subscriber_id'];
		}

		$emails = (array) ($arguments['emails'] ?? []);
		if (isset($arguments['email'])) {
			$emails[] = $arguments['email'];
		}

		foreach ($emails as $e) {
			$row = $this->acyFindSubscriber(null, (string) $e);
			if ($row) {
				$ids[] = (int) $row['id'];
			} else {
				$missing[] = (string) $e;
			}
		}

		if ($ids !== []) {
			$found = $this->db->setQuery(
				$this->db->getQuery(true)->select($this->acyQn('id'))
					->from($this->acyQn($this->acyTable('user')))
					->whereIn($this->acyQn('id'), array_values(array_unique($ids)))
			)->loadColumn() ?: [];
			$found = array_map('intval', $found);

			foreach (array_diff(array_unique($ids), $found) as $gone) {
				$missing[] = 'id ' . $gone;
			}
			$ids = $found;
		}

		return ['ids' => array_values(array_unique($ids)), 'missing' => $missing];
	}

	/**
	 * Validate list ids against #__acym_list.
	 *
	 * @return array{ids: array<int,int>, missing: array<int,int>}
	 */
	protected function acyResolveLists(array $listIds): array
	{
		$listIds = array_values(array_unique(array_filter(array_map('intval', $listIds), static fn (int $v): bool => $v > 0)));
		if ($listIds === []) {
			return ['ids' => [], 'missing' => []];
		}

		$found = array_map('intval', $this->db->setQuery(
			$this->db->getQuery(true)->select($this->acyQn('id'))
				->from($this->acyQn($this->acyTable('list')))
				->whereIn($this->acyQn('id'), $listIds)
		)->loadColumn() ?: []);

		return ['ids' => $found, 'missing' => array_values(array_diff($listIds, $found))];
	}

	/** Subscription rows (list id => status/dates/list name) for one subscriber. */
	protected function acySubscriptions(int $userId): array
	{
		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select(['ul.list_id', 'ul.status', 'ul.subscription_date', 'ul.unsubscribe_date', 'l.name', 'l.type'])
				->from($this->acyQn($this->acyTable('user_has_list'), 'ul'))
				->join('LEFT', $this->acyQn($this->acyTable('list'), 'l') . ' ON l.id = ul.list_id')
				->where('ul.user_id = ' . (int) $userId)
				->order('ul.list_id ASC')
		)->loadAssocList() ?: [];

		return array_map(static function (array $r): array {
			return [
				'list_id'           => (int) $r['list_id'],
				'list_name'         => $r['name'],
				'list_type'         => $r['type'],
				'status'            => ((int) $r['status']) === 1 ? 'subscribed' : 'unsubscribed',
				'subscription_date' => $r['subscription_date'],
				'unsubscribe_date'  => $r['unsubscribe_date'],
			];
		}, $rows);
	}

	// ------------------------------------------------------------------ mail

	/** Reverse MailClass::utf8Encode on a raw #__acym_mail row. */
	protected function acyDecodeMail(array $row): array
	{
		foreach (self::ACYM_ENCODED_MAIL_FIELDS as $f) {
			if (isset($row[$f]) && \is_string($row[$f]) && $row[$f] !== '') {
				$row[$f] = mb_convert_encoding($row[$f], 'ISO-8859-1', 'UTF-8');
			}
		}

		return $row;
	}

	/** Raw mail row (decoded), or null. */
	protected function acyMailRow(int $mailId): ?array
	{
		$row = $this->db->setQuery(
			$this->db->getQuery(true)->select('*')
				->from($this->acyQn($this->acyTable('mail')))
				->where('id = ' . (int) $mailId)
		)->loadAssoc();

		return $row ? $this->acyDecodeMail($row) : null;
	}

	/** Raw campaign row joined to nothing, or null. */
	protected function acyCampaignRow(int $campaignId): ?array
	{
		$row = $this->db->setQuery(
			$this->db->getQuery(true)->select('*')
				->from($this->acyQn($this->acyTable('campaign')))
				->where('id = ' . (int) $campaignId)
		)->loadAssoc();

		return $row ?: null;
	}

	/** List ids attached to a mail. */
	protected function acyMailListIds(int $mailId): array
	{
		return array_map('intval', $this->db->setQuery(
			$this->db->getQuery(true)->select('list_id')
				->from($this->acyQn($this->acyTable('mail_has_list')))
				->where('mail_id = ' . (int) $mailId)
		)->loadColumn() ?: []);
	}

	/**
	 * Count the people a campaign to these lists would actually be queued for,
	 * using CampaignClass::send()'s predicate, plus the naive per-list sum.
	 *
	 * @param array<int,int> $listIds
	 * @return array{distinct_recipients: int, per_list: array<int, array{list_id:int, eligible:int}>, naive_sum: int, duplicates_avoided: int, requires_confirmation: bool}
	 */
	protected function acyRecipientCount(array $listIds): array
	{
		$requireConf = $this->acyRequiresConfirmation();
		$perList     = [];
		$naive       = 0;
		$distinct    = 0;

		if ($listIds !== []) {
			$base = $this->db->getQuery(true)
				->from($this->acyQn($this->acyTable('user_has_list'), 'ul'))
				->join('INNER', $this->acyQn($this->acyTable('user'), 'u') . ' ON u.id = ul.user_id')
				->where('u.active = 1')
				->where('ul.status = 1')
				->whereIn('ul.list_id', $listIds);
			if ($requireConf) {
				$base->where('u.confirmed = 1');
			}

			$q = clone $base;
			$q->select(['ul.list_id', 'COUNT(*) AS n'])->group('ul.list_id');
			foreach ($this->db->setQuery($q)->loadAssocList() ?: [] as $r) {
				$perList[] = ['list_id' => (int) $r['list_id'], 'eligible' => (int) $r['n']];
				$naive    += (int) $r['n'];
			}

			$q2 = clone $base;
			$q2->select('COUNT(DISTINCT ul.user_id)');
			$distinct = (int) $this->db->setQuery($q2)->loadResult();
		}

		return [
			'distinct_recipients'   => $distinct,
			'per_list'              => $perList,
			'naive_sum'             => $naive,
			'duplicates_avoided'    => $naive - $distinct,
			'requires_confirmation' => $requireConf,
		];
	}

	/** What will happen to mail handed to AcyMailing's mailer on this site. */
	protected function acyMailerSummary(): array
	{
		$cfg = $this->acyConfigAll();

		return [
			'mailer_method' => $cfg['mailer_method'] ?? '',
			'from_email'    => $cfg['from_email'] ?? '',
			'from_name'     => $cfg['from_name'] ?? '',
			'queue_type'    => $cfg['queue_type'] ?? '',
			'active_cron'   => (int) ($cfg['active_cron'] ?? 0),
		];
	}

	// --------------------------------------------------------------- helpers

	protected function acyLimit(array $arguments, int $default = 50, int $max = 500): int
	{
		$l = (int) ($arguments['limit'] ?? $default);

		return max(1, min($max, $l));
	}

	protected function acyOffset(array $arguments): int
	{
		return max(0, (int) ($arguments['offset'] ?? 0));
	}

	/** Refusal used by every tool that can send or destroy something. */
	protected function acyConfirmRequired(string $what): ToolResult
	{
		return ToolResult::error($what . ' This tool will not act without confirm: true. Re-run with confirm: true once you have reviewed the dry-run output.');
	}

	protected function acyNullDate(?string $d): ?string
	{
		if ($d === null || $d === '' || str_starts_with($d, '0000-00-00')) {
			return null;
		}

		return $d;
	}
}
