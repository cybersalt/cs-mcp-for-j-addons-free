<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjce\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceBootTrait;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceSafetyTrait;
use Joomla\CMS\Access\Access;
use Joomla\CMS\User\User;

/**
 * Hunt for the profile half of a JCE compromise. Pure read, no writes, no
 * side effects.
 *
 * In June 2026 JCE ≤ 2.9.99.4 was mass-exploited through CVE-2026-48907 — CVSS
 * v4 10.0, CISA Known Exploited Vulnerabilities catalogue, public proof of
 * concept. The headline was an unauthenticated file write via
 * `task=profiles.import`, but the variant that matters for this table used the
 * import endpoint simply to INSTALL A MALICIOUS EDITOR PROFILE that re-enabled
 * `php`/`phtml` uploads with MIME validation off, and then uploaded a webshell
 * through JCE's own file browser. In that variant the `#__wf_profiles` row IS
 * the payload.
 *
 * Updating JCE does not remove that row. A site patched in June 2026 may still
 * be carrying the attacker's profile today, unnoticed, because it looks like an
 * ordinary entry in the JCE profile list. This tool looks for it.
 *
 * The observed fingerprints, per the vendor's and mySites.guru's incident
 * guidance:
 *
 *   - machine-generated names such as `J940401`, `J938560`
 *   - absurd negative `ordering`, commonly `-99999`, pinning the profile above
 *     every legitimate one so that first-match-wins picks it
 *   - executable extensions in `browser.extensions` / `imgmanager.extensions`
 *   - `editor.validate_mimetype` = 0
 *   - assignment to Public or to every group, in every component, in both areas
 *
 * None of these is individually proof. Together, and especially with a negative
 * ordering plus a php extension, they are close to it.
 */
final class AuditProfilesTool extends AbstractTool
{
	use JceBootTrait;
	use JceSafetyTrait;

	/**
	 * Names matching the observed machine-generated pattern.
	 *
	 * Kept deliberately tight. A regex loose enough to catch every possible
	 * generated name would flag half the legitimate profiles on a real site,
	 * and a finding nobody trusts is worse than no finding.
	 */
	private const SUSPICIOUS_NAME_PATTERNS = [
		'/^J\d{6}$/'                    => 'Matches the machine-generated name pattern observed in the CVE-2026-48907 campaign (J940401, J938560).',
		'/^[A-Z]\d{5,8}$/'              => 'A single capital letter followed by digits — a generated-looking name with no human meaning.',
		'/^(pwned|hacked|shell|backdoor|test123|admin1)$/i' => 'A blunt attacker label.',
		'/^[a-f0-9]{8,}$/i'             => 'A bare hexadecimal string, typical of an automatically generated identifier.',
	];

	/** `ordering` below this is not something a human sets by hand. */
	private const ORDERING_FLOOR = -100;

	public function getName(): string { return 'audit_jce_profiles'; }

	public function getDescription(): string
	{
		return 'HUNT FOR A COMPROMISED JCE PROFILE. This is the most valuable tool in this add-on and '
			. 'it should be the first one you run on any site you do not already know. It is entirely '
			. 'read-only and touches nothing but #__wf_profiles. '
			. 'WHY IT EXISTS: JCE 1.0.0 – 2.9.99.4 carried CVE-2026-48907 — unauthenticated remote code '
			. 'execution, CVSS v4 10.0, on CISA\'s Known Exploited Vulnerabilities catalogue, '
			. 'mass-exploited from June 2026 with public exploit code. One of the two documented '
			. 'in-the-wild attack paths did not need the file-write bug at all: attackers imported a '
			. 'malicious EDITOR PROFILE that re-enabled php/phtml in the upload filetypes with MIME '
			. 'validation switched off, then uploaded a webshell through JCE\'s own file browser. The '
			. 'profile row was the payload. '
			. 'UPDATING JCE DOES NOT REMOVE THAT ROW. A site patched in June 2026 can still be carrying '
			. 'the attacker\'s profile today, sitting in the JCE profile list looking unremarkable. That '
			. 'is what this tool looks for. '
			. 'INDICATORS CHECKED, per profile: machine-generated-looking names (J940401 and similar); '
			. 'absurd negative `ordering` such as -99999, which pins a profile above every legitimate '
			. 'one so that JCE\'s first-match-wins selection picks it; executable extensions (php, '
			. 'phtml, phar, shtml, asp, jsp, cgi, htaccess and the rest of the vendor\'s own blocklist, '
			. 'in any position including double extensions) in ANY *.extensions param; '
			. 'editor.validate_mimetype disabled; editor.allow_php / allow_javascript / '
			. 'allow_event_attributes enabled; sanitize_html or verify_html disabled; assignment to the '
			. 'Public group or to unauthenticated visitors; over-broad assignment combined with upload '
			. 'and file-management permissions; profiles whose params are encrypted and therefore could '
			. 'not be checked at all. It also reports the installed version against the CVE fix line. '
			. 'WHAT IT CANNOT TELL YOU: this finds the PROFILE half only. These campaigns are layered — '
			. 'webshells on disk, rogue Super User accounts, scheduled tasks, modified .htaccess. A '
			. 'clean result here is NOT a clean bill of health for the site, and the response says so '
			. 'rather than implying otherwise. '
			. 'Read-only. Nothing is modified, and no profile is deleted or disabled.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'include_clean' => ['type' => 'boolean', 'description' => 'Include profiles with no findings in the per-profile output. Default false — only profiles with at least one finding are listed.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->jceAdminBase() === null) {
			return $this->jceNotInstalledError();
		}

