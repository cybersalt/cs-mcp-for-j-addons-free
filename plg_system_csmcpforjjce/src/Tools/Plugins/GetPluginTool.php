<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjce\Tools\Plugins;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceBootTrait;
use Joomla\CMS\User\User;

/**
 * One plugin's per-profile parameter schema, read from the manifest that is
 * actually in force on this site.
 *
 * The distinction that makes this tool necessary is a JCE-specific extension to
 * the Joomla manifest format. A `jce`-group plugin XML carries TWO parameter
 * surfaces, and they are easy to confuse:
 *
 *   `<config><fields name="params">`  → ordinary Joomla plugin params, stored
 *                                        site-wide in `#__extensions.params`
 *   `<fields name="<pluginname>">`    → PER-PROFILE params, stored under the
 *                                        key `<pluginname>` inside
 *                                        `#__wf_profiles.params`
 *
 * Only the second is what set_jce_profile_params writes, and it is the one
 * reported here.
 *
 * The second complication is that Pro ships REPLACEMENT manifests for `browser`,
 * `imgmanager`, `link`, `clipboard`, `styleselect`, `source` and `textpattern`
 * at `plugins/system/jcepro/editor/plugins/<name>/<name>.xml`. The same plugin
 * name therefore has a larger field set under Pro, and a tool that only knew the
 * core manifest would report valid Pro configuration as unknown. The Pro
 * manifest is preferred when the Pro system plugin is enabled, and which one was
 * used is stated in the response.
 */
final class GetPluginTool extends AbstractTool
{
	use JceBootTrait;

	public function getName(): string { return 'get_jce_plugin'; }

