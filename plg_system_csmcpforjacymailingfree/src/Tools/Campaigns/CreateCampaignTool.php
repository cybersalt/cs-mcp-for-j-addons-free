<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Campaigns;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

/**
 * Create a DRAFT classic campaign, the way Campaigns\Edition::saveEditEmail()
 * does for a new campaign: a #__acym_mail row of type standard through
 * MailClass::save() (which applies the vendor's double encoding), then a
 * #__acym_campaign row with draft = 1, active = 0, sending_type = now, then
 * the target lists through CampaignClass::manageListsToCampaign().
 *
 * A draft is inert: nothing is queued and nothing is sent until
 * queue_acym_campaign is called with confirm: true.
 */
final class CreateCampaignTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'create_acym_campaign'; }

	public function getDescription(): string
	{
		return 'Create an AcyMailing classic campaign as a DRAFT (never sent or queued by this tool). Requires '
			. 'subject and either body_html or template_id. Optional: name, preheader, from/reply-to overrides, '
			. 'stylesheet, tracking, list_ids. Written through the vendor\'s MailClass/CampaignClass so the '
			. 'body is stored in AcyMailing\'s encoding and shows correctly in its editor. The body is raw HTML; '
			. 'AcyMailing dynamic tags such as {subtag:name} and {unsubscribe}...{/unsubscribe} work in it. A body '
			. 'without the drag-and-drop editor wrapper opens in AcyMailing\'s HTML editor. To send: '
			. 'send_acym_test_email first, then queue_acym_campaign.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['subject'],
			'properties' => CampaignPayload::schema(),
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->acyAdminBase() === null) {
			return $this->notInstalledError();
		}
		if (!$this->ensureAcyLoaded()) {
			return ToolResult::error('Could not load the AcyMailing framework.');
		}

		$fields = CampaignPayload::mailFields($arguments);
		if (\is_string($fields)) {
			return ToolResult::error($fields);
		}
		if (trim((string) ($fields['subject'] ?? '')) === '') {
			return ToolResult::error('subject is required.');
		}

		$lists = $this->acyResolveLists((array) ($arguments['list_ids'] ?? []));
		if ($lists['missing'] !== []) {
			return ToolResult::error('Unknown list id(s): ' . implode(', ', $lists['missing']) . '.');
		}

		$template = null;
		if (!empty($arguments['template_id'])) {
			$template = $this->acyMailRow((int) $arguments['template_id']);
			if (!$template || $template['type'] !== 'template') {
				return ToolResult::error('template_id ' . (int) $arguments['template_id'] . ' is not an AcyMailing template (mail type template).');
			}
		}
		if (!isset($fields['body']) && $template === null) {
			return ToolResult::error('Provide body_html or template_id.');
		}

		$mail                = new \stdClass();
		$mail->type          = 'standard';
		$mail->creation_date = acym_date('now', 'Y-m-d H:i:s', false);
		$mail->creator_id    = (int) $actor->id;
		$mail->body          = $fields['body'] ?? (string) $template['body'];
		$mail->stylesheet    = $fields['stylesheet'] ?? ($template['stylesheet'] ?? '');
		$mail->settings      = $template['settings'] ?? '';
		$mail->headers       = $fields['headers'] ?? ($template['headers'] ?? '');
		foreach ($fields as $k => $v) {
			if ($k !== 'body') {
				$mail->$k = $v;
			}
		}
		$mail->name         = trim((string) ($fields['name'] ?? '')) !== '' ? $fields['name'] : $fields['subject'];
		$mail->drag_editor  = str_contains($mail->body, 'acym__wysid__template') ? 1 : 0;
		$mail->attachments  = [];
		$mail->mail_settings = json_encode(['mainColors' => '']);

		$mailClass = new \AcyMailing\Classes\MailClass();
		$mailId    = $mailClass->save($mail);
		if (empty($mailId)) {
			return ToolResult::error('MailClass::save() failed: ' . implode('; ', array_merge($mailClass->errors, $this->acyDrainMessages())));
		}

		$campaign                 = new \stdClass();
		$campaign->mail_id        = (int) $mailId;
		$campaign->draft          = 1;
		$campaign->active         = 0;
		$campaign->sent           = 0;
		$campaign->sending_type   = 'now';
		$campaign->sending_params = [];
		$campaign->visible        = 1;

		$campaignClass = new \AcyMailing\Classes\CampaignClass();
		$campaignId    = $campaignClass->save($campaign);
		if (empty($campaignId)) {
			$mailClass->delete([(int) $mailId]);

			return ToolResult::error('CampaignClass::save() failed (the mail row was removed again): ' . implode('; ', array_merge($campaignClass->errors, $this->acyDrainMessages())));
		}

		if ($lists['ids'] !== []) {
			$campaignClass->manageListsToCampaign($lists['ids'], (int) $mailId);
		}

		$stored = $this->acyMailRow((int) $mailId);

		return ToolResult::json([
			'ok'               => true,
			'campaign_id'      => (int) $campaignId,
			'mail_id'          => (int) $mailId,
			'status'           => 'draft',
			'subject_stored'   => $stored['subject'] ?? null,
			'subject_round_trips' => ($stored['subject'] ?? null) === $mail->subject,
			'body_bytes'       => \strlen((string) ($stored['body'] ?? '')),
			'lists'            => $this->acyMailListIds((int) $mailId),
			'would_reach'      => $this->acyRecipientCount($this->acyMailListIds((int) $mailId)),
			'next_steps'       => 'send_acym_test_email to check rendering, then queue_acym_campaign (dry run first).',
			'vendor_messages'  => $this->acyDrainMessages(),
		]);
	}
}
