<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjce\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceBootTrait;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\User\User;

/**
 * `com_jce`'s own component configuration — and, just as importantly, what is
 * NOT in it.
 *
 * The instinct when asked "what can users upload in JCE?" is to look at the
 * component configuration. There is nothing there. `config.xml` has three
 * fieldsets and eight fields: an ACL rules field, six presentation/help
 * settings, and two security settings. Every directory root, extension list,
 * size limit and file-management flag lives per-profile inside
 * `#__wf_profiles.params`.
 *
 * The two settings that ARE here both override profiles rather than being
 * overridden by them, which makes them worth reading before anything else:
 *
 *   `allow_profile_guests`     — when 0 (default), guests are refused a profile
 *                                before matching begins
 *                                (`classes/application.php:331-337`)
 *   `profile_groups_whitelist` — added in 2.9.99.7 as part of the
 *                                CVE-2026-48907 remediation; intersected with
 *                                the user's groups during matching
 *                                (`:386-387`) and with `types` on every save
 *                                (`models/profile.php:612-616`), silently
 */
final class GetConfigTool extends AbstractTool
{
	use JceBootTrait;

	/** The ACL actions declared in `administrator/components/com_jce/access.xml`. */
	private const ACL_ACTIONS = [
		'core.admin'      => 'Configure com_jce permissions.',
		'core.manage'     => 'Access the JCE administration area.',
		'jce.config'      => 'Edit JCE preferences.',
		'jce.profiles'    => 'Create, edit, import, export and delete editor profiles. This is the action that CVE-2026-48907\'s profiles.import task was missing.',
		'jce.preferences' => 'Edit editor preferences.',
		'jce.browser'     => 'Use the file browser.',
		'jce.mediabox'    => 'Use the media box.',
	];

	public function getName(): string { return 'get_jce_config'; }

	public function getDescription(): string
	{
		return 'Return com_jce\'s component configuration, with the two security parameters that '
			. 'override every profile explained in full. '
			. 'THE HEADLINE IS WHAT IS ABSENT. com_jce\'s config.xml has three fieldsets and eight '
			. 'fields: an ACL rules field, six presentation and help settings, and two security '
			. 'settings. There are NO global file, folder, upload or extension restrictions anywhere in '
			. 'it. Every directory root, allowed-extension list, maximum upload size and '
			. 'upload/delete/rename flag is per-profile, inside #__wf_profiles.params. If you are '
			. 'looking for "what can users upload", this is the wrong tool — use '
			. 'get_jce_profile_params, or audit_jce_profiles for the site-wide view. '
			. 'allow_profile_guests: when 0 (the default), unauthenticated visitors are returned no '
			. 'profile at all, checked before any profile is evaluated (classes/application.php:331-337). '
			. 'When 1, guests are eligible for any profile matching the Public group — which, combined '
			. 'with an upload permission, is an unauthenticated upload endpoint. Worth verifying '
			. 'deliberately. '
			. 'profile_groups_whitelist: a hard cap on which user groups can ever receive a JCE profile. '
			. 'Added in 2.9.99.7 specifically in response to the CVE-2026-48907 exploitation campaign. '
			. 'When non-empty it is intersected with the user\'s groups during matching '
			. '(application.php:386-387) AND with incoming `types` on every profile save '
			. '(models/profile.php:612-616) — silently, in both cases. A profile assigned to a group '
			. 'outside it will appear to save correctly and then never match. '
			. 'Also reports updates_key presence (NOT its value — it is a credential), which gates '
			. 'downloads from the vendor\'s update server and nothing else, and the seven ACL actions '
			. 'from access.xml with what each governs. '
			. 'Read-only. This tool does not write component configuration; there is no set_jce_config '
			. 'in this add-on, because the only two meaningful settings here are security controls that '
			. 'should be changed deliberately in the JCE Preferences screen.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->jceAdminBase() === null) {
			return $this->jceNotInstalledError();
		}

		$params = ComponentHelper::getParams('com_jce');

