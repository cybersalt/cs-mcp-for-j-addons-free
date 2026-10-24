<?php
/**
 * @package     MCP for JComments
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\JcommentsBootTrait;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\CMS\Version;

/**
 * One call that says whether JComments is working and what needs attention.
 * Every check is read-only.
 */
final class CheckHealthTool extends AbstractTool
{
	use JcommentsBootTrait;

	public function getName(): string { return 'check_jcomments_health'; }

	public function getDescription(): string
	{
		return 'Check JComments end to end and say what needs attention: versions, whether the component and its '
			. 'content/system plugins are enabled (without the content plugin no comments appear on articles), the '
			. 'approval queue and reported comments, the mail queue (stuck or failed notifications, moderator '
			. 'notifications with no address, Joomla mail switched off), thread integrity (replies whose parent is '
			. 'gone, wrong thread/path/level), comments on deleted or unpublished articles, missing article cache '
			. 'rows, Joomla 6 compatibility, and settings that surprise people (no captcha or no approval for guests, '
			. 'flat view, blacklist off). Read-only.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->jcInstalled()) {
			return $this->jcNotInstalledError();
		}

		$this->jcRequireAccess($actor);

		$db     = $this->db;
		$params = $this->jcParams();
		$t      = $db->quoteName($this->jcTable(''));
		$checks = [];
		$add    = static function (string $severity, string $code, string $message, array $data = []) use (&$checks): void {
			$checks[] = ['severity' => $severity, 'code' => $code, 'message' => $message] + ($data ? ['data' => $data] : []);
		};

		// --- Extensions --------------------------------------------------------
		$ext = $db->setQuery(
			"SELECT CONCAT(type, ':', folder, ':', element) AS k, enabled FROM " . $db->quoteName('#__extensions')
			. " WHERE element IN ('com_jcomments', 'jcomments', 'pkg_jcomments')"
		)->loadAssocList('k', 'enabled') ?: [];

		if ((int) ($ext['component::com_jcomments'] ?? 0) !== 1) {
			$add('error', 'component_disabled', 'com_jcomments is disabled in Extensions: no comments are shown or accepted.');
		}

		if ((int) ($ext['plugin:content:jcomments'] ?? 0) !== 1) {
			$add('error', 'content_plugin_off', 'The "Content - JComments" plugin is missing or disabled. It is what puts comments under articles, so no article shows any.');
		}

		if ((int) ($ext['plugin:system:jcomments'] ?? 0) !== 1) {
			$add('warning', 'system_plugin_off', 'The "System - JComments" plugin is missing or disabled. It loads JComments\' script and stylesheet on article pages, so the comment form will not work.');
		}

		// --- Joomla 6 ---------------------------------------------------------
		$ipRule = $this->jcAdminBase() . '/models/rules/ip.php';

		if (Version::MAJOR_VERSION >= 6 && !\defined('JPATH_PLATFORM') && is_file($ipRule)
			&& str_contains((string) @file_get_contents($ipRule), "defined('JPATH_PLATFORM') or die")) {
			$add(
				'warning',
				'joomla6_blacklist_form_dies',
				'administrator/components/com_jcomments/models/rules/ip.php starts with defined(\'JPATH_PLATFORM\') or die. Joomla 6 '
				. 'defines JPATH_PLATFORM only when the "Behaviour - Backward Compatibility 6" plugin is on, and it is not, so '
				. 'saving an entry in JComments\' admin Blacklist form stops dead with a blank page. mark_jcomments_spam (ban_ip) '
				. 'is not affected: it does not load that file.'
			);
		}

		// --- Queue and reports ------------------------------------------------
		$pending = $db->setQuery('SELECT COUNT(*) AS n, MIN(date) AS oldest FROM ' . $t . ' WHERE published = 0 AND deleted = 0')->loadAssoc() ?: [];

		if ((int) $pending['n'] > 0) {
			$add('info', 'approval_queue', (int) $pending['n'] . ' comment(s) waiting for approval; the oldest is from ' . $pending['oldest'] . ' UTC.', ['count' => (int) $pending['n'], 'oldest' => $pending['oldest']]);
		}

		$reported = $this->jcTableExists('_reports')
			? (int) $db->setQuery('SELECT COUNT(DISTINCT commentid) FROM ' . $db->quoteName($this->jcTable('_reports')))->loadResult()
			: 0;

