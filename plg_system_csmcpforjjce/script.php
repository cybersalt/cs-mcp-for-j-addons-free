<?php

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;

class PlgSystemCsmcpforjjceInstallerScript implements InstallerScriptInterface
{
	/** The version that fixed CVE-2026-48907. Below this, all write tools refuse. */
	private const CVE_FIX_VERSION = '2.9.99.5';

	/** Current recommended floor. Below this, CVE-2026-65891 is unpatched. */
	private const RECOMMENDED_VERSION = '2.9.99.10';

	public function install(InstallerAdapter $adapter): bool   { return true; }
	public function update(InstallerAdapter $adapter): bool    { return true; }
	public function uninstall(InstallerAdapter $adapter): bool { return true; }
	public function preflight(string $type, InstallerAdapter $adapter): bool { return true; }

	public function postflight(string $type, InstallerAdapter $adapter): bool
	{
		if (!in_array($type, ['install', 'update', 'discover_install'], true)) {
			return true;
		}

		$app = Factory::getApplication();

		try {
			$db    = Factory::getContainer()->get(DatabaseInterface::class);
			$query = $db->getQuery(true)
				->update($db->quoteName('#__extensions'))
				->set($db->quoteName('enabled') . ' = 1')
				->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
				->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
				->where($db->quoteName('element') . ' = ' . $db->quote('csmcpforjjce'));
			$db->setQuery($query)->execute();
		} catch (\Throwable $e) {
			$app->enqueueMessage(
				'csmcpforjjce auto-enable failed: ' . $e->getMessage(),
				'warning'
			);
		}

		$app->enqueueMessage(
			'MCP add-on for JCE installed and active. 21 new tools are exposed to your connected MCP '
			. 'clients: editor profiles, their assignment to user groups and components, the toolbar '
			. 'layout, the per-plugin configuration blob, the plugin catalogue, and a read-only '
			. 'security audit. It works on both the free and Pro editions and reports which it found. '
			. 'There is no separate admin UI for this plugin by design — the tools appear in your MCP '
			. 'client automatically.',
			'message'
		);

		$base = JPATH_ADMINISTRATOR . '/components/com_jce';

		if (!is_dir($base)) {
			$app->enqueueMessage(
				'JCE (com_jce) is not installed on this site yet, so these tools will report "not '
				. 'installed" until it is. The free edition is enough for everything in this add-on.',
				'notice'
			);

			return true;
		}

		$version = $this->readVersion($base);

		$this->reportEdition($app, $version);
		$this->warnOnVulnerableVersion($app, $version);
		$this->auditHint($app);

		return true;
	}

	/**
	 * Version from the component manifest, falling back to the WF_VERSION
	 * constant, which is a plain literal in includes/constants.php:16.
	 */
	private function readVersion(string $base): ?string
	{
		$manifest = $base . '/jce.xml';

		if (is_file($manifest)) {
			$xml = @simplexml_load_file($manifest);

			if ($xml !== false) {
				$version = trim((string) ($xml->version ?? ''));

				if ($version !== '') {
					return $version;
				}
			}
		}

		$constants = $base . '/includes/constants.php';

		if (is_file($constants)) {
			$body = (string) @file_get_contents($constants);

			if (preg_match("/define\(\s*'WF_VERSION'\s*,\s*'([^']+)'/", $body, $m) === 1) {
				return $m[1];
			}
		}

		return null;
	}

