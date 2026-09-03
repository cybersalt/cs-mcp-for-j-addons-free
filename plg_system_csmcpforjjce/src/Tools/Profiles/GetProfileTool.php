<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjce\Tools\Profiles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceBootTrait;
use Joomla\CMS\User\User;

/**
 * One profile, fully decoded: every comma list expanded, the toolbar grid
 * parsed, the params blob decoded, and every token cross-checked against the
 * plugin catalogue.
 *
 * The cross-check is the point. `rows` and `plugins` are independent columns
 * that can — and on real sites routinely do — disagree, and JCE never says so.
 * `JceModelProfile::getRows()` (`models/profile.php:263-321`) silently drops
 * any token in `rows` that is not a known, active button, so a typo produces a
 * missing button and no error anywhere. Meanwhile a name in `plugins` with no
 * entry in `rows` is correct for the row-0 plugins (`source`, `textpattern`,
 * `contextmenu`, `browser`, `media`, `preview`) and a mistake for everything
 * else.
 *
 * Neither asymmetry is repaired here, and no write tool in this add-on repairs
 * it automatically either — see set_jce_profile_toolbar.
 */
final class GetProfileTool extends AbstractTool
{
	use JceBootTrait;

	public function getName(): string { return 'get_jce_profile'; }

	public function getDescription(): string
	{
		return 'Fetch one JCE profile by id, fully decoded. '
			. 'Returns the raw column values alongside a decoded view: `types` and `users` expanded to '
			. 'ids with their user-group titles resolved, `components` and `device` as lists, `area` '
			. 'labelled, `rows` parsed into the toolbar grid it encodes (`;` separates rows, `,` '
			. 'separates buttons within a row, and the literal token `spacer` is a group divider that is '
			. 'preserved, not stripped), and `params` decoded from JSON. '
			. 'CROSS-CHECKS, which is the real value here: every token in `rows` and `plugins` is '
			. 'validated against the merged button catalogue (helpers/plugins.json + helpers/commands.json '
			. '+ the Pro pro.json when Pro is enabled + installed third-party `jce`-group plugins). '
			. 'Unknown tokens are reported — JCE drops them silently at render time, so a typo is '
			. 'otherwise invisible. Buttons present in `rows` but missing from `plugins` are reported '
			. '(they render nothing). Plugins present in `plugins` but absent from `rows` are reported '
			. 'and classified, because for row-0 plugins such as source, textpattern, contextmenu, '
			. 'browser, media and preview that is correct rather than a fault. On the free edition, '
			. 'references to Pro-only names are flagged as inert. '
			. 'PARAMS MAY BE ENCRYPTED. On a site upgraded from an older JCE the params column can carry '
			. 'a ###AES128###, ###CTR128### or ###DEFUSE### prefix, keyed from a per-site serverkey.php '
			. 'that ships in no package. This tool detects that and reports it rather than returning an '
			. 'empty object — a naive json_decode returns null there with no error. '
			. 'Set include_params=false to omit the params tree from the response when you only want the '
			. 'structure. '
			. 'Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'             => ['type' => 'integer', 'description' => 'The #__wf_profiles id.'],
				'include_params' => ['type' => 'boolean', 'description' => 'Include the decoded params tree. Default true.'],
			],
			'required' => ['id'],
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

		$id  = $this->requirePositiveInt($arguments, 'id');
		$row = $this->jceProfileRow($id);

		if ($row === null) {
			return $this->jceProfileNotFoundError($id);
		}

		$includeParams = !\array_key_exists('include_params', $arguments)
			|| (bool) $arguments['include_params'];

		$catalog    = $this->jcePluginCatalog();
		$rowsGrid   = $this->jceParseRows($row['rows'] ?? '');
		$pluginList = $this->jceSplitList($row['plugins'] ?? '');

		$types = $this->jceSplitIntList($row['types'] ?? '');
		$users = $this->jceSplitIntList($row['users'] ?? '');