		if ($reported > 0) {
			$add('info', 'reported_comments', $reported . ' comment(s) have reader reports (list_jcomments_reports).');
		}

		// --- Mail --------------------------------------------------------------
		$app = Factory::getApplication();

		if (!(bool) $app->get('mailonline', true)) {
			$add('warning', 'joomla_mail_off', 'Joomla\'s global "Send Mail" is off, so no JComments notification ever leaves the site.');
		}

		$notifyOn = (int) $params->get('enable_notification', 0) === 1;
		$types    = array_map('intval', (array) $params->get('notification_type', []));

		if ($notifyOn && \in_array(1, $types, true) && trim((string) $params->get('notification_email', '')) === '') {
			$add('warning', 'moderator_mail_nowhere', 'Moderator notifications are on but "notification_email" is empty, so nobody is told about new comments waiting for approval.');
		}

		if ($this->jcTableExists('_mailq')) {
			$q = $db->setQuery(
				'SELECT COUNT(*) AS n, SUM(attempts > 0) AS failed, MIN(created) AS oldest FROM ' . $db->quoteName($this->jcTable('_mailq'))
			)->loadAssoc() ?: [];

			if ((int) $q['failed'] > 0) {
				$add('warning', 'mail_failures', (int) $q['failed'] . ' queued notification(s) have failed to send at least once (list_jcomments_mail_queue).');
			}

			if ((int) $q['n'] > 0 && strtotime((string) $q['oldest']) < time() - 86400) {
				$add('warning', 'mail_queue_stale', (int) $q['n'] . ' notification(s) queued, the oldest since ' . $q['oldest'] . ' UTC. JComments only sends from the queue when a comment page is viewed or a new notification is pushed.');
			} elseif ((int) $q['n'] > 0) {
				$add('info', 'mail_queued', (int) $q['n'] . ' notification(s) waiting in JComments\' mail queue.');
			}
		}

		// --- Thread integrity -------------------------------------------------
		$orphans = $db->setQuery(
			'SELECT c.id FROM ' . $t . ' c LEFT JOIN ' . $t . ' p ON p.id = c.parent WHERE c.parent > 0 AND p.id IS NULL', 0, 50
		)->loadColumn() ?: [];

		if ($orphans !== []) {
			$add('warning', 'orphan_replies', \count($orphans) . (\count($orphans) === 50 ? '+' : '') . ' repl(ies) point at a parent comment that no longer exists; JComments\' tree view cannot show them.', ['ids' => array_map('intval', $orphans)]);
		}

		$bad = $db->setQuery(
			'SELECT c.id FROM ' . $t . ' c JOIN ' . $t . ' p ON p.id = c.parent WHERE c.parent > 0 AND ('
			. 'c.thread_id <> IF(p.thread_id = 0, p.id, p.thread_id) OR c.level <> p.level + 1 OR c.path <> CONCAT(p.path, \',\', p.id))',
			0,
			50
		)->loadColumn() ?: [];

		if ($bad !== []) {
			$add('warning', 'thread_columns_inconsistent', \count($bad) . ' repl(ies) have thread_id, level or path that do not match their parent (as JComments\' table would compute them). Usually imported comments.', ['ids' => array_map('intval', $bad)]);
		}

		$deep = (int) $db->setQuery('SELECT MAX(CHAR_LENGTH(path)) FROM ' . $t)->loadResult();

		if ($deep > 170) {
			$add('warning', 'path_near_limit', 'The longest thread path is ' . $deep . ' characters; the column holds 190 and JComments does not check, so deeper replies would be truncated.');
		}

		// --- Commented items --------------------------------------------------
		$noObject = $this->jcTableExists('_objects') ? (int) $db->setQuery(
			'SELECT COUNT(DISTINCT c.object_group, c.object_id, c.lang) FROM ' . $t . ' c LEFT JOIN ' . $db->quoteName($this->jcTable('_objects'))
			. ' o ON o.object_id = c.object_id AND o.object_group = c.object_group AND o.lang = c.lang WHERE o.id IS NULL'
		)->loadResult() : 0;

		if ($noObject > 0) {
			$add('info', 'objects_rows_missing', $noObject . ' commented item/language pair(s) have no #__jcomments_objects row yet. JComments creates it on the next page view; until then the admin list shows no title and subscriber mail for that item finds no recipients.');
		}

