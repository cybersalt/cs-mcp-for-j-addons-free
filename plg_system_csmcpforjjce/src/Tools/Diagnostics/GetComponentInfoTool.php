<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjce\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceBootTrait;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceSafetyTrait;
use Joomla\CMS\User\User;

/**
 * Orientation. Call this first on an unfamiliar site.
 *
 * Answers in one round trip: is JCE installed, which version, free or Pro, is
 * the one table present and intact, how many profiles are on it, which of the
 * nine bundled plugins are installed and enabled, and is the version past the
 * CVE-2026-48907 fix line.
 *
 * The edition question has exactly one honest answer:
 * `PluginHelper::isEnabled('system','jcepro')`. There is no `isPro()` helper, no
 * `JCE_PRO` constant, and no runtime licence check anywhere in the component —
 * the single Pro test in 2.9.99.10 is at `models/cpanel.php:127-131` and it is
 * that call. The `updates_key` component param exists but gates DOWNLOADS from
 * the vendor's update server, never features (`plg_installer_jce/jce.php:45`),
 * so a Pro site with a lapsed subscription is still fully Pro and we do not look
 * at it.
 */
final class GetComponentInfoTool extends AbstractTool
{
	use JceBootTrait;
	use JceSafetyTrait;

	/**
	 * The eight plugins JCE's package ships alongside the component.
	 *
	 * Only `system/jcepro` is Pro-only. `pkg_jce.xml` declares one component
	 * and eight plugins.
	 */
	private const BUNDLED_PLUGINS = [
		['folder' => 'system',    'element' => 'jce',        'role' => 'Injects the updates_key field into com_jce params and handles update-server registration.'],
		['folder' => 'editors',   'element' => 'jce',        'role' => 'The editor itself. Without this, JCE is installed but not selectable as the site editor.'],
		['folder' => 'system',    'element' => 'jcepro',     'role' => 'PRO ONLY. Delivers every Pro capability through onWf* event listeners. Its enabled state IS the Pro gate.'],
		['folder' => 'installer', 'element' => 'jce',        'role' => 'Appends the updates_key to vendor download URLs.'],
		['folder' => 'extension', 'element' => 'jce',        'role' => 'Install/uninstall hooks for jce-group plugins.'],
		['folder' => 'content',   'element' => 'jce',        'role' => 'Content preparation.'],
		['folder' => 'fields',    'element' => 'mediajce',   'role' => 'The JCE media custom-field type.'],
		['folder' => 'quickicon', 'element' => 'jce',        'role' => 'Control-panel quick icon.'],
	];

	public function getName(): string { return 'get_jce_component_info'; }