		$whitelist = $this->jceGroupsWhitelist();
		$guests    = $this->jceAllowProfileGuests();

		$updatesKey = trim((string) $params->get('updates_key', ''));

		$response = [
			'ok'      => true,
			'component' => $this->jceEditionNotice(),
			'security' => [
				'allow_profile_guests' => [
					'value' => $guests,
					'default' => false,
					'meaning' => $guests
						? 'ON. Unauthenticated visitors are eligible for any profile matching the '
							. 'Public group. Combined with an upload permission on such a profile this '
							. 'is an unauthenticated upload endpoint — the exact shape of the '
							. 'CVE-2026-48907 exploitation. Verify this is deliberate.'
						: 'OFF (the default). WFApplication::getProfiles() returns null immediately for '
							. 'a guest (classes/application.php:331-337), so no profile is evaluated and '
							. 'no editor is rendered for unauthenticated visitors regardless of any '
							. 'profile\'s assignment.',
				],
				'profile_groups_whitelist' => [
					'value'  => $whitelist,
					'groups' => $this->jceDescribeGroups($whitelist),
					'meaning' => $whitelist === []
						? 'Not set, so no cap applies and any group named in a profile\'s `types` can '
							. 'receive it. This parameter was added in 2.9.99.7 as part of the '
							. 'CVE-2026-48907 remediation and is worth setting on any site where only a '
							. 'few groups should ever have an editor profile — it is a defence that '
							. 'holds even if a profile row is tampered with.'
						: 'SET. This is a hard cap. It is intersected with the user\'s groups during '
							. 'profile matching (application.php:386-387) AND with incoming `types` on '
							. 'every profile save (models/profile.php:612-616). Both are silent: a save '
							. 'requesting a group outside this list appears to succeed and stores '
							. 'something narrower.',
				],
			],
			'updates_key' => [
				'set'     => $updatesKey !== '',
				'length'  => \strlen($updatesKey),
				'meaning' => 'The Pro subscription key. Defined by plg_system_jce '
					. '(forms/updates.xml) and stored in com_jce\'s params. Its ONLY consumer appends '
					. 'it to vendor download URLs (plg_installer_jce/jce.php:45). It gates DOWNLOADS, '
					. 'never features — a Pro site whose subscription has lapsed is still fully Pro. '
					. 'The value is deliberately not returned: it is a credential.',
			],
			'standard' => [
				'custom_help'  => $params->get('custom_help', '0'),
				'help_url'     => $params->get('help_url', ''),
				'help_method'  => $params->get('help_method', 'reference'),
				'help_pattern' => $params->get('help_pattern', ''),
				'feed'         => $params->get('feed', '0'),
				'feed_limit'   => $params->get('feed_limit', '2'),
				'inline_help'  => $params->get('inline_help', '1'),
			],
			'acl_actions' => self::ACL_ACTIONS,
			'acl_note'    => 'Every task in JceControllerProfiles now checks jce.profiles on top of the '
				. 'CSRF token — import at controller/profiles.php:30-36, repair :61-67, copy :83-89, '
				. 'export :113-119. That authorisation check is precisely what CVE-2026-48907 was: the '
				. 'import task had the token check but no ACL check, and the token is embedded in every '
				. 'public page. The front end additionally hard-allowlists only plugin.* and editor.* '
				. 'tasks before MVC dispatch (components/com_jce/jce.php:23-26), so profiles.import is '
				. 'now unreachable from the site whatever the ACL says.',
			'where_the_real_settings_live' => 'com_jce has NO global file, folder, upload or extension '
				. 'restrictions. Every one of them — browser.dir, browser.extensions, browser.max_size, '
				. 'browser.upload, imgmanager.*, editor.allow_php, editor.validate_mimetype and the '
				. 'rest — is per-profile inside #__wf_profiles.params. Use get_jce_profile_params for '
				. 'one profile, or audit_jce_profiles for all of them at once.',
		];

		return ToolResult::json($response);
	}
}
