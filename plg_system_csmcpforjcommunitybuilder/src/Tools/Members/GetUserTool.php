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

final class GetUserTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use MemberSupportTrait;

	public function getName(): string { return 'get_cb_user'; }

	public function getDescription(): string
	{
		return 'One Community Builder member, assembled: the Joomla account (no password, OTP or activation '
			. 'secrets), Joomla groups, CB moderation state (approved / confirmed / banned with date, by whom and '
			. 'reason / blocked, and whether the member can log in), whether a confirmation code exists (never the '
			. 'code itself), and every CB profile field grouped by tab with its stored value and, for select / '
			. 'radio / checkbox fields, the option labels. Also connection and report counts. Find the member by '
			. 'user_id, username or email. Empty and unpublished fields are left out unless asked for. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'user_id'             => ['type' => 'integer'],
				'username'            => ['type' => 'string'],
				'email'               => ['type' => 'string'],
				'include_empty'       => ['type' => 'boolean', 'description' => 'Include fields with no value. Default false.'],
				'include_unpublished' => ['type' => 'boolean', 'description' => 'Include unpublished fields. Default false.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cbInstalled()) {
			return $this->cbNotInstalledError();
		}

		$this->cbRequireAccess($actor, 'core.manage', 'com_users');

		$db = $this->db;
		$id = (int) ($arguments['user_id'] ?? 0);

		if ($id <= 0) {
			foreach (['username', 'email'] as $key) {
				if (($v = trim((string) ($arguments[$key] ?? ''))) !== '') {
					$id = (int) $db->setQuery(
						'SELECT id FROM ' . $db->quoteName('#__users') . ' WHERE ' . $db->quoteName($key) . ' = ' . $db->quote($v)
					)->loadResult();
					break;
				}
			}
		}

		if ($id <= 0) {
			throw new \InvalidArgumentException('Give user_id, username or email of an existing user.');
		}

		$row = $this->cbUserRow($id);

		if ($row === null) {
			throw new \InvalidArgumentException('No Joomla user with id ' . $id . '.');
		}

		$includeEmpty = $this->cbBool($arguments, 'include_empty');
		$includeUnpub = $this->cbBool($arguments, 'include_unpublished');
		$secret       = $this->cbSecretProfileColumns();
		$hasProfile   = $row['cb_id'] !== null;

		$groups = $db->setQuery(
			'SELECT g.id, g.title FROM ' . $db->quoteName('#__user_usergroup_map') . ' m JOIN '
			. $db->quoteName('#__usergroups') . ' g ON g.id = m.group_id WHERE m.user_id = ' . $id . ' ORDER BY g.lft'
		)->loadAssocList() ?: [];

		$tabs = $db->setQuery('SELECT tabid, title, enabled FROM ' . $db->quoteName('#__comprofiler_tabs'))->loadAssocList('tabid') ?: [];

		// Translating tab and field titles needs CB's language strings; boot is optional.
		$translate = false;

		try {
			$this->cbRun($actor, static fn () => null);
			$translate = true;
		} catch (\Throwable $e) {
			// Fall back to raw titles.
		}

		$profile = [];

		if ($hasProfile) {
			foreach ($this->cbFieldRows() as $f) {
				if ((int) $f['published'] !== 1 && !$includeUnpub) {
					continue;
				}

				$cols = $this->cbFieldColumns($f);

				if ($cols === [] && !$includeEmpty) {
					continue;
				}

				// #__users-backed fields (name, username, email...) are in "account" already.
				if ((string) $f['table'] !== '#__comprofiler') {
					continue;
				}

				$v = $this->cbFieldValue($f, $row, $secret);

				$empty = $v['value'] === null || $v['value'] === '' || $v['value'] === []
					|| (\is_array($v['value']) && array_filter($v['value'], static fn ($x): bool => $x !== null && $x !== '') === []);

				if ($empty && !$includeEmpty) {
					continue;
				}

				$tabId    = (int) $f['tabid'];
				$tabTitle = isset($tabs[$tabId]) ? (string) $tabs[$tabId]['title'] : '(tab ' . $tabId . ' missing)';
				$tabKey   = $translate ? $this->cbText($tabTitle) : $tabTitle;

				$profile[$tabKey][] = [
					'field'     => $f['name'],
					'title'     => $translate ? $this->cbText((string) $f['title']) : $f['title'],
					'type'      => $f['type'],
					'published' => (int) $f['published'] === 1,
				] + $v;
			}
		}

		$reports = (int) $db->setQuery(
			'SELECT COUNT(*) FROM ' . $db->quoteName('#__comprofiler_userreports') . ' WHERE reporteduser = ' . $id . ' AND reportedstatus = 0'
		)->loadResult();

		// cbConnection::addConnectionToDatabase(): the requester's row (referenceid = requester) is
		// accepted=1 pending=1 until the other side accepts; then both rows are accepted=1 pending=0.
		$connections = $db->setQuery(
			'SELECT'
			. ' (SELECT COUNT(*) FROM ' . $db->quoteName('#__comprofiler_members') . ' WHERE referenceid = ' . $id . ' AND accepted = 1 AND pending = 0) AS connected,'
			. ' (SELECT COUNT(*) FROM ' . $db->quoteName('#__comprofiler_members') . ' WHERE referenceid = ' . $id . ' AND pending = 1) AS sent,'
			. ' (SELECT COUNT(*) FROM ' . $db->quoteName('#__comprofiler_members') . ' WHERE memberid = ' . $id . ' AND pending = 1) AS received'
		)->loadAssoc() ?: [];

		$moderation = null;

		if ($hasProfile) {
			$moderation = [
				'banned_date'     => $row['banneddate'],
				'banned_by'       => $row['bannedby'] !== null ? (int) $row['bannedby'] : null,
				'banned_reason'   => $row['bannedreason'],
				'unbanned_date'   => $row['unbanneddate'],
				'unbanned_by'     => $row['unbannedby'] !== null ? (int) $row['unbannedby'] : null,
				'has_activation_code' => (string) $row['cbactivation'] !== '',
				'registration_ip_logged' => (string) $row['registeripaddr'] !== '',
				'last_cb_update'  => $row['lastupdatedate'],
				'accepted_terms'  => (int) $row['acceptedterms'] === 1,
				'avatar'          => $row['avatar'],
				'avatar_approved' => (int) $row['avatarapproved'] === 1,
				'profile_hits'    => (int) $row['hits'],
			];
		}

		$account = [];

		foreach (self::CB_USERS_COLUMNS as $c) {
			$account[$c] = $row[$c] ?? null;
		}

		$account['id'] = $id;

		$response = [
			'ok'          => true,
			'account'     => $account,
			'groups'      => $groups,
			'has_cb_profile' => $hasProfile,
			'status'      => $this->cbStatus($row),
			'moderation'  => $moderation,
			'names'       => $hasProfile ? ['firstname' => $row['firstname'], 'middlename' => $row['middlename'], 'lastname' => $row['lastname'], 'alias' => $row['alias']] : null,
			'profile'     => $profile === [] ? new \stdClass() : $profile,
			'connections' => [
				'connected'                 => (int) ($connections['connected'] ?? 0),
				'requests_sent_pending'     => (int) ($connections['sent'] ?? 0),
				'requests_received_pending' => (int) ($connections['received'] ?? 0),
			],
			'open_reports_against' => $reports,
		];

		if (!$hasProfile) {
			$response['note'] = 'This Joomla user has no #__comprofiler row (created outside CB and never synchronized). CB creates it on the first CB save; CB -> Tools -> Synchronize Users creates them all.';
		}

		return ToolResult::json($response);
	}
}