		$response = [
			'ok'      => true,
			'profile' => [
				'id'          => (int) $row['id'],
				'name'        => (string) $row['name'],
				'description' => (string) $row['description'],
				'published'   => (int) $row['published'] === 1,
				'ordering'    => (int) $row['ordering'],
				'area'        => (int) $row['area'],
				'area_label'  => $this->jceAreaLabel((int) $row['area']),
				'assignment'  => [
					'user_groups'       => $this->jceDescribeGroups($types),
					'users'             => $users,
					'components'        => $this->jceSplitList($row['components'] ?? ''),
					'devices_stored'    => $this->jceSplitList($row['device'] ?? ''),
					'devices_effective' => $this->jceEffectiveDevices($row['device'] ?? ''),
				],
				'toolbar' => [
					'row_count' => \count($rowsGrid),
					'rows'      => $rowsGrid,
					'raw'       => (string) ($row['rows'] ?? ''),
				],
				'plugins' => $pluginList,
			],
			'analysis'  => $this->analyse($rowsGrid, $pluginList, $catalog),
			'component' => $this->jceEditionNotice(),
		];

		foreach (['checked_out', 'checked_out_time', 'created', 'created_by', 'modified', 'modified_by'] as $column) {
			if (\array_key_exists($column, $row)) {
				$response['profile'][$column] = $row[$column];
			}
		}

		$missingColumns = $this->jceMissingDriftColumns();

		if ($missingColumns !== []) {
			$response['schema_note'] = 'This install is missing the tracking column(s) '
				. implode(', ', $missingColumns) . '. They are added to an UPGRADED install by '
				. 'ALTER TABLE only when the driver is MySQL (install.pkg.php:498-517), so a long-lived '
				. 'PostgreSQL or SQL Server site legitimately does not have them. Nothing is broken.';
		}

		if ($types === [] && $users === []) {
			$response['profile']['dead'] = true;
			$response['profile']['dead_note'] = 'Both `types` and `users` are empty. JCE skips such a '
				. 'profile during matching (application.php:394), so it applies to nobody regardless of '
				. 'its published state.';
		}

		if (strcasecmp((string) $row['name'], 'Default') === 0) {
			$response['profile']['default_profile_note'] = 'This profile is named "Default", which JCE '
				. 'treats as magic. JcePluginsHelper::postInstall() registers every newly installed JCE '
				. 'editor plugin into `WHERE name = \'Default\' OR id = 1` (helpers/plugins.php:408), and '
				. 'removeFromProfile() unregisters from the same row. Renaming it makes JCE plugin '
				. 'install and uninstall hooks silently no-op.';
		}

		if ($includeParams) {
			$decoded = $this->jceDecodeParams($row['params'] ?? '');

			if (!$decoded['ok']) {
				$response['params'] = [
					'available' => false,
					'encrypted' => $decoded['encrypted'],
					'algorithm' => $decoded['algorithm'],
					'bytes'     => $decoded['bytes'],
					'error'     => $decoded['error'],
				];
			} else {
				$response['params'] = [
					'available' => true,
					'bytes'     => $decoded['bytes'],
					'keys'      => array_keys($decoded['params']),
					'tree'      => $decoded['params'],
					'note'      => 'Top-level keys are one per plugin name, plus `editor` for the '
						. 'profile-wide editor settings and, under Pro, `setup` for query-variable '
						. 'scoping. Params for a plugin no longer listed in the `plugins` column are '
						. 'orphaned but preserved — JceModelProfile::save() only merges keys in '
						. '`plugins` + editor + setup (models/profile.php:874-880), so removing a plugin '
						. 'strands its config rather than clearing it, and re-adding the plugin '
						. 'resurrects the old values.',
				];

				$orphans = array_values(array_diff(
					array_keys($decoded['params']),
					array_merge($pluginList, ['editor', 'setup'])
				));

				if ($orphans !== []) {
					$response['params']['orphaned_keys'] = $orphans;
					$response['params']['orphaned_note'] = 'These params keys belong to plugins not '
						. 'currently listed in the `plugins` column. They are inert now but will take '
						. 'effect again if the plugin is re-enabled on this profile.';
				}
			}
		}

