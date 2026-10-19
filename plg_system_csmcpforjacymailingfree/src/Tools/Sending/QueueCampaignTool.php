<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Sending;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

/**
 * The one tool here that starts a real mailing: CampaignClass::send(), the
 * exact call behind the admin "Send" / "Add to queue" button
 * (Controllers/Campaigns/Actions.php::addQueue()).
 *
 * send() does not deliver anything itself. It INSERT IGNOREs one queue row per
 * eligible subscriber (the queue's PK collapses people on several lists to one
 * row), marks the campaign sent, and writes the mail_stat row. Delivery is then
 * whatever processes the queue: on Starter, an administrator keeping the
 * AcyMailing queue page open; on Essential/Enterprise, AcyMailing's cron.
 *
 * Rails: dry_run defaults to TRUE; a real queue needs dry_run: false AND
 * confirm: true AND expected_recipients equal to the live deduplicated count,
 * so an approval given for 12 people cannot silently become 1,200 because a
 * list changed in between. cancel_acym_campaign_sending undoes a queue that
 * has not been processed yet.
 */
final class QueueCampaignTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'queue_acym_campaign'; }

	public function getDescription(): string
	{
		return 'Queue a DRAFT AcyMailing campaign for sending to its lists via the vendor\'s CampaignClass::send() '
			. '(the admin Send button). DRY RUN BY DEFAULT: returns the deduplicated recipient count, the per-list '
			. 'breakdown and what will process the queue on this edition. To really queue: dry_run: false, confirm: '
			. 'true and expected_recipients set to the dry run\'s distinct_recipients (refused if the live count '
			. 'differs). Queueing is the irreversible-in-practice step: on Starter the emails go out when an admin '
			. 'processes the queue page, on Essential/Enterprise the cron sends them automatically. Recipients are '
			. 'active subscribers of the lists (confirmed ones only when require_confirmation is on), one email per '
			. 'person however many lists they are on. Only drafts with sending type "now" are accepted - scheduled '
			. 'sending is an Essential feature in the Pro add-on.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['campaign_id'],
			'properties' => [
				'campaign_id'         => ['type' => 'integer', 'minimum' => 1],
				'dry_run'             => ['type' => 'boolean', 'description' => 'Default TRUE.'],
				'confirm'             => ['type' => 'boolean'],
				'expected_recipients' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Must equal the dry run\'s distinct_recipients.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	public function getMcpAnnotations(): array
	{
		return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => true];
	}

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->acyAdminBase() === null) {
			return $this->notInstalledError();
		}
		if (!$this->ensureAcyLoaded()) {
			return ToolResult::error('Could not load the AcyMailing framework.');
		}

		$cid      = $this->requirePositiveInt($arguments, 'campaign_id');
		$campaign = $this->acyCampaignRow($cid);
		if (!$campaign) {
			return ToolResult::error('No AcyMailing campaign with id ' . $cid . '.');
		}
		$mailId = (int) $campaign['mail_id'];
		$mail   = $this->acyMailRow($mailId);
		$t      = fn (string $s): string => $this->acyQn($this->acyTable($s));
		$queued = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $t('queue') . ' WHERE mail_id = ' . $mailId)->loadResult();

		if ($campaign['sending_type'] !== 'now') {
			return ToolResult::error('Campaign ' . $cid . ' has sending_type "' . $campaign['sending_type'] . '". Only classic send-now campaigns are queued here; scheduled and automatic campaigns are Essential/Enterprise features.');
		}
		if ((int) $campaign['draft'] !== 1 || (int) $campaign['sent'] === 1 || $queued > 0) {
			return ToolResult::error('Campaign ' . $cid . ' is not an unsent draft (draft=' . $campaign['draft'] . ', sent=' . $campaign['sent'] . ', queued=' . $queued . ').');
		}
		if (!$mail || trim(strip_tags((string) $mail['body'])) === '' && !str_contains((string) $mail['body'], '<img')) {
			return ToolResult::error('The campaign mail has no body.');
		}

		// Segments and A/B splits (Enterprise) narrow or divide the recipients
		// inside CampaignClass::send() through filter plugins this count cannot
		// reproduce, so the dry-run number would be wrong. Refuse rather than
		// approve a send whose size we cannot state.
		$params = json_decode((string) $campaign['sending_params'], true) ?: [];
		if (!empty($params['segment']) || !empty($params['abtest']) || !empty($params['resendTarget'])) {
			return ToolResult::error('Campaign ' . $cid . ' uses ' . implode(' / ', array_keys(array_filter(['segment' => !empty($params['segment']), 'abtest' => !empty($params['abtest']), 'resendTarget' => !empty($params['resendTarget'])])))
				. ' sending parameters. Their recipient set is computed by AcyMailing filter plugins this tool cannot predict, so it will not queue it. Send it from the AcyMailing admin.');
		}

		$listIds = $this->acyMailListIds($mailId);
		if ($listIds === []) {
			return ToolResult::error('Campaign ' . $cid . ' has no target lists (update_acym_campaign list_ids).');
		}

		$reach   = $this->acyRecipientCount($listIds);
		$level   = $this->acyLevel();
		$cfg     = $this->acyConfigAll();
		$process = $level === 'starter'
			? 'Starter: nothing sends until an administrator opens AcyMailing > Queue and processes it (or clicks Send on the campaign summary, which processes in browser batches).'
			: 'Essential/Enterprise: queue_type=' . ($cfg['queue_type'] ?? '') . ', active_cron=' . ($cfg['active_cron'] ?? '0') . '. With the cron active the queue is delivered automatically in batches of ' . ($cfg['queue_nbmail_auto'] ?? '?') . '.';

		$summary = [
			'campaign_id'          => $cid,
			'mail_id'              => $mailId,
			'subject'              => $mail['subject'],
			'lists'                => $listIds,
			'recipients'           => $reach,
			'edition'              => $level,
			'what_processes_queue' => $process,
			'mailer'               => $this->acyMailerSummary(),
		];

		if (($arguments['dry_run'] ?? true) !== false) {
			return ToolResult::json(['ok' => true, 'dry_run' => true, 'queued' => 0] + $summary + [
				'to_send' => 'Re-run with dry_run: false, confirm: true, expected_recipients: ' . $reach['distinct_recipients'] . '.',
			]);
		}
		if (($arguments['confirm'] ?? false) !== true) {
			return $this->acyConfirmRequired('Queueing a campaign sends real email to ' . $reach['distinct_recipients'] . ' people.');
		}
		if ((int) ($arguments['expected_recipients'] ?? 0) !== $reach['distinct_recipients']) {
			return ToolResult::error('expected_recipients (' . (int) ($arguments['expected_recipients'] ?? 0) . ') does not match the live deduplicated count (' . $reach['distinct_recipients'] . '). Review the dry run again.');
		}
		if ($reach['distinct_recipients'] === 0) {
			return ToolResult::error('No eligible recipients.');
		}

		$class  = $this->acyCampaignClass();
		$result = $this->withSiteRoot(fn () => $class->send($cid));
		$msgs   = array_merge($class->errors, $this->acyDrainMessages());

		$after     = $this->acyCampaignRow($cid);
		$nowQueued = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $t('queue') . ' WHERE mail_id = ' . $mailId)->loadResult();
		$stat      = $this->db->setQuery('SELECT total_subscribers, sent, send_date FROM ' . $t('mail_stat') . ' WHERE mail_id = ' . $mailId)->loadAssoc();

		return ToolResult::json([
			'ok'               => $result !== false && $nowQueued > 0,
			'queued'           => $nowQueued,
			'send_returned'    => $result,
			'campaign_after'   => ['draft' => (int) $after['draft'], 'sent' => (int) $after['sent'], 'active' => (int) $after['active'], 'sending_date' => $after['sending_date']],
			'mail_stat'        => $stat,
			'vendor_messages'  => $msgs,
			'undo'             => 'cancel_acym_campaign_sending with campaign_id ' . $cid . ' removes whatever has not been sent yet.',
		] + $summary);
	}
}