		if (!$this->jceTableExists()) {
			return $this->jceMissingTableError();
		}

		$includeClean = (bool) ($arguments['include_clean'] ?? false);

		$query = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName($this->jceTable()))
			->order($this->db->quoteName('ordering') . ' ASC');

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];

		$publicGroups = $this->publicGroupIds();

		$profiles = [];
		$counts   = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0];

		foreach ($rows as $row) {
			$findings = $this->assess($row, $publicGroups);

			foreach ($findings as $finding) {
				$counts[$finding['severity']] = ($counts[$finding['severity']] ?? 0) + 1;
			}

			if ($findings === [] && !$includeClean) {
				continue;
			}

			$profiles[] = [
				'id'        => (int) $row['id'],
				'name'      => (string) $row['name'],
				'description' => (string) $row['description'],
				'published' => (int) $row['published'] === 1,
				'ordering'  => (int) $row['ordering'],
				'area'      => $this->jceAreaLabel((int) $row['area']),
				'user_groups' => $this->jceSplitIntList($row['types'] ?? ''),
				'users'     => $this->jceSplitIntList($row['users'] ?? ''),
				'components' => $this->jceSplitList($row['components'] ?? ''),
				'created'   => $row['created'] ?? null,
				'modified'  => $row['modified'] ?? null,
				'findings'  => $findings,
				'highest_severity' => $findings === [] ? null : $findings[0]['severity'],
			];
		}

		$version   = $this->jceVersion();
		$vulnerable = $version !== null && version_compare($version, '2.9.99.5', '<');

		$verdict = $this->verdict($counts, $vulnerable, $version);

		$response = [
			'ok'             => true,
			'verdict'        => $verdict['verdict'],
			'summary'        => $verdict['summary'],
			'profiles_total' => \count($rows),
			'profiles_with_findings' => \count(array_filter($profiles, static fn ($p) => $p['findings'] !== [])),
			'finding_counts' => $counts,
			'version_check'  => [
				'installed'  => $version,
				'cve'        => 'CVE-2026-48907',
				'fixed_in'   => '2.9.99.5',
				'recommended' => '2.9.99.10',
				'vulnerable' => $vulnerable,
				'finding'    => $this->jceVersionFinding(),
			],
			'profiles'       => $profiles,
			'scope_warning'  => 'THIS TOOL FINDS THE PROFILE HALF ONLY. The CVE-2026-48907 campaigns '
				. 'were layered: attackers typically dropped multiple webshells, created rogue Super '
				. 'User accounts, added scheduled tasks and modified .htaccess, using the editor profile '
				. 'purely as the upload permission. A clean result here means no rogue profile was '
				. 'found. It does NOT mean the site is clean. If this site ran JCE below 2.9.99.5 at any '
				. 'point after 3 June 2026, treat it as compromised until a full file-integrity, user '
				. 'and scheduled-task review says otherwise.',
			'how_to_respond' => [
				'Do not delete a suspicious profile before recording it — the row is evidence, and '
					. 'there is no trash state on this table.',
				'Update JCE to 2.9.99.10 or later FIRST. Removing a rogue profile on an unpatched site '
					. 'simply lets the attacker recreate it.',
				'Then unpublish the profile with set_jce_profile_state before deleting it, so the '
					. 'permission stops immediately while you investigate.',
				'Audit the media directories for files with executable extensions, audit #__users for '
					. 'accounts you do not recognise in the Super Users group, and audit '
					. '#__scheduler_tasks.',
			],
			'component'      => $this->jceEditionNotice(),
		];

		return ToolResult::json($response);
	}

	/**
	 * All findings for one profile, most severe first.
	 *
	 * @param array<string,mixed> $row
	 * @param array<int,int>      $publicGroups
	 * @return array<int,array<string,mixed>>
	 */
	private function assess(array $row, array $publicGroups): array
	{
		$findings = [];

		$name     = (string) $row['name'];
		$ordering = (int) $row['ordering'];
		$types    = $this->jceSplitIntList($row['types'] ?? '');
		$users    = $this->jceSplitIntList($row['users'] ?? '');
		$components = $this->jceSplitList($row['components'] ?? '');
		$published = (int) $row['published'] === 1;

		// ---- name -------------------------------------------------------
		foreach (self::SUSPICIOUS_NAME_PATTERNS as $pattern => $why) {
			if (preg_match($pattern, $name) === 1) {
				$findings[] = [
					'severity' => 'high',
					'code'     => 'suspicious_name',
					'detail'   => sprintf('Profile name "%s" looks machine-generated. %s', $name, $why),
					'context'  => 'JCE ships five seeded profiles — Default, Front End, Blogger, Mobile '
						. 'and Markdown (models/profiles.xml) — and humans name their own profiles after '
						. 'what they do. A name with no meaning is worth explaining.',
				];

				break;
			}
		}

		$description = strtolower((string) $row['description']);

		foreach (['rce', 'shell', 'hack', 'pwn', 'exploit', 'bypass'] as $needle) {
			if ($description !== '' && str_contains($description, $needle)) {
				$findings[] = [
					'severity' => 'high',
					'code'     => 'suspicious_description',
					'detail'   => sprintf('The description contains "%s": %s', $needle, (string) $row['description']),
				];

				break;
			}
		}

		// ---- ordering ---------------------------------------------------
		if ($ordering <= self::ORDERING_FLOOR) {
			$findings[] = [
				'severity' => 'critical',
				'code'     => 'pinned_ordering',
				'detail'   => sprintf(
					'`ordering` is %d. JCE evaluates published profiles in ascending `ordering` order '
						. 'and returns the FIRST match (classes/application.php:367, :461-464), so a '
						. 'large negative value pins this profile above every legitimate one and makes '
						. 'it win for everybody it matches. This is the exact technique used by the '
						. 'CVE-2026-48907 rogue profiles, commonly with -99999. No JCE UI action '
						. 'produces a value like this — new profiles get MAX(ordering)+1 '
						. '(models/profile.php:656-668).',
					$ordering
				),
			];
		} elseif ($ordering < 0) {
			$findings[] = [
				'severity' => 'medium',
				'code'     => 'negative_ordering',
				'detail'   => sprintf(
					'`ordering` is %d. Negative values are legal but are never produced by JCE itself, '
						. 'which assigns MAX(ordering)+1 to new profiles. This profile is evaluated '
						. 'before every normally ordered one.',
					$ordering
				),
			];
		}

		// ---- params -----------------------------------------------------
		$decoded = $this->jceDecodeParams($row['params'] ?? '');

		if (!$decoded['ok']) {
			$findings[] = [
				'severity' => $decoded['encrypted'] ? 'info' : 'medium',
				'code'     => $decoded['encrypted'] ? 'params_encrypted' : 'params_corrupt',
				'detail'   => $decoded['encrypted']
					? 'This profile\'s params are encrypted with the legacy ' . $decoded['algorithm']
						. ' scheme, so THEY COULD NOT BE AUDITED. Its upload filetypes, directory roots '
						. 'and content-filtering flags are unknown to this scan. Encryption itself is '
						. 'not suspicious — it is what old JCE versions did — but it is a blind spot. '
						. 'Open and save the profile once in the JCE admin UI to decrypt it in place, '
						. 'then re-run this audit.'
					: 'This profile\'s params column is neither valid JSON nor a recognised JCE '
						. 'ciphertext, so it could not be audited: ' . (string) $decoded['error'],
			];
		} else {
			foreach ($this->assessParams($decoded['params']) as $finding) {
				$findings[] = $finding;
			}
		}

		// ---- assignment breadth -----------------------------------------
		$publicHit = array_values(array_intersect($types, $publicGroups));

		if ($publicHit !== []) {
			$findings[] = [
				'severity' => $this->jceAllowProfileGuests() ? 'critical' : 'high',
				'code'     => 'assigned_to_public',
				'detail'   => sprintf(
					'This profile is assigned to the Public group (id %s), which every visitor belongs '
						. 'to, authenticated or not. %s',
					implode(', ', $publicHit),
					$this->jceAllowProfileGuests()
						? 'The com_jce parameter allow_profile_guests is ALSO ON, so unauthenticated '
							. 'visitors genuinely receive this profile. Combined with any upload '
							. 'permission this is an unauthenticated upload endpoint.'
						: 'The com_jce parameter allow_profile_guests is off, so guests are rejected '
							. 'before matching (application.php:331-337) and only logged-in users are '
							. 'affected — but every logged-in user, of every group, is.'
				),
			];
		}

		if ($published && $types !== [] && $components === [] && (int) $row['area'] === 0
			&& \count($types) >= 5) {
			$findings[] = [
				'severity' => 'low',
				'code'     => 'broad_assignment',
				'detail'   => sprintf(
					'Published, assigned to %d user groups, restricted to no component and to neither '
						. 'area (area 0 matches both). That is the widest possible scope. It is also '
						. 'exactly how JCE\'s own seeded Default profile is configured, so this is '
						. 'context rather than an accusation — it matters only alongside a permissive '
						. 'params set.',
					\count($types)
				),
			];
		}

		if ($types === [] && $users === []) {
			$findings[] = [
				'severity' => 'info',
				'code'     => 'dead_profile',
				'detail'   => 'Both `types` and `users` are empty, so JCE skips this profile entirely '
					. 'during matching (application.php:394). It applies to nobody. Not a security '
					. 'problem, but it means the profile is doing nothing and may not be what someone '
					. 'thinks it is.',
			];
		}

		// Sort by severity, worst first.
		$rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'info' => 4];

		usort($findings, static fn ($a, $b) => ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9));

		return $findings;
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<int,array<string,mixed>>
	 */
	private function assessParams(array $params): array
	{
		$findings = [];
		$flat     = $this->jceFlattenParams($params);

		foreach ($flat as $path => $value) {
			if (!\is_scalar($value) && $value !== null) {
				continue;
			}

			if (str_ends_with($path, 'extensions')) {
				$tokens = $this->jceExtractExtensionTokens($value);
				$bad    = [];

				foreach ($tokens as $token) {
					foreach (array_filter(explode('.', $token), static fn ($s) => $s !== '') as $segment) {
						if (\in_array($segment, $this->jceExecutableExtensions(), true)
							|| preg_match('/^php\d+$/i', $segment) === 1) {
							$bad[] = $token;

							break;
						}
					}
				}

				if ($bad !== []) {
					$findings[] = [
						'severity' => 'critical',
						'code'     => 'executable_upload_extension',
						'detail'   => sprintf(
							'%s permits executable extension(s): %s. Everyone this profile applies to '
								. 'can upload a file with one of these through JCE\'s file browser, and '
								. 'a .php file in the webroot is remote code execution. This is the '
								. 'documented CVE-2026-48907 profile payload verbatim. If nobody '
								. 'deliberately configured this, treat the site as compromised NOW.',
							$path,
							implode(', ', array_unique($bad))
						),
						'value'    => (string) $value,
					];
				}

				$risky = array_values(array_intersect($tokens, ['svg', 'html', 'htm']));

				if ($risky !== []) {
					$findings[] = [
						'severity' => 'low',
						'code'     => 'risky_upload_extension',
						'detail'   => sprintf(
							'%s permits %s. JCE blocks these by default and allows them only as a final '
								. 'extension when the profile permits them (utility.php:1382-1386), '
								. 'which this does. SVG is sanitised where the sanitiser library is '
								. 'available; an uploaded HTML file is served as-is and is a stored-XSS '
								. 'and phishing vector on your own domain.',
							$path,
							implode(', ', $risky)
						),
					];
				}

				continue;
			}

			if ($path === 'editor.validate_mimetype' && $this->jceNormaliseFlag($value) === '0') {
				$findings[] = [
					'severity' => 'high',
					'code'     => 'mime_validation_disabled',
					'detail'   => 'editor.validate_mimetype is OFF. JCE then skips WFMimeType::check() '
						. 'during upload validation (classes/browser.php:1965-2028), so a file whose '
						. 'contents do not match its extension passes. Turning this off is the second '
						. 'half of the documented CVE-2026-48907 profile payload — the first half being '
						. 'an executable extension in the filetype list.',
				];
			}

			foreach (['editor.allow_php' => 'raw PHP', 'editor.allow_javascript' => 'inline JavaScript',
				'editor.allow_event_attributes' => 'event attributes such as onclick and onerror'] as $key => $what) {
				if ($path === $key && $this->jceNormaliseFlag($value) === '1') {
					$findings[] = [
						'severity' => $key === 'editor.allow_php' ? 'critical' : 'high',
						'code'     => 'content_filtering_relaxed',
						'detail'   => sprintf(
							'%s is ON, permitting %s in editor content for everyone this profile '
								. 'applies to. On a profile assigned to Author, Editor or Publisher '
								. 'this is a privilege escalation to whatever that content is rendered '
								. 'with.',
							$key,
							$what
						),
					];
				}
			}

			foreach (['editor.sanitize_html', 'editor.verify_html'] as $key) {
				if ($path === $key && $this->jceNormaliseFlag($value) === '0') {
					$findings[] = [
						'severity' => 'medium',
						'code'     => 'html_filtering_disabled',
						'detail'   => $key . ' is OFF for this profile, so content is not passed through '
							. ($key === 'editor.sanitize_html' ? 'HTML Purifier' : 'the element schema validator')
							. '. Legitimate on a profile restricted to trusted administrators; a finding '
							. 'on anything wider.',
					];
				}
			}

			if (preg_match('/(^|\.)dir$/', $path) === 1 && \is_string($value)) {
				$trimmed = trim($value);

				if (str_contains($trimmed, '..') || $trimmed === '/' || str_starts_with($trimmed, '/')) {
					$findings[] = [
						'severity' => 'critical',
						'code'     => 'directory_escape',
						'detail'   => sprintf(
							'%s is "%s" — an absolute or traversing path. JCE enforces browse and '
								. 'upload boundaries against this stored value, so this widens the file '
								. 'browser beyond the media root. No JCE UI produces a value like this.',
							$path,
							$value
						),
					];
				}
			}
		}

		return $findings;
	}

	/**
	 * @param array<string,int> $counts
	 * @return array{verdict:string,summary:string}
	 */
	private function verdict(array $counts, bool $vulnerable, ?string $version): array
	{
		if (($counts['critical'] ?? 0) > 0) {
			return [
				'verdict' => 'CRITICAL — act now',
				'summary' => sprintf(
					'%d critical finding(s). At least one profile permits executable uploads, pins '
						. 'itself above every other profile, or escapes the media root. These are the '
						. 'signatures of the CVE-2026-48907 compromise. Do not delete anything yet — '
						. 'record the profile, update JCE, unpublish the profile, then investigate the '
						. 'rest of the site.',
					$counts['critical']
				),
			];
		}

		if ($vulnerable) {
			return [
				'verdict' => 'CRITICAL — vulnerable version',
				'summary' => sprintf(
					'No rogue profile was found, but this site runs JCE %s, below the 2.9.99.5 fix for '
						. 'CVE-2026-48907 (CVSS 10.0, CISA KEV, mass-exploited). It is exploitable right '
						. 'now regardless of how clean the profiles look. Update first, then re-run this '
						. 'audit.',
					(string) $version
				),
			];
		}

		if (($counts['high'] ?? 0) > 0) {
			return [
				'verdict' => 'HIGH — investigate',
				'summary' => sprintf(
					'%d high-severity finding(s): suspicious naming, MIME validation disabled, relaxed '
						. 'content filtering, or a profile reaching the Public group. Each needs an '
						. 'explanation. Any of them could be a deliberate configuration; none of them '
						. 'should be a surprise.',
					$counts['high']
				),
			];
		}

		if (($counts['medium'] ?? 0) + ($counts['low'] ?? 0) > 0) {
			return [
				'verdict' => 'REVIEW',
				'summary' => 'No indicator of compromise was found. There are lower-severity findings '
					. 'worth reading — usually a broad assignment or a permissive-but-plausible setting.',
			];
		}

		return [
			'verdict' => 'NO PROFILE INDICATORS FOUND',
			'summary' => 'No rogue-profile indicators were detected and the installed version is past '
				. 'the CVE-2026-48907 fix line. Read the scope_warning: this checks profiles only, and '
				. 'the campaigns that abused this table were layered.',
		];
	}

	/** @return array<int,int> */
	private function publicGroupIds(): array
	{
		$ids = [];

		// The Public group is the root of the group tree — parent_id 0 — rather
		// than a hardcoded id 1, which a reorganised site may not use.
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName('id'))
			->from($this->db->quoteName('#__usergroups'))
			->where($this->db->quoteName('parent_id') . ' = 0');

		foreach ($this->db->setQuery($query)->loadColumn() ?: [] as $id) {
			$ids[] = (int) $id;
		}

		// Whatever groups an unauthenticated visitor actually holds.
		foreach (Access::getGroupsByUser(0, true) ?: [] as $id) {
			$ids[] = (int) $id;
		}

		return array_values(array_unique($ids));
	}
}
