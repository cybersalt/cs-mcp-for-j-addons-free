<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjce\Tools\Editor;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceBootTrait;
use Cybersalt\Plugin\System\Csmcpforjjce\Tools\JceSafetyTrait;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;

/**
 * The whole configuration of one profile, decoded, flattened to dotted paths
 * and annotated with its security-relevant values.
 *
 * `params` is the only place JCE keeps configuration. `com_jce`'s own
 * `config.xml` has three fieldsets and eight fields, none of them about files
 * or uploads — every directory, extension list, size limit and feature flag is
 * per-profile, inside this blob. That is the single most important structural
 * fact about the extension.
 *
 * Two things this tool is careful about:
 *
 *   - ENCRYPTION. A `###AES128###` / `###CTR128###` / `###DEFUSE###` prefix is
 *     detected and reported rather than fed to `json_decode()`, which would
 *     return null and be indistinguishable from "no configuration".
 *   - THE GLOBAL BASELINE. `WFApplication::getParams()` merges
 *     `plg_editors_jce`'s own `#__extensions.params` under the `editor` key
 *     BENEATH the profile's params (`classes/application.php:527-547`). In
 *     2.9.99.10 that plugin declares no config fields, so it is normally empty,
 *     but on a site upgraded from an older JCE it can still hold legacy values
 *     acting as a silent baseline under every profile. It is reported
 *     separately, and the difference between "the profile setting" and "the
 *     effective setting" is stated rather than fudged.
 */
final class GetProfileParamsTool extends AbstractTool
{
	use JceBootTrait;
	use JceSafetyTrait;

	public function getName(): string { return 'get_jce_profile_params'; }

