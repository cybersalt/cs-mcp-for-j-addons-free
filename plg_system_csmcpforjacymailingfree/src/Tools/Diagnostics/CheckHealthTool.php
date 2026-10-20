<?php
/**
 * @package     MCP for AcyMailing
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;

/**
 * Read-only audit of the things that make AcyMailing silently not send, send
 * to the wrong people, or report numbers that disagree with reality.
 */
final class CheckHealthTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'check_acym_health'; }

	public function getDescription(): string
	{
		return 'Read-only health audit of AcyMailing. Checks: the acymtriggers system plugin (Joomla user sync '
			. 'and form display), install completeness, a lapsed trial silently demoting the site to Starter, '
			. 'whether anything will ever process the queue (Starter has no cron - someone must click Send in '
			. 'the admin; Essential+ needs a running cron), stuck/failed queue rows, queue rows for inactive or '
			. 'unconfirmed subscribers that will never send, paused campaigns holding queue rows, the mailer '
			. 'method and From domain, unconfirmed subscribers excluded by require_confirmation, drift between '
			. 'the cached list counters and real membership, orphaned subscription and queue rows, and whether '
			. 'AcyMailing\'s link root resolved to /api/. Never changes anything.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->acyAdminBase() === null) {
			return $this->notInstalledError();
		}

		$this->ensureAcyLoaded();
		$cfg    = $this->acyConfigAll();
		$level  = $this->acyLevel();
		$checks = [];
		$add    = static function (string $id, string $severity, string $message, array $data = []) use (&$checks): void {
			$checks[] = ['check' => $id, 'severity' => $severity, 'message' => $message] + ($data ? ['data' => $data] : []);
		};
		$one = fn (string $sql): int => (int) $this->db->setQuery($sql)->loadResult();
		$t   = fn (string $short): string => $this->acyQn($this->acyTable($short));

		// 1. acymtriggers
		$trig = $this->db->setQuery(
			"SELECT enabled FROM #__extensions WHERE type='plugin' AND folder='system' AND element='acymtriggers'"
		)->loadResult();
		if ($trig === null) {
			$add('acymtriggers_plugin', 'critical', 'plg_system_acymtriggers is not installed. Joomla user registrations/updates no longer sync to AcyMailing subscribers and pop-up/inline subscription forms do not render.');
		} elseif ((int) $trig !== 1) {
			$add('acymtriggers_plugin', 'warning', 'plg_system_acymtriggers is DISABLED. AcyMailing\'s own installer enables it on first install. Without it Joomla user changes do not sync to subscribers. (A package install that aborted and was then re-run as an update leaves it disabled.)');
		} else {
			$add('acymtriggers_plugin', 'ok', 'plg_system_acymtriggers is enabled.');
		}

		// 2. install complete
		$add('install_complete', ($cfg['installcomplete'] ?? '0') === '1' ? 'ok' : 'warning',
			($cfg['installcomplete'] ?? '0') === '1' ? 'Install completed.' : 'installcomplete is not 1 - the component\'s install routine did not finish. Open AcyMailing in the admin once.');

		// 3. trial demotion
		$configured = strtolower((string) ($cfg['level'] ?? ''));
		if ($configured !== '' && $configured !== $level && $level !== 'unknown') {
			$add('edition', 'warning', 'Configured edition is ' . $cfg['level'] . ' but acym_level() resolves to ' . $level . ' - a trial has expired, so every ' . $cfg['level'] . ' feature is switched off.');
		} else {
			$add('edition', 'ok', 'Edition: ' . $level . '.');
		}

		// 4. queue processing
		$queued   = $one('SELECT COUNT(*) FROM ' . $t('queue'));
		$cronLast = (int) ($cfg['cron_last'] ?? 0);
		if ($level === 'starter') {
			$add('queue_processing', $queued > 0 ? 'warning' : 'info',
				'Starter has no automatic sending: the queue is only processed while an administrator keeps the AcyMailing Queue page open and clicks Send. ' . $queued . ' email(s) are queued now.',
				['queued' => $queued]);
		} else {
			$auto = ($cfg['queue_type'] ?? 'manual') !== 'manual';
			$stale = $cronLast === 0 || $cronLast < time() - 86400;
			$add('queue_processing', $auto && $stale && $queued > 0 ? 'warning' : 'info',
				'queue_type=' . ($cfg['queue_type'] ?? '') . ', active_cron=' . ($cfg['active_cron'] ?? '0') . ', last cron run: ' . ($cronLast ? gmdate('Y-m-d H:i', $cronLast) . ' UTC' : 'never')
				. '. On Joomla 4+ the page-load trigger in acymtriggers is disabled, so automatic sending needs AcyMailing\'s web cron (licence-activated) or a real cron hitting the cron URL.',
				['queued' => $queued, 'cron_last' => $cronLast ?: null]);
		}

		// 5. failed / undeliverable queue rows
		$maxTry = max(1, (int) ($cfg['queue_try'] ?? 3));
		$failed = $one('SELECT COUNT(*) FROM ' . $t('queue') . ' WHERE try >= ' . $maxTry);
		if ($failed > 0) {
			$add('queue_failed', 'warning', $failed . ' queued email(s) have reached queue_try (' . $maxTry . ') and will not be retried.');
		}
		$deadUsers = $one('SELECT COUNT(*) FROM ' . $t('queue') . ' q JOIN ' . $t('user') . ' u ON u.id = q.user_id WHERE u.active = 0'
			. ($this->acyRequiresConfirmation() ? ' OR u.confirmed = 0' : ''));
		if ($deadUsers > 0) {
			$add('queue_inactive_recipients', 'info', $deadUsers . ' queue row(s) belong to inactive or unconfirmed subscribers. The sender skips them and QueueClass::cleanQueue() deletes them after two days.');
		}
		$paused = $this->db->setQuery('SELECT c.id, COUNT(q.user_id) n FROM ' . $t('campaign') . ' c JOIN ' . $t('queue') . ' q ON q.mail_id = c.mail_id WHERE c.active = 0 GROUP BY c.id')->loadAssocList() ?: [];
		if ($paused) {
			$add('paused_campaigns', 'info', count($paused) . ' paused campaign(s) are holding queue rows; nothing sends for them until resumed (set_acym_campaign_paused).', ['campaigns' => $paused]);
		}

		// 6. mailer
		$method = $cfg['mailer_method'] ?? '';
		$add('mailer_method', $method === 'phpmail' ? 'warning' : 'info',
			$method === 'phpmail' ? 'mailer_method is phpmail (PHP mail()). Bulk mail through PHP mail() on shared hosting is commonly throttled or spam-foldered; SMTP or a sending service is recommended.' : 'mailer_method is ' . $method . '.');
		$from = strtolower((string) ($cfg['from_email'] ?? ''));
		$host = strtolower((string) parse_url($this->acySiteRoot(), PHP_URL_HOST));
		$fromDomain = substr((string) strrchr($from, '@'), 1);
		if ($fromDomain !== '' && $host !== '' && !str_ends_with($host, $fromDomain) && !str_ends_with($fromDomain, preg_replace('/^www\./', '', $host))) {
			$add('from_domain', 'warning', 'From address domain (' . $fromDomain . ') differs from the site domain (' . $host . '). Unless that domain\'s SPF/DKIM authorise this server, receivers will treat the mail as spoofed.');
		}

		// 7. unconfirmed
		if ($this->acyRequiresConfirmation()) {
			$unconf = $one('SELECT COUNT(*) FROM ' . $t('user') . ' WHERE confirmed = 0 AND active = 1');
			$add('unconfirmed_subscribers', $unconf > 0 ? 'info' : 'ok', 'require_confirmation = 1, so ' . $unconf . ' active but unconfirmed subscriber(s) are excluded from every campaign.');
		}

		// 8. cached counter drift
		$drift = $this->db->setQuery(
			'SELECT l.id, l.name, l.subscribers AS cached, COALESCE(x.live, 0) AS live FROM ' . $t('list') . ' l'
			. ' LEFT JOIN (SELECT list_id, SUM(status = 1) AS live FROM ' . $t('user_has_list') . ' GROUP BY list_id) x ON x.list_id = l.id'
			. ' WHERE COALESCE(l.subscribers, 0) <> COALESCE(x.live, 0)'
		)->loadAssocList() ?: [];
		if ($drift) {
			$add('list_counter_drift', 'info', count($drift) . ' list(s) have a cached #__acym_list.subscribers value that disagrees with real membership. The column is only refreshed by an admin AJAX call; every count in these tools is computed live.', ['lists' => $drift]);
		}

		// 9. orphans
		$orphanSubs = $one('SELECT COUNT(*) FROM ' . $t('user_has_list') . ' ul LEFT JOIN ' . $t('list') . ' l ON l.id = ul.list_id LEFT JOIN ' . $t('user') . ' u ON u.id = ul.user_id WHERE l.id IS NULL OR u.id IS NULL');
		$orphanQ    = $one('SELECT COUNT(*) FROM ' . $t('queue') . ' q LEFT JOIN ' . $t('user') . ' u ON u.id = q.user_id LEFT JOIN ' . $t('mail') . ' m ON m.id = q.mail_id WHERE u.id IS NULL OR m.id IS NULL');
		if ($orphanSubs + $orphanQ > 0) {
			$add('orphans', 'warning', $orphanSubs . ' subscription row(s) and ' . $orphanQ . ' queue row(s) point at a subscriber, list or mail that no longer exists (usually raw SQL deletes).');
		}

		// 10. email hygiene
		$upper = $one('SELECT COUNT(*) FROM ' . $t('user') . ' WHERE BINARY email <> BINARY LOWER(email)');
		if ($upper > 0) {
			$add('email_case', 'info', $upper . ' subscriber email(s) contain upper-case letters. UserClass::save() lower-cases every address, so these were written by an import or raw SQL; the unique index is case-insensitive, so no duplicate exists, but exact-match lookups elsewhere may miss them.');
		}

		// 11. welcome mails
		$welcome = $one('SELECT COUNT(*) FROM ' . $t('list') . ' WHERE welcome_id IS NOT NULL AND welcome_id > 0');
		if ($welcome > 0) {
			$add('welcome_emails', 'info', $welcome . ' list(s) have a welcome email. subscribe_acym_subscribers does NOT send it unless send_welcome: true is passed.');
		}

		// 11b. QueueClass::getReady() lets a queued mail reach UNCONFIRMED
		// subscribers when `mail.type = 'notification' OR mail.name LIKE
		// '%confirm%'` - meant for the double-opt-in email, but it matches any
		// mail whose internal name contains "confirm".
		if ($this->acyRequiresConfirmation()) {
			$confirmNamed = $this->db->setQuery(
				'SELECT id, type, name FROM ' . $t('mail') . " WHERE type IN ('standard','followup','automation','welcome','unsubscribe','scenario','mailbox_action') AND name LIKE '%confirm%'"
			)->loadAssocList() ?: [];
			if ($confirmNamed) {
				$add('confirm_named_mails', 'warning', count($confirmNamed) . ' campaign / follow-up / automation mail(s) have "confirm" in their internal name. AcyMailing\'s queue sender (QueueClass::getReady) exempts any mail named like %confirm% from require_confirmation, so if one is queued for unconfirmed subscribers (follow-ups, automations) it reaches them. Rename them.', [
					'mails' => array_map(fn (array $m): array => $this->acyDecodeMail($m), $confirmNamed),
				]);
			}
		}

		// 12. URL root
		if (\defined('ACYM_LIVE')) {
			$bad = str_contains((string) ACYM_LIVE, '/api/');
			$add('link_root', $bad ? 'critical' : 'ok', $bad
				? 'ACYM_LIVE resolved to ' . ACYM_LIVE . ' - links in mail rendered in this request would point at /api/.'
				: 'ACYM_LIVE is ' . ACYM_LIVE . ' (request root ' . Uri::root() . ').');
		}

		$counts = ['critical' => 0, 'warning' => 0, 'info' => 0, 'ok' => 0];
		foreach ($checks as $c) {
			$counts[$c['severity']]++;
		}

		return ToolResult::json(['edition' => $level, 'summary' => $counts, 'checks' => $checks]);
	}
}
