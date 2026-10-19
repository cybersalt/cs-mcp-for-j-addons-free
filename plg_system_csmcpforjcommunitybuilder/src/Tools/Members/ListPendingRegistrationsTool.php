<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Members;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

final class ListPendingRegistrationsTool extends AbstractTool
{
	use CommunitybuilderBootTrait;

	public function getName(): string { return 'list_cb_pending_registrations'; }

	public function getDescription(): string
	{
		return 'The Community Builder moderation queue: members awaiting admin approval and/or email confirmation, '
			. 'oldest first, with how long each has waited, whether they have logged in, whether a confirmation '
			. 'code exists (a member awaiting confirmation without one can never confirm by link), and what is '
			. 'needed next (approve_cb_user, confirm_cb_user, or the member clicking their link). Rejected members '
			. '(approved = 2) are listed only with include_rejected. Also reports the current approval and '
			. 'confirmation switches. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'awaiting'         => ['type' => 'string', 'enum' => ['any', 'approval', 'confirmation'], 'description' => 'Default any.'],
				'include_rejected' => ['type' => 'boolean', 'description' => 'Also list rejected sign-ups. Default false.'],
				'limit'            => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'           => ['type' => 'integer'],
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

		$db       = $this->db;
		$awaiting = (string) ($arguments['awaiting'] ?? 'any');

		$conditions = match ($awaiting) {
			'approval'     => ['c.approved = 0'],
			'confirmation' => ['c.confirmed = 0'],
			default        => ['c.approved = 0', 'c.confirmed = 0'],
		};

		if ($this->cbBool($arguments, 'include_rejected')) {
			$conditions[] = 'c.approved = 2';
		}

		$where = '(' . implode(' OR ', $conditions) . ')';
		$from  = ' FROM ' . $db->quoteName('#__comprofiler') . ' c JOIN ' . $db->quoteName('#__users') . ' u ON u.id = c.id';

		$total = (int) $db->setQuery('SELECT COUNT(*)' . $from . ' WHERE ' . $where)->loadResult();

		$rows = $db->setQuery(
			'SELECT u.id, u.username, u.name, u.email, u.block, u.registerDate, u.lastvisitDate,'
			. ' c.approved, c.confirmed, c.banned, c.cbactivation' . $from . ' WHERE ' . $where
			. ' ORDER BY u.registerDate ASC',
			$this->cbOffset($arguments),
			$this->cbLimit($arguments)
		)->loadAssocList() ?: [];

		$now     = time();
		$members = [];

		foreach ($rows as $r) {
			$registered = strtotime((string) $r['registerDate'] . ' UTC') ?: $now;
			$approved   = (int) $r['approved'];
			$confirmed  = (int) $r['confirmed'];
			$hasCode    = (string) $r['cbactivation'] !== '';

			$next = [];

			if ($confirmed === 0) {
				$next[] = $hasCode
					? 'Member must click the [CONFIRM] link in their Pending email, or a moderator runs confirm_cb_user.'
					: 'No confirmation code exists, so no email can carry a working link: confirm_cb_user is the only way.';
			}

			if ($approved === 0) {
				$next[] = 'A moderator runs approve_cb_user (decision approve or reject).';
			} elseif ($approved === 2) {
				$next[] = 'Rejected. approve_cb_user with decision approve reverses it.';
			}

			$lastVisit = (string) $r['lastvisitDate'];

			$members[] = [
				'id'                  => (int) $r['id'],
				'username'            => $r['username'],
				'name'                => $r['name'],
				'email'               => $r['email'],
				'registered'          => $r['registerDate'],
				'waiting_days'        => round(($now - $registered) / 86400, 1),
				'awaiting_approval'   => $approved === 0,
				'awaiting_confirmation' => $confirmed === 0,
				'rejected'            => $approved === 2,
				'blocked'             => (int) $r['block'] === 1,
				'banned'              => (int) $r['banned'] === 1,
				'has_activation_code' => $hasCode,
				'has_logged_in'       => $lastVisit !== '' && !str_starts_with($lastVisit, '0000') && $lastVisit !== $db->getNullDate(),
				'next_step'           => $next,
			];
		}

		$cfg = $this->cbRawConfig();

		return ToolResult::json([
			'ok'      => true,
			'total'   => $total,
			'showing' => \count($members),
			'config'  => [
				'admin_approval'     => $cfg['reg_admin_approval'] ?? null,
				'email_confirmation' => $cfg['reg_confirmation'] ?? null,
				'generate_passwords' => $cfg['emailpass'] ?? null,
			],
			'members' => $members,
		]);
	}
}
