<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\CpnbBootTrait;
use Joomla\CMS\User\User;

/**
 * Orientation: what is installed, which edition, and the facts that change how
 * the other tools should be used.
 */
final class GetComponentInfoTool extends AbstractTool
{
	use CpnbBootTrait;

	/** Package members and what breaks when each one is off. */
	private const MEMBERS = [
		['component', 'com_cookiespolicynotificationbar', '', 'The admin dashboard (a React app). Only an editor; nothing on the front end reads it.'],
		['plugin', 'cookiespolicynotificationbar', 'system', 'RENDERS THE BAR and owns every setting (its #__extensions.params). Disabled = no bar, no blocking, no consent logs.'],
		['plugin', 'cookiespolicynotificationbar', 'ajax', 'Carries the settings API the dashboard and these tools write through, and the front-end consent endpoints.'],
		['plugin', 'web357framework', 'system', 'Web357 framework: licence key, version checks, shared form fields. Required by CPNB.'],
		['plugin', 'web357framework', 'ajax', 'Web357 framework AJAX endpoints.'],
		['plugin', 'cookiespolicynotificationbar', 'installer', 'Adds the Web357 licence key to update downloads.'],
		['plugin', 'deletecpnblogs', 'task', 'Scheduled task that purges consent logs older than N days (PRO).'],
	];

	public function getName(): string { return 'get_cpnb_component_info'; }

	public function getDescription(): string
	{
		return 'Orientation for Cookies Policy Notification Bar (CPNB, by Web357). Call this first. Returns the '
			. 'version, the edition (free or PRO, read the vendor\'s own two ways), every package member with its '
			. 'enabled state and what disabling it breaks, the site\'s active languages as CPNB sees them, and the '
			. 'consent-cookie contract (cookie names and the cpnb_cookiesSettings JSON format) that any non-Joomla '
			. 'page sharing the consent needs. Facts this surfaces: (1) every CPNB 5.x setting is one JSON blob in '
			. 'the SYSTEM plugin\'s params; the old plugin Options page no longer saves. (2) Texts are stored per '
			. 'language (header_message_en_GB, ...) and the bar shows the visitor\'s language. (3) PRO-only '
			. 'capabilities per Web357: cookie blocking, consent logs, GeoIP targeting, multilingual texts, '
			. 'import/export — tools for those are in the Pro add-on. Read-only.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cpnbInstalled()) {
			return $this->cpnbNotInstalledError();
		}

		$this->cpnbRequireAccess($actor);

		$members  = [];
		$warnings = [];
		$versions = [];

		foreach (self::MEMBERS as [$type, $element, $folder, $role]) {
			$row = $this->cpnbExtensionRow($type, $element, $folder);

			$members[] = [
				'type'         => $type,
				'element'      => $element,
				'folder'       => $folder,
				'installed'    => $row !== null,
				'enabled'      => $row['enabled'] ?? false,
				'version'      => $row['version'] ?? null,
				'extension_id' => $row['extension_id'] ?? null,
				'role'         => $role,
			];

			if ($row !== null && str_contains($element, 'cookiespolicynotificationbar') && $row['version'] !== '') {
				$versions[$row['version']] = true;
			}

			if ($row !== null && !$row['enabled'] && \in_array($folder, ['system', 'ajax'], true)) {
				$warnings[] = 'plg_' . $folder . '_' . $element . ' is DISABLED. ' . $role;
			}
		}

		if (\count($versions) > 1) {
			$warnings[] = 'CPNB package members report different versions (' . implode(', ', array_keys($versions))
				. '). Reinstall the full pkg_cookiespolicynotificationbar package.';
		}

		$edition = $this->cpnbEdition();

		if (!$edition['agree']) {
			$warnings[] = 'The two edition signals disagree (manifest says "' . $edition['manifest_variant']
				. '", the settings API says ' . ($edition['api_is_pro_flag'] ? 'PRO' : 'free') . '). A free/PRO mix '
				. 'usually means a partial upgrade; reinstall the PRO package. The API flag is trusted.';
		}

		$settings = $this->cpnbEffective();

		return ToolResult::json([
			'ok'               => true,
			'cpnb_version'     => $this->cpnbVersion(),
			'edition'          => $edition['is_pro'] ? 'pro' : 'free',
			'edition_signals'  => $edition,
			'pro_capabilities' => [
				'per_web357' => 'cookie blocking, consent logs, GeoIP targeting, multilingual texts, import/export',
				'available_on_this_site' => $edition['is_pro'],
				'tools' => $edition['is_pro']
					? 'Install the Cybersalt Pro add-on (csmcpforjcpnbpro) for dedicated tools. This free add-on can also set the PRO switches through set_cpnb_config on this PRO site.'
					: 'Refused here: this site runs the free build of CPNB.',
			],
			'joomla_version'   => JVERSION,
			'php_version'      => PHP_VERSION,
			'package_members'  => $members,
			'languages'        => [
				'active_tags'     => $this->cpnbActiveLanguageTags(),
				'site_default'    => $this->cpnbDefaultLanguageTag(),
				'note'            => 'Text settings are stored as <name>_<tag>. CPNB lists Content Languages plus the site default; the bar renders the visitor\'s language tag.',
			],
			'consent_cookies'  => [
				'accept_cookie'          => (string) ($settings['cookie_name'] ?? 'cookiesDirective'),
				'decline_cookie'         => (string) ($settings['cookie_name_declined'] ?? 'cpnbCookiesDeclined'),
				'cancel_cookie'          => (string) ($settings['cookie_name_cancelled'] ?? 'cpnbCookiesCancelled'),
				'per_category_cookie'    => self::CPNB_CONSENT_COOKIE,
				'per_category_format'    => 'URL-encoded JSON: {"v": {"<cookie_category_id>": 1|0, ...}, "exp": "<expiry>"}. Read "v"; a missing or unparsable cookie means no decision yet.',
				'expiry_days'            => [
					'settings' => $settings['expiration_cookieSettings'] ?? null,
					'accept'   => $settings['expiration_cookieAccept'] ?? null,
					'decline'  => $settings['expiration_cookieDecline'] ?? null,
					'cancel'   => $settings['expiration_cookieCancel'] ?? null,
				],
				'shared_across_subdomains' => (string) ($settings['share_cookies_across_subdomains'] ?? '0') === '1',
			],
			'warnings'         => $warnings,
			'next_steps'       => 'check_cpnb_health for configuration problems; check_cpnb_frontend to see what the live home page actually serves.',
		]);
	}
}
