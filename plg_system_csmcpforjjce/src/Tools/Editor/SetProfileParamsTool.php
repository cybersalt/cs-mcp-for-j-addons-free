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
 * The one tool that writes into `params`, and therefore the one that carries
 * every refusal in JceSafetyTrait.
 *
 * It writes the `params` column directly rather than going through
 * `JceModelProfile::save()`, which MERGES incoming params into the stored ones
 * (`models/profile.php:908`). That merge makes deletion impossible through the
 * vendor path — setting a key to `""` merges an empty string, and unsetting it
 * leaves the old value untouched — so a tool that wants honest set-and-unset
 * semantics has to own the whole blob. Owning it also means owning the
 * responsibility the merge provided: keys not being written are read back and
 * preserved verbatim, including params orphaned by a plugin that is no longer
 * in the `plugins` column.
 *
 * What it refuses, unconditionally and without an override flag:
 *
 *   - anything at all when the installed JCE is below 2.9.99.5
 *   - any write to a profile whose stored params are encrypted
 *   - any executable extension in any `*.extensions` path
 *   - any change that LOOSENS editor.allow_php, allow_javascript,
 *     allow_event_attributes, allow_custom_xml, validate_mimetype,
 *     sanitize_html or verify_html — tightening them is allowed
 *   - a `dir` value that traverses out of, or replaces, the media root
 */
final class SetProfileParamsTool extends AbstractTool
{
	use JceBootTrait;
	use JceSafetyTrait;

	public function getName(): string { return 'set_jce_profile_params'; }

