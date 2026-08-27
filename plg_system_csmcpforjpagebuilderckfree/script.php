<?php

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;

class PlgSystemCsmcpforjpagebuilderckfreeInstallerScript implements InstallerScriptInterface
{
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
				->where($db->quoteName('element') . ' = ' . $db->quote('csmcpforjpagebuilderckfree'));
			$db->setQuery($query)->execute();
		} catch (\Throwable $e) {
			$app->enqueueMessage(
				'csmcpforjpagebuilderckfree auto-enable failed: ' . $e->getMessage(),
				'warning'
			);
		}

		$app->enqueueMessage(
			'MCP add-on for Page Builder CK installed and active. 30 new tools are exposed to your '
			. 'connected MCP clients: pages, the block tree inside htmlcode, rows and columns, styles, '
			. 'saved elements, fonts and the .pbck backup history. There is no separate admin UI for '
			. 'this plugin by design — the tools appear in your MCP client automatically.',
			'message'
		);

		$base = JPATH_ADMINISTRATOR . '/components/com_pagebuilderck';

		if (!is_dir($base)) {
			$app->enqueueMessage(
				'Page Builder CK (com_pagebuilderck) is not installed on this site yet, so these tools '
				. 'will report "not installed" until it is. The free Light edition is enough.',
				'notice'
			);

			return true;
		}

		$this->reportEdition($app, $base);
		$this->warnOnVulnerableVersion($app, $base);

		return true;
	}

	/**
	 * Say which edition is present, and which tools that unlocks.
	 *
	 * The `pro` directory IS Page Builder CK's entire Pro gate — there is no
	 * licence key anywhere in the installed code.
	 */
	private function reportEdition(object $app, string $base): void
	{
		if (is_dir($base . '/pro')) {
			$app->enqueueMessage(
				'Page Builder CK PRO detected. All 30 tools here work, and the separate Pro add-on '
				. '(export/import, block library, presets) will also work on this site.',
				'message'
			);

			return;
		}

		$app->enqueueMessage(
			'Page Builder CK Light (free) detected. All 30 tools in this add-on work against it. '
			. 'Light ships 11 of the 29 block addons — a page referencing a Pro-only type such as '
			. 'heading or button still renders its inner markup as static HTML rather than blanking, '
			. 'with no error anywhere, so the tools report which types have no enabled plugin.',
			'notice'
		);
	}

	/**
	 * Page Builder CK below 3.6.5 has known, actively exploited RCEs.
	 *
	 * CVE-2026-56290 is on the CISA Known Exploited Vulnerabilities list with
	 * public mass-scanning tooling. Three further security releases followed it,
	 * so 3.6.0 is NOT sufficient — 3.6.5 is the safe floor on the Joomla 5/6
	 * branch.
	 */
	private function warnOnVulnerableVersion(object $app, string $base): void
	{
		$manifest = $base . '/pagebuilderck.xml';

		if (!is_file($manifest)) {
			return;
		}

		$xml = @simplexml_load_file($manifest);

		if ($xml === false) {
			return;
		}

		$version = trim((string) ($xml->version ?? ''));

		if ($version === '' || version_compare($version, '3.6.5', '>=')) {
			return;
		}

		$app->enqueueMessage(
			sprintf(
				'SECURITY: this site runs Page Builder CK %s. Versions below 3.6.5 carry known remote '
				. 'code execution vulnerabilities — CVE-2026-56290 is on the CISA Known Exploited '
				. 'Vulnerabilities list and public mass-exploitation tooling exists. Note that 3.6.0 '
				. 'only partially fixed it; 3.6.3 and 3.6.5 closed two further RCE/SQLi issues. Update '
				. 'to 3.6.5 or later before doing anything else.',
				$version
			),
			'error'
		);
	}
}