	public function getDescription(): string
	{
		return 'Return one JCE profile\'s entire params configuration, decoded from JSON and also '
			. 'flattened into dotted paths (editor.toolbar_theme, browser.extensions, '
			. 'imgmanager.max_size, setup.custom) for use with set_jce_profile_params. '
			. 'Optionally pass `path` to return a single value, or `key` to return one top-level '
			. 'section (a plugin name, or `editor`, or `setup`). '
			. 'STRUCTURE: top-level keys are one per plugin name plus `editor` for the profile-wide '
			. 'editor settings and, under Pro, `setup` for query-variable profile scoping. This is where '
			. 'ALL of JCE\'s file and upload configuration lives — com_jce\'s own component config has '
			. 'only eight fields and none of them concerns files. browser.dir, browser.extensions, '
			. 'browser.max_size, browser.upload, imgmanager.* and the editor.allow_* / sanitize_html / '
			. 'validate_mimetype family are all per-profile values in this blob. '
			. 'ENCRYPTION: on a site upgraded from an old JCE the column may carry a ###AES128###, '
			. '###CTR128### or ###DEFUSE### prefix keyed from a per-site serverkey.php that ships in no '
			. 'package. This tool detects that and refuses, rather than returning the empty object a '
			. 'naive json_decode would produce. '
			. 'SECURITY SUMMARY: the response always includes a security_relevant section listing the '
			. 'upload filetype lists, upload/delete/rename feature flags, directory roots and content '
			. 'filtering flags actually set on this profile, with any executable extension flagged '
			. 'loudly. That is the same signal audit_jce_profiles uses site-wide. '
			. 'GLOBAL BASELINE: also reports plg_editors_jce\'s own plugin params, which '
			. 'WFApplication::getParams() merges UNDER the profile\'s params as the `editor` key '
			. '(application.php:527-547). Empty on a modern install; on an upgraded one it can be a '
			. 'silent global default beneath every profile. '
			. 'Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'   => ['type' => 'integer', 'description' => 'The #__wf_profiles id.'],
				'key'  => ['type' => 'string', 'description' => 'Return only this top-level section, e.g. "editor", "browser", "imgmanager", "setup".'],
				'path' => ['type' => 'string', 'description' => 'Return only this dotted path, e.g. "editor.toolbar_theme" or "browser.extensions".'],
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

		$decoded = $this->jceDecodeParams($row['params'] ?? '');

		if (!$decoded['ok']) {
			return $this->jceEncryptedParamsError($id, $decoded);
		}

		$params  = $decoded['params'];
		$flat    = $this->jceFlattenParams($params);
		$plugins = $this->jceSplitList($row['plugins'] ?? '');

		$path = trim((string) ($arguments['path'] ?? ''));
		$key  = trim((string) ($arguments['key'] ?? ''));

		if ($path !== '') {
			$sentinel = new \stdClass();
			$value    = $this->jceGetParamPath($params, $path, $sentinel);

			return ToolResult::json([
				'ok'         => true,
				'profile_id' => $id,
				'path'       => $path,
				'set'        => $value !== $sentinel,
				'value'      => $value === $sentinel ? null : $value,
				'note'       => $value === $sentinel
					? 'This path is not present in the profile\'s params. That means JCE falls back to '
						. 'the field default from the plugin\'s XML manifest — an unset value is not the '
						. 'same as a value of 0 or "".'
					: null,
				'component'  => $this->jceEditionNotice(),
			]);
		}

		if ($key !== '') {
			if (!\array_key_exists($key, $params)) {
				return ToolResult::json([
					'ok'         => true,
					'profile_id' => $id,
					'key'        => $key,
					'present'    => false,
					'available_keys' => array_keys($params),
					'note'       => 'This section has no stored values on this profile, so every field '
						. 'in it uses its manifest default.',
					'component'  => $this->jceEditionNotice(),
				]);
			}

			return ToolResult::json([
				'ok'         => true,
				'profile_id' => $id,
				'key'        => $key,
				'present'    => true,
				'values'     => $params[$key],
				'flat'       => $this->jceFlattenParams([$key => $params[$key]]),
				'component'  => $this->jceEditionNotice(),
			]);
		}

		$orphans = array_values(array_diff(array_keys($params), array_merge($plugins, ['editor', 'setup'])));

		$response = [
			'ok'         => true,
			'profile_id' => $id,
			'name'       => (string) $row['name'],
			'published'  => (int) $row['published'] === 1,
			'params'     => [
				'bytes' => $decoded['bytes'],
				'keys'  => array_keys($params),
				'tree'  => $params,
				'flat'  => $flat,
			],
			'security_relevant' => $this->securitySummary($flat),
			'global_baseline'   => $this->globalBaseline(),
			'merge_semantics'   => 'JceModelProfile::save() MERGES incoming params with the stored ones '
				. '(WFUtility::array_merge_recursive_distinct, models/profile.php:908) rather than '
				. 'replacing them, and only for keys in the `plugins` column plus editor and setup '
				. '(:874-880). Consequences: you cannot delete a param through JCE\'s own save path, '
				. 'setting a key to "" merges an empty string rather than removing it, and params for a '
				. 'plugin no longer in `plugins` are orphaned but preserved. set_jce_profile_params owns '
				. 'the whole blob and therefore CAN unset a path — see its `unset` argument.',
			'component'  => $this->jceEditionNotice(),
		];

		if ($orphans !== []) {
			$response['orphaned_keys'] = $orphans;
			$response['orphaned_note'] = 'These sections belong to plugins not currently listed in the '
				. '`plugins` column. They are inert right now, and they will take effect again the '
				. 'moment the plugin is re-enabled on this profile. Worth reviewing rather than '
				. 'assuming they are dead.';
		}

		return ToolResult::json($response);
	}

