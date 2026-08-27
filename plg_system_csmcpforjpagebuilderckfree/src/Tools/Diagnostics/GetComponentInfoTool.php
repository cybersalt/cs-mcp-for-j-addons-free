<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckBootTrait;
use Joomla\CMS\User\User;

/**
 * Orientation. Call this first on an unfamiliar site.
 *
 * Answers, in one round trip: is Page Builder CK installed, which version, which
 * edition (and whether the two independent signals for that agree), which block
 * types can actually render, whether nested rows are switched on, which of the
 * six tables exist and how much is in them.
 *
 * The edition question is worth doing properly. Page Builder CK's gate is a
 * single `file_exists()` on the component's `pro` directory — honest, unlike SP
 * Page Builder's `isProVersion()`, which is hardcoded `true` even in the free
 * build. But the manifest carries its own claim in `<ckpro>` and `<variant>`,
 * and the two can disagree after one edition is installed over the other. The
 * directory is what governs behaviour; the mismatch is reported because it means
 * there are stale files on disk.
 */
final class GetComponentInfoTool extends AbstractTool
{
	use PagebuilderckBootTrait;

	/**
	 * The tables Page Builder CK uses. `options` is listed last because it is
	 * the odd one out — see the note in the response.
	 */
	private const TABLES = ['pages', 'elements', 'categories', 'styles', 'fonts', 'options'];

	/**
	 * Below this, the known actively-exploited font upload RCE is unpatched.
	 */
	private const SECURITY_FLOOR = '3.6.5';

	public function getName(): string { return 'get_pagebuilderck_component_info'; }

	public function getDescription(): string
	{
		return 'Orientation for Page Builder CK on this site — call this before anything else when you '
			. 'do not already know the install. '
			. 'Reports: the installed version from the component manifest; the edition, resolved from '
			. 'BOTH independent signals and with the mismatch called out if they disagree — the manifest '
			. '<ckpro>/<variant> claim, and the presence of the pro directory, which is the entire '
			. 'licence gate and the only thing the vendor\'s own code checks; the full addon inventory '
			. '(every `pagebuilderck` plugin with its enabled state) plus the three core block types the '
			. 'component handles itself with no plugin (row, rowinrow, readmore); the `nestedrows` '
			. 'component parameter, which controls whether the editor offers nested rows — existing '
			. 'nested rows still render either way; and which of the six Page Builder CK tables exist, '
			. 'with row counts and total content bytes. '
			. 'NOTE ON #__pagebuilderck_options: it is NOT created by the installer. Page Builder CK '
			. 'creates it lazily the first time a content type or plugin option is saved, and swallows '
			. 'any failure. Its absence on a fresh site is completely normal and is not a broken '
			. 'install. Every other table missing IS a problem. '
			. 'Also flags an installed version below ' . self::SECURITY_FLOOR . ', which carries known, '
			. 'actively exploited remote code execution in the font upload path. '
			. 'Read-only.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$base = $this->pbckAdminBase();

		if ($base === null) {
			return $this->pbckNotInstalledError();
		}

		$manifest = $this->pbckManifest() ?? [];
		$version  = $this->pbckVersion();
		$dirPro   = $this->pbckVendorIsPro();
		$claimPro = ($manifest['ckpro'] ?? '') === '1' || strtolower((string) ($manifest['variant'] ?? '')) === 'pro';

		$edition = [
			'effective'           => $dirPro ? 'pro' : 'light',
			'pro_directory_present' => $dirPro,
			'manifest_ckpro'      => $manifest['ckpro'] ?? null,
			'manifest_variant'    => $manifest['variant'] ?? null,
			'manifest_claims'     => $claimPro ? 'pro' : 'light',
			'agreement'           => $claimPro === $dirPro,
			'how_it_is_decided'   => 'Page Builder CK gates every Pro capability on file_exists() of the '
				. 'component\'s `pro` directory. There is no licence key, no download id and no '
				. 'phone-home. The manifest flags are a separate, purely informational claim, and the '
				. 'directory is what actually governs behaviour.',
		];

		if ($claimPro !== $dirPro) {
			$edition['warning'] = sprintf(
				'MISMATCH. The manifest says %s but the `pro` directory is %s. Page Builder CK will '
					. 'behave as %s. This normally means one edition was installed over the other and '
					. 'left stale files behind — worth cleaning up, because a leftover `pro` directory '
					. 'from an expired Pro install keeps Pro features switched on with unmaintained code '
					. 'behind them.',
				$claimPro ? 'Pro' : 'Light',
				$dirPro ? 'present' : 'absent',
				$dirPro ? 'Pro' : 'Light'
			);
		}

		$addons  = $this->pbckAddonInventory();
		$enabled = 0;

		foreach ($addons as $addon) {
			if ($addon['enabled']) {
				$enabled++;
			}
		}

		$tables  = [];
		$missing = [];

		foreach (self::TABLES as $name) {
			$tables[] = $report = $this->describeTable($name);

			if (!$report['exists'] && $name !== 'options') {
				$missing[] = $this->pbckTable($name);
			}
		}

		$response = [
			'ok'        => true,
			'installed' => true,
			'name'      => 'Page Builder CK',
			'version'   => $version,
			'paths'     => [
				'administrator' => 'administrator/components/com_pagebuilderck',
				'site'          => $this->pbckSiteBase() === null ? null : 'components/com_pagebuilderck',
				'site_present'  => $this->pbckSiteBase() !== null,
			],
			'edition'   => $edition,
			'addons'    => [
				'total'      => \count($addons),
				'enabled'    => $enabled,
				'disabled'   => \count($addons) - $enabled,
				'plugins'    => $addons,
				'core_types' => $this->pbckCoreTypes(),
				'note'       => 'A plugin\'s element name IS the block data-type. The renderer gates on '
					. 'PluginHelper::isEnabled(\'pagebuilderck\', $type), so a disabled plugin means '
					. 'every block of that type on the site renders as inert static HTML with no error. '
					. 'Use list_pagebuilderck_addons for the full explanation.',
			],
			'params'    => [
				'nestedrows' => $this->pbckNestedRowsEnabled(),
				'nestedrows_note' => 'Controls only whether the editor OFFERS nested rows. Nested rows '
					. 'that already exist in a page render regardless of this setting.',
			],
			'tables'    => $tables,
			'options_table_note' => '#__pagebuilderck_options is NOT created by the installer. Page '
				. 'Builder CK creates it lazily the first time a content type or plugin option is saved '
				. 'and silently ignores failure, so its absence on a site where that has never happened '
				. 'is normal and expected — not a broken install.',
			'component' => $this->pbckEditionNotice(),
		];

		if ($this->pbckSiteBase() === null) {
			$response['warning'] = 'The SITE half of the component (components/com_pagebuilderck) is '
				. 'missing while the administrator half is present. Pages will not render on the front '
				. 'end. Reinstall the component.';
		}

		if ($missing !== []) {
			$response['missing_tables'] = $missing;
			$response['missing_tables_warning'] = 'These tables are created by the installer and are '
				. 'absent, which means an incomplete or partially rolled-back installation. Tools '
				. 'targeting them will refuse. Reinstall the component to have them recreated — the '
				. 'installer uses CREATE TABLE IF NOT EXISTS, so existing data is not touched.';
		}

		if ($version !== null && version_compare($version, self::SECURITY_FLOOR, '<')) {
			$response['security_warning'] = sprintf(
				'Page Builder CK %s is below %s and therefore carries known, ACTIVELY EXPLOITED remote '
					. 'code execution in the font upload path (CVE-2026-56290, unauthenticated arbitrary '
					. 'file upload, CVSS 10.0, on CISA\'s Known Exploited Vulnerabilities catalogue with '
					. 'public mass-exploitation tooling). Update before doing anything else, and treat '
					. 'this site as potentially already compromised rather than merely at risk.',
				$version,
				self::SECURITY_FLOOR
			);
		}

		if ($version === null) {
			$response['version_note'] = 'The component manifest could not be read, so the version is '
				. 'unknown and the security check could not run. Expected '
				. 'administrator/components/com_pagebuilderck/pagebuilderck.xml.';
		}

		return ToolResult::json($response);
	}