	public function getDescription(): string
	{
		return 'Return the per-profile parameter schema for one JCE plugin — the field names, types and '
			. 'defaults you can write with set_jce_profile_params — plus which profiles currently '
			. 'reference it. '
			. 'Also accepts the two pseudo-plugins: "editor" (the profile-wide editor settings, from '
			. 'administrator/components/com_jce/models/forms/editor.xml, extended by Pro\'s own '
			. 'plugins/system/jcepro/forms/editor.xml when Pro is enabled) and "setup" (Pro-only '
			. 'query-variable profile scoping, from plugins/system/jcepro/forms/setup.xml). '
			. 'TWO PARAMETER SURFACES, and this tool reports the right one. A jce-group plugin manifest '
			. 'carries both `<config><fields name="params">` — ordinary Joomla plugin params stored '
			. 'site-wide in #__extensions.params — and a top-level `<fields name="<pluginname>">`, which '
			. 'is the JCE-specific extension holding the PER-PROFILE params stored inside '
			. '#__wf_profiles.params. Only the second is reported, because only the second is what a '
			. 'profile params write touches. '
			. 'PRO CHANGES THE ANSWER. Pro ships replacement manifests for browser, imgmanager, link, '
			. 'clipboard, styleselect, source and textpattern, so the same plugin name has a LARGER '
			. 'field set under Pro. The Pro manifest is preferred when the Pro system plugin is enabled, '
			. 'and the response states which file was read so the answer is never ambiguous. '
			. 'Security-relevant fields (extensions, dir, max_size, upload and the file-management '
			. 'flags) are called out separately, with a note that set_jce_profile_params hard-refuses '
			. 'executable extensions in any of them. '
			. 'Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'name' => ['type' => 'string', 'description' => 'The bare plugin name, e.g. browser, imgmanager, link, table. Also accepts "editor" and "setup".'],
			],
			'required' => ['name'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$base = $this->jceAdminBase();

		if ($base === null) {
			return $this->jceNotInstalledError();
		}

		$name = strtolower($this->requireString($arguments, 'name'));
		$name = (string) preg_replace('/^editor[-_]/', '', $name);

		if (preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Plugin names are lowercase word characters only. Use the bare name — an '
					. 'installed element `editor_codesample` is addressed as `codesample`.',
			], true);
		}

		$catalog = $this->jcePluginCatalog();
		$sources = $this->manifestCandidates($base, $name);

		$fields = [];
		$read   = [];

		foreach ($sources as $candidate) {
			$found = $this->readFields($candidate['file'], $candidate['section']);

			if ($found === null) {
				continue;
			}

			$read[] = [
				'file'    => $candidate['label'],
				'edition' => $candidate['edition'],
				'fields'  => \count($found),
			];

			// Later candidates are lower priority; earlier ones win on a clash,
			// mirroring the way Pro assigns over the core definition.
			$fields += $found;
		}

		if ($read === []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf(
					'No per-profile parameter manifest was found for "%s". Either the plugin is not '
						. 'installed on this site, or it declares no profile-level configuration at all '
						. '(many do not — a plugin is marked `editable` in the catalogue only when its '
						. 'manifest carries a populated <fields> block, helpers/plugins.php:129-185).',
					$name
				),
				'in_catalogue' => \array_key_exists($name, $catalog),
				'catalogue_entry' => $catalog[$name] ?? null,
				'searched'     => array_column($sources, 'label'),
			], true);
		}

		$usage = $this->usage($name);

		$response = [
			'ok'      => true,
			'name'    => $name,
			'catalogue_entry' => $catalog[$name] ?? null,
			'params_key' => $name,
			'params_key_note' => sprintf(
				'These fields live under the top-level key "%s" inside #__wf_profiles.params, addressed '
					. 'by set_jce_profile_params as "%s.<field>".',
				$name,
				$name
			),
			'manifests_read' => $read,
			'field_count' => \count($fields),
			'fields'  => array_values($fields),
			'used_by' => $usage,
			'component' => $this->jceEditionNotice(),
		];

		if (\count($read) > 1) {
			$response['merge_note'] = 'More than one manifest contributed. Where the same field name '
				. 'appears in both, the Pro definition wins — Pro assigns over the core plugin '
				. 'definition unconditionally (EditorTrait.php:144) and ships replacement manifests for '
				. 'browser, imgmanager, link, clipboard, styleselect, source and textpattern.';
		}

		$security = [];

		foreach ($fields as $field) {
			if (preg_match('/^(extensions|dir|path|max_size|total_files|total_size|upload|folder_new|folder_delete|folder_rename|folder_move|file_delete|file_rename|file_move|allow_download|websafe_mode|thumbnail_folder)$/', (string) $field['name']) === 1) {
				$security[] = $field;
			}
		}

		if ($security !== []) {
			$response['security_relevant_fields'] = $security;
			$response['security_note'] = 'These fields govern what may be uploaded and where. JCE '
				. 'validates uploads at request time AGAINST these stored values '
				. '(WFFileBrowser::validateUploadedFile(), classes/browser.php:1965-2028), so a '
				. 'permissive value here is the vulnerability rather than something later validation '
				. 'catches. set_jce_profile_params hard-refuses any executable extension in an '
				. '`extensions` field, and any `dir` value that traverses or replaces the media root.';
		}

		return ToolResult::json($response);
	}

	/**
	 * Manifest files to try, highest priority first.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function manifestCandidates(string $base, string $name): array
	{
		$candidates = [];
		$pro        = $this->jceIsPro();

		if ($name === 'editor') {
			if ($pro) {
				$candidates[] = [
					'file'    => JPATH_PLUGINS . '/system/jcepro/forms/editor.xml',
					'label'   => 'plugins/system/jcepro/forms/editor.xml',
					'section' => 'editor',
					'edition' => 'pro',
				];
			}

			$candidates[] = [
				'file'    => $base . '/models/forms/editor.xml',
				'label'   => 'administrator/components/com_jce/models/forms/editor.xml',
				'section' => 'editor',
				'edition' => 'core',
			];

			return $candidates;
		}

		if ($name === 'setup') {
			return [[
				'file'    => JPATH_PLUGINS . '/system/jcepro/forms/setup.xml',
				'label'   => 'plugins/system/jcepro/forms/setup.xml',
				'section' => 'setup',
				'edition' => 'pro',
			]];
		}

		if ($pro) {
			$candidates[] = [
				'file'    => JPATH_PLUGINS . '/system/jcepro/editor/plugins/' . $name . '/' . $name . '.xml',
				'label'   => 'plugins/system/jcepro/editor/plugins/' . $name . '/' . $name . '.xml',
				'section' => $name,
				'edition' => 'pro',
			];
		}

		$candidates[] = [
			'file'    => JPATH_SITE . '/components/com_jce/editor/plugins/' . $name . '/' . $name . '.xml',
			'label'   => 'components/com_jce/editor/plugins/' . $name . '/' . $name . '.xml',
			'section' => $name,
			'edition' => 'core',
		];

		// Third-party plugins live in the Joomla plugin tree under either
		// naming form; helpers/plugins.php accepts both.
		foreach (['editor_' . $name, 'editor-' . $name] as $element) {
			$candidates[] = [
				'file'    => JPATH_PLUGINS . '/jce/' . $element . '/' . $element . '.xml',
				'label'   => 'plugins/jce/' . $element . '/' . $element . '.xml',
				'section' => $name,
				'edition' => 'third_party',
			];
		}

		return $candidates;
	}

	/**
	 * Read `<fields name="$section">` from a manifest.
	 *
	 * Deliberately ignores `<config><fields name="params">`, which is the
	 * ordinary Joomla plugin params surface stored in #__extensions and has
	 * nothing to do with profiles.
	 *
	 * @return array<string,array<string,mixed>>|null Null when the file or the section is absent.
	 */
	private function readFields(string $file, string $section): ?array
	{
		if (!is_file($file)) {
			return null;
		}

		$xml = @simplexml_load_file($file, 'SimpleXMLElement', LIBXML_NONET);

		if ($xml === false) {
			return null;
		}

		$blocks = $xml->xpath('//fields[@name="' . $section . '"]') ?: [];
		$fields = [];

		foreach ($blocks as $block) {
			// Skip a <fields name="params"> that sits inside <config>.
			$parents = $block->xpath('parent::config');

			if ($parents !== [] && $parents !== false) {
				continue;
			}

			foreach ($block->xpath('.//field') ?: [] as $field) {
				$fieldName = trim((string) $field['name']);

				if ($fieldName === '') {
					continue;
				}

				$entry = [
					'name'    => $fieldName,
					'path'    => $section . '.' . $fieldName,
					'type'    => trim((string) $field['type']),
					'default' => isset($field['default']) ? (string) $field['default'] : null,
					'label_key' => trim((string) $field['label']),
				];

				$options = [];

				foreach ($field->xpath('./option') ?: [] as $option) {
					$options[] = (string) $option['value'];
				}

				if ($options !== []) {
					$entry['options'] = $options;
				}

				$fields[$fieldName] = $entry;
			}
		}

		return $fields === [] ? null : $fields;
	}

	/** @return array<string,mixed> */
	private function usage(string $name): array
	{
		if (!$this->jceTableExists()) {
			return ['enabled_in' => [], 'on_toolbar_in' => [], 'configured_in' => []];
		}

		$query = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('id'),
				$this->db->quoteName('name'),
				$this->db->quoteName('rows'),
				$this->db->quoteName('plugins'),
				$this->db->quoteName('params'),
			])
			->from($this->db->quoteName($this->jceTable()))
			->order($this->db->quoteName('ordering') . ' ASC');

		$profiles = $this->db->setQuery($query)->loadAssocList() ?: [];

		$enabled    = [];
		$onToolbar  = [];
		$configured = [];

		foreach ($profiles as $profile) {
			$label = (string) $profile['name'] . ' (#' . (int) $profile['id'] . ')';

			if (\in_array($name, $this->jceSplitList($profile['plugins'] ?? ''), true)) {
				$enabled[] = $label;
			}

			foreach ($this->jceParseRows($profile['rows'] ?? '') as $buttons) {
				if (\in_array($name, $buttons, true)) {
					$onToolbar[] = $label;

					break;
				}
			}

			$decoded = $this->jceDecodeParams($profile['params'] ?? '');

			if ($decoded['ok'] && \array_key_exists($name, $decoded['params'])) {
				$configured[] = $label;
			}
		}

		return [
			'enabled_in'    => $enabled,
			'on_toolbar_in' => $onToolbar,
			'configured_in' => $configured,
			'note'          => 'A profile can configure a plugin it has not enabled — those params are '
				. 'orphaned but preserved, and take effect the moment the plugin is added back to the '
				. '`plugins` column (models/profile.php:874-880).',
		];
	}
}
