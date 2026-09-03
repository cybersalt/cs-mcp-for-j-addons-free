<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjce\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;

/**
 * The refusals. Read this before changing anything in a write tool.
 *
 * ---------------------------------------------------------------------------
 * WHY A JCE PROFILE WRITER NEEDS A SAFETY LAYER AT ALL
 * ---------------------------------------------------------------------------
 *
 * CVE-2026-48907 is an unauthenticated remote-code-execution flaw in JCE
 * 1.0.0 – 2.9.99.4. CVSS v4 10.0, on CISA's Known Exploited Vulnerabilities
 * catalogue, mass-exploited from June 2026 with public proof-of-concept code.
 * It was fixed in 2.9.99.5 (3 June 2026) and hardened further in 2.9.99.6,
 * .7 and .10.
 *
 * The part that matters here is the SECOND exploitation path. The headline bug
 * was an unauthenticated file write through `task=profiles.import`, but
 * attackers also used the import endpoint simply to install a malicious editor
 * profile that re-enabled `php`/`phtml` in the upload filetypes with MIME
 * validation switched off, and then uploaded a webshell through JCE's own file
 * browser. In that variant the profile row IS the payload.
 *
 * `#__wf_profiles` is the exact table these tools write to. So:
 *
 *   1. There is NO profile-import tool in this add-on, and there never will be.
 *      A tool that accepts a whole profile blob and writes it is a
 *      reimplementation of the exploit primitive, however carefully validated.
 *      Every write here is a named, field-level setter instead.
 *
 *   2. Executable extensions are HARD-REFUSED in any write that touches an
 *      `*.extensions` param. The whole call is refused with an explanation. We
 *      never strip the offending entries and proceed, because a caller told
 *      "done" would reasonably believe it got what it asked for.
 *
 *   3. Writes are refused outright below 2.9.99.5. On such a site a profile
 *      write is indistinguishable from the live attack, and the site is
 *      compromised-until-proven-otherwise anyway. Reads still work, because
 *      reading is how you find out what happened.
 *
 *   4. Content-security flags can be tightened but not loosened. Turning
 *      `editor.allow_php` on, or `editor.validate_mimetype` off, is a
 *      privilege-escalation change with no legitimate automation use case.
 *
 * These refusals are not configurable. There is deliberately no `force` flag.
 */
trait JceSafetyTrait
{
	/**
	 * The version at which CVE-2026-48907 was fixed.
	 *
	 * Note the caveat: the vendor also published a standalone patch for 2.7.x /
	 * 2.8.x / 2.9.x sites that could not upgrade. It closes the hole without any
	 * of the later hardening, so a patched-but-old site reports an old version
	 * string while actually being safe from this one CVE. We cannot detect that,
	 * so we go by version and say so.
	 */
	private const CVE_FIX_VERSION = '2.9.99.5';

	/** Current recommended floor. Below this, CVE-2026-65891 is unpatched. */
	private const RECOMMENDED_VERSION = '2.9.99.10';

	/**
	 * Extensions that must never be added to an upload filetype list.
	 *
	 * This is the vendor's own blocklist from
	 * `components/com_jce/editor/libraries/classes/utility.php:1353-1368`,
	 * reproduced verbatim so the two cannot drift apart, MINUS `svg`, `html` and
	 * `htm`. Those three are blocked by JCE at upload time by default but are
	 * explicitly allowed as a final extension when the profile permits them
	 * (`utility.php:1382-1386`), so they are a legitimate — if risky — profile
	 * setting. We warn about them rather than refusing.
	 */
	private const EXECUTABLE_EXTENSIONS = [
		// PHP, covering every handler mapping seen in the wild
		'php', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'pht', 'phtm', 'phtml', 'phps',
		'phpt', 'phar', 'pgif',
		// other server-side languages and script hosts
		'asp', 'aspx', 'asa', 'asax', 'cer', 'jsp', 'jspx', 'cgi', 'pl', 'perl', 'py', 'java',
		'go', 'js', 'jse', 'vb', 'vbe', 'vbs', 'wsc', 'wsf', 'wsh', 'sct', 'inc',
		// server-parsed pages / SSI
		'shtml', 'shtm', 'stm',
		// native executables and libraries
		'exe', 'dll', 'com', 'bat', 'cmd', 'scr', 'pif', 'cpl', 'msc', 'msp', 'mst', 'hta',
		'chm', 'sys', 'vxd', 'lib', 'shb', 'ade', 'adp', 'ins', 'isp', 'mde',
		// server configuration
		'htaccess', 'htpasswd', 'ini',
	];