	public function getDescription(): string
	{
		return 'Orientation for JCE (Joomla Content Editor) on this site — call this before anything '
			. 'else when you do not already know the install. '
			. 'Reports: the installed version, read from the component manifest and cross-checked '
			. 'against the WF_VERSION constant; the edition, resolved by the only honest test there is, '
			. 'PluginHelper::isEnabled(\'system\',\'jcepro\') — there is no isPro() helper, no JCE_PRO '
			. 'constant and no runtime licence check anywhere in the component, and the updates_key '
			. 'component param gates downloads from the vendor rather than features, so a Pro site with '
			. 'a lapsed subscription is still fully Pro; the state of #__wf_profiles, which is JCE\'s '
			. 'ONLY table (Pro adds no SQL install file at all, so this add-on covers 100% of both '
			. 'editions\' persisted state) including profile counts, whether any profile is published, '
			. 'and whether the four tracking columns are present; and the install state of all eight '
			. 'bundled plugins with what each one does. '
			. 'SECURITY: flags a version below 2.9.99.5, which carries CVE-2026-48907 — unauthenticated '
			. 'RCE, CVSS 10.0, CISA Known Exploited Vulnerabilities catalogue, mass-exploited June 2026 '
			. '— and below which every write tool in this add-on refuses. Also flags a version below '
			. '2.9.99.10, which is missing the CVE-2026-65891 file-rename fix. '
			. 'SCHEMA DRIFT is reported honestly: created, created_by, modified and modified_by are '
			. 'back-filled onto an UPGRADED install by ALTER TABLE only when the driver is MySQL '
			. '(install.pkg.php:498-517), so a long-lived PostgreSQL or SQL Server site legitimately '
			. 'does not have them and nothing is broken. '
			. 'Read-only.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$base = $this->jceAdminBase();

		if ($base === null) {
			return $this->jceNotInstalledError();
		}

		$version = $this->jceVersion();
		$pro     = $this->jceIsPro();

		$response = [
			'ok'        => true,
			'installed' => true,
			'name'      => 'JCE (Joomla Content Editor)',
			'version'   => $version,
			'edition'   => $pro ? 'pro' : 'free',
			'paths'     => [
				'administrator' => 'administrator/components/com_jce',
				'site'          => $this->jceSiteBase() === null ? null : 'components/com_jce',
				'site_present'  => $this->jceSiteBase() !== null,
			],
			'edition_detail' => [
				'gate'   => "PluginHelper::isEnabled('system', 'jcepro')",
				'source' => 'models/cpanel.php:127-131 — the ONLY runtime Pro check in the entire '
					. 'component. There is no isPro() helper, no JCE_PRO constant in '
					. 'includes/constants.php, and no licence-key check at runtime.',
				'licence_key_note' => 'The `updates_key` component parameter does exist, defined by '
					. 'plg_system_jce and stored in com_jce\'s params, but its only consumer appends it '
					. 'to vendor download URLs (plg_installer_jce/jce.php:45). It gates DOWNLOADS, never '
					. 'features. A Pro site with an expired subscription is still fully Pro, so this '
					. 'add-on deliberately does not look at it.',
				'data_surface_note' => 'Pro adds NO new table and no new stored entity — '
					. 'plg_system_jcepro has no <install><sql> block at all. What it adds is more keys '
					. 'inside the same #__wf_profiles.params blob (the editor.upload_*, '
					. 'editor.watermark_*, editor.crop_*/resize_* families and the setup.custom '
					. 'key) plus browser-side editor capability that persists nothing. These tools '
					. 'therefore cover 100% of both editions\' persisted state, which is why there is no '
					. 'separate Pro add-on.',
			],
			'table'     => $this->describeTable(),
			'bundled_plugins' => $this->describePlugins(),
			'component_params' => [
				'allow_profile_guests'     => $this->jceAllowProfileGuests(),
				'profile_groups_whitelist' => $this->jceGroupsWhitelist(),
				'note' => 'com_jce\'s own config.xml has three fieldsets and eight fields, and NONE of '
					. 'them concerns files, folders, uploads or extensions. All of that is per-profile, '
					. 'inside #__wf_profiles.params. That is the single most important structural fact '
					. 'about JCE. Use get_jce_config for the full component configuration.',
			],
			'component' => $this->jceEditionNotice(),
		];

		$finding = $this->jceVersionFinding();

		if ($finding !== null) {
			$response['security'] = $finding;
		}

		if ($this->jceSiteBase() === null) {
			$response['warning'] = 'The SITE half of the component (components/com_jce) is missing while '
				. 'the administrator half is present. Every editor dialog — image manager, file '
				. 'browser, link — is dispatched through the site component, so the editor will fail '
				. 'to open them. Reinstall com_jce.';
		}

		return ToolResult::json($response);
	}

