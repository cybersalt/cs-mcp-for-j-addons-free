<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjce\Tools\Editor;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceBootTrait;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceSafetyTrait;
use Joomla\CMS\User\User;

/**
 * Write the toolbar layout and the enabled plugin list.
 *
 * Three rules, all of them consequences of how JCE stores this:
 *
 *   1. Every token is validated against the merged catalogue before anything is
 *      written. JCE would accept an unknown token happily and then drop it at
 *      render time (`models/profile.php:294-301`), producing a missing button
 *      with no error anywhere. Refusing up front is the only way the caller
 *      finds out.
 *
 *   2. The vendor's sanitisers are applied HERE, and if they change the string
 *      the write is refused rather than performed. `rows` loses everything
 *      outside `[\w,;]` and `plugins` everything outside `[\w_,]`
 *      (`models/profile.php:643-646`), so a hyphenated name silently becomes a
 *      different, non-existent name. Better to say so than to store it.
 *
 *   3. `rows` and `plugins` are never auto-reconciled. A button whose plugin is
 *      not enabled is reported, not fixed — "fixing" it would enable a
 *      capability nobody asked for, and for the row-0 plugins the apparent
 *      mismatch is the correct configuration.
 */
final class SetProfileToolbarTool extends AbstractTool
{
	use JceBootTrait;
	use JceSafetyTrait;

	public function getName(): string { return 'set_jce_profile_toolbar'; }

	public function getDescription(): string
	{
		return 'Replace a JCE profile\'s toolbar layout and/or its enabled plugin list. '
			. 'rows: an array of arrays of button tokens — one inner array per toolbar row, in order. '
			. 'Use the literal token "spacer" for a group divider within a row. This is written as the '
			. '`;`-and-`,` string JCE stores; do not hand-assemble that string yourself. '
			. 'plugins: a flat array of plugin names to enable. '
			. 'Supply either or both. Whichever you supply REPLACES the column entirely. '
			. 'EVERY TOKEN IS VALIDATED against the merged catalogue — helpers/commands.json (20 toolbar '
			. 'commands) + helpers/plugins.json (45 core plugins) + pro.json (12 Pro plugins, when the '
			. 'Pro system plugin is enabled) + installed third-party plugins in the Joomla `jce` group. '
			. 'An unknown token refuses the whole call, because JCE itself would accept it and then drop '
			. 'it silently at render time (models/profile.php:294-301), leaving a missing button and no '
			. 'diagnostic anywhere. Call list_jce_plugins for the valid names. '
			. 'USE THE BARE NAME. A third-party plugin installed as `editor_codesample` contributes the '
			. 'token `codesample` — JCE strips the 7-character editor_ prefix when keying the catalogue '
			. '(helpers/plugins.php:127) — and `plugins` is sanitised with '
			. 'preg_replace(\'#[^\\w_,]+#\',\'\'), so a hyphenated `editor-codesample` would be stored '
			. 'as `editorcodesample`, which matches nothing. This tool applies the vendor sanitisers '
			. 'itself and REFUSES if they would change what you asked for, rather than storing something '
			. 'different. '
			. 'rows and plugins are NOT reconciled automatically. A button whose plugin is not in '
			. '`plugins` renders nothing, and is reported as a warning — not silently enabled, because '
			. 'that would grant a capability you did not request. Conversely the row-0 plugins (source, '
			. 'textpattern, contextmenu, browser, media, preview) correctly have no button at all. '
			. 'Refuses entirely if the installed JCE is below 2.9.99.5 (CVE-2026-48907).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'   => ['type' => 'integer', 'description' => 'The #__wf_profiles id.'],
				'rows' => [
					'type'  => 'array',
					'items' => ['type' => 'array', 'items' => ['type' => 'string']],
					'description' => 'Toolbar rows, outer array = rows in order, inner array = button tokens in order. Use "spacer" for a group divider within a row. Replaces the whole `rows` column.',
				],
				'plugins' => [
					'type'  => 'array',
					'items' => ['type' => 'string'],
					'description' => 'Bare plugin names to enable, e.g. ["link","imgmanager","lists"]. Replaces the whole `plugins` column.',
				],
			],
			'required' => ['id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->jceAdminBase() === null) {
			return $this->jceNotInstalledError();
		}

		if (!$this->jceTableExists()) {
			return $this->jceMissingTableError();
		}

		if (($refusal = $this->jceRequireWritableVersion()) !== null) {
			return $refusal;
		}

		$id  = $this->requirePositiveInt($arguments, 'id');
		$row = $this->jceProfileRow($id);

		if ($row === null) {
			return $this->jceProfileNotFoundError($id);
		}

		$hasRows    = \array_key_exists('rows', $arguments);
		$hasPlugins = \array_key_exists('plugins', $arguments);

