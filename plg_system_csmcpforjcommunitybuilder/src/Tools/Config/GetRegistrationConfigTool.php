<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Config;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Registration settings, the emails they drive, and an audit of the
 * combinations that silently break sign-up.
 *
 * The motivating case: CB's Pending email rendered its [CONFIRM] link empty.
 * Why that happens is in `cbNotification::_replaceVariables()`
 * (`libraries/CBLib/CB/Legacy/cbNotification.php:560-590`): [CONFIRM] becomes
 * the confirmation link ONLY when the member has an activation code and is not
 * yet confirmed; otherwise it becomes "Email address is already confirmed" if
 * email confirmation is switched on, and NOTHING AT ALL if it is switched off.
 * [CONFIRMLINK] is always a link — with an empty code when there is none. So a
 * Pending email that relies on [CONFIRM] while "Email Confirmation" is off goes
 * out with a hole where the link should be, and nothing reports it.
 */
final class GetRegistrationConfigTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use CbConfigSchemaTrait;

	private const KEYS = [
		'reg_admin_allowcbregistration', 'emailpass', 'reg_admin_approval', 'reg_confirmation',
		'reg_username_checker', 'reg_ipaddress', 'reg_email_name', 'reg_email_from', 'reg_email_replyto',
		'reg_email_html', 'reg_pend_appr_sub', 'reg_pend_appr_msg', 'reg_welcome_sub', 'reg_welcome_msg',
		'reg_first_visit_url', 'moderator_viewaccesslevel', 'allowModUserApproval', 'moderatorEmail',
		'sendemails', 'maxEmailsPerHr',
	];

	public function getName(): string { return 'get_cb_registration_config'; }

	public function getDescription(): string
	{
		return 'Community Builder\'s registration settings and the emails they drive, plus an audit of the '
			. 'combinations that silently break sign-up. Returns: who may register (CB\'s switch and Joomla\'s), '
			. 'admin approval, email confirmation, generated passwords; the Pending and Welcome email subjects and '
			. 'bodies both as stored (often a language key) and as CB will render them, with the placeholders each '
			. 'uses; which email CB sends at each step of the sign-up flow for THIS configuration; who receives '
			. 'moderator alerts; and findings. The findings catch, among others: a Pending email whose [CONFIRM] '
			. 'link renders EMPTY (it is empty whenever email confirmation is off — CB only fills it for an '
			. 'unconfirmed member with an activation code); confirmation required but no Pending email to carry '
			. 'the link; generated passwords that no email delivers; approvals no moderator is told about; and '
			. 'CB or Joomla mail switched off. Read-only.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cbInstalled()) {
			return $this->cbNotInstalledError();
		}

		$this->cbRequireAccess($actor, 'core.admin', 'com_comprofiler');

		$stored = $this->cbRawConfig();
		$schema = $this->cbConfigSchema();
		$cfg    = [];

		foreach (self::KEYS as $key) {
			$cfg[$key] = \array_key_exists($key, $stored) ? (string) $stored[$key] : ($schema[$key]['default'] ?? null);
		}

		// Render the email texts the way CB does (they are usually language keys).
		$texts = ['reg_pend_appr_sub', 'reg_pend_appr_msg', 'reg_welcome_sub', 'reg_welcome_msg', 'reg_email_name'];
		$rendered = [];

		try {
			$run = $this->cbRun($actor, function () use ($cfg, $texts): array {
				$out = [];

				foreach ($texts as $key) {
					$out[$key] = $this->cbText(stripslashes((string) $cfg[$key]));
				}

				return $out;
			});

			$rendered = $run['result'];
		} catch (\Throwable $e) {
			foreach ($texts as $key) {
				$rendered[$key] = (string) $cfg[$key];
			}
		}

		$on = static fn (?string $v): bool => (string) $v === '1';

		$confirmation = $on($cfg['reg_confirmation']);
		$approval     = $on($cfg['reg_admin_approval']);
		$genPass      = $on($cfg['emailpass']);
		$modEmail     = $on($cfg['moderatorEmail']);
		$cbMail       = $cfg['sendemails'] === null ? true : $on($cfg['sendemails']);

		$app        = Factory::getApplication();
		$mailOnline = (int) $app->get('mailonline', 1) === 1;
		$joomla     = $this->cbJoomlaUsersParams();

		$pendSub = (string) $rendered['reg_pend_appr_sub'];
		$pendMsg = (string) $rendered['reg_pend_appr_msg'];
		$welSub  = (string) $rendered['reg_welcome_sub'];
		$welMsg  = (string) $rendered['reg_welcome_msg'];

		$placeholders = static function (string $text): array {
			preg_match_all('/\[([a-z0-9_]+)\]/i', $text, $m);

			return array_values(array_unique(array_map('strtolower', $m[1] ?? [])));
		};

		$pendTags = $placeholders($pendSub . ' ' . $pendMsg);
		$welTags  = $placeholders($welSub . ' ' . $welMsg);

		$pendingSends = trim($pendSub) !== '' || trim($pendMsg) !== '';
		$welcomeSends = trim($welSub) !== '' || trim($welMsg) !== '';

		$moderators = $this->moderators((int) ($cfg['moderator_viewaccesslevel'] ?? 3));

		$findings = [];
		$add      = static function (string $severity, string $code, string $message) use (&$findings): void {
			$findings[] = ['severity' => $severity, 'code' => $code, 'message' => $message];
		};

		if (!$cbMail) {
			$add('critical', 'cb_mail_off', 'CB\'s "sendemails" setting is off: Community Builder sends no email at all, including confirmation links.');
		}

		if (!$mailOnline) {
			$add('critical', 'joomla_mail_off', 'Joomla\'s global "Send Mail" (mailonline) is off: no email leaves the site, CB\'s included.');
		}

		if ($confirmation && !$pendingSends) {
			$add('critical', 'confirmation_without_pending_email', 'Email confirmation is on, but the Pending email subject and body are both empty, so CB never sends the confirmation link. New members stay unconfirmed and cannot log in.');
		}

		if ($confirmation && $pendingSends && !\in_array('confirm', $pendTags, true) && !\in_array('confirmlink', $pendTags, true)) {
			$add('critical', 'pending_email_has_no_link', 'Email confirmation is on, but the Pending email uses neither [CONFIRM] nor [CONFIRMLINK], so it never carries the confirmation link.');
		}

		if (!$confirmation && $pendingSends && \in_array('confirm', $pendTags, true)) {
			$add('warning', 'confirm_placeholder_renders_empty', 'The Pending email uses [CONFIRM] but email confirmation is OFF. CB fills [CONFIRM] with the link only for an unconfirmed member with an activation code, and with "Email address is already confirmed" only while confirmation is ON; with it off, [CONFIRM] renders as nothing. Approval-pending members get an email with a blank where the link was. Remove [CONFIRM] from the Pending email, or switch confirmation on.');
		}

		if (\in_array('confirmlink', $pendTags, true) || \in_array('confirmlink', $welTags, true)) {
			$add('info', 'confirmlink_always_a_link', '[CONFIRMLINK] is always rendered as a link, even for a member who is already confirmed or has no activation code (the code part is then empty). [CONFIRM] is the conditional form.');
		}

		if ($confirmation && $approval && \in_array('confirm', $pendTags, true)) {
			$add('info', 'pending_resent_after_confirmation', 'With both confirmation and approval on, a member who confirms but is not yet approved gets no new email; if a moderator approves someone still unconfirmed, CB re-sends the Pending email, with the link.');
		}

		if ($genPass && $welcomeSends && !\in_array('password', $welTags, true) && !\in_array('details', $welTags, true)) {
			$add('critical', 'generated_password_never_delivered', 'Generate Registration Passwords is on, but the Welcome email uses neither [PASSWORD] nor [DETAILS]. CB generates the password at activation and only the Welcome email can carry it, so members can never learn it.');
		}

		if ($genPass && !$welcomeSends) {
			$add('critical', 'generated_password_no_welcome', 'Generate Registration Passwords is on, but the Welcome email is empty, so the generated password is never sent.');
		}

		if ($genPass) {
			$add('warning', 'approval_resets_password', 'With Generate Registration Passwords on, approving or confirming a member REPLACES their password with a new random one (UserTable::approveUser / confirmUser). approve_cb_user and confirm_cb_user refuse unless send_email is true for that reason.');
		}

		if ($approval && !$modEmail) {
			$add('warning', 'approval_without_moderator_email', 'Admin approval is on but moderator emails are off: nobody is told when someone is waiting. Use list_cb_pending_registrations.');
		}

		if ($approval && $modEmail && $moderators['eligible'] === 0) {
			$add('critical', 'no_moderator_receives_alerts', 'Admin approval and moderator emails are on, but no account qualifies to receive them (a member of the moderator view level\'s groups, not blocked, confirmed, approved, and with "Receive System Emails" on). Approval alerts go nowhere.');
		}

		if ($approval && !$welcomeSends) {
			$add('warning', 'approval_without_welcome', 'Admin approval is on but the Welcome email is empty: approved members are never told they can log in.');
		}

		if ((string) $cfg['reg_admin_allowcbregistration'] !== '1' && (string) ($joomla['allowUserRegistration'] ?? '0') !== '1') {
			$add('info', 'registration_closed', 'Registration is closed: CB follows Joomla\'s "Allow User Registration" (reg_admin_allowcbregistration = 0) and Joomla has it off.');
		}

		if ((string) ($joomla['useractivation'] ?? '0') !== '0') {
			$add('info', 'joomla_activation_ignored', 'Joomla\'s own "New User Account Activation" is set, but sign-ups through CB use CB\'s confirmation and approval switches instead.');
		}

		if ((string) $cfg['reg_email_html'] !== '1' && preg_match('/<(a|p|br|div|strong)\b/i', $pendMsg . $welMsg) === 1) {
			$add('info', 'html_in_plain_text_email', 'The Pending or Welcome body contains HTML tags but "Registration Email HTML" is off, so members see the raw tags.');
		}

		// Members already stuck because of this.
		$stuck = (int) $this->db->setQuery(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__comprofiler') . ' WHERE confirmed = 0 AND (cbactivation = \'\' OR cbactivation IS NULL)'
		)->loadResult();

		if ($stuck > 0) {
			$add('warning', 'unconfirmed_without_code', $stuck . ' unconfirmed member(s) have no activation code, so any email CB sends them has no working confirmation link. confirm_cb_user confirms them on their behalf.');
		}

		return ToolResult::json([
			'ok'       => true,
			'switches' => [
				'cb_allow_registration'     => $cfg['reg_admin_allowcbregistration'],
				'joomla_allow_registration' => $joomla['allowUserRegistration'] ?? null,
				'joomla_new_user_group'     => $joomla['new_usertype'] ?? null,
				'admin_approval'            => $cfg['reg_admin_approval'],
				'email_confirmation'        => $cfg['reg_confirmation'],
				'generate_passwords'        => $cfg['emailpass'],
				'log_registration_ip'       => $cfg['reg_ipaddress'],
				'ajax_username_checker'     => $cfg['reg_username_checker'],
				'first_login_redirect'      => $cfg['reg_first_visit_url'],
				'cb_sends_email'            => $cfg['sendemails'],
				'joomla_mailonline'         => $mailOnline,
				'max_user_emails_per_hour'  => $cfg['maxEmailsPerHr'],
			],
			'emails' => [
				'from_name'    => ['stored' => $cfg['reg_email_name'], 'rendered' => $rendered['reg_email_name']],
				'from_address' => $cfg['reg_email_from'] !== '' && $cfg['reg_email_from'] !== null ? $cfg['reg_email_from'] : '(site default: ' . (string) $app->get('mailfrom') . ')',
				'reply_to'     => $cfg['reg_email_replyto'],
				'html'         => $cfg['reg_email_html'],
				'pending'      => [
					'subject_stored'   => $cfg['reg_pend_appr_sub'],
					'body_stored'      => $cfg['reg_pend_appr_msg'],
					'subject_rendered' => $pendSub,
					'body_rendered'    => $pendMsg,
					'placeholders'     => $pendTags,
					'sends'            => $pendingSends,
				],
				'welcome'      => [
					'subject_stored'   => $cfg['reg_welcome_sub'],
					'body_stored'      => $cfg['reg_welcome_msg'],
					'subject_rendered' => $welSub,
					'body_rendered'    => $welMsg,
					'placeholders'     => $welTags,
					'sends'            => $welcomeSends,
				],
				'placeholder_rules' => [
					'[CONFIRM]'     => 'Link only for an unconfirmed member with an activation code; otherwise "Email address is already confirmed" if confirmation is on, or EMPTY if it is off.',
					'[CONFIRMLINK]' => 'Always the confirmation URL, even when there is no code.',
					'[DETAILS]'     => 'Email, name, username; plus the password only when Generate Registration Passwords is on and the member is confirmed and approved.',
					'[PASSWORD]'    => 'The cleartext password at the moment of sending; only meaningful when CB generated it.',
					'[SITEURL]'     => 'The site URL CB sees. From an MCP call it would end in /api; the add-on\'s mail guard repairs that.',
				],
			],
			'flow'       => $this->flow($confirmation, $approval, $pendingSends, $welcomeSends, $modEmail),
			'moderators' => $moderators,
			'findings'   => $findings,
		]);
	}

	/** What CB sends at each step, for this configuration (from `activateUser()`, LegacyComprofilerFunctions.php:690). */
	private function flow(bool $confirmation, bool $approval, bool $pending, bool $welcome, bool $modEmail): array
	{
		$steps = [];

		if ($confirmation) {
			$steps[] = 'Sign-up: member is unconfirmed' . ($approval ? ' and unapproved' : '') . ' and blocked; CB sends the Pending email' . ($pending ? '' : ' (EMPTY — nothing is sent)') . ', which should carry the [CONFIRM] link.';
			$steps[] = 'Member clicks the link: confirmed. ' . ($approval
				? 'Still awaiting approval; CB alerts moderators' . ($modEmail ? '' : ' (moderator emails are OFF — nobody is told)') . '.'
				: 'Activated and unblocked; CB sends the Welcome email' . ($welcome ? '' : ' (EMPTY — nothing is sent)') . '.');

			if ($approval) {
				$steps[] = 'Moderator approves: activated and unblocked; CB sends the Welcome email' . ($welcome ? '' : ' (EMPTY — nothing is sent)') . '.';
			}
		} elseif ($approval) {
			$steps[] = 'Sign-up: member is confirmed but unapproved and blocked; CB sends the Pending email' . ($pending ? '' : ' (EMPTY — nothing is sent)') . ' and alerts moderators' . ($modEmail ? '' : ' (moderator emails are OFF)') . '.';
			$steps[] = 'Moderator approves: activated and unblocked; CB sends the Welcome email' . ($welcome ? '' : ' (EMPTY — nothing is sent)') . '.';
		} else {
			$steps[] = 'Sign-up: member is active immediately; CB sends the Welcome email' . ($welcome ? '' : ' (EMPTY — nothing is sent)') . ($modEmail ? ' and a "new user" alert to moderators' : '') . '.';
		}

		$steps[] = 'Moderator rejects: CB sends a fixed rejection email (language strings UE_REG_REJECT_SUB / UE_USERREJECT_MSG, not configurable here).';

		return $steps;
	}

	/** Accounts that would receive CB moderator emails (cbNotification::sendToModerators()). */
	private function moderators(int $viewLevel): array
	{
		$db    = $this->db;
		$rules = (string) ($db->setQuery(
			'SELECT rules FROM ' . $db->quoteName('#__viewlevels') . ' WHERE id = ' . $viewLevel
		)->loadResult() ?? '');

		$groups = array_map('intval', (array) (json_decode($rules, true) ?: []));

		if ($groups === []) {
			return ['view_level' => $viewLevel, 'groups' => [], 'eligible' => 0, 'accounts' => []];
		}

		// Child groups inherit view levels (CB passes $inheritedOnesToo = true).
		$all = $db->setQuery(
			'SELECT DISTINCT c.id FROM ' . $db->quoteName('#__usergroups') . ' p'
			. ' JOIN ' . $db->quoteName('#__usergroups') . ' c ON c.lft >= p.lft AND c.rgt <= p.rgt'
			. ' WHERE p.id IN (' . implode(',', $groups) . ')'
		)->loadColumn() ?: [];

		$all = array_map('intval', $all);

		$rows = $db->setQuery(
			'SELECT DISTINCT u.id, u.username, u.email, u.block, u.sendEmail, c.confirmed, c.approved'
			. ' FROM ' . $db->quoteName('#__users') . ' u'
			. ' JOIN ' . $db->quoteName('#__user_usergroup_map') . ' g ON g.user_id = u.id'
			. ' LEFT JOIN ' . $db->quoteName('#__comprofiler') . ' c ON c.id = u.id'
			. ' WHERE g.group_id IN (' . implode(',', $all) . ')'
			. ' ORDER BY u.id',
			0,
			50
		)->loadAssocList() ?: [];

		$accounts = [];
		$eligible = 0;

		foreach ($rows as $r) {
			$ok = (int) $r['block'] === 0 && (int) $r['sendEmail'] === 1 && (int) $r['confirmed'] === 1 && (int) $r['approved'] === 1;
			$eligible += $ok ? 1 : 0;

			$why = [];

			if ((int) $r['block'] === 1) { $why[] = 'blocked'; }
			if ((int) $r['sendEmail'] !== 1) { $why[] = 'Receive System Emails is off'; }
			if ($r['confirmed'] === null) { $why[] = 'no CB profile row'; }
			elseif ((int) $r['confirmed'] !== 1 || (int) $r['approved'] !== 1) { $why[] = 'not confirmed/approved in CB'; }

			$accounts[] = [
				'id'       => (int) $r['id'],
				'username' => $r['username'],
				'email'    => $r['email'],
				'receives' => $ok,
				'why_not'  => $ok ? null : implode(', ', $why),
			];
		}

		return ['view_level' => $viewLevel, 'groups' => $all, 'eligible' => $eligible, 'accounts' => $accounts];
	}
}
