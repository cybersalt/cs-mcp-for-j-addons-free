<?php
/**
 * @package     MCP for Community Builder
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Config;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

/**
 * Targeted configuration writes.
 *
 * CB's own Configuration save replaces the whole JSON blob with whatever the
 * form posted — it survives only because the form posts every key. So this
 * never hands CB a partial blob: it re-reads the stored blob, merges the named
 * keys, and stores the WHOLE result through CB's own `PluginTable` (row 1).
 * Going through `PluginTable::store()` rather than an UPDATE matters: when the
 * name style changes, that store publishes and unpublishes the name /
 * firstname / middlename / lastname fields to match
 * (`libraries/CBLib/CB/Database/Table/PluginTable.php:132-160`). Afterwards
 * the blob is re-read straight from the database and diffed, to prove nothing
 * outside the named keys moved.
 */
final class SetConfigTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use CbConfigSchemaTrait;

	public function getName(): string { return 'set_cb_config'; }

	public function getDescription(): string
	{
		return 'Change named keys in Community Builder\'s configuration. Each key is validated against CB\'s own '
			. 'Configuration form (unknown keys are refused with suggestions; list and yes/no keys must use a value '
			. 'the form offers; view access levels and field names must exist). The stored JSON blob is re-read, '
			. 'merged and saved whole through CB\'s own PluginTable, so the name-style side effect (publishing the '
			. 'name / first / middle / last name fields to match) still happens; then it is re-read and diffed to '
			. 'prove only the named keys changed. CB\'s own Configuration save replaces the whole blob, which is '
			. 'why a partial write is never handed to it. Refused: installFromWebKey / installFromWebToken '
			. '(Joomlapolis membership credentials) and keys the form does not store. dry_run shows the change '
			. 'without saving.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'values'  => ['type' => 'object', 'description' => 'key => new value, e.g. {"reg_admin_approval": "1", "reg_welcome_sub": "Welcome to [sitename]"}.'],
				'dry_run' => ['type' => 'boolean', 'description' => 'Validate and show before/after without saving. Default false.'],
			],
			'required'             => ['values'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cbInstalled()) {
			return $this->cbNotInstalledError();
		}

		$this->cbRequireAccess($actor, 'core.admin', 'com_comprofiler');

		$values = $arguments['values'] ?? null;

		if (!\is_array($values) || $values === []) {
			throw new \InvalidArgumentException('values must be a non-empty object of key => value.');
		}

		$schema  = $this->cbConfigSchema();
		$before  = $this->cbRawConfig();
		$changes = [];
		$errors  = [];

		foreach ($values as $key => $value) {
			$key = (string) $key;

			if (\in_array($key, self::CB_SECRET_CONFIG_KEYS, true)) {
				$errors[] = ['key' => $key, 'error' => 'This is a Joomlapolis membership credential for Install from web. It is never written through MCP.'];
				continue;
			}

			if (!isset($schema[$key])) {
				$errors[] = ['key' => $key, 'error' => 'Not a key on CB\'s Configuration form.', 'did_you_mean' => $this->cbConfigSuggest($key)];
				continue;
			}

			if (!$this->cbConfigWritable($schema[$key])) {
				$errors[] = ['key' => $key, 'error' => 'CB renders or manages this key itself (type ' . $schema[$key]['type'] . '); it is not a stored setting.'];
				continue;
			}

			try {
				$new = $this->cbConfigValidate($key, $schema[$key], $value);
			} catch (\InvalidArgumentException $e) {
				$errors[] = ['key' => $key, 'error' => $e->getMessage()];
				continue;
			}

			$old = \array_key_exists($key, $before) ? (string) $before[$key] : null;

			if ($old === $new) {
				continue;
			}

			$changes[$key] = ['before' => $old, 'after' => $new, 'was_default' => $old === null];
		}

		if ($errors !== []) {
			return ToolResult::json(['ok' => false, 'error' => 'Nothing was written.', 'problems' => $errors], true);
		}

		if ($changes === []) {
			return ToolResult::json(['ok' => true, 'changed' => false, 'note' => 'Every value already matches; nothing to write.']);
		}

		$notes = [];

		if (isset($changes['name_style'])) {
			$notes[] = 'name_style changes which name fields are published: 1 = name only; 2 = first + last; 3 = '
				. 'first + middle + last. CB\'s PluginTable::store() flips those fields\' published flag.';
		}

		if (isset($changes['emailpass']) && $changes['emailpass']['after'] === '1') {
			$notes[] = 'With Generate Registration Passwords on, approving or confirming a member REPLACES their '
				. 'password with a random one that only the welcome email carries.';
		}

		if ($this->cbBool($arguments, 'dry_run')) {
			return ToolResult::json(['ok' => true, 'dry_run' => true, 'would_change' => $changes, 'notes' => $notes]);
		}

		$changeKeys = array_keys($changes);

		$run = $this->cbRun($actor, static function () use ($changes): array {
			$plugin = new \CB\Database\Table\PluginTable();

			if (!$plugin->load(1)) {
				throw new \RuntimeException('Community Builder could not load its configuration row (#__comprofiler_plugin id 1).');
			}

			$params = json_decode((string) $plugin->params, true);

			if (!\is_array($params)) {
				throw new \RuntimeException('The stored CB configuration is not valid JSON. Refusing to overwrite it.');
			}

			foreach ($changes as $key => $change) {
				$params[$key] = $change['after'];
			}

			$plugin->params = json_encode($params);
			$ok             = $plugin->store();

			return ['ok' => (bool) $ok, 'error' => $ok ? null : (string) $plugin->getError()];
		});

		$after = $this->cbRawConfig();

		$verified   = [];
		$unexpected = [];

		foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
			$b = \array_key_exists($key, $before) ? (string) $before[$key] : null;
			$a = \array_key_exists($key, $after) ? (string) $after[$key] : null;

			if (\in_array($key, $changeKeys, true)) {
				$verified[$key] = $a === $changes[$key]['after'];
			} elseif ($b !== $a) {
				$unexpected[$key] = \in_array($key, self::CB_SECRET_CONFIG_KEYS, true)
					? ['before' => '[redacted]', 'after' => '[redacted]']
					: ['before' => $b, 'after' => $a];
			}
		}

		$ok = $run['result']['ok'] && !\in_array(false, $verified, true);

		return ToolResult::json([
			'ok'                  => $ok,
			'changed'             => $changes,
			'verified_by_reread'  => $verified,
			'unexpected_changes'  => $unexpected === [] ? new \stdClass() : $unexpected,
			'error'               => $run['result']['error'],
			'notes'               => $notes,
			'cb_messages'         => $run['messages'],
			'plugin_errors'       => $run['plugin_errors'],
		] + $this->cbContextNote(), !$ok);
	}
}