	public function getDescription(): string
	{
		return 'Set or unset values inside a JCE profile\'s params blob, addressed by dotted path — '
			. 'editor.toolbar_theme, browser.extensions, imgmanager.max_size, browser.upload, '
			. 'setup.custom. '
			. 'set: an object of path => value. unset: an array of paths to remove entirely. Supply '
			. 'either or both. Everything not named is preserved exactly as it was. '
			. 'REAL UNSET IS POSSIBLE HERE and nowhere else. JCE\'s own save path merges incoming params '
			. 'into the stored ones (WFUtility::array_merge_recursive_distinct, models/profile.php:908), '
			. 'so through the JCE UI a param can never be deleted — setting it to "" merges an empty '
			. 'string. This tool writes the whole column, so `unset` genuinely removes a path and the '
			. 'field returns to its manifest default. '
			. 'HARD REFUSALS, none of which has an override flag: '
			. '(1) any write at all when the installed JCE is below 2.9.99.5, which carries '
			. 'CVE-2026-48907 — unauthenticated RCE, CVSS 10.0, CISA Known Exploited Vulnerabilities '
			. 'catalogue, mass-exploited June 2026, whose documented payload is a profile row exactly '
			. 'like the one this tool writes; '
			. '(2) any write to a profile whose params are encrypted with a ###AES128###, ###CTR128### '
			. 'or ###DEFUSE### prefix — JCE 2.9.99.10 has a decrypt path but no encrypt path, so writing '
			. 'would silently and permanently convert the row to plaintext; '
			. '(3) ANY executable extension in ANY path ending in "extensions" — php, phtml, phar, '
			. 'shtml, asp, jsp, cgi, htaccess and the rest of the vendor\'s own blocklist, in any '
			. 'position including double extensions like php.jpg. The whole call is refused; nothing is '
			. 'stripped and stored silently; '
			. '(4) any change that LOOSENS editor.allow_php, editor.allow_javascript, '
			. 'editor.allow_event_attributes, editor.allow_custom_xml, editor.validate_mimetype, '
			. 'editor.sanitize_html or editor.verify_html. Tightening them is allowed. There is no '
			. 'automation use case for switching PHP-in-content on; '
			. '(5) a `dir` value containing "..", an absolute path, or the filesystem root — JCE '
			. 'enforces browse and upload boundaries against this stored value, so a bad value here IS '
			. 'the boundary rather than something later validation catches. '
			. 'Set preview=true to see the exact before/after without writing. '
			. 'Refuses to write a section for a plugin not listed in the profile\'s `plugins` column '
			. 'unless allow_orphan_section=true, because such params are inert until that plugin is '
			. 'enabled and silently take effect if it ever is.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'    => ['type' => 'integer', 'description' => 'The #__wf_profiles id.'],
				'set'   => ['type' => 'object', 'description' => 'Dotted path => value. Values may be strings, numbers, booleans, objects or arrays. JCE stores yes/no fields as the strings "0" and "1".'],
				'unset' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Dotted paths to remove entirely, returning those fields to their manifest defaults.'],
				'preview' => ['type' => 'boolean', 'description' => 'true to validate and show the before/after without writing.'],
				'allow_orphan_section' => ['type' => 'boolean', 'description' => 'Permit writing a section for a plugin not currently in the profile\'s `plugins` column. Default false.'],
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

		$decoded = $this->jceDecodeParams($row['params'] ?? '');

		if (!$decoded['ok']) {
			return $this->jceEncryptedParamsError($id, $decoded);
		}

		$sets   = (array) ($arguments['set'] ?? []);
		$unsets = [];

		foreach ((array) ($arguments['unset'] ?? []) as $path) {
			$path = trim((string) $path);

			if ($path !== '') {
				$unsets[] = $path;
			}
		}

		if ($sets === [] && $unsets === []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Nothing to do. Supply `set` (path => value), `unset` (array of paths), or '
					. 'both.',
			], true);
		}

		// ------------------------------------------------------------------
		// Refusals, before anything is modified in memory.
		// ------------------------------------------------------------------

		foreach ($sets as $path => $value) {
			$path = trim((string) $path);

			if ($path === '' || str_contains($path, '..') || preg_match('/^[\w.]+$/', $path) !== 1) {
				return ToolResult::json([
					'ok'    => false,
					'error' => sprintf(
						'Invalid params path "%s". Paths are dot-separated word characters, e.g. '
							. '"editor.toolbar_theme" or "browser.extensions".',
						(string) $path
					),
				], true);
			}

			if (str_ends_with($path, 'extensions')) {
				$refusal = $this->jceAssertNoExecutableExtensions($path, $this->stringifyForScan($value), [
					'profile_id' => $id,
					'profile_name' => (string) $row['name'],
				]);

				if ($refusal !== null) {
					return $refusal;
				}
			}

			if (preg_match('/(^|\.)(dir|thumbnail_folder)$/', $path) === 1 && \is_scalar($value)) {
				$refusal = $this->jceAssertSafeDirectory($path, (string) $value);

				if ($refusal !== null) {
					return $refusal;
				}
			}
		}

		if (($refusal = $this->jceAssertNotWeakening($sets)) !== null) {
			return $refusal;
		}

		// Unsetting a one-way security param is also a loosening: the field
		// reverts to its manifest default, which for allow_* is off but for
		// validate_mimetype and sanitize_html is not guaranteed to be on.
		$unsafeUnsets = array_values(array_intersect($unsets, [
			'editor.validate_mimetype', 'editor.sanitize_html', 'editor.verify_html',
		]));

		if ($unsafeUnsets !== []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'REFUSED. Unsetting ' . implode(', ', $unsafeUnsets) . ' removes an explicit '
					. 'hardening setting and returns the field to whatever its manifest default happens '
					. 'to be on this version — which is not something a caller can rely on. Set the '
					. 'value explicitly instead. Loosening these is refused either way.',
				'unset_refused' => $unsafeUnsets,
			], true);
		}

		$plugins  = $this->jceSplitList($row['plugins'] ?? '');
		$allowed  = array_merge($plugins, ['editor', 'setup']);
		$orphans  = [];

		foreach (array_keys($sets) as $path) {
			$section = explode('.', (string) $path)[0];

			if (!\in_array($section, $allowed, true) && !\in_array($section, $orphans, true)) {
				$orphans[] = $section;
			}
		}

		if ($orphans !== [] && !(bool) ($arguments['allow_orphan_section'] ?? false)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf(
					'REFUSED. Section(s) %s are not in this profile\'s `plugins` column, so params '
						. 'written there are inert right now — and would take effect silently the moment '
						. 'that plugin is added to the profile. That is how a stale, permissive '
						. 'configuration reappears months later with nobody remembering writing it.',
					implode(', ', $orphans)
				),
				'orphan_sections' => $orphans,
				'enabled_plugins' => $plugins,
				'resolution' => 'Either enable the plugin first with set_jce_profile_toolbar, or pass '
					. 'allow_orphan_section=true if writing dormant configuration is genuinely the '
					. 'intent. Note that JCE\'s own save path would have dropped these keys entirely '
					. '(models/profile.php:874-880) rather than storing them.',
			], true);
		}

		// ------------------------------------------------------------------
		// Apply.
		// ------------------------------------------------------------------

		$before  = $decoded['params'];
		$after   = $before;
		$changes = [];

		foreach ($sets as $path => $value) {
			$path     = (string) $path;
			$sentinel = new \stdClass();
			$old      = $this->jceGetParamPath($before, $path, $sentinel);

			$after = $this->jceSetParamPath($after, $path, $value);

			$changes[] = [
				'action' => 'set',
				'path'   => $path,
				'was'    => $old === $sentinel ? null : $old,
				'was_set' => $old !== $sentinel,
				'now'    => $value,
			];
		}

		foreach ($unsets as $path) {
			$sentinel = new \stdClass();
			$old      = $this->jceGetParamPath($before, $path, $sentinel);

			if ($old === $sentinel) {
				$changes[] = ['action' => 'unset', 'path' => $path, 'was_set' => false,
					'note' => 'Not present; nothing to remove.'];

				continue;
			}

			$after = $this->jceUnsetParamPath($after, $path);

			$changes[] = ['action' => 'unset', 'path' => $path, 'was' => $old, 'was_set' => true];
		}

		$encoded = $this->jceEncodeParams($after);

		$warnings = [];

		foreach ($sets as $path => $value) {
			if (str_ends_with((string) $path, 'extensions')) {
				foreach ($this->jceExtensionWarnings((string) $path, $this->stringifyForScan($value)) as $w) {
					$warnings[] = $w;
				}
			}
		}

		if ($orphans !== []) {
			$warnings[] = 'Wrote dormant section(s) ' . implode(', ', $orphans) . ' for plugin(s) not '
				. 'enabled on this profile, at your explicit request. They do nothing until the plugin '
				. 'is added to `plugins`, at which point they take effect immediately.';
		}

		// #__wf_profiles.params is a `text` column — 65,535 bytes on MySQL. MySQL
		// truncates silently in non-strict mode, which would corrupt the JSON at
		// an arbitrary byte and leave the profile unreadable.
		if (\strlen($encoded) > 65535) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf(
					'REFUSED. The resulting params JSON is %d bytes, over the 65,535-byte limit of the '
						. '`text` column it lives in. MySQL truncates silently in non-strict mode, which '
						. 'would cut the JSON mid-structure and leave the profile\'s entire configuration '
						. 'unreadable. Nothing was written.',
					\strlen($encoded)
				),
			], true);
		}

		$response = [
			'ok'         => true,
			'profile_id' => $id,
			'name'       => (string) $row['name'],
			'changes'    => $changes,
			'params_bytes' => ['before' => $decoded['bytes'], 'after' => \strlen($encoded)],
			'security_note' => 'This tool wrote the whole `params` column rather than going through '
				. 'JceModelProfile::save(), which merges instead of replacing (models/profile.php:908) '
				. 'and therefore cannot delete anything. Every key not named above was read back and '
				. 'preserved verbatim, including params orphaned by plugins no longer in the `plugins` '
				. 'column — JCE\'s own UI preserves those too.',
			'component'  => $this->jceEditionNotice(),
		];

		if ((bool) ($arguments['preview'] ?? false)) {
			$response['preview'] = true;
			$response['written'] = false;
			$response['message'] = 'Validated but not written. Every refusal that applies to a real '
				. 'write was evaluated first, so a preview that returns ok:true will also write '
				. 'successfully. Call again without preview to apply.';
			$response['result_tree'] = $after;

			if ($warnings !== []) {
				$response['warnings'] = $warnings;
			}

			return ToolResult::json($response);
		}

		$set = [$this->db->quoteName('params') . ' = ' . $this->db->quote($encoded)];

		foreach ($this->jceModifiedColumns((int) $actor->id) as $column => $value) {
			$set[] = $this->db->quoteName($column) . ' = '
				. (\is_int($value) ? (string) $value : $this->db->quote((string) $value));
		}

		$query = $this->db->getQuery(true)
			->update($this->db->quoteName($this->jceTable()))
			->set($set)
			->where($this->db->quoteName('id') . ' = ' . $id);

		$this->db->setQuery($query)->execute();

		$response['written'] = true;

		if ($warnings !== []) {
			$response['warnings'] = $warnings;
		}

		if ((int) $row['published'] !== 1) {
			$response['notes'][] = 'This profile is unpublished, so none of these settings take effect '
				. 'until it is published.';
		}

		return ToolResult::json($response);
	}

	/**
	 * Render a value for extension scanning.
	 *
	 * A filetype list is normally a string, but the JCE `filetype` form field
	 * can also submit an array of tokens, and a caller may reasonably pass one.
	 * Flattening to a comma list lets the same scanner handle both.
	 */
	private function stringifyForScan(mixed $value): string
	{
		if (\is_array($value)) {
			$parts = [];

			array_walk_recursive($value, static function ($item) use (&$parts): void {
				if (\is_scalar($item)) {
					$parts[] = (string) $item;
				}
			});

			return implode(',', $parts);
		}

		return \is_scalar($value) ? (string) $value : '';
	}
}
