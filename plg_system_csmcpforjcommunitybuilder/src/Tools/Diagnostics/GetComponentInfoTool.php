<?php
/**
 * @package     MCP for Community Builder
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

final class GetComponentInfoTool extends AbstractTool
{
	use CommunitybuilderBootTrait;

	public function getName(): string { return 'get_cb_component_info'; }

	public function getDescription(): string
	{
		return 'Orientation for Community Builder (com_comprofiler) by Joomlapolis: installed version, whether '
			. 'CB boots from an MCP request (and in which context), member counts by moderation state '
			. '(awaiting approval, awaiting email confirmation, rejected, banned, blocked, Joomla users with no '
			. 'CB profile row), custom field / tab / list / CB plugin counts, the CB system plugin\'s state, and '
			. 'the registration switches that decide how sign-ups are moderated. Run this first. Read-only.';
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

		$this->cbRequireAccess($actor, 'core.manage', 'com_comprofiler');

		$db = $this->db;

		$count = function (string $sql) use ($db): int {
			return (int) $db->setQuery($sql)->loadResult();
		};

		$u = $db->quoteName('#__users');
		$c = $db->quoteName('#__comprofiler');

		$members = [
			'joomla_users'                => $count('SELECT COUNT(*) FROM ' . $u),
			'cb_profiles'                 => $count('SELECT COUNT(*) FROM ' . $c),
			'users_without_cb_profile'    => $count('SELECT COUNT(*) FROM ' . $u . ' u LEFT JOIN ' . $c . ' c ON c.id = u.id WHERE c.id IS NULL'),
			'awaiting_approval'           => $count('SELECT COUNT(*) FROM ' . $c . ' WHERE approved = 0'),
			'awaiting_email_confirmation' => $count('SELECT COUNT(*) FROM ' . $c . ' WHERE confirmed = 0'),
			'rejected'                    => $count('SELECT COUNT(*) FROM ' . $c . ' WHERE approved = 2'),
			'banned'                      => $count('SELECT COUNT(*) FROM ' . $c . ' WHERE banned = 1'),
			'unban_requested'             => $count('SELECT COUNT(*) FROM ' . $c . ' WHERE banned = 2'),
			'blocked'                     => $count('SELECT COUNT(*) FROM ' . $u . ' WHERE block = 1'),
		];

		$structure = [
			'fields'         => $count('SELECT COUNT(*) FROM ' . $db->quoteName('#__comprofiler_fields')),
			'custom_fields'  => $count('SELECT COUNT(*) FROM ' . $db->quoteName('#__comprofiler_fields') . ' WHERE sys = 0'),
			'tabs'           => $count('SELECT COUNT(*) FROM ' . $db->quoteName('#__comprofiler_tabs')),
			'lists'          => $count('SELECT COUNT(*) FROM ' . $db->quoteName('#__comprofiler_lists')),
			'cb_plugins'     => $count('SELECT COUNT(*) FROM ' . $db->quoteName('#__comprofiler_plugin')),
			'connections'    => $count('SELECT COUNT(*) FROM ' . $db->quoteName('#__comprofiler_members')),
			'user_reports'   => $count('SELECT COUNT(*) FROM ' . $db->quoteName('#__comprofiler_userreports')),
		];

		$systemPlugin = $db->setQuery(
			$db->getQuery(true)
				->select([$db->quoteName('enabled'), $db->quoteName('params')])
				->from($db->quoteName('#__extensions'))
				->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
				->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
				->where($db->quoteName('element') . ' = ' . $db->quote('communitybuilder'))
		)->loadAssoc();

		$config    = $this->cbRawConfig();
		$joomla    = $this->cbJoomlaUsersParams();

		$boot = ['ok' => false];

		try {
			$run = $this->cbRun($actor, static function (): array {
				global $_CB_framework;

				return [
					'cblib_version' => \defined('CBLIB') ? CBLIB : null,
					'ui'            => $_CB_framework->getUi() === 2 ? 'backend (2)' : 'frontend (1)',
					'live_site'     => $_CB_framework->getCfg('live_site'),
				];
			});

			$boot = ['ok' => true] + $run['result'] + $this->cbContextNote() + [
				'site_url'      => $this->cbSiteBase(),
				'live_site_note' => 'CB derives [SITEURL] and every link it emails from live_site, which under '
					. 'Joomla\'s API application ends in /api. The mail guard rewrites those links to site_url '
					. 'before anything is sent.',
			];
		} catch (\Throwable $e) {
			$boot = ['ok' => false, 'error' => $e->getMessage()];
		}

		return ToolResult::json([
			'ok'        => true,
			'component' => [
				'name'                  => 'Community Builder',
				'element'               => 'com_comprofiler',
				'vendor'                => 'Joomlapolis (Lightning MultiCom SA)',
				'version'               => $this->cbVersion(),
				'core_plugins_unpacked' => is_dir(JPATH_SITE . '/components/com_comprofiler/plugin/user/plug_cbcore'),
				'install_step_two_pending' => is_file($this->cbAdminBase() . '/pluginsfiles.tgz'),
			],
			'boot'          => $boot,
			'system_plugin' => $systemPlugin ? [
				'enabled'       => (int) $systemPlugin['enabled'] === 1,
				'params'        => json_decode((string) $systemPlugin['params'], true) ?: new \stdClass(),
				'note'          => 'plg_system_communitybuilder reroutes Joomla\'s com_users login, registration '
					. 'and profile pages to CB (redirect_urls) and removes CB rows when a Joomla user is deleted.',
			] : null,
			'members'       => $members,
			'structure'     => $structure,
			'registration'  => [
				'cb_allow_registration'      => $config['reg_admin_allowcbregistration'] ?? null,
				'cb_allow_registration_note' => '0 = follow Joomla\'s "Allow User Registration"; 1 = CB allows registration regardless of Joomla.',
				'joomla_allow_registration'  => $joomla['allowUserRegistration'] ?? null,
				'admin_approval'             => $config['reg_admin_approval'] ?? null,
				'email_confirmation'         => $config['reg_confirmation'] ?? null,
				'generate_passwords'         => $config['emailpass'] ?? null,
				'moderator_emails'           => $config['moderatorEmail'] ?? null,
				'cb_sends_emails'            => $config['sendemails'] ?? null,
				'detail'                     => 'get_cb_registration_config shows the emails and audits them.',
			],
		]);
	}
}