	/**
	 * Say which edition is present.
	 *
	 * The Pro gate is one call in one place — models/cpanel.php:127-131 — and
	 * there is no licence-key check at runtime anywhere in the component.
	 */
	private function reportEdition(object $app, ?string $version): void
	{
		$label = $version === null ? '' : ' ' . $version;

		if (PluginHelper::isEnabled('system', 'jcepro')) {
			$app->enqueueMessage(
				'JCE PRO' . $label . ' detected (plg_system_jcepro is enabled — that plugin\'s enabled '
				. 'state is JCE\'s entire Pro gate; there is no licence check at runtime). All 21 tools '
				. 'work. There is deliberately NO separate Pro add-on for JCE: plg_system_jcepro ships '
				. 'no SQL install file, adds no table and no new stored entity, and contributes only '
				. 'additional keys inside the same #__wf_profiles.params blob — so this add-on already '
				. 'covers 100% of Pro\'s persisted state.',
				'message'
			);

			return;
		}

		$app->enqueueMessage(
			'JCE' . $label . ' (free edition) detected. All 21 tools work against it — JCE has exactly '
			. 'one table on both editions. Note that JCE\'s own seeded "Default" profile references '
			. 'several Pro-only plugin names (imgmanager_ext, filemanager, mediamanager, '
			. 'templatemanager, caption, columns, microdata, source, textpattern). On the free edition '
			. 'those are silently inert: no button, no error, no log entry. The tools report them '
			. 'rather than leaving you to wonder.',
			'notice'
		);
	}

	/**
	 * JCE below 2.9.99.5 carries an actively exploited, CISA-KEV-listed 10.0.
	 *
	 * This is the loudest message this installer can produce, and rightly so.
	 * The documented in-the-wild attack writes a #__wf_profiles row that permits
	 * .php uploads — the exact table this add-on operates on.
	 */
	private function warnOnVulnerableVersion(object $app, ?string $version): void
	{
		if ($version === null) {
			$app->enqueueMessage(
				'The installed JCE version could not be read from '
				. 'administrator/components/com_jce/jce.xml or includes/constants.php. This add-on '
				. 'cannot establish whether the site is patched against CVE-2026-48907, so every WRITE '
				. 'tool will refuse until it can. Read tools, including the security audit, still work.',
				'warning'
			);

			return;
		}

		if (version_compare($version, self::CVE_FIX_VERSION, '<')) {
			$app->enqueueMessage(
				sprintf(
					'SECURITY — ACT NOW. This site runs JCE %s, which is below %s and therefore carries '
					. 'CVE-2026-48907: unauthenticated remote code execution, CVSS v4 10.0, on CISA\'s '
					. 'Known Exploited Vulnerabilities catalogue, mass-exploited from June 2026 with '
					. 'public exploit code. One of the two documented in-the-wild attack paths installs '
					. 'a malicious JCE editor profile that permits .php uploads and then uploads a '
					. 'webshell — the profile row IS the payload, in the same table this add-on reads '
					. 'and writes. Update JCE to %s or later before anything else. Every write tool in '
					. 'this add-on refuses while the version is below %s, because a profile write on '
					. 'such a site is indistinguishable from the attack. Then run audit_jce_profiles: '
					. 'updating does NOT remove a rogue profile that is already there.',
					$version,
					self::CVE_FIX_VERSION,
					self::RECOMMENDED_VERSION,
					self::CVE_FIX_VERSION
				),
				'error'
			);

			return;
		}

		if (version_compare($version, self::RECOMMENDED_VERSION, '<')) {
			$app->enqueueMessage(
				sprintf(
					'JCE %s is patched against CVE-2026-48907 but is below the recommended %s, which '
					. 'fixed CVE-2026-65891 — an authenticated user with file-browser and Rename '
					. 'permissions could rename a file so that it becomes hidden in the browsed folder. '
					. 'Concealment rather than execution, and mitigable in the meantime by turning off '
					. 'the file_rename profile flags. Updating is still recommended.',
					$version,
					self::RECOMMENDED_VERSION
				),
				'warning'
			);
		}
	}

	private function auditHint(object $app): void
	{
		$app->enqueueMessage(
			'Suggested first call: audit_jce_profiles. It is entirely read-only and looks for the '
			. 'rogue-profile indicators left by the June 2026 CVE-2026-48907 campaign — '
			. 'machine-generated profile names, absurd negative ordering values that pin a profile above '
			. 'every legitimate one, executable extensions in the upload filetype lists, MIME validation '
			. 'disabled, and profiles reaching the Public group. A site that was exploited then may '
			. 'still be carrying the attacker\'s profile today, because updating JCE does not remove it.',
			'notice'
		);
	}
}