		$gone = $db->setQuery(
			'SELECT c.object_id, COUNT(*) AS n FROM ' . $t . ' c LEFT JOIN ' . $db->quoteName('#__content') . ' a ON a.id = c.object_id'
			. " WHERE c.object_group = 'com_content' AND a.id IS NULL GROUP BY c.object_id",
			0,
			50
		)->loadAssocList() ?: [];

		if ($gone !== []) {
			$add('warning', 'comments_on_deleted_articles', array_sum(array_column($gone, 'n')) . ' comment(s) belong to articles that no longer exist. Nobody can see them.', ['article_ids' => array_map('intval', array_column($gone, 'object_id'))]);
		}

		$hidden = (int) $db->setQuery(
			'SELECT COUNT(*) FROM ' . $t . ' c JOIN ' . $db->quoteName('#__content') . ' a ON a.id = c.object_id'
			. " WHERE c.object_group = 'com_content' AND a.state <> 1 AND c.published = 1"
		)->loadResult();

		if ($hidden > 0) {
			$add('info', 'comments_on_unpublished_articles', $hidden . ' published comment(s) are on articles that are not published, so they are not visible.');
		}

		// --- Settings that surprise people ------------------------------------
		$guest = (int) \Joomla\CMS\Component\ComponentHelper::getParams('com_users')->get('guest_usergroup', 9);

		if (Access::checkGroup($guest, 'comment.comment', 'com_jcomments')) {
			if (Access::checkGroup($guest, 'comment.autopublish', 'com_jcomments')) {
				$add('warning', 'guest_autopublish', 'Guest comments are published immediately, with no approval step.');
			}

			if (Access::checkGroup($guest, 'comment.captcha', 'com_jcomments')) {
				$add('warning', 'guest_no_captcha', 'Guests comment without a captcha (Guest is allowed comment.captcha, which exempts a group from it).');
			}
		}

		// plg_content_jcomments shows comments on an article only when its
		// category is in enable_categories ('*' = all) or the article text has
		// {jcomments on} (site/helpers/content.php checkCategory(), isEnabled()).
		// A fresh install saves enable_categories as [""]: comments nowhere.
		$cats = array_values(array_filter(array_map('strval', (array) $params->get('enable_categories', [])), static fn (string $c): bool => $c !== ''));

		if ($cats === []) {
			$add('warning', 'no_categories_enabled', 'No category is enabled for comments ("enable_categories" is empty, as a fresh install leaves it), so JComments shows comments only on articles whose text contains {jcomments on}.');
		}

		if ((string) $params->get('template_view', 'tree') !== 'tree') {
			$add('info', 'flat_view', 'template_view is "list": visitors get no Reply button and replies show flat, by date.');
		}

		$blCount = $this->jcTableExists('_blacklist') ? $this->jcCount('_blacklist') : 0;

		if (!$this->jcBlacklistEnabled($params) && $blCount > 0) {
			$add('warning', 'blacklist_off', 'The blacklist has ' . $blCount . ' entr' . ($blCount === 1 ? 'y' : 'ies') . ' but enable_blacklist is off, so none is enforced.');
		}

		if ((int) $params->get('stopforumspam', 0) === 1) {
			$add('info', 'stopforumspam_on', 'StopForumSpam is on: every new comment waits on an HTTP lookup of the poster\'s IP (timeout ' . (int) $params->get('antispam_request_timeout', 3) . 's).');
		}

		$severities = array_column($checks, 'severity');
		$status     = \in_array('error', $severities, true) ? 'errors' : (\in_array('warning', $severities, true) ? 'warnings' : 'ok');

		$totals = $db->setQuery(
			'SELECT COUNT(*) AS total, SUM(published = 1 AND deleted = 0) AS published, SUM(published = 0 AND deleted = 0) AS pending, SUM(deleted = 1) AS deleted FROM ' . $t
		)->loadAssoc() ?: [];

		return ToolResult::json([
			'ok'       => true,
			'status'   => $status,
			'versions' => $this->jcVersions() + ['joomla' => JVERSION, 'php' => PHP_VERSION],
			'totals'   => array_map('intval', $totals),
			'delete_mode' => $this->jcDeleteMode($params),
			'checks'   => $checks,
		]);
	}
}
