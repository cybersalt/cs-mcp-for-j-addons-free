<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Moderation;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Shared driver for the moderation tools.
 *
 * Every moderation write calls CB's own `UserTable` method — `approveUser()`,
 * `confirmUser()`, `banUser()`, `blockUser()` — exactly as CB's back-end user
 * list toggles do (`UserTable::toggleUser*()`, minus the `canToggle()` check,
 * which cbRequireCanModerate() reproduces against the MCP actor). Those methods
 * fire CB's before/after events, and approval/confirmation run CB's
 * `activateUser()`, which unblocks the member, clears the confirmation code and
 * fires `onUserActive` — the same side effects as clicking the toggle.
 *
 * The run is wrapped so that: CB's mail goes through the guard (held back
 * unless send_email); both rows are snapshotted before and after and diffed;
 * the expected end state is checked against the re-read row, not against the
 * method's return value.
 */
trait ModerationSupportTrait
{
	/** Members without a CB row have no moderation state to change. */
	protected function cbRequireProfile(array $row, int $userId): void
	{
		if ($row['cb_id'] === null) {
			throw new \InvalidArgumentException(
				'User ' . $userId . ' has no Community Builder profile row (#__comprofiler), so there is no CB '
				. 'moderation state to change. CB -> Tools -> Synchronize Users creates the row (as approved and '
				. 'confirmed).'
			);
		}
	}

	/**
	 * @param callable(object):array{ok:bool,error:?string,messages?:mixed} $op  receives the loaded UserTable
	 * @param array<string,array{0:string,1:mixed}> $expect  label => [ 'table.column', expected value ]
	 */
	protected function cbModerate(User $actor, int $userId, callable $op, bool $sendEmail, array $expect, array $extra = []): ToolResult
	{
		$before = $this->cbSnapshot($userId);

		$run = $this->cbRun($actor, function () use ($userId, $op): array {
			$user = $this->cbLoadUserTable($userId);

			return $op($user);
		}, $sendEmail);

		$after = $this->cbSnapshot($userId);
		$diff  = $this->cbDiff($before, $after);

		$verified = [];

		foreach ($expect as $label => [$path, $value]) {
			[$table, $column] = explode('.', $path, 2);
			$verified[$label] = (string) ($after[$table][$column] ?? '') === (string) $value;
		}

		$ok = (bool) ($run['result']['ok'] ?? false) && !\in_array(false, $verified, true);

		$messages = $run['result']['messages'] ?? null;

		if (\is_array($messages)) {
			$messages = array_values(array_map(static fn ($m): string => trim(strip_tags(\is_array($m) ? implode(' ', $m) : (string) $m)), $messages));
		} elseif (\is_string($messages)) {
			$messages = trim(strip_tags($messages));
		}

		$response = [
			'ok'                 => $ok,
			'user_id'            => $userId,
			'changes'            => $diff === [] ? new \stdClass() : $diff,
			'verified_by_reread' => $verified,
			'error'              => $run['result']['error'] ?? null,
			'cb_user_messages'   => $messages,
			'cb_messages'        => $run['messages'],
			'plugin_errors'      => $run['plugin_errors'],
		] + $extra + $this->cbContextNote() + $this->cbMailReport($run['mail'], $sendEmail);

		if (isset($diff['users.password'])) {
			$response['password_changed'] = 'Community Builder replaced this member\'s password (Generate '
				. 'Registration Passwords is on). Only the email above carries the new one.';
		}

		if ($run['stray_output'] !== '') {
			$response['stray_output'] = mb_substr($run['stray_output'], 0, 2000);
		}

		return ToolResult::json($response, !$ok);
	}

	/** CB's "Generate Registration Passwords" switch. */
	protected function cbGeneratesPasswords(): bool
	{
		return (string) ($this->cbRawConfig()['emailpass'] ?? '0') === '1';
	}
}