		if (!$hasRows && !$hasPlugins) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Nothing to update. Supply rows, plugins, or both.',
			], true);
		}

		$catalog = $this->jcePluginCatalog();
		$updates = [];
		$notes   = [];

		$finalGrid    = $this->jceParseRows($row['rows'] ?? '');
		$finalPlugins = $this->jceSplitList($row['plugins'] ?? '');

		if ($hasRows) {
			$grid    = [];
			$unknown = [];

			foreach ((array) $arguments['rows'] as $rowIndex => $buttons) {
				$clean = [];

				foreach ((array) $buttons as $token) {
					$token = trim((string) $token);

					if ($token === '') {
						continue;
					}

					if (!\array_key_exists($token, $catalog)) {
						$unknown[] = $token;
					}

					$clean[] = $token;
				}

				if ($clean !== []) {
					$grid[] = $clean;
				} else {
					$notes[] = sprintf('Row %d was empty and has been omitted — an empty row would '
						. 'produce a stray `;;` in the stored string.', (int) $rowIndex + 1);
				}
			}

			if ($unknown !== []) {
				return $this->unknownTokenError('rows', array_values(array_unique($unknown)), $catalog);
			}

			$rowsString = $this->jceBuildRows($grid);
			$sanitised  = $this->jceSanitiseRows($rowsString);

			if ($sanitised !== $rowsString) {
				return $this->sanitiserError('rows', $rowsString, $sanitised, '#[^\\w,;]+#');
			}

			$updates['rows'] = $rowsString;
			$finalGrid       = $grid;
		}

		if ($hasPlugins) {
			$names   = [];
			$unknown = [];

			foreach ((array) $arguments['plugins'] as $name) {
				$name = trim((string) $name);

				if ($name === '') {
					continue;
				}

				if ($name === 'spacer') {
					return ToolResult::json([
						'ok'    => false,
						'error' => '"spacer" is a toolbar group divider, valid only in `rows`. It is not '
							. 'a plugin and must not appear in `plugins`.',
					], true);
				}

				$entry = $catalog[$name] ?? null;

				if ($entry === null) {
					$unknown[] = $name;

					continue;
				}

				if ((string) $entry['kind'] === 'command') {
					$notes[] = sprintf('"%s" is a built-in toolbar command, not a plugin. It works from '
						. '`rows` alone and does not need to be in `plugins`. It has been kept, because '
						. 'JCE tolerates it, but it does nothing there.', $name);
				}

				if (!\in_array($name, $names, true)) {
					$names[] = $name;
				}
			}

			if ($unknown !== []) {
				return $this->unknownTokenError('plugins', array_values(array_unique($unknown)), $catalog);
			}

			$pluginsString = $this->jceJoinList($names);
			$sanitised     = $this->jceSanitisePlugins($pluginsString);

			if ($sanitised !== $pluginsString) {
				return $this->sanitiserError('plugins', $pluginsString, $sanitised, '#[^\\w_,]+#');
			}

			$updates['plugins'] = $pluginsString;
			$finalPlugins       = $names;
		}

		$updates += $this->jceModifiedColumns((int) $actor->id);

		$set = [];

		foreach ($updates as $column => $value) {
			$set[] = $this->db->quoteName($column) . ' = '
				. (\is_int($value) ? (string) $value : $this->db->quote((string) $value));
		}

		$query = $this->db->getQuery(true)
			->update($this->db->quoteName($this->jceTable()))
			->set($set)
			->where($this->db->quoteName('id') . ' = ' . $id);

		$this->db->setQuery($query)->execute();

		$response = [
			'ok'         => true,
			'profile_id' => $id,
			'name'       => (string) $row['name'],
			'updated'    => array_values(array_diff(array_keys($updates), ['modified', 'modified_by'])),
			'toolbar'    => [
				'row_count' => \count($finalGrid),
				'rows'      => $finalGrid,
				'stored'    => $updates['rows'] ?? (string) ($row['rows'] ?? ''),
			],
			'plugins'    => [
				'enabled' => $finalPlugins,
				'stored'  => $updates['plugins'] ?? (string) ($row['plugins'] ?? ''),
			],
			'consistency' => $this->consistency($finalGrid, $finalPlugins, $catalog),
			'params_note' => 'Removing a plugin from `plugins` does NOT clear its params. '
				. 'JceModelProfile::save() only merges params keys that are in `plugins` plus editor '
				. 'and setup (models/profile.php:874-880), so the stale configuration is orphaned but '
				. 'preserved and comes back if the plugin is re-enabled. get_jce_profile_params reports '
				. 'orphaned keys.',
			'component'  => $this->jceEditionNotice(),
		];

		if ($notes !== []) {
			$response['notes'] = $notes;
		}

		if ((int) $row['published'] !== 1) {
			$response['notes'][] = 'This profile is unpublished, so the new toolbar takes effect only '
				. 'once it is published.';
		}

		return ToolResult::json($response);
	}

	/**
	 * @param array<int,string>                 $unknown
	 * @param array<string,array<string,mixed>> $catalog
	 */
	private function unknownTokenError(string $field, array $unknown, array $catalog): ToolResult
	{
		$suggestions = [];

		foreach ($unknown as $token) {
			$bare = preg_replace('/^editor[-_]/', '', strtolower($token));

			if ($bare !== $token && \array_key_exists($bare, $catalog)) {
				$suggestions[$token] = $bare;

				continue;
			}

			foreach (array_keys($catalog) as $known) {
				if (levenshtein(strtolower($token), $known) <= 2) {
					$suggestions[$token] = $known;

					break;
				}
			}
		}

		$payload = [
			'ok'    => false,
			'error' => sprintf(
				'REFUSED. Unknown token(s) in %s: %s. Nothing was written. JCE would have accepted these '
					. 'and then dropped them at render time (models/profile.php:294-301), giving you a '
					. 'missing button with no error message, no warning and no log entry — so the whole '
					. 'call is refused instead.',
				$field,
				implode(', ', $unknown)
			),
			'unknown_tokens' => $unknown,
			'valid_source'   => 'helpers/commands.json + helpers/plugins.json + pro.json (Pro only) + '
				. 'installed jce-group plugins matching ^editor[-_]. Call list_jce_plugins for the '
				. 'complete list on this site.',
		];

		if ($suggestions !== []) {
			$payload['did_you_mean'] = $suggestions;
			$payload['prefix_note']  = 'Use the BARE plugin name. An installed plugin element '
				. '`editor_codesample` contributes the token `codesample`; JCE strips the 7-character '
				. 'editor_ prefix when keying its catalogue (helpers/plugins.php:127).';
		}

		return ToolResult::json($payload, true);
	}

	private function sanitiserError(string $field, string $requested, string $sanitised, string $pattern): ToolResult
	{
		return ToolResult::json([
			'ok'    => false,
			'error' => sprintf(
				'REFUSED. JCE sanitises the `%s` column with preg_replace(\'%s\', \'\', $value) on every '
					. 'save (models/profile.php), and applying it to your value changes it. Storing it '
					. 'would silently give you something other than what you asked for, so nothing was '
					. 'written.',
				$field,
				$pattern
			),
			'requested' => $requested,
			'would_be_stored_as' => $sanitised,
			'hint' => $field === 'plugins'
				? 'The usual cause is a hyphen: `editor-codesample` becomes `editorcodesample`. Use the '
					. 'bare underscore-free name, e.g. `codesample`.'
				: 'The usual cause is whitespace or a pipe character inside a token. Toolbar tokens are '
					. 'word characters only.',
		], true);
	}

	/**
	 * @param array<int,array<int,string>>      $grid
	 * @param array<int,string>                 $plugins
	 * @param array<string,array<string,mixed>> $catalog
	 * @return array<string,mixed>
	 */
	private function consistency(array $grid, array $plugins, array $catalog): array
	{
		$buttons = [];

		foreach ($grid as $rowButtons) {
			foreach ($rowButtons as $token) {
				$buttons[] = $token;
			}
		}

		$buttons = array_values(array_unique($buttons));

		$notEnabled = [];

		foreach ($buttons as $token) {
			$entry = $catalog[$token] ?? null;

			if ($entry !== null && (string) $entry['kind'] === 'plugin' && !\in_array($token, $plugins, true)) {
				$notEnabled[] = $token;
			}
		}

		$noButton = [];

		foreach ($plugins as $plugin) {
			if (\in_array($plugin, $buttons, true)) {
				continue;
			}

			$entry   = $catalog[$plugin] ?? null;
			$hasIcon = $entry !== null && trim((string) ($entry['icon'] ?? '')) !== '';

			if ($hasIcon) {
				$noButton[] = $plugin;
			}
		}

		$report = [
			'buttons_without_enabled_plugin' => $notEnabled,
			'iconed_plugins_without_button'  => $noButton,
		];

		if ($notEnabled !== []) {
			$report['warning'] = 'These buttons are in `rows` but their plugins are not in `plugins`, '
				. 'so they will render nothing at all. They were NOT enabled automatically — that would '
				. 'grant a capability you did not ask for. Add them to `plugins` deliberately if that is '
				. 'the intent.';
		}

		if ($noButton !== []) {
			$report['note'] = 'These plugins declare a toolbar icon but have no entry in `rows`, so '
				. 'they are enabled with no way to reach them. Harmless, and correct in some setups '
				. '(for example enabling `browser` for other plugins to use), but usually an oversight.';
		}

		return $report;
	}
}
