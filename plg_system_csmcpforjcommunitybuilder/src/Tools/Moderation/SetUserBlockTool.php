<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Moderation;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

/**
 * Block or unblock login through `UserTable::blockUser()`
 * (`libraries/CBLib/CB/Database/Table/UserTable.php:1626`) — the Enabled toggle.
 * It sends no email. Blocking ends the member's sessions
 * (`UserTable::store()`, `:693-699`).
 */
final class SetUserBlockTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use ModerationSupportTrait;

	public function getName(): string { return 'set_cb_user_block'; }

	public function getDescription(): string
	{
		return 'Block or unblock a member\'s login (Joomla\'s block flag), through Community Builder\'s own '
			. 'UserTable::blockUser() so CB\'s onBeforeUserBlocking / onAfterUserBlocking events fire — the Enabled '
			. 'toggle in CB\'s user list. Blocking also ends the member\'s active sessions. Sends no email. Note '
			. 'that unblocking alone does not let a member in who is still unapproved or unconfirmed in CB: the '
			. 'response says what else stands in the way. You cannot block yourself. Both rows are '
			. 're-read and diffed.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'user_id' => ['type' => 'integer'],
				'blocked' => ['type' => 'boolean', 'description' => 'true = block, false = unblock.'],
			],
			'required'             => ['user_id', 'blocked'],
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

		if (!\array_key_exists('blocked', $arguments)) {
			throw new \InvalidArgumentException('blocked (true or false) is required.');
		}

		if ($userId === (int) $actor->id) {
			throw new \InvalidArgumentException('You cannot block or unblock yourself.');
		}

		$this->cbRequireCanModerate($actor, $userId);

		$row   = $this->cbRequireUserExists($userId);
		$state = $this->cbBool($arguments, 'blocked') ? 1 : 0;

		if ((int) $row['block'] === $state) {
			return ToolResult::json(['ok' => true, 'changed' => false, 'note' => 'Already ' . ($state ? 'blocked' : 'unblocked') . '.', 'status' => $this->cbStatus($row)]);
		}

		$extra = [];

		if ($state === 0 && $row['cb_id'] !== null) {
			$still = [];

			if ((int) $row['approved'] !== 1) { $still[] = (int) $row['approved'] === 2 ? 'rejected' : 'awaiting approval'; }
			if ((int) $row['confirmed'] !== 1) { $still[] = 'awaiting email confirmation'; }

			if ($still !== []) {
				$extra['still_in_the_way'] = $still;
				$extra['note'] = 'CB\'s login refuses members who are not approved and confirmed even when unblocked; approve_cb_user / confirm_cb_user handle that (and unblock as part of activation).';
			}
		}

		return $this->cbModerate(
			$actor,
			$userId,
			static function (object $user) use ($state): array {
				$ok = (bool) $user->blockUser($state);

				return ['ok' => $ok, 'error' => $ok ? null : (string) $user->getError()];
			},
			false,
			['block' => ['users.block', $state]],
			$extra
		);
	}
}