	/**
	 * Everything in this blob that affects who can upload what, and how content
	 * is filtered.
	 *
	 * @param array<string,mixed> $flat
	 * @return array<string,mixed>
	 */
	private function securitySummary(array $flat): array
	{
		$summary = [
			'upload_filetypes'   => [],
			'directories'        => [],
			'feature_flags'      => [],
			'content_filtering'  => [],
			'executable_extensions_found' => [],
		];

		foreach ($flat as $path => $value) {
			if (!\is_scalar($value) && $value !== null) {
				continue;
			}

			if (str_ends_with($path, 'extensions')) {
				$tokens = $this->jceExtractExtensionTokens($value);
				$bad    = array_values(array_intersect($tokens, $this->jceExecutableExtensions()));

				$summary['upload_filetypes'][$path] = [
					'value'  => $value,
					'tokens' => $tokens,
				];

				if ($bad !== []) {
					$summary['executable_extensions_found'][$path] = $bad;
				}

				continue;
			}

			if (preg_match('/(^|\.)(dir|path|thumbnail_folder)$/', $path) === 1) {
				$summary['directories'][$path] = $value;

				continue;
			}

			if (preg_match('/(^|\.)(upload|folder_new|folder_delete|folder_rename|folder_move|file_delete|file_rename|file_move|allow_download|inline_upload)$/', $path) === 1) {
				$summary['feature_flags'][$path] = $value;

				continue;
			}

			if (preg_match('/^editor\.(allow_php|allow_javascript|allow_css|allow_event_attributes|allow_custom_xml|sanitize_html|verify_html|validate_mimetype|validate_styles|invalid_elements|extended_elements|invalid_attributes|schema|max_size|total_files|total_size|websafe_mode)$/', $path) === 1) {
				$summary['content_filtering'][$path] = $value;
			}
		}

		if ($summary['executable_extensions_found'] !== []) {
			$summary['critical'] = 'EXECUTABLE EXTENSIONS ARE PERMITTED BY THIS PROFILE. Anyone this '
				. 'profile applies to can upload a file with one of these extensions through JCE\'s file '
				. 'browser. This is the documented in-the-wild exploitation of CVE-2026-48907, in which '
				. 'attackers wrote exactly such a profile and then uploaded a webshell. If you did not '
				. 'configure this deliberately, treat the site as compromised: run audit_jce_profiles, '
				. 'check for unexpected files in the media directories, and look for rogue Super Users '
				. 'and scheduled tasks.';
		}

		if (($summary['content_filtering']['editor.allow_php'] ?? null) !== null
			&& $this->jceNormaliseFlag($summary['content_filtering']['editor.allow_php']) === '1') {
			$summary['allow_php_warning'] = 'editor.allow_php is ON for this profile. Every user it '
				. 'applies to can put raw PHP into content. On a profile assigned to Author or Editor '
				. 'that is a full privilege escalation.';
		}

		return $summary;
	}

	/**
	 * `plg_editors_jce`'s own plugin params, which sit underneath every profile.
	 *
	 * @return array<string,mixed>
	 */
	private function globalBaseline(): array
	{
		$plugin = PluginHelper::getPlugin('editors', 'jce');

		if ($plugin === null || !isset($plugin->params)) {
			return [
				'plugin'  => 'plg_editors_jce',
				'present' => false,
				'note'    => 'The JCE editor plugin was not found. That is unusual on a working site — '
					. 'without it JCE is installed but is not selectable as the site editor.',
			];
		}

		$registry = new Registry($plugin->params);
		$values   = $registry->toArray();

		return [
			'plugin'  => 'plg_editors_jce',
			'present' => true,
			'params'  => $values,
			'note'    => $values === []
				? 'Empty, which is normal: in 2.9.99.10 plg_editors_jce declares no config fields at '
					. 'all, so the profile is the sole source of editor configuration.'
				: 'NOT EMPTY. WFApplication::getParams() nests these under the `editor` key and merges '
					. 'the profile\'s params OVER them (classes/application.php:527-547), so these act '
					. 'as a silent global default beneath EVERY profile. Modern JCE defines no fields '
					. 'here, so these are almost certainly legacy values left by an upgrade from an '
					. 'older version. They are worth understanding before concluding that a profile '
					. 'setting is the effective setting.',
		];
	}
}