	/** @return array<string,mixed> */
	private function describeTable(): array
	{
		$report = [
			'name'   => $this->jceTable(),
			'exists' => $this->jceTableExists(),
			'note'   => 'This is JCE\'s only table, on both editions.',
		];

		if (!$report['exists']) {
			$report['error'] = 'The table is missing. The installer creates it with '
				. 'CREATE TABLE IF NOT EXISTS, so its absence means an incomplete or rolled-back '
				. 'installation. Every tool in this add-on will refuse until it is restored. '
				. 'Reinstalling com_jce recreates it without touching existing data.';

			return $report;
		}

		$columns = $this->jceColumns();
		$missing = $this->jceMissingDriftColumns();

		$report['columns'] = $columns;
		$report['column_count'] = \count($columns);

		if ($missing !== []) {
			$report['missing_tracking_columns'] = $missing;
			$report['schema_drift_note'] = 'These tracking columns are absent. A FRESH install gets all '
				. '19 columns on every driver, but on an UPGRADED install they are added by ALTER TABLE '
				. 'only when the driver is MySQL (install.pkg.php:498-517; checkTableUpdate() also '
				. 'early-returns for non-MySQL at :489-491). On a long-lived PostgreSQL or SQL Server '
				. 'site this is expected and nothing is broken — the write tools in this add-on omit '
				. 'columns that do not exist.';
		}

		try {
			$quoted = $this->db->quoteName($this->jceTable());

			$report['profiles_total'] = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $quoted)->loadResult();
			$report['profiles_published'] = (int) $this->db->setQuery(
				'SELECT COUNT(*) FROM ' . $quoted . ' WHERE ' . $this->db->quoteName('published') . ' = 1'
			)->loadResult();
			$report['params_bytes_total'] = (int) $this->db->setQuery(
				'SELECT COALESCE(SUM(LENGTH(' . $this->db->quoteName('params') . ')), 0) FROM ' . $quoted
			)->loadResult();

			if ($report['profiles_total'] === 0) {
				$report['empty_warning'] = 'The table exists but has no rows. JCE seeds five profiles '
					. '(Default, Front End, Blogger, Mobile, Markdown) from '
					. 'administrator/components/com_jce/models/profiles.xml at install time — '
					. 'note the SQL file inserts nothing, seeding happens in PHP and only when the '
					. 'table is empty (helpers/profiles.php:83-113). An empty table means no editor for '
					. 'anyone. The JCE control panel has a Repair action that reseeds it.';
			} elseif ($report['profiles_published'] === 0) {
				$report['unpublished_warning'] = 'No profile is published. JCE selects only from '
					. 'published rows (classes/application.php:367) and renders no editor at all when '
					. 'nothing matches — for every user, in both the site and the administrator, with '
					. 'no error message. Note that JCE\'s own import and repair actions force '
					. 'published = 0 on every row (helpers/profiles.php:447-449) and then re-publish '
					. '"Default" with a raw UPDATE; a repair that was interrupted leaves exactly this '
					. 'state.';
			}
		} catch (\Throwable $e) {
			$report['query_error'] = 'The table exists but could not be queried: ' . $e->getMessage();
		}

		return $report;
	}

	/** @return array<int,array<string,mixed>> */
	private function describePlugins(): array
	{
		$query = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('folder'),
				$this->db->quoteName('element'),
				$this->db->quoteName('enabled'),
			])
			->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'));

		$rows    = $this->db->setQuery($query)->loadAssocList() ?: [];
		$indexed = [];

		foreach ($rows as $row) {
			$indexed[$row['folder'] . '/' . $row['element']] = (int) $row['enabled'] === 1;
		}

		$out = [];

		foreach (self::BUNDLED_PLUGINS as $plugin) {
			$key = $plugin['folder'] . '/' . $plugin['element'];

			$entry = [
				'plugin'    => 'plg_' . $plugin['folder'] . '_' . $plugin['element'],
				'folder'    => $plugin['folder'],
				'element'   => $plugin['element'],
				'installed' => \array_key_exists($key, $indexed),
				'enabled'   => $indexed[$key] ?? false,
				'role'      => $plugin['role'],
			];

			if ($key === 'editors/jce' && $entry['installed'] && !$entry['enabled']) {
				$entry['warning'] = 'DISABLED. JCE cannot be used as the site editor while this plugin '
					. 'is off, whatever the profiles say.';
			}

			if ($key === 'system/jcepro' && $entry['installed'] && !$entry['enabled']) {
				$entry['warning'] = 'The Pro plugin is INSTALLED but DISABLED, so this site behaves '
					. 'exactly as the free edition: the Pro plugin catalogue is injected through an '
					. 'onWfPluginsHelperGetPlugins listener that never fires, and every Pro-only button '
					. 'in every profile is silently inert. Enabling it restores Pro behaviour with no '
					. 'data change, because Pro stores nothing of its own.';
			}

			$out[] = $entry;
		}

		return $out;
	}
}
