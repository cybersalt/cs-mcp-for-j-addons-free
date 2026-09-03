<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjce\Tools\Plugins;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceBootTrait;
use Joomla\CMS\User\User;

/**
 * Every valid `rows` / `plugins` token on this site, and which profiles use it.
 *
 * JCE's plugin model catches people out because most of it is not in the
 * database. The 45 core plugins are a static JSON manifest
 * (`helpers/plugins.json`) filtered by whether a `plugin.js` exists on disk
 * (`helpers/plugins.php:71-73`); the 20 toolbar commands are another
 * (`helpers/commands.json`); Pro's 12 are a third, injected through an event
 * (`pro.json`, `EditorTrait.php:106-149`). Only third-party plugins are real
 * `#__extensions` rows, and even those are split into two kinds by a naming
 * convention rather than a column: `^editor[-_]` makes it an editor plugin,
 * anything else makes it a JCE "extension" (a filesystem, link, popup,
 * aggregator or search adapter) that never appears in a toolbar at all.
 */
final class ListPluginsTool extends AbstractTool
{
	use JceBootTrait;

	public function getName(): string { return 'list_jce_plugins'; }

	public function getDescription(): string
	{
		return 'List every valid JCE toolbar token on this site — commands, core plugins, Pro plugins '
			. 'and installed third-party plugins — with, for each one, the profiles that reference it. '
			. 'FOUR SOURCES, merged: helpers/commands.json (20 built-in toolbar commands such as bold, '
			. 'undo and justifyleft — these are NOT plugins and need no entry in a profile\'s `plugins` '
			. 'column); helpers/plugins.json (45 core plugins, listed only when '
			. 'media/com_jce/editor/tinymce/plugins/<name>/plugin.js exists on disk); '
			. 'plugins/system/jcepro/editor/pro.json (12 Pro plugins, merged in only when the Pro system '
			. 'plugin is enabled — and note that Pro\'s clipboard and styleselect entries REPLACE the '
			. 'core ones outright rather than adding to them, EditorTrait.php:144); and Joomla plugins '
			. 'in the `jce` group. '
			. 'THE `jce` GROUP HOLDS TWO DIFFERENT THINGS, distinguished only by a name prefix '
			. '(helpers/plugins.php:102). An element matching ^editor[-_] is a toolbar plugin, and the '
			. 'token you put in `rows`/`plugins` is the BARE name with that 7-character prefix stripped: '
			. 'editor_codesample contributes `codesample`. Anything else — filesystem_joomla, '
			. 'links_joomlalinks and so on — is a JCE "extension", a backend adapter that never appears '
			. 'in a toolbar. Both are listed here, clearly separated. '
			. 'USAGE ANALYSIS: for each token, which profiles list it in `plugins`, which list it in '
			. '`rows`, and which list it in one but not the other. A button in `rows` whose plugin is '
			. 'absent from `plugins` renders nothing; a plugin in `plugins` with no icon correctly has '
			. 'no entry in `rows`. Also reports tokens used by a profile that exist nowhere — those are '
			. 'silently dropped at render time with no error. '
			. 'Filters: kind (command | plugin | separator), source (core | pro | third_party), '
			. 'used_only (true to list only tokens some profile references), unused_only. '
			. 'Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'kind'       => ['type' => 'string', 'description' => 'command | plugin | separator.'],
				'source'     => ['type' => 'string', 'description' => 'core | pro | third_party.'],
				'used_only'  => ['type' => 'boolean', 'description' => 'Only tokens referenced by at least one profile.'],
				'unused_only' => ['type' => 'boolean', 'description' => 'Only tokens no profile references.'],
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

		$catalog = $this->jcePluginCatalog();
		$usage   = $this->collectUsage();

		$kind       = strtolower(trim((string) ($arguments['kind'] ?? '')));
		$source     = strtolower(trim((string) ($arguments['source'] ?? '')));
		$usedOnly   = (bool) ($arguments['used_only'] ?? false);
		$unusedOnly = (bool) ($arguments['unused_only'] ?? false);

		$tokens = [];

		foreach ($catalog as $name => $entry) {
			if ($kind !== '' && (string) $entry['kind'] !== $kind) {
				continue;
			}

			if ($source !== '' && (string) ($entry['source'] ?? '') !== $source) {
				continue;
			}

			$inPlugins = $usage['plugins'][$name] ?? [];
			$inRows    = $usage['rows'][$name] ?? [];
			$used      = $inPlugins !== [] || $inRows !== [];

			if ($usedOnly && !$used) {
				continue;
			}

			if ($unusedOnly && $used) {
				continue;
			}

			$item = [
				'token'  => $name,
				'kind'   => (string) $entry['kind'],
				'source' => (string) ($entry['source'] ?? ''),
				'toolbar_icon' => (string) ($entry['icon'] ?? ''),
				'palette_row'  => $entry['row'] ?? null,
				'has_profile_config' => (bool) ($entry['editable'] ?? false),
				'used_by' => [
					'enabled_in_profiles' => $inPlugins,
					'on_toolbar_in_profiles' => $inRows,
				],
			];

			if (isset($entry['installed_element'])) {
				$item['installed_element'] = $entry['installed_element'];
				$item['installed_enabled'] = $entry['installed_enabled'];

				if ($entry['installed_enabled'] === false) {
					$item['warning'] = 'The Joomla plugin ' . $entry['installed_element'] . ' is '
						. 'DISABLED, so this token resolves to nothing even where a profile lists it.';
				}
			}

			if (($entry['overrides_core'] ?? false) === true) {
				$item['overrides_core'] = true;
				$item['override_note']  = 'The Pro build supplies its own definition for this name and '
					. 'assigns it over the core one unconditionally (EditorTrait.php:144), so the '
					. 'behaviour and the available profile parameters are Pro\'s, not core\'s.';
			}

			if ((string) ($entry['source'] ?? '') === 'pro' && !$this->jceIsPro()) {
				$item['inert'] = true;
			}

			$onlyRows    = array_values(array_diff($inRows, $inPlugins));
			$onlyPlugins = array_values(array_diff($inPlugins, $inRows));

			if ((string) $entry['kind'] === 'plugin' && $onlyRows !== []) {
				$item['inconsistency'] = 'Profiles ' . implode(', ', $onlyRows) . ' put this button on '
					. 'the toolbar without enabling the plugin. It renders nothing there.';
			}

			if ((string) $entry['kind'] === 'plugin' && $onlyPlugins !== []
				&& trim((string) ($entry['icon'] ?? '')) !== '') {
				$item['note'] = 'Profiles ' . implode(', ', $onlyPlugins) . ' enable this plugin but '
					. 'give it no toolbar entry, even though it declares an icon.';
			}

			$tokens[] = $item;
		}

		$extensions = [];

		foreach ($this->jceInstalledJcePlugins() as $plugin) {
			if ($plugin['is_editor_plugin']) {
				continue;
			}

			$extensions[] = [
				'element' => $plugin['element'],
				'name'    => $plugin['name'],
				'enabled' => $plugin['enabled'],
				'type'    => $plugin['extension_type'],
			];
		}

		$unknownInProfiles = [];

		foreach (array_merge(array_keys($usage['rows']), array_keys($usage['plugins'])) as $token) {
			if (!\array_key_exists($token, $catalog) && !\in_array($token, $unknownInProfiles, true)) {
				$unknownInProfiles[] = $token;
			}
		}

		$response = [
			'ok'     => true,
			'total'  => \count($tokens),
			'tokens' => $tokens,
			'jce_extensions' => [
				'items' => $extensions,
				'note'  => 'These are Joomla plugins in the `jce` group whose element does NOT start '
					. 'with editor_ or editor-. JcePluginsHelper distinguishes the two kinds purely by '
					. 'that prefix (helpers/plugins.php:102 includes, :244 excludes). Extensions are '
					. 'backend adapters — filesystems, link providers, popup handlers, aggregators, '
					. 'search adapters — and never appear in a toolbar, so they are not valid tokens for '
					. '`rows` or `plugins`. They are selected by name in profile params instead, e.g. '
					. 'browser.filesystem.',
			],
			'catalogue_sources' => [
				'commands' => 'administrator/components/com_jce/helpers/commands.json',
				'core_plugins' => 'administrator/components/com_jce/helpers/plugins.json',
				'pro_plugins' => $this->jceIsPro()
					? 'plugins/system/jcepro/editor/pro.json (merged — Pro is enabled)'
					: 'plugins/system/jcepro/editor/pro.json (NOT merged — the Pro system plugin is not enabled)',
				'third_party' => '#__extensions where type=plugin and folder=jce',
			],
			'component' => $this->jceEditionNotice(),
		];

		if ($unknownInProfiles !== []) {
			$response['unknown_tokens_in_profiles'] = $unknownInProfiles;
			$response['unknown_tokens_warning'] = 'These tokens appear in a profile\'s `rows` or '
				. '`plugins` column but match nothing in any catalogue and no installed plugin. '
				. 'JceModelProfile::getRows() drops unknown tokens silently at render time '
				. '(models/profile.php:294-301), so they produce a missing button and no diagnostic '
				. 'anywhere. Usually a typo, or a plugin that has since been uninstalled without '
				. 'removeFromProfile() running.';
		}

		return ToolResult::json($response);
	}

