<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Moderation;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

/**
 * Approve or reject a sign-up through `UserTable::approveUser()`
 * (`libraries/CBLib/CB/Database/Table/UserTable.php:1574`).
 *
 * What CB does, in order: with "Generate Registration Passwords" on, approval
 * first REPLACES the member's password with a random one; it fires
 * `onBeforeUserApproval`, stores `approved`, fires `onAfterUserApproval`; then
 * on approve runs `activateUser()` — which, if the member is also confirmed,
 * unblocks them, clears their confirmation code, fires `onUserActive` and sends
 * the Welcome email (or, if still unconfirmed, re-sends the Pending email with
 * the link) — and on reject sends the fixed rejection email with the reason.
 */
final class ApproveUserTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use ModerationSupportTrait;

	public function getName(): string { return 'approve_cb_user'; }

	public function getDescription(): string
	{
		return 'Approve or reject a Community Builder sign-up, through CB\'s own UserTable::approveUser() — the '
			. 'same code as the Approved toggle in CB\'s user list. Approving a confirmed member also ACTIVATES '
			. 'them: CB unblocks the account, clears the confirmation code and fires onUserActive. EMAIL: approve '
			. 'makes CB send the Welcome email (or the Pending email again if the member is still unconfirmed); '
			. 'reject sends CB\'s rejection email with your reason. Every such email is HELD BACK unless '
			. 'send_email is true, and the response lists each email exactly as CB built it, sent or not (links '
			. 'repaired from /api/ to the site URL). PASSWORDS: with CB\'s "Generate Registration Passwords" on, '
			. 'approving REPLACES the member\'s password with a random one that only the Welcome email carries, '
			. 'so approval is refused then unless send_email is true. Both #__users and #__comprofiler are '
			. 're-read and diffed afterwards. Refuses Super User targets unless you are one, and members with no '
			. 'CB profile row. dry_run reports what would happen.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'user_id'    => ['type' => 'integer'],
				'decision'   => ['type' => 'string', 'enum' => ['approve', 'reject']],
				'reason'     => ['type' => 'string', 'description' => 'Rejection reason; CB puts it in the rejection email as [reason].'],
				'send_email' => ['type' => 'boolean', 'description' => 'Let CB send its email. Default false (held back and shown).'],
				'dry_run'    => ['type' => 'boolean', 'description' => 'Report what would happen without doing it. Default false.'],
			],
			'required'             => ['user_id', 'decision'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cbInstalled()) {
			return $this->cbNotInstalledError();
		}

		$userId   = $this->requirePositiveInt($arguments, 'user_id');
		$decision = (string) ($arguments['decision'] ?? '');

		if (!\in_array($decision, ['approve', 'reject'], true)) {
			throw new \InvalidArgumentException('decision must be "approve" or "reject".');
		}

		$this->cbRequireCanModerate($actor, $userId);

		$row = $this->cbRequireUserExists($userId);
		$this->cbRequireProfile($row, $userId);

		$state     = $decision === 'approve' ? 1 : 2;
		$sendEmail = $this->cbBool($arguments, 'send_email');
		$reason    = trim((string) ($arguments['reason'] ?? ''));

		if ((int) $row['approved'] === $state) {
			return ToolResult::json([
				'ok'      => true,
				'changed' => false,
				'note'    => 'Already ' . ($state === 1 ? 'approved' : 'rejected') . '; CB does nothing for a no-op toggle and neither does this tool.',
				'status'  => $this->cbStatus($row),
			]);
		}

		$genPass   = $this->cbGeneratesPasswords();
		$confirmed = (int) $row['confirmed'] === 1;

		$predicted = [];

		if ($state === 1) {
			if ($genPass) {
				$predicted[] = 'CB replaces the member\'s password with a random one (Generate Registration Passwords is on).';
			}

			$predicted[] = $confirmed
				? 'Member becomes active: unblocked, confirmation code cleared, onUserActive fired; CB builds the Welcome email.'
				: 'Member is approved but still unconfirmed, so not activated; CB builds the Pending email again (with the confirmation link).';
		} else {
			$predicted[] = 'Member is marked rejected (approved = 2); CB builds its rejection email' . ($reason !== '' ? ' with your reason.' : ' (no reason given — [reason] will be empty).');
		}

		$predicted[] = $sendEmail ? 'Emails are SENT.' : 'Emails are held back and shown in the response.';

		if ($state === 1 && $genPass && !$sendEmail) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Refused. Community Builder\'s "Generate Registration Passwords" (emailpass) is on, so '
					. 'approving this member REPLACES their password with a random one that only the Welcome email '
					. 'delivers. Holding that email back would lock them out. Pass send_email: true to approve and '
					. 'send it, or turn emailpass off (set_cb_config).',
			], true);
		}

		if ($this->cbBool($arguments, 'dry_run')) {
			return ToolResult::json([
				'ok'        => true,
				'dry_run'   => true,
				'current'   => $this->cbStatus($row),
				'would'     => $predicted,
			]);
		}

		$expect = ['approved' => ['comprofiler.approved', $state]];

		if ($state === 1 && $confirmed) {
			$expect['unblocked'] = ['users.block', 0];
		}

		return $this->cbModerate(
			$actor,
			$userId,
			static function (object $user) use ($state, $reason): array {
				$messages = $state === 2 ? $reason : null;
				$ok       = (bool) $user->approveUser($state, $messages);

				return ['ok' => $ok, 'error' => $ok ? null : (string) $user->getError(), 'messages' => $state === 1 ? $messages : null];
			},
			$sendEmail,
			$expect,
			['decision' => $decision, 'predicted' => $predicted]
		);
	}
}
