<?php
/**
 * @package     MCP for jDownloads
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Lookups;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\JdownloadsBootTrait;
use Joomla\CMS\User\User;

/**
 * jDownloads' "User Groups Settings" (#__jdownloads_usergroups_limits): per
 * Joomla user group download limits, upload rights and front-end form
 * settings. Read-only.
 */
final class ListGroupsTool extends AbstractTool
{
	use JdownloadsBootTrait;

	private const LIMIT_COLUMNS = [
		'importance', 'download_limit_daily', 'download_limit_weekly', 'download_limit_monthly',
		'download_volume_limit_daily', 'download_volume_limit_weekly', 'download_volume_limit_monthly',
		'how_many_times', 'download_limit_after_this_time', 'transfer_speed_limit_kb', 'upload_limit_daily',
		'view_captcha', 'view_inquiry_form', 'view_report_form', 'must_form_fill_out', 'countdown_timer_duration',
		'may_edit_own_downloads', 'may_edit_all_downloads', 'use_private_area', 'view_user_his_limits',
		'uploads_only_in_cat_id', 'uploads_auto_publish', 'uploads_allowed_types', 'uploads_maxfilesize_kb',
		'uploads_default_access_level', 'uploads_can_change_category', 'uploads_max_amount_images',
		'view_gdpr_dsgvo_option',
	];

	public function getName(): string { return 'list_jdownloads_groups'; }

	public function getDescription(): string
	{
		return 'Read-only. jDownloads\' "User Groups Settings": for each Joomla user group, its download limits '
			. '(per day/week/month, volume, how many times, speed), captcha and form requirements, whether it may '
			. 'upload or edit downloads on the front end and with which types, size and auto-publish rule. Groups '
			. 'with no jDownloads row are listed too. include_form_settings adds the upload-form field switches. For '
			. 'Joomla permissions (who may download, create, edit), use list_jdownloads_permissions.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'group_id'              => ['type' => 'integer'],
				'include_form_settings' => ['type' => 'boolean'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->jdInstalled()) {
			return $this->jdNotInstalledError();
		}

		$this->jdRequireAccess($actor);

		$db       = $this->db;
		$groupId  = (int) ($arguments['group_id'] ?? 0);
		$groups   = $db->setQuery(
			'SELECT id, title, parent_id FROM #__usergroups' . ($groupId > 0 ? ' WHERE id = ' . $groupId : '') . ' ORDER BY lft'
		)->loadAssocList('id') ?: [];
		$settings = $this->jdTableExists('jdownloads_usergroups_limits')
			? ($db->setQuery('SELECT * FROM #__jdownloads_usergroups_limits' . ($groupId > 0 ? ' WHERE group_id = ' . $groupId : ''))->loadAssocList('group_id') ?: [])
			: [];

		$withForm = !empty($arguments['include_form_settings']);
		$out      = [];

		foreach ($groups as $gid => $g) {
			$s    = $settings[$gid] ?? null;
			$item = ['group_id' => (int) $gid, 'group_title' => (string) $g['title'], 'has_jdownloads_settings' => $s !== null];

			if ($s !== null) {
				foreach (self::LIMIT_COLUMNS as $col) {
					if (\array_key_exists($col, $s)) {
						$item[$col] = is_numeric($s[$col]) ? (int) $s[$col] : $s[$col];
					}
				}

				if ($withForm) {
					$item['form_settings'] = array_filter(
						$s,
						static fn ($k) => str_starts_with((string) $k, 'form_'),
						ARRAY_FILTER_USE_KEY
					);
				}
			}

			$out[] = $item;
		}

		$orphans = array_values(array_diff(array_map('intval', array_keys($settings)), array_map('intval', array_keys($groups))));

		$response = ['ok' => true, 'groups' => $out];

		if ($orphans !== [] && $groupId === 0) {
			$response['settings_for_missing_groups'] = $orphans;
		}

		return ToolResult::json($response);
	}
}