	/**
	 * Which profiles reference each token, split by column.
	 *
	 * @return array{rows:array<string,array<int,string>>,plugins:array<string,array<int,string>>}
	 */
	private function collectUsage(): array
	{
		if (!$this->jceTableExists()) {
			return ['rows' => [], 'plugins' => []];
		}

		$query = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('id'),
				$this->db->quoteName('name'),
				$this->db->quoteName('rows'),
				$this->db->quoteName('plugins'),
			])
			->from($this->db->quoteName($this->jceTable()))
			->order($this->db->quoteName('ordering') . ' ASC');

		$profiles = $this->db->setQuery($query)->loadAssocList() ?: [];

		$usage = ['rows' => [], 'plugins' => []];

		foreach ($profiles as $profile) {
			$label = (string) $profile['name'] . ' (#' . (int) $profile['id'] . ')';

			foreach ($this->jceParseRows($profile['rows'] ?? '') as $buttons) {
				foreach ($buttons as $token) {
					if (!isset($usage['rows'][$token]) || !\in_array($label, $usage['rows'][$token], true)) {
						$usage['rows'][$token][] = $label;
					}
				}
			}

			foreach ($this->jceSplitList($profile['plugins'] ?? '') as $token) {
				if (!isset($usage['plugins'][$token]) || !\in_array($label, $usage['plugins'][$token], true)) {
					$usage['plugins'][$token][] = $label;
				}
			}
		}

		return $usage;
	}
}
