<?php
/**
 * @package     MCP for AcyMailing
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Sending;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

/**
 * Send a campaign (or template) to a handful of explicit test addresses —
 * the same path as the admin "Send a test" button
 * (CampaignsController::test()): MailerHelper::sendOne($mailId, $receiver,
 * ['isTest' => true]) per address, plus a `test_sent` history row.
 *
 * Two vendor behaviours surfaced rather than hidden:
 *  - The admin path auto-CREATES a subscriber for any test address that is not
 *    one yet (getOneByEmail($addr, true)). We do the same (so the mail can be
 *    personalised and the history row has an owner) but create it with every
 *    side effect off and the site's default language, and report it.
 *  - Rendering happens inside withSiteRoot() so links and the tracking pixel
 *    point at the site, not /api/.
 */
final class SendTestEmailTool extends AbstractTool
{
	use AcymailingBootTrait;

	private const MAX_ADDRESSES = 5;

	public function getName(): string { return 'send_acym_test_email'; }

	public function getDescription(): string
	{
		return 'Send an AcyMailing campaign (campaign_id) or any mail (mail_id, e.g. a template) as a TEST to at most '
			. '5 explicit addresses, exactly like the admin "Send a test" button. This really sends email through '
			. 'the configured mailer, so it requires confirm: true; without it the tool only shows what would be '
			. 'sent and to whom. Test sends do not touch the queue, do not mark the campaign sent and do not count '
			. 'in campaign statistics. An address that is not a subscriber yet is created as one (AcyMailing does '
			. 'this too) with no lists and no emails sent to it; the response lists any such creations so they can '
			. 'be removed. Optional test_note is shown at the top of the test email.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['addresses'],
			'properties' => [
				'campaign_id' => ['type' => 'integer', 'minimum' => 1],
				'mail_id'     => ['type' => 'integer', 'minimum' => 1],
				'addresses'   => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => self::MAX_ADDRESSES],
				'test_note'   => ['type' => 'string'],
				'confirm'     => ['type' => 'boolean', 'description' => 'Must be true to actually send.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	public function getMcpAnnotations(): array
	{
		return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true];
	}

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->acyAdminBase() === null) {
			return $this->notInstalledError();
		}
		if (!$this->ensureAcyLoaded()) {
			return ToolResult::error('Could not load the AcyMailing framework.');
		}

		$mailId = 0;
		if (!empty($arguments['campaign_id'])) {
			$c = $this->acyCampaignRow((int) $arguments['campaign_id']);
			if (!$c) {
				return ToolResult::error('No campaign ' . (int) $arguments['campaign_id'] . '.');
			}
			$mailId = (int) $c['mail_id'];
		} elseif (!empty($arguments['mail_id'])) {
			$mailId = (int) $arguments['mail_id'];
		}
		$mail = $mailId ? $this->acyMailRow($mailId) : null;
		if (!$mail) {
			return ToolResult::error('Pass a campaign_id or mail_id that exists.');
		}

		$addresses = [];
		foreach ((array) $arguments['addresses'] as $a) {
			$a = strtolower(trim((string) $a));
			if (!acym_isValidEmail($a)) {
				return ToolResult::error('Not a valid email address: ' . $a);
			}
			$addresses[] = $a;
		}
		$addresses = array_values(array_unique($addresses));
		if (count($addresses) > self::MAX_ADDRESSES) {
			return ToolResult::error('At most ' . self::MAX_ADDRESSES . ' test addresses.');
		}

		$plan = [];
		foreach ($addresses as $a) {
			$plan[] = ['address' => $a, 'existing_subscriber_id' => ($s = $this->acyFindSubscriber(null, $a)) ? (int) $s['id'] : null];
		}

		if (($arguments['confirm'] ?? false) !== true) {
			return ToolResult::json([
				'ok'      => false,
				'sent'    => false,
				'mail'    => ['id' => $mailId, 'subject' => $mail['subject'], 'type' => $mail['type']],
				'would_send_to' => $plan,
				'mailer'  => $this->acyMailerSummary(),
				'message' => 'Nothing sent. Re-run with confirm: true to send these test emails.',
			]);
		}

		$userClass = $this->acyUserClass();
		$history   = new \AcyMailing\Classes\HistoryClass();
		$results   = [];
		$created   = [];

		$this->withSiteRoot(function () use ($plan, $mailId, $arguments, $userClass, $history, &$results, &$created): void {
			foreach ($plan as $p) {
				$receiver = $p['existing_subscriber_id'] ? $userClass->getOneById($p['existing_subscriber_id']) : null;
				if (!$receiver) {
					$new         = new \stdClass();
					$new->email  = $p['address'];
					$new->source = 'MCP test send';
					$new->language = $this->acyDefaultLanguage();
					$uid = $userClass->save($new);
					if (empty($uid)) {
						$results[] = ['address' => $p['address'], 'sent' => false, 'error' => 'could not create subscriber: ' . implode('; ', $userClass->errors)];
						continue;
					}
					$created[] = ['id' => (int) $uid, 'email' => $p['address']];
					$receiver  = $userClass->getOneById((int) $uid);
				}

				$mailer         = new \AcyMailing\Helpers\MailerHelper();
				$mailer->report = false;
				try {
					$ok = $mailer->sendOne($mailId, $receiver, ['isTest' => true, 'testNote' => (string) ($arguments['test_note'] ?? '')]);
				} catch (\Throwable $e) {
					$ok = false;
					$mailer->reportMessage = $e->getMessage();
				}
				if ($ok) {
					$history->insert((int) $receiver->id, 'test_sent', [], $mailId);
				}
				$results[] = [
					'address'       => $p['address'],
					'subscriber_id' => (int) $receiver->id,
					'sent'          => (bool) $ok,
					'report'        => trim(strip_tags((string) $mailer->reportMessage)),
					// PHPMailer's ErrorInfo is a full SMTP/mail() transcript INCLUDING the
					// List-Unsubscribe header, which carries the subscriber's secret
					// userKey. Only surface it on failure, and scrub the key.
					'mailer_error'  => $ok ? null : $this->scrub((string) ($mailer->ErrorInfo ?? '')),
				];
			}
		});

		return ToolResult::json([
			'ok'                  => !\in_array(false, array_column($results, 'sent'), true),
			'mail'                => ['id' => $mailId, 'subject' => $mail['subject']],
			'results'             => $results,
			'subscribers_created' => $created,
			'mailer'              => $this->acyMailerSummary(),
			'vendor_messages'     => $this->acyDrainMessages(),
			'note'                => 'A "sent" result means the mailer accepted the message, not that it was delivered.',
		]);
	}

	/** Remove subscriber keys (plain or quoted-printable) and cap the length. */
	private function scrub(string $s): string
	{
		// The headers are where keys live, and quoted-printable folding can split
		// a key across lines, so drop the header block outright.
		$cut = stripos($s, 'Headers:');
		if ($cut !== false) {
			$s = substr($s, 0, $cut) . '[headers omitted]';
		}
		$s = preg_replace('/(userKey|subKey|key)(=3D|=)[A-Za-z0-9?=_]+/i', '$1$2[redacted]', $s) ?? '';

		return mb_substr(trim($s), 0, 600);
	}
}