		return ToolResult::json($response);
	}

	/**
	 * Cross-check the toolbar grid and the plugin list against the catalogue.
	 *
	 * @param array<int,array<int,string>>      $rowsGrid
	 * @param array<int,string>                 $pluginList
	 * @param array<string,array<string,mixed>> $catalog
	 * @return array<string,mixed>
	 */
	private function analyse(array $rowsGrid, array $pluginList, array $catalog): array
	{
		$buttons = [];

		foreach ($rowsGrid as $rowButtons) {
			foreach ($rowButtons as $button) {
				$buttons[] = $button;
			}
		}

		$buttons     = array_values(array_unique($buttons));
		$realButtons = array_values(array_diff($buttons, ['spacer']));

		$unknownButtons = array_values(array_diff($buttons, array_keys($catalog)));
		$unknownPlugins = array_values(array_diff($pluginList, array_keys($catalog)));

		// A button whose name is a plugin must also appear in `plugins` to
		// render; a button that is a command (undo, bold, ...) does not.
		$buttonsNotEnabled = [];

		foreach ($realButtons as $button) {
			$entry = $catalog[$button] ?? null;

			if ($entry === null || ($entry['kind'] ?? '') !== 'plugin') {
				continue;
			}

			if (!\in_array($button, $pluginList, true)) {
				$buttonsNotEnabled[] = $button;
			}
		}

		$enabledNoButton = [];

		foreach (array_unique($pluginList) as $plugin) {
			if (\in_array($plugin, $buttons, true)) {
				continue;
			}

			$entry    = $catalog[$plugin] ?? null;
			$hasIcon  = $entry !== null && trim((string) ($entry['icon'] ?? '')) !== '';

			$enabledNoButton[] = [
				'plugin'   => $plugin,
				'expected' => !$hasIcon,
				'note'     => $hasIcon
					? 'This plugin declares a toolbar icon but has no entry in `rows`, so it is enabled '
						. 'with no way to reach it.'
					: 'This plugin declares no toolbar icon (row 0), so having no entry in `rows` is '
						. 'correct — it works through the context menu, paste handling or the editor '
						. 'source view rather than a button.',
			];
		}

		$proOnly    = [];
		$duplicates = [];
		$seen       = [];

		foreach ($pluginList as $plugin) {
			if (isset($seen[$plugin])) {
				$duplicates[] = $plugin;
			}

			$seen[$plugin] = true;

			$entry = $catalog[$plugin] ?? null;

			if ($entry !== null && ($entry['source'] ?? '') === 'pro') {
				$proOnly[] = $plugin;
			}
		}

		$analysis = [
			'button_count'       => \count($realButtons),
			'spacer_count'       => \count(array_filter($buttons, static fn ($b) => $b === 'spacer')),
			'unknown_row_tokens' => $unknownButtons,
			'unknown_plugin_names' => $unknownPlugins,
			'buttons_without_enabled_plugin' => $buttonsNotEnabled,
			'plugins_without_button'         => $enabledNoButton,
			'duplicate_plugin_names'         => array_values(array_unique($duplicates)),
		];

		if ($unknownButtons !== []) {
			$analysis['unknown_row_tokens_note'] = 'These tokens in `rows` match no command, no core '
				. 'plugin, no Pro plugin and no installed third-party jce plugin. '
				. 'JceModelProfile::getRows() drops unknown or inactive tokens without any error '
				. '(models/profile.php:294-301), so they simply produce a missing button. Usually a typo '
				. 'or a plugin that has since been uninstalled.';
		}

		if ($buttonsNotEnabled !== []) {
			$analysis['buttons_without_enabled_plugin_note'] = '`rows` and `plugins` are independent '
				. 'columns. A button whose plugin is not in `plugins` renders nothing at all. This is '
				. 'not repaired automatically anywhere in this add-on, because "fixing" it would enable '
				. 'a capability nobody asked for.';
		}

		if ($duplicates !== []) {
			$analysis['duplicate_plugin_names_note'] = 'Duplicates in `plugins` are harmless — JCE '
				. 'de-duplicates when rendering rows (models/profile.php:285) — and the profile JCE '
				. 'itself seeds ships with `spellchecker` and `article` listed twice, so this is '
				. 'usually original vendor data rather than damage.';
		}

		if ($proOnly !== []) {
			$analysis['pro_only_plugins'] = array_values(array_unique($proOnly));

			if (!$this->jceIsPro()) {
				$analysis['pro_only_note'] = 'This profile references Pro-only plugin names while the '
					. 'Pro system plugin is not enabled. Under the free edition those names are inert: '
					. 'the Pro catalogue is injected through the onWfPluginsHelperGetPlugins listener '
					. '(EditorTrait.php:106-149), which never fires, so the buttons simply do not appear '
					. 'and nothing is logged. Note the seeded Default profile ships this way, so it is '
					. 'not evidence of a problem.';
			}
		}

		return $analysis;
	}
}
