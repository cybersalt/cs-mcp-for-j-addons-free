<?php

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;

class PlgSystemCsmcpforjvirtuemartInstallerScript implements InstallerScriptInterface
{
	public function install(InstallerAdapter $adapter): bool   { return true; }
	public function update(InstallerAdapter $adapter): bool    { return true; }
	public function uninstall(InstallerAdapter $adapter): bool { return true; }
	public function preflight(string $type, InstallerAdapter $adapter): bool { return true; }

	public function postflight(string $type, InstallerAdapter $adapter): bool
	{
		if (!\in_array($type, ['install', 'update', 'discover_install'], true)) {
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
				->where($db->quoteName('element') . ' = ' . $db->quote('csmcpforjvirtuemart'));
			$db->setQuery($query)->execute();
		} catch (\Throwable $e) {
			$app->enqueueMessage(
				'csmcpforjvirtuemart auto-enable failed: ' . $e->getMessage(),
				'warning'
			);
		}

		$app->enqueueMessage(
			'MCP add-on for VirtueMart installed and active. 48 new tools are exposed to your connected '
			. 'MCP clients: products and their language rows, categories, manufacturers, prices, stock, '
			. 'orders, customers, shopper groups, reviews and a set of diagnostics. There is no separate '
			. 'admin UI for this plugin by design — the tools appear in your MCP client automatically.',
			'message'
		);

		$base = JPATH_ADMINISTRATOR . '/components/com_virtuemart';

		if (!is_dir($base)) {
			$app->enqueueMessage(
				'VirtueMart (com_virtuemart) is not installed on this site yet, so these tools will '
				. 'report "not installed" until it is. VirtueMart is GPL and ships one free edition — '
				. 'there is nothing to buy.',
				'notice'
			);

			return true;
		}

		$version = $this->detectVersion($base);

		$this->reportEdition($app, $version);
		$this->warnOnVulnerableVersion($app, $version);
		$this->warnOnJoomlaSix($app);
		$this->reportLanguageTables($app);

		return true;
	}

	/**
	 * VirtueMart's release number, read by regular expression from version.php.
	 *
	 * Deliberately not included: including it would declare `class vmVersion`
	 * into the installer request for no benefit, and on a Joomla 6 site without
	 * the compat aliases we want to touch as little VirtueMart PHP as possible.
	 */
	private function detectVersion(string $base): ?string
	{
		$file = $base . '/version.php';

		if (!is_file($file)) {
			return null;
		}

		$raw = (string) @file_get_contents($file);

		if (preg_match('/\$RELEASE\s*=\s*\'([^\']+)\'/', $raw, $m) === 1) {
			return $m[1];
		}

		return null;
	}

	private function reportEdition(object $app, ?string $version): void
	{
		$app->enqueueMessage(
			'VirtueMart ' . ($version ?? '(version undetermined)') . ' detected. VirtueMart is GPL with a '
			. 'single free edition — there is no Pro build, no licence key and no feature gate — so all '
			. '48 tools work against any install and this add-on is free for the same reason.',
			'message'
		);
	}

	/**
	 * VirtueMart below 4.8.0 has an unpatched critical payment-plugin flaw, and
	 * below 4.4.10 an authenticated RCE chain.
	 */
	private function warnOnVulnerableVersion(object $app, ?string $version): void
	{
		if ($version === null || version_compare($version, '4.8.0', '>=')) {
			return;
		}

		$app->enqueueMessage(
			sprintf(
				'SECURITY: this site runs VirtueMart %s. Version 4.8.0 (17 August 2026) fixed a CRITICAL '
				. 'vulnerability in the SKRILL PAYMENT PLUGIN — the vendor advisory says to update or '
				. 'remove that plugin entirely — and added token validation to backend controllers and '
				. 'frontend forms. Update to 4.8.0 or later.',
				$version
			),
			'error'
		);

		if (version_compare($version, '4.4.10', '<')) {
			$app->enqueueMessage(
				sprintf(
					'SECURITY: VirtueMart %s also predates 4.4.10 and carries CVE-2025-25228, '
					. 'CVE-2025-25229 and CVE-2025-25230 — an authenticated SQL injection in backend '
					. 'product management, an unrestricted file upload in the product-image section, and '
					. 'a CSRF that BYPASSES the token check on that same upload. Chained, those are '
					. 'remote code execution, and public proof-of-concept code exists. This add-on '
					. 'deliberately exposes no media or file upload tool of any kind, but the vulnerable '
					. 'code is still reachable through the normal admin interface. Update before doing '
					. 'anything else.',
					$version
				),
				'error'
			);
		}
	}

	/**
	 * Report the Joomla 6 problem without gating anything on it.
	 *
	 * 47 of the 48 tools work directly against the database and load none of
	 * VirtueMart's PHP, so a broken compat setup does not stop them — but it
	 * does stop VirtueMart, and an administrator installing this add-on is
	 * exactly the person who should know.
	 */
	private function warnOnJoomlaSix(object $app): void
	{
		if (version_compare(JVERSION, '6.0', '<')) {
			return;
		}

		$aliasesLive = class_exists('JFactory') && class_exists('JPlugin');

		if (!$aliasesLive) {
			$app->enqueueMessage(
				'JOOMLA 6: the legacy class aliases are not registered in this request. VirtueMart 4.x '
				. 'has no version gating for legacy classes and no shim of its own — its bootstrap calls '
				. 'JFactory at helpers/config.php:367 before anything else — so every VirtueMart page is '
				. 'fatal without them. Enable the Behaviour - Backward Compatibility plugin and switch '
				. 'its classes_aliases parameter ON. Note the defaults differ between the Joomla 5 and '
				. 'Joomla 6 versions of that plugin. Run get_virtuemart_component_info for the full '
				. 'picture.',
				'error'
			);

			return;
		}

		$app->enqueueMessage(
			'JOOMLA 6: the legacy class aliases are live, so VirtueMart will boot. Be aware that the '
			. 'compat plugin alone is NOT sufficient: helpers/vmdefines.php:165 assigns JPATH_PLATFORM to '
			. 'VMPATH_LIBS, and on Joomla 6 that constant is defined only by the compat6 plugin and '
			. 'points at the plugin directory rather than <root>/libraries. TCPDF is looked up beneath it '
			. '(helpers/vmdefines.php:214-229), so invoice and PDF generation fails with '
			. 'COM_VIRTUEMART_TCPDF_NINSTALLED while the rest of the shop works. '
			. 'get_virtuemart_component_info reports this.',
			'warning'
		);
	}

	/**
	 * Point the administrator at the highest-value diagnostic straight away.
	 */
	private function reportLanguageTables(object $app): void
	{
		$app->enqueueMessage(
			'FIRST THING TO RUN: check_virtuemart_language_tables. VirtueMart stores every product, '
			. 'category and manufacturer NAME in a per-language satellite table and joins it with an '
			. 'INNER JOIN, so a record missing that row is invisible in the shop rather than '
			. 'untranslated — no listing, no search result, no error, no log line. That audit finds them '
			. 'across the whole shop, and it is the single most common reason an imported product never '
			. 'appears.',
			'notice'
		);
	}
}