	/** Risky but legitimate. Warn, do not refuse. */
	private const WARNED_EXTENSIONS = ['svg', 'html', 'htm', 'xml', 'swf'];

	/**
	 * Params that may only ever move towards "safer".
	 *
	 * `safe_value` is the value that represents the hardened state. A write that
	 * moves a key away from it is refused; a write that moves it towards it, or
	 * leaves it alone, is allowed.
	 */
	private const ONE_WAY_PARAMS = [
		'editor.allow_php'              => ['safe' => '0', 'meaning' => 'permit raw PHP in editor content'],
		'editor.allow_javascript'       => ['safe' => '0', 'meaning' => 'permit inline <script> in editor content'],
		'editor.allow_event_attributes' => ['safe' => '0', 'meaning' => 'permit onclick/onerror-style attributes'],
		'editor.allow_custom_xml'       => ['safe' => '0', 'meaning' => 'permit arbitrary XML processing instructions'],
		'editor.validate_mimetype'      => ['safe' => '1', 'meaning' => 'verify an upload\'s real MIME type'],
		'editor.sanitize_html'          => ['safe' => '1', 'meaning' => 'run content through HTML Purifier'],
		'editor.verify_html'            => ['safe' => '1', 'meaning' => 'validate content against the element schema'],
	];

	// -----------------------------------------------------------------------
	// Version gate
	// -----------------------------------------------------------------------

