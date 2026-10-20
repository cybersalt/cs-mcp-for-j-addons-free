<?php
/**
 * @package     MCP for Community Builder
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Moderation;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

/**
 * Ban or unban a member's profile through `UserTable::banUser()`
 * (`libraries/CBLib/CB/Database/Table/UserTable.php:1661`). A CB ban hides the
 * profile from other users; it does not block login (that is set_cb_user_block).
 * banUser() emails the member on every ban and unban, unconditionally — the
 * mail guard holds that back unless send_email.
 */
final class SetUserBanTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use ModerationSupportTrait;

	public function getName(): string { return 'set_cb_user_ban'; }

	public function getDescription(): string
	{
		return 'Ban or unban a Community Builder member\'s PROFILE, through CB\'s own UserTable::banUser() (the Banned '
			. 'toggle). A CB ban hides the profile from other members and records when, by whom and why; it does '
			. 'NOT stop the member logging in — use set_cb_user_block for that. Unbanning also clears a pending '
			. 'unban request. CB emails the member on every ban and unban; that email is HELD BACK unless '
			. 'send_email is true, and the response shows it as CB built it. You cannot ban yourself. Both rows '
			. 'are re-read and diffed.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'user_id'    => ['type' => 'integer'],
				'banned'     => ['type' => 'boolean', 'description' => 'true = ban, false = unban.'],
				'reason'     => ['type' => 'string', 'description' => 'Stored in bannedreason; the member sees it on their profile.'],
				'send_email' => ['type' => 'boolean', 'description' => 'Let CB send its ban/unban email. Default false.'],
			],
			'required'             => ['user_id', 'banned'],
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

		if (!\array_key_exists('banned', $arguments)) {
			throw new \InvalidArgumentException('banned (true or false) is required.');
		}

		if ($userId === (int) $actor->id) {
			throw new \InvalidArgumentException('You cannot ban or unban yourself.');
		}

		$this->cbRequireCanModerate($actor, $userId);

		$row = $this->cbRequireUserExists($userId);
		$this->cbRequireProfile($row, $userId);

		$state     = $this->cbBool($arguments, 'banned') ? 1 : 0;
		$reason    = trim((string) ($arguments['reason'] ?? ''));
		$sendEmail = $this->cbBool($arguments, 'send_email');

		if ((int) $row['banned'] === $state) {
			return ToolResult::json(['ok' => true, 'changed' => false, 'note' => 'Already ' . ($state ? 'banned' : 'not banned') . '.', 'status' => $this->cbStatus($row)]);
		}

		$actorId = (int) $actor->id;

		return $this->cbModerate(
			$actor,
			$userId,
			static function (object $user) use ($state, $reason, $actorId): array {
				$by = \CBuser::getUserDataInstance($actorId);
				$ok = (bool) $user->banUser($state, $by, $reason !== '' ? $reason : null);

				return ['ok' => $ok, 'error' => $ok ? null : (string) $user->getError()];
			},
			$sendEmail,
			['banned' => ['comprofiler.banned', $state], ($state ? 'bannedby' : 'unbannedby') => ['comprofiler.' . ($state ? 'bannedby' : 'unbannedby'), $actorId]]
		);
	}
}
