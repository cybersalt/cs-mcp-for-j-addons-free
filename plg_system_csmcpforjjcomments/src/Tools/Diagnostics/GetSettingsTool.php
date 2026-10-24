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
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\User\User;

/**
 * JComments' settings, grouped by what they decide, plus the permission
 * matrix that actually governs moderation.
 *
 * Much of JComments' behaviour is NOT in its options but in its permissions
 * (administrator/access.xml), and several are named the opposite way round
 * from how they work: "comment.captcha", "comment.flood",
 * "comment.length_check" and "comment.terms_of_use" each EXEMPT the group
 * from that check when allowed (site/jcomments.ajax.php:273, 296, 309).
 * The matrix below reports them as "skips_*" so nobody has to remember that.
 *
 * Read-only. There is no settings writer: JComments' own settings save goes
 * through com_config's component options form, and the rule-heavy parts are
 * Joomla permissions, which core MCP for J's permission tools already edit.
 */
final class GetSettingsTool extends AbstractTool
{
	use JcommentsBootTrait;

	/** Permission => label in the matrix. */
	private const JC_ACTIONS = [
		'comment.comment'      => 'can_comment',
		'comment.reply'        => 'can_reply',
		'comment.autopublish'  => 'published_without_approval',
		'comment.captcha'      => 'skips_captcha',
		'comment.flood'        => 'skips_flood_check',
		'comment.length_check' => 'skips_length_check',
		'comment.terms_of_use' => 'skips_terms_of_use',
		'comment.subscribe'    => 'can_subscribe',
		'comment.vote'         => 'can_vote',
		'comment.report'       => 'can_report',
		'comment.bbcode.url'   => 'can_post_links_bbcode',
		'comment.autolink'     => 'links_made_clickable',
		'comment.publish'      => 'can_publish_any',
		'comment.delete'       => 'can_delete_any',
		'comment.ban'          => 'can_ban',
		'core.manage'          => 'admin_access',
	];

	private const JC_GROUPS = [
		'moderation'    => ['template_view', 'comments_list_order', 'comments_tree_order', 'comments_per_page', 'delete_mode', 'merge_time', 'enable_quick_moderation', 'max_comments_per_object', 'enable_categories', 'categories', 'enable_plugins'],
		'author_fields' => ['author_name', 'author_email', 'author_homepage', 'comment_title', 'enable_username_check', 'username_maxlength', 'forbidden_names'],
		'limits'        => ['comment_minlength', 'comment_maxlength', 'link_maxlength', 'flood_time'],
		'anti_spam'     => ['captcha_engine', 'enable_blacklist', 'stopforumspam', 'antispam_request_timeout', 'enable_autocensor', 'badwords', 'show_checkbox_terms_of_use'],
		'notifications' => ['enable_notification', 'notification_type', 'notification_email'],
		'reports'       => ['enable_reports', 'report_reason_required', 'reports_per_comment', 'reports_before_unpublish'],
		'display'       => ['template', 'display_author', 'display_title', 'enable_smilies', 'enable_custom_bbcode', 'enable_voting', 'enable_rss', 'form_show', 'form_position', 'load_cached_comments'],
	];

	public function getName(): string { return 'get_jcomments_settings'; }

	public function getDescription(): string
	{
		return 'Read JComments\' settings grouped by what they decide (moderation, author fields, limits, anti-spam, '
			. 'notifications, reports, display), plus the permission matrix that really governs moderation: for '
			. 'Public, Guest, Registered and your own account, whether they can comment and reply, are published '
			. 'without approval, skip captcha / flood / length checks, and can publish, delete or ban. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'include_all' => ['type' => 'boolean', 'description' => 'Also return every raw option, not only the grouped ones.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->jcInstalled()) {
			return $this->jcNotInstalledError();
		}

		$this->jcRequireAccess($actor);

		$params  = $this->jcParams();
		$all     = $params->toArray();
		$grouped = [];

		foreach (self::JC_GROUPS as $group => $keys) {
			foreach ($keys as $key) {
				$grouped[$group][$key] = $all[$key] ?? null;
			}
		}

		// Size the censor list rather than echo a long regex list back.
		$bad = trim((string) ($all['badwords'] ?? ''));
		$grouped['anti_spam']['badwords'] = $bad === '' ? ['entries' => 0] : ['entries' => \count(array_filter(array_map('trim', preg_split('/[,\r\n]+/', $bad) ?: [])))];

		$guestGroup = (int) ComponentHelper::getParams('com_users')->get('guest_usergroup', 9);
		$newGroup   = (int) ComponentHelper::getParams('com_users')->get('new_usertype', 2);
		$groupIds   = ['public' => 1, 'guest' => $guestGroup, 'registered' => $newGroup];
		$titles     = $this->db->setQuery(
			'SELECT id, title FROM ' . $this->db->quoteName('#__usergroups') . ' WHERE id IN (' . implode(',', array_unique($groupIds)) . ')'
		)->loadAssocList('id', 'title') ?: [];

		$matrix = [];

		foreach ($groupIds as $label => $gid) {
			$row = ['group_id' => $gid, 'group' => $titles[$gid] ?? null];

			foreach (self::JC_ACTIONS as $action => $name) {
				$row[$name] = (bool) Access::checkGroup($gid, $action, 'com_jcomments');
			}

			$matrix[$label] = $row;
		}

		$mine = ['user_id' => (int) $actor->id];

		foreach (self::JC_ACTIONS as $action => $name) {
			$mine[$name] = $actor->authorise($action, 'com_jcomments');
		}

		$matrix['you'] = $mine;

		$findings = [];

		if (!$matrix['guest']['published_without_approval'] && $matrix['guest']['can_comment']) {
			$findings[] = 'Guest comments wait for approval (Guest lacks comment.autopublish).';
		}

		if ($matrix['guest']['can_comment'] && $matrix['guest']['published_without_approval']) {
			$findings[] = 'Guest comments are published immediately, with no approval step.';
		}

		if ($matrix['guest']['can_comment'] && $matrix['guest']['skips_captcha']) {
			$findings[] = 'Guests comment without a captcha (Guest is allowed comment.captcha, which EXEMPTS a group).';
		}

		if ((string) ($all['template_view'] ?? 'tree') !== 'tree') {
			$findings[] = 'template_view is "list": no Reply button for visitors and replies show flat, in date order.';
		}

		if ((int) ($all['enable_plugins'] ?? 0) !== 1) {
			$findings[] = 'enable_plugins is off: JComments fires none of its onJComments* plugin events, so notify: true runs no plugin events either.';
		}

		$out = [
			'ok'          => true,
			'versions'    => $this->jcVersions(),
			'settings'    => $grouped,
			'permissions' => $matrix,
			'permission_notes' => 'skips_captcha / skips_flood_check / skips_length_check / skips_terms_of_use are JComments\' comment.captcha, comment.flood, comment.length_check and comment.terms_of_use: allowing them EXEMPTS the group from that check.',
			'findings'    => $findings,
		];

		if (!empty($arguments['include_all'])) {
			$out['raw'] = $all;
		}

		return ToolResult::json($out);
	}
}
