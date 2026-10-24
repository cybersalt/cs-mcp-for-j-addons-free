<?php
/**
 * @package     MCP for Page Protector
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Status;

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\CaptchaHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\LogHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\ProtectionHelper;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\PageProtectorBootTrait;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;

final class GetStatusTool extends AbstractTool
{
	use PageProtectorBootTrait;

	public function getName(): string { return 'get_page_protector_status'; }

	public function getDescription(): string
	{
		return 'Page Protector\'s dashboard health checks, worst first: is the gatekeeper plugin on, can the configured '
			. 'captcha actually run (the core Proof-of-Work captcha needs Joomla 6.1+), what is protected, the Cloudflare '
			. 'IP warning, logging, page cache. Plus versions, the protection mode in words, the protected modules, '
			. 'exemptions and logging settings. Start here: when the captcha cannot run, protected pages are either open '
			. 'to everyone or answer 403, depending on the "captcha_unavailable" option.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($error = $this->ppCheck()) {
			return $error;
		}

		$this->ppRequireAccess($actor, 'view');

		$state  = $this->ppState();
		$params = $this->ppStoredParams();

		/** @var \Cybersalt\Component\Cspageprotector\Administrator\Model\DashboardModel $dashboard */
		$dashboard = $this->getModel(self::PP_COMPONENT, 'Dashboard');

		// The dashboard's own checks (DashboardModel::getChecks()). They read params through
		// ComponentHelper, so with caching on they can trail a change made seconds ago.
		$checks = array_map(
			static fn (array $c): array => array_filter([
				'level' => $c['level'],
				'text'  => $c['text'],
				'admin_link' => $c['link'] !== '' ? $c['link'] : null,
			]),
			$dashboard->getChecks()
		);

		$captcha   = CaptchaHelper::getConfiguredPlugin($params);
		$problem   = CaptchaHelper::getProblem($captcha);
		$installed = array_map(
			static fn (object $p): array => ['element' => (string) $p->element, 'enabled' => (int) $p->enabled === 1],
			CaptchaHelper::getInstalledPlugins()
		);

		$mode       = (string) $params->get('protection_mode', ProtectionHelper::MODE_SELECTED);
		$menuIds    = ProtectionHelper::getMenuItemIds($params);
		$pages      = ProtectionHelper::hasPageProtection($params);
		$modules    = ProtectionHelper::hasModuleProtection($params);
		$unavailable = (string) $params->get('captcha_unavailable', 'allow');

		$impact = null;

		if ($problem !== CaptchaHelper::PROBLEM_NONE && ($pages || $modules)) {
			$impact = trim(
				($pages ? ($unavailable === 'allow'
					? 'Protected pages are OPEN to everyone (captcha_unavailable = allow). '
					: 'Protected pages answer 403 to every visitor without a pass (captcha_unavailable = block). ') : '')
				. ($modules ? 'Protected modules stay locked for everyone without a pass.' : '')
			);
		}

		$overall = 'ok';

		foreach ($checks as $c) {
			if ($c['level'] === 'danger') {
				$overall = 'error';
				break;
			}

			if ($c['level'] === 'warning') {
				$overall = 'warning';
			}
		}

		if (!$state['component_enabled'] || !$state['plugin_enabled']) {
			$overall = 'error';
		}

		return ToolResult::json([
			'ok'       => true,
			'overall'  => $overall,
			'versions' => [
				'package'   => $state['package_version'],
				'component' => $state['version'],
				'plugin'    => $state['plugin_version'],
				'joomla'    => JVERSION,
			],
			'component_enabled' => $state['component_enabled'],
			'plugin_enabled'    => $state['plugin_enabled'],
			'not_running'       => !$state['component_enabled'] || !$state['plugin_enabled']
				? 'With the component or the System - Cybersalt Page Protector plugin disabled, nothing is protected.'
				: null,
			'checks'  => $checks,
			'captcha' => [
				'configured'      => $captcha,
				'problem'         => $problem !== CaptchaHelper::PROBLEM_NONE ? $problem : null,
				'impact_now'      => $impact,
				'if_unavailable'  => $unavailable,
				'installed'       => $installed,
				'core_pow_note'   => 'The core Proof-of-Work captcha (powcaptcha) ships with Joomla 6.1 and later. Joomla 5 and '
					. '6.0 ship no captcha plugin at all, so they need a third-party one picked in Options.',
			],
			'protection' => [
				'mode'                => $mode,
				'pages_protected'     => $pages,
				'menu_items_configured' => \count($menuIds),
				'menu_items_mean'     => $mode === ProtectionHelper::MODE_ALL_EXCEPT ? 'pages EXCLUDED from protection' : 'pages protected',
				'match_content'       => (int) $params->get('match_content', 1) === 1,
				'modules_protected'   => $modules,
				'protected_modules'   => $modules ? $dashboard->getProtectedModules() : [],
				'module_mode'         => (string) $params->get('module_mode', ProtectionHelper::MODULE_MODE_PLACEHOLDER),
				'pass_lifetime_hours' => (int) $params->get('pass_lifetime', 24),
				'bind_ip'             => (int) $params->get('bind_ip', 0) === 1,
			],
			'exemptions' => [
				'logged_in_users'     => (int) $params->get('exempt_logged_in', 1) === 1,
				'user_groups'         => array_map('intval', (array) $params->get('exempt_groups', [])),
				'verified_search_engines' => (int) $params->get('exempt_search_engines', 1) === 1,
				'ip_entries'          => \count($this->ppIpCount((string) $params->get('exempt_ips', ''))),
				'ip_source'           => (string) $params->get('ip_source', 'remote_addr'),
			],
			'logging' => [
				'enabled'        => (int) $params->get('logging_enabled', 1) === 1,
				'shorten_ips'    => LogHelper::anonymizeEnabled($params),
				'retention_days' => (int) $params->get('retention_days', 30),
			],
			'page_cache_plugin_enabled' => PluginHelper::isEnabled('system', 'cache'),
			'no_guarantee_notice' => (string) $params->get('notice_accepted_at', '') !== ''
				? ['accepted' => true, 'by' => (string) $params->get('notice_accepted_name', ''), 'at' => (string) $params->get('notice_accepted_at', '')]
				: ['accepted' => false, 'note' => 'The red "no guarantee" box shows on every Page Protector admin page until someone with core.options accepts it there.'],
			'cloudflare_check_note' => 'The dashboard\'s Cloudflare warning looks at the request that asked, which here is this MCP call. '
				. 'It is only meaningful when the API is reached through the same Cloudflare zone as the site.',
		]);
	}

	/** Lines that hold at least one entry, for a count (the full parse is in get_page_protector_settings). */
	private function ppIpCount(string $text): array
	{
		return array_filter(
			preg_split('/[\r\n,]+/', $text) ?: [],
			static fn (string $piece): bool => trim(preg_replace('/#.*$/', '', $piece) ?? '') !== ''
		);
	}
}