	/**
	 * Existence, row count and content weight for one table.
	 *
	 * Each table is probed independently and a failure is recorded against that
	 * table alone — an install missing half its tables should still get an answer
	 * about the half it has.
	 *
	 * @return array<string,mixed>
	 */
	private function describeTable(string $name): array
	{
		$report = [
			'table'  => $this->pbckTable($name),
			'exists' => $this->pbckTableExists($name),
		];

		if (!$report['exists']) {
			if ($name === 'options') {
				$report['normal'] = true;
				$report['note']   = 'Not created by the installer. Absence is expected on a site where '
					. 'no content type or plugin option has ever been saved.';
			}

			return $report;
		}

		try {
			$quoted = $this->db->quoteName($this->pbckTable($name));

			$report['rows'] = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $quoted)->loadResult();

			$columns = $this->pbckColumns($name);

			$report['columns'] = $columns;

			if (\in_array('htmlcode', $columns, true)) {
				$report['htmlcode_bytes'] = (int) $this->db->setQuery(
					'SELECT COALESCE(SUM(LENGTH(' . $this->db->quoteName('htmlcode') . ')), 0) FROM ' . $quoted
				)->loadResult();
			}

			// styles.htmlcode and styles.stylecode are `text`, 65,535 bytes, not
			// longtext. MySQL truncates silently in non-strict mode, so a row near
			// the ceiling is a live hazard rather than trivia.
			if ($name === 'styles' && \in_array('stylecode', $columns, true)) {
				$report['largest_stylecode_bytes'] = (int) $this->db->setQuery(
					'SELECT COALESCE(MAX(LENGTH(' . $this->db->quoteName('stylecode') . ')), 0) FROM ' . $quoted
				)->loadResult();
				$report['text_column_limit'] = 65535;
				$report['note'] = 'htmlcode and stylecode here are `text` (65,535 bytes), not longtext. '
					. 'Run check_pagebuilderck_health for rows approaching that ceiling.';
			}
		} catch (\Throwable $e) {
			$report['error'] = 'The table exists but could not be queried: ' . $e->getMessage();
		}

		return $report;
	}
}