	/**
	 * Refuse every write on a JCE that predates the CVE-2026-48907 fix.
	 *
	 * Also refuses when the version cannot be determined at all. "Unknown" is
	 * not "fine" — an unreadable manifest on a security-critical component is
	 * itself a finding.
	 */
	protected function jceRequireWritableVersion(): ?ToolResult
	{
		$version = $this->jceVersion();

		if ($version === null) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Refusing to write: the installed JCE version could not be determined from '
					. 'administrator/components/com_jce/jce.xml or includes/constants.php. JCE below '
					. self::CVE_FIX_VERSION . ' carries CVE-2026-48907, an unauthenticated remote code '
					. 'execution flaw (CVSS 10.0, CISA Known Exploited Vulnerabilities catalogue, '
					. 'mass-exploited from June 2026) whose payload is a row in this very table. Without '
					. 'a version this add-on cannot establish that a write is safe, so it refuses. Reads '
					. 'still work — start with audit_jce_profiles.',
				'cve'   => 'CVE-2026-48907',
				'component' => $this->jceEditionNotice(),
			], true);
		}

		if (version_compare($version, self::CVE_FIX_VERSION, '>=')) {
			return null;
		}

		return ToolResult::json([
			'ok'    => false,
			'error' => sprintf(
				'Refusing to write. This site runs JCE %s, which is below %s and therefore carries '
					. 'CVE-2026-48907: unauthenticated remote code execution, CVSS v4 10.0, on CISA\'s '
					. 'Known Exploited Vulnerabilities catalogue, mass-exploited from June 2026 with '
					. 'public exploit code. One of the two documented in-the-wild attack paths consists '
					. 'of writing a #__wf_profiles row that permits .php uploads and then uploading a '
					. 'webshell — the same table and the same operation this tool performs. A write here '
					. 'would be indistinguishable from the attack in any log or forensic timeline.',
				$version,
				self::CVE_FIX_VERSION
			),
			'cve'          => 'CVE-2026-48907',
			'fixed_in'     => self::CVE_FIX_VERSION,
			'recommended'  => self::RECOMMENDED_VERSION,
			'what_to_do'   => 'Update JCE to ' . self::RECOMMENDED_VERSION . ' or later, then run '
				. 'audit_jce_profiles BEFORE trusting the site. A site that was reachable on this CVE '
				. 'between June 2026 and the update may still be carrying the attacker\'s editor '
				. 'profile, and updating does not remove it.',
			'caveat'       => 'The vendor also shipped a standalone patch for 2.7.x/2.8.x/2.9.x sites '
				. 'that could not upgrade. It closes this CVE without the later hardening, and it does '
				. 'not change the version string, so a patched site still reports an old version and '
				. 'still gets refused here. Upgrading properly is the fix.',
			'reads_still_work' => true,
			'component'    => $this->jceEditionNotice(),
		], true);
	}

	/**
	 * Version findings for a diagnostic response. Null when nothing to say.
	 *
	 * @return array<string,mixed>|null
	 */
	protected function jceVersionFinding(): ?array
	{
		$version = $this->jceVersion();

		if ($version === null) {
			return [
				'severity' => 'warning',
				'message'  => 'The installed JCE version could not be determined, so no security '
					. 'assessment is possible and all write tools will refuse.',
			];
		}

		if (version_compare($version, self::CVE_FIX_VERSION, '<')) {
			return [
				'severity' => 'critical',
				'cve'      => 'CVE-2026-48907',
				'message'  => sprintf(
					'JCE %s is below %s and carries CVE-2026-48907 — unauthenticated remote code '
						. 'execution, CVSS v4 10.0, CISA Known Exploited Vulnerabilities catalogue, '
						. 'mass-exploited from June 2026. Treat this site as ALREADY COMPROMISED rather '
						. 'than merely at risk: update, then run audit_jce_profiles, then audit for '
						. 'webshells, rogue Super Users and cron jobs, because these campaigns are '
						. 'layered and the profile is only one of the persistence mechanisms. All write '
						. 'tools in this add-on refuse while the version is below %s.',
					$version,
					self::CVE_FIX_VERSION,
					self::CVE_FIX_VERSION
				),
			];
		}

		if (version_compare($version, self::RECOMMENDED_VERSION, '<')) {
			return [
				'severity' => 'notice',
				'cve'      => 'CVE-2026-65891',
				'message'  => sprintf(
					'JCE %s is patched against CVE-2026-48907 but is below the recommended %s. '
						. '2.9.99.10 fixed CVE-2026-65891, a low-severity issue where an authenticated '
						. 'user holding file-browser access plus the Rename permission could rename a '
						. 'file so that it becomes hidden in the browsed folder. Concealment, not '
						. 'execution. It can be mitigated in the meantime by turning off the '
						. 'browser.file_rename / imgmanager.file_rename profile flags.',
					$version,
					self::RECOMMENDED_VERSION
				),
			];
		}

		return null;
	}

	// -----------------------------------------------------------------------
	// Upload filetype lists
	// -----------------------------------------------------------------------

	/** @return array<int,string> */
	protected function jceExecutableExtensions(): array
	{
		return self::EXECUTABLE_EXTENSIONS;
	}

	/**
	 * Flatten a JCE filetype list into its extension tokens.
	 *
	 * The value is not always a plain comma list. `WFUtility::formatFileTypesList()`
	 * (`classes/utility.php:1532-1570`) splits on `;` into groups, allows an
	 * optional `type=` prefix per group, and treats a leading `-` as an
	 * exclusion — both on a whole group and on an individual item. So
	 * `images=jpg,png;media=mp4` and `doc,pdf,-txt` are both valid, and a naive
	 * `explode(',')` would both miss real entries and flag exclusions as
	 * inclusions.
	 *
	 * @return array<int,string> Lower-cased, inclusion-only tokens.
	 */
	protected function jceExtractExtensionTokens(mixed $value): array
	{
		$tokens = [];

		foreach (explode(';', (string) $value) as $group) {
			$group = trim($group);

			// A whole group prefixed with '-' is an exclusion group: skipped.
			if ($group === '' || (str_contains($group, '=') && str_starts_with($group, '-'))) {
				continue;
			}

			$parts = explode('=', $group);
			$items = array_pop($parts);

			foreach (explode(',', (string) $items) as $item) {
				$item = strtolower(trim($item, " \t\n\r\0\x0B.:;"));

				// A leading '-' excludes rather than includes.
				if ($item === '' || str_starts_with($item, '-')) {
					continue;
				}

				$tokens[] = $item;
			}
		}

		return array_values(array_unique($tokens));
	}

	/**
	 * Hard-refuse a filetype list containing an executable extension.
	 *
	 * This is the single most important check in the add-on. Refuse the WHOLE
	 * call — never silently strip and proceed.
	 *
	 * @param array<string,mixed> $extra Extra keys to merge into the refusal.
	 */
	protected function jceAssertNoExecutableExtensions(string $path, mixed $value, array $extra = []): ?ToolResult
	{
		$tokens = $this->jceExtractExtensionTokens($value);
		$found  = [];

		foreach ($tokens as $token) {
			// Double-extension shapes such as `php.jpg` or `jpg.php` never belong
			// in a filetype list at all, and are how the 2011/2012 mass-exploit
			// wave defeated naive final-extension checks.
			$segments = array_filter(explode('.', $token), static fn ($s) => $s !== '');

			foreach ($segments as $segment) {
				if (\in_array($segment, self::EXECUTABLE_EXTENSIONS, true)
					|| preg_match('/^php\d+$/i', $segment) === 1) {
					$found[] = $token;

					break;
				}
			}
		}

		$found = array_values(array_unique($found));

		if ($found === []) {
			return null;
		}

		return ToolResult::json(array_merge([
			'ok'    => false,
			'error' => sprintf(
				'REFUSED. The value for "%s" contains executable extension(s): %s. Adding an executable '
					. 'extension to a JCE upload filetype list is precisely the in-the-wild exploitation '
					. 'of CVE-2026-48907: attackers wrote a #__wf_profiles row permitting .php uploads and '
					. 'then uploaded a webshell through JCE\'s own file browser. This add-on refuses that '
					. 'write unconditionally, on every version, for every caller. There is no override '
					. 'flag and there will not be one.',
				$path,
				implode(', ', $found)
			),
			'refused_extensions' => $found,
			'submitted_tokens'   => $tokens,
			'why_not_stripped'   => 'The whole call is refused rather than the offending entries being '
				. 'removed, because a caller told the write succeeded would reasonably assume it got the '
				. 'list it asked for. Silent narrowing of a security setting is its own bug.',
			'blocklist_source'   => 'The list is the vendor\'s own, from WFUtility::validateFileName() at '
				. 'components/com_jce/editor/libraries/classes/utility.php:1353-1368, so the two cannot '
				. 'drift apart. JCE blocks these at upload time in ANY position of the filename, which is '
				. 'what defeats image.php.jpg — but it does that check against the profile value, so a '
				. 'bad profile value is the vulnerability rather than something the check protects you '
				. 'from.',
			'legitimate_alternative' => 'If the goal is to let editors upload a genuinely needed file '
				. 'type, add only that type. If the goal is code, it does not belong in a media library.',
		], $extra), true);
	}

	/**
	 * Non-fatal notes about a filetype list. Include in the response of any
	 * accepted `*.extensions` write.
	 *
	 * @return array<int,string>
	 */
	protected function jceExtensionWarnings(string $path, mixed $value): array
	{
		$warnings = [];
		$tokens   = $this->jceExtractExtensionTokens($value);
		$risky    = array_values(array_intersect($tokens, self::WARNED_EXTENSIONS));

		if ($risky !== []) {
			$warnings[] = sprintf(
				'%s permits %s. JCE blocks svg, html and htm at upload time by default and allows them '
					. 'only as the final extension and only when the profile permits them '
					. '(utility.php:1382-1386) — which this value now does. SVG is sanitised through '
					. 'enshrined/svg-sanitize when that library is available and a DOMDocument fallback '
					. 'otherwise (utility.php:1200+), but an uploaded HTML file is served as-is and is a '
					. 'stored-XSS and phishing vector on your own domain.',
				$path,
				implode(', ', $risky)
			);
		}

		if ($tokens === []) {
			$warnings[] = sprintf(
				'%s is now empty. An empty extensions list is not "allow everything" — '
					. 'WFFileBrowser::validateUploadedFile() rejects any upload whose extension is not in '
					. 'the list, so this disables uploading through that plugin entirely. That may be what '
					. 'you want; it is stated here so it is not a surprise.',
				$path
			);
		}

		return $warnings;
	}

	// -----------------------------------------------------------------------
	// One-way security flags
	// -----------------------------------------------------------------------

	/**
	 * Refuse a params write that loosens a content-security control.
	 *
	 * Tightening is always allowed. This asymmetry is deliberate: there is no
	 * automation scenario in which an agent needs to switch on raw PHP in
	 * editor content or switch off MIME validation, and both are direct
	 * privilege-escalation levers on a profile that low-privileged groups can
	 * reach.
	 *
	 * @param array<string,mixed> $changes Dotted path => new value.
	 */
	protected function jceAssertNotWeakening(array $changes): ?ToolResult
	{
		$refused = [];

		foreach ($changes as $path => $value) {
			$rule = self::ONE_WAY_PARAMS[$path] ?? null;

			if ($rule === null) {
				continue;
			}

			// Normalise the way JCE does: these are radio fields storing '0'/'1'
			// as strings, but a caller may reasonably send a boolean or an int.
			$normalised = $this->jceNormaliseFlag($value);

			if ($normalised === $rule['safe']) {
				continue;
			}

			$refused[] = [
				'path'         => $path,
				'requested'    => $normalised,
				'safe_value'   => $rule['safe'],
				'what_it_does' => $rule['meaning'],
			];
		}

		if ($refused === []) {
			return null;
		}

		return ToolResult::json([
			'ok'    => false,
			'error' => sprintf(
				'REFUSED. This write would loosen %d content-security control(s) on a JCE profile: %s. '
					. 'These tools will tighten these settings but never loosen them. Flipping '
					. 'editor.allow_php on for a profile assigned to Author gives every Author PHP '
					. 'execution inside content; turning editor.validate_mimetype off removes the MIME '
					. 'check that stops a renamed webshell — that exact combination is the documented '
					. 'in-the-wild CVE-2026-48907 profile payload.',
				\count($refused),
				implode(', ', array_column($refused, 'path'))
			),
			'refused'     => $refused,
			'policy'      => 'One-way: any of these may be moved to its hardened value through '
				. 'set_jce_profile_params, but not away from it. There is no override flag.',
			'if_you_must' => 'Make the change in the JCE admin UI, where it is a deliberate human act '
				. 'with a visible audit trail, rather than a side effect of an automated call.',
		], true);
	}

	/** Normalise a yes/no param to the '0'/'1' strings JCE stores. */
	protected function jceNormaliseFlag(mixed $value): string
	{
		if (\is_bool($value)) {
			return $value ? '1' : '0';
		}

		$raw = strtolower(trim((string) $value));

		return \in_array($raw, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
	}

	// -----------------------------------------------------------------------
	// Directory values
	// -----------------------------------------------------------------------

	/**
	 * Refuse a `dir` value that escapes or widens the media root.
	 *
	 * JCE validates paths at browse/upload time using this value, so a bad value
	 * here IS the vulnerability rather than something JCE's own guards catch.
	 * `$variables`-style tokens (`$id`, `$username`, `$usertype`) are legitimate
	 * and are left alone.
	 */
	protected function jceAssertSafeDirectory(string $path, string $value): ?ToolResult
	{
		$trimmed = trim($value);
		$reason  = null;

		if (str_contains($trimmed, '..')) {
			$reason = 'it contains "..", which traverses out of the media root';
		} elseif ($trimmed === '/' || $trimmed === '\\') {
			$reason = 'it is the filesystem root, which would expose the entire site to the file browser';
		} elseif (preg_match('#^[a-zA-Z]:[\\\\/]#', $trimmed) === 1) {
			$reason = 'it is an absolute Windows path';
		} elseif (str_starts_with($trimmed, '/') && !str_starts_with($trimmed, '//')) {
			$reason = 'it is an absolute path. JCE `dir` values are relative to the filesystem adapter\'s '
				. 'own root (JPATH_SITE for the default `joomla` filesystem)';
		} elseif (str_contains($trimmed, "\0")) {
			$reason = 'it contains a null byte';
		}

		if ($reason === null) {
			return null;
		}

		return ToolResult::json([
			'ok'    => false,
			'error' => sprintf('REFUSED. The value for "%s" was rejected because %s.', $path, $reason),
			'submitted' => $value,
			'expected'  => 'A path relative to the filesystem adapter root, e.g. "images" or '
				. '"images/clients". JCE also supports the $id, $username and $usertype variables, which '
				. 'are permitted here.',
			'why'       => 'JCE validates browse and upload paths at request time against this stored '
				. 'value (WFFileBrowser::checkPathAccess(), classes/browser.php:872). A traversing or '
				. 'root-level value is therefore not caught later — it becomes the boundary.',
		], true);
	}

	// -----------------------------------------------------------------------
	// Assignment guards
	// -----------------------------------------------------------------------

	/**
	 * Refuse an assignment that would silently disable the profile.
	 *
	 * `WFApplication::getProfiles()` skips any row where both `types` and
	 * `users` are empty (`classes/application.php:394`). The row stays
	 * `published = 1` and looks perfectly healthy in the JCE list view while
	 * matching nobody — the worst kind of failure, because there is nothing to
	 * notice.
	 *
	 * @param array<int,int> $types
	 * @param array<int,int> $users
	 */
	protected function jceAssertProfileReachable(array $types, array $users): ?ToolResult
	{
		if ($types !== [] || $users !== []) {
			return null;
		}

		return ToolResult::json([
			'ok'    => false,
			'error' => 'REFUSED. This would leave both `types` (user groups) and `users` empty. JCE skips '
				. 'such a profile entirely during matching — classes/application.php:394 does '
				. '`if (empty($item->types) && empty($item->users)) continue;` — so the profile would '
				. 'still show as published in the JCE admin list while silently matching nobody. If the '
				. 'intent is to switch the profile off, use set_jce_profile_state, which is honest about '
				. 'it and is reversible.',
		], true);
	}

	/**
	 * Warn when requested groups will be silently dropped by the component's
	 * own whitelist.
	 *
	 * `profile_groups_whitelist` was added in 2.9.99.7 as part of the
	 * CVE-2026-48907 remediation. `models/profile.php:612-616` intersects
	 * incoming `types` with it and reports nothing, so a write appears to
	 * succeed and produces a different result than requested.
	 *
	 * @param array<int,int> $requested
	 * @return array{effective:array<int,int>,dropped:array<int,int>,warning:?string}
	 */
	protected function jceApplyGroupsWhitelist(array $requested): array
	{
		$whitelist = $this->jceGroupsWhitelist();

		if ($whitelist === []) {
			return ['effective' => $requested, 'dropped' => [], 'warning' => null];
		}

		$effective = array_values(array_intersect($requested, $whitelist));
		$dropped   = array_values(array_diff($requested, $whitelist));

		return [
			'effective' => $effective,
			'dropped'   => $dropped,
			'warning'   => $dropped === [] ? null : sprintf(
				'The com_jce component parameter profile_groups_whitelist is set, and group(s) %s are '
					. 'not in it. JCE intersects incoming types with that whitelist on every save '
					. '(models/profile.php:612-616) and reports nothing, so those groups have been '
					. 'dropped from this write. The whitelist is a deliberate hard cap added in 2.9.99.7 '
					. 'as part of the CVE-2026-48907 remediation — widen it in JCE\'s own Preferences '
					. 'screen if that is genuinely intended.',
				implode(', ', $dropped)
			),
		];
	}
}
