<?php
/**
 * @package     MCP for Community Builder
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Members;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

/**
 * Writes named CB profile values through CB's own narrow writer.
 *
 * `UserTable::storeDatabaseValues()` (`libraries/CBLib/CB/Database/Table/UserTable.php:746`)
 * is CB's "store these values on this user" path — the one its ban code uses.
 * It copies the fully loaded member, sets only the named values, fires CB's
 * update events, and saves through `UserTable::store()`, which saves the Joomla
 * user first (Joomla's own `User::save()`, so Joomla user plugins run) and then
 * the `#__comprofiler` row. Nothing un-named is touched — unlike CB's form save
 * (`saveSafely()`), which runs every field's handler over the posted form and
 * treats an absent checkbox as "unchecked".
 *
 * Because a save's own success flag is not proof, both rows are snapshotted
 * before and after and diffed; anything that moved besides the named columns
 * is reported as unexpected.
 */
final class UpdateUserFieldsTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use MemberSupportTrait;

	public function getName(): string { return 'update_cb_user_fields'; }

	public function getDescription(): string
	{
		return 'Set Community Builder profile field values on one member, e.g. {"cb_company": "Acme", '
			. '"cb_interests": ["Hiking","Chess"], "firstname": "Dana"}. Only the named fields change. Writes '
			. 'through CB\'s own UserTable::storeDatabaseValues(), which loads the whole member, sets just these '
			. 'values, fires CB\'s update events and saves both #__users and #__comprofiler; then both rows are '
			. 're-read and diffed. Values are validated by field type: select/radio take one of the field\'s '
			. 'options (value or label), multi-select/multi-checkbox take a list, single checkbox true/false, '
			. 'integer/decimal numbers, date YYYY-MM-DD, datetime YYYY-MM-DD HH:MM:SS, email addresses checked, '
			. 'text checked against the field\'s max length; required fields cannot be emptied. Writable: '
			. 'text, textarea, editor, web address, color, email, number, date/time, checkbox, select, radio, '
			. 'multi-select, multi-checkbox, tag, and the first/middle/last name fields. Not writable: username, '
			. 'email, password, groups (core update_user), images, files, terms, ratings, points, computed '
			. 'fields. No email is sent. dry_run validates without saving.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'user_id' => ['type' => 'integer'],
				'values'  => ['type' => 'object', 'description' => 'CB field name => value.'],
				'dry_run' => ['type' => 'boolean', 'description' => 'Validate and show what would be stored, without saving. Default false.'],
			],
			'required'             => ['user_id', 'values'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cbInstalled()) {
			return $this->cbNotInstalledError();
		}

		$userId = $this->requirePositiveInt($arguments, 'user_id');

		$this->cbRequireAccess($actor, 'core.manage', 'com_users');
		$this->cbRequireAccess($actor, 'core.edit', 'com_users');
		$this->requireCanEditTargetUser($actor, $userId);
		$this->cbRequireUserExists($userId);

		$values = $arguments['values'] ?? null;

		if (!\is_array($values) || $values === []) {
			throw new \InvalidArgumentException('values must be a non-empty object of field name => value.');
		}

		$store    = [];
		$problems = [];
		$columns  = $this->cbProfileColumns();

		foreach ($values as $name => $value) {
			$field = $this->cbFindField((string) $name);

			if ($field === null) {
				$problems[] = ['field' => $name, 'error' => 'No CB field with that name. list_cb_fields shows them.'];
				continue;
			}

			try {
				$pair = $this->cbValidateFieldWrite($field, $value);
			} catch (\InvalidArgumentException $e) {
				$problems[] = ['field' => $name, 'error' => $e->getMessage()];
				continue;
			}

			foreach ($pair as $col => $v) {
				if (!\in_array($col, $columns, true)) {
					$problems[] = ['field' => $name, 'error' => 'Its column ' . $col . ' is missing from #__comprofiler (run check_cb_health).'];
					continue 2;
				}

				$store[$col] = $v;
			}
		}

		if ($problems !== []) {
			return ToolResult::json(['ok' => false, 'error' => 'Nothing was written.', 'problems' => $problems], true);
		}

		$before    = $this->cbSnapshot($userId);
		$noProfile = $before['comprofiler'] === [];

		if ($this->cbBool($arguments, 'dry_run')) {
			$preview = [];

			foreach ($store as $col => $v) {
				$preview[$col] = ['before' => $before['comprofiler'][$col] ?? null, 'after' => $v];
			}

			return ToolResult::json([
				'ok'          => true,
				'dry_run'     => true,
				'would_store' => $preview,
				'note'        => $noProfile ? 'This user has no CB profile row yet; it would first be created the way CB\'s Synchronize Users does.' : null,
			]);
		}

		/*
		 * A Joomla user created outside CB has no #__comprofiler row. Letting
		 * UserTable::store() create it is NOT harmless: store() sees
		 * confirmed == 0 (NULL) with email confirmation on and mints an
		 * activation code (UserTable.php:617-619), writing it into Joomla's own
		 * #__users.activation as well — so Joomla's Users screen then shows the
		 * member as not activated. Verified live on CB 2.12.0. So we create the
		 * row first exactly as CB's Tools -> Synchronize Users does
		 * (controller.default.php:600-625, scoped to this one user), which gives
		 * CB's column defaults (approved = confirmed = 1) and no code.
		 */
		if ($noProfile) {
			$this->cbSyncOneUser($userId);
		}

		$run = $this->cbRun($actor, function () use ($userId, $store): array {
			$user = $this->cbLoadUserTable($userId);
			$ok   = (bool) $user->storeDatabaseValues($store, true);

			return ['ok' => $ok, 'error' => $ok ? null : (string) $user->getError()];
		});

		$after = $this->cbSnapshot($userId);
		$diff  = $this->cbDiff($before, $after);

		$verified   = [];
		$unexpected = [];

		foreach ($store as $col => $v) {
			$verified[$col] = (string) ($after['comprofiler'][$col] ?? '') === (string) $v;
		}

		foreach ($diff as $key => $change) {
			$col = substr($key, \strlen('comprofiler.'));

			if (str_starts_with($key, 'comprofiler.') && ($noProfile || \array_key_exists($col, $store))) {
				continue; // a new profile row legitimately brings every CB default with it
			}

			$unexpected[$key] = $change;
		}

		$ok = $run['result']['ok'] && !\in_array(false, $verified, true);

		$response = [
			'ok'                 => $ok,
			'user_id'            => $userId,
			'changes'            => $diff === [] ? new \stdClass() : $diff,
			'verified_by_reread' => $verified,
			'unexpected_changes' => $unexpected === [] ? new \stdClass() : $unexpected,
			'error'              => $run['result']['error'],
			'cb_messages'        => $run['messages'],
			'plugin_errors'      => $run['plugin_errors'],
		] + $this->cbContextNote() + $this->cbMailReport($run['mail'], false);

		if (array_intersect(array_keys($store), ['firstname', 'middlename', 'lastname']) !== []) {
			$response['name_note'] = 'CB builds the Joomla "name" from first/middle/last name only when the member saves the form; this write does not change #__users.name.';
		}

		if ($noProfile) {
			$response['profile_created'] = $after['comprofiler'] !== [];
			$response['profile_note']    = 'The member had no CB profile row; it was created first the way CB\'s Synchronize Users does.';
		}

		return ToolResult::json($response, !$ok);
	}

	/** CB's own Synchronize Users INSERT (controller.default.php:600-625), for one user. */
	private function cbSyncOneUser(int $userId): void
	{
		$db    = $this->db;
		$style = (string) ($this->cbRawConfig()['name_style'] ?? '1');
		$c     = $db->quoteName('#__comprofiler');
		$u     = $db->quoteName('#__users');

		switch ($style) {
			case '2':
				$sql = 'INSERT IGNORE INTO ' . $c . ' (id, user_id, lastname, firstname)'
					. ' SELECT id, id, SUBSTRING_INDEX(name, \' \', -1),'
					. ' SUBSTRING(name, 1, LENGTH(name) - LENGTH(SUBSTRING_INDEX(name, \' \', -1)) - 1)'
					. ' FROM ' . $u . ' WHERE id = ' . $userId;
				break;
			case '3':
				$sql = 'INSERT IGNORE INTO ' . $c . ' (id, user_id, middlename, lastname, firstname)'
					. ' SELECT id, id, SUBSTRING(name, INSTR(name, \' \') + 1,'
					. ' LENGTH(name) - INSTR(name, \' \') - LENGTH(SUBSTRING_INDEX(name, \' \', -1)) - 1),'
					. ' SUBSTRING_INDEX(name, \' \', -1),'
					. ' IF(INSTR(name, \' \'), SUBSTRING_INDEX(name, \' \', 1), \'\')'
					. ' FROM ' . $u . ' WHERE id = ' . $userId;
				break;
			default:
				$sql = 'INSERT IGNORE INTO ' . $c . ' (id, user_id) SELECT id, id FROM ' . $u . ' WHERE id = ' . $userId;
		}

		$db->setQuery($sql)->execute();
	}
}
