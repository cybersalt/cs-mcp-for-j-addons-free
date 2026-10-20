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
 * Confirm a member's email on their behalf, through `UserTable::confirmUser(1)`
 * (`libraries/CBLib/CB/Database/Table/UserTable.php:1529`) — what CB's
 * Confirmed toggle does. CB's toggle offers no way back to "unconfirmed"
 * (`model.users.xml`: that option is `toggleable="false"`), and neither do we.
 */
final class ConfirmUserTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use ModerationSupportTrait;

	public function getName(): string { return 'confirm_cb_user'; }

	public function getDescription(): string
	{
		return 'Mark a Community Builder member\'s email address as confirmed on their behalf — for the member '
			. 'whose confirmation email never arrived or arrived with an empty link. Runs CB\'s own '
			. 'UserTable::confirmUser(), as the Confirmed toggle in CB\'s user list does. If the member is also '
			. 'approved, CB activates them: unblocks the account, clears the confirmation code, fires onUserActive '
			. 'and builds the Welcome email. If they still await approval, nothing is emailed from here (CB\'s back '
			. 'end does not alert moderators). Every email is HELD BACK unless send_email is true, and listed in '
			. 'the response. With "Generate Registration Passwords" on, confirming an approved member REPLACES '
			. 'their password, so it is refused then unless send_email is true. Both rows are re-read and diffed. '
			. 'There is deliberately no un-confirm (CB offers none). dry_run reports what would happen.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'user_id'    => ['type' => 'integer'],
				'send_email' => ['type' => 'boolean', 'description' => 'Let CB send its email. Default false.'],
				'dry_run'    => ['type' => 'boolean'],
			],
			'required'             => ['user_id'],
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

		$this->cbRequireCanModerate($actor, $userId);

		$row = $this->cbRequireUserExists($userId);
		$this->cbRequireProfile($row, $userId);

		$sendEmail = $this->cbBool($arguments, 'send_email');

		if ((int) $row['confirmed'] === 1) {
			return ToolResult::json(['ok' => true, 'changed' => false, 'note' => 'Already confirmed.', 'status' => $this->cbStatus($row)]);
		}

		$approved  = (int) $row['approved'] === 1;
		$genPass   = $this->cbGeneratesPasswords();
		$predicted = [];

		if ($approved && $genPass) {
			$predicted[] = 'CB replaces the member\'s password with a random one (Generate Registration Passwords is on).';
		}

		$predicted[] = $approved
			? 'Member becomes active: unblocked, confirmation code cleared, onUserActive fired; CB builds the Welcome email.'
			: 'Member is confirmed but still awaits approval, so stays blocked; no email from a back-end confirmation.';
		$predicted[] = $sendEmail ? 'Emails are SENT.' : 'Emails are held back and shown in the response.';

		if ($approved && $genPass && !$sendEmail) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Refused. "Generate Registration Passwords" (emailpass) is on and this member is approved, so '
					. 'confirming them REPLACES their password with a random one that only the Welcome email delivers. '
					. 'Pass send_email: true, or turn emailpass off.',
			], true);
		}

		if ($this->cbBool($arguments, 'dry_run')) {
			return ToolResult::json(['ok' => true, 'dry_run' => true, 'current' => $this->cbStatus($row), 'would' => $predicted]);
		}

		$expect = ['confirmed' => ['comprofiler.confirmed', 1]];

		if ($approved) {
			$expect['unblocked'] = ['users.block', 0];
		}

		return $this->cbModerate(
			$actor,
			$userId,
			static function (object $user): array {
				$messages = null;
				$ok       = (bool) $user->confirmUser(1, $messages);

				return ['ok' => $ok, 'error' => $ok ? null : (string) $user->getError(), 'messages' => $messages];
			},
			$sendEmail,
			$expect,
			['predicted' => $predicted]
		);
	}
}
