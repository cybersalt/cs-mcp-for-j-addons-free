<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Campaigns;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

/**
 * Edit a DRAFT campaign's mail and target lists.
 *
 * MailClass::save() is not partial-safe: on every save it forces
 * `access = ''` when the property is absent (wiping a front-end access
 * restriction) and nulls `autosave`. So this tool hydrates the complete raw
 * mail row (decoded from the vendor's double encoding, WITHOUT the
 * "Powered by AcyMailing" footer that MailClass::getOneById() injects on
 * Starter), overlays the caller's fields and saves the whole object.
 *
 * Only drafts are editable: once a campaign is queued its mail is what the
 * queue renders, and changing it mid-send would give recipients different
 * emails.
 */
final class UpdateCampaignTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'update_acym_campaign'; }

	public function getDescription(): string
	{
		return 'Update a DRAFT AcyMailing campaign: subject, name, preheader, body_html, stylesheet, from/reply-to, '
			. 'tracking, and list_ids (replaces the target lists). Refused for anything already queued, sent or '
			. 'scheduled. The full mail row is hydrated first because a partial MailClass::save() silently clears the '
			. 'mail\'s access restriction. Returns the before/after diff of the mail row and the new reach.';
	}

	public function getInputSchema(): array
	{
		$s = CampaignPayload::schema();
		unset($s['template_id']);

		return [
			'type'       => 'object',
			'required'   => ['campaign_id'],
			'properties' => ['campaign_id' => ['type' => 'integer', 'minimum' => 1]] + $s,
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

		$cid      = $this->requirePositiveInt($arguments, 'campaign_id');
		$campaign = $this->acyCampaignRow($cid);
		if (!$campaign) {
			return ToolResult::error('No AcyMailing campaign with id ' . $cid . '.');
		}
		$mailId = (int) $campaign['mail_id'];
		$queued = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->acyQn($this->acyTable('queue')) . ' WHERE mail_id = ' . $mailId)->loadResult();
		if ((int) $campaign['draft'] !== 1 || (int) $campaign['sent'] === 1 || $queued > 0) {
			return ToolResult::error('Campaign ' . $cid . ' is not an unsent draft (draft=' . $campaign['draft'] . ', sent=' . $campaign['sent'] . ', queued=' . $queued . '). Only drafts are editable.');
		}

		$fields = CampaignPayload::mailFields($arguments);
		if (\is_string($fields)) {
			return ToolResult::error($fields);
		}
		$listChange = array_key_exists('list_ids', $arguments);
		if ($fields === [] && !$listChange) {
			return ToolResult::error('Nothing to update.');
		}

		$before = $this->acyMailRow($mailId);
		if (!$before) {
			return ToolResult::error('Campaign ' . $cid . ' points at mail ' . $mailId . ', which does not exist.');
		}

		if ($fields !== []) {
			if (array_key_exists('subject', $fields) && trim($fields['subject']) === '') {
				return ToolResult::error('subject cannot be empty.');
			}
			$mail = (object) $before;
			foreach ($fields as $k => $v) {
				$mail->$k = $v;
			}
			if (isset($fields['body'])) {
				$mail->drag_editor = str_contains($mail->body, 'acym__wysid__template') ? 1 : 0;
			}
			$mailClass = new \AcyMailing\Classes\MailClass();
			$saved     = $mailClass->save($mail);
			if ((int) $saved !== $mailId) {
				return ToolResult::error('MailClass::save() did not update mail ' . $mailId . ': ' . implode('; ', array_merge($mailClass->errors, $this->acyDrainMessages())));
			}
		}

		if ($listChange) {
			$lists = $this->acyResolveLists((array) $arguments['list_ids']);
			if ($lists['missing'] !== []) {
				return ToolResult::error('Unknown list id(s): ' . implode(', ', $lists['missing']) . ' (mail fields, if any, were saved).');
			}
			$current = $this->acyMailListIds($mailId);
			$remove  = array_values(array_diff($current, $lists['ids']));
			$campaignClass = new \AcyMailing\Classes\CampaignClass();
			if ($lists['ids'] === []) {
				$this->db->setQuery('DELETE FROM ' . $this->acyQn($this->acyTable('mail_has_list')) . ' WHERE mail_id = ' . $mailId)->execute();
			} else {
				$campaignClass->manageListsToCampaign($lists['ids'], $mailId, $remove);
			}
		}

		$after = $this->acyMailRow($mailId) ?? [];
		$diff  = [];
		foreach ($after as $k => $v) {
			if (\in_array($k, ['autosave'], true)) {
				continue;
			}
			if ((string) $v !== (string) ($before[$k] ?? '')) {
				$diff[$k] = $k === 'body'
					? ['from_bytes' => \strlen((string) ($before[$k] ?? '')), 'to_bytes' => \strlen((string) $v)]
					: ['from' => $before[$k] ?? null, 'to' => $v];
			}
		}
		$expected = array_keys($fields);
		if (isset($fields['body'])) {
			$expected[] = 'drag_editor';
		}

		return ToolResult::json([
			'ok'              => true,
			'campaign_id'     => $cid,
			'mail_id'         => $mailId,
			'mail_changed'    => $diff,
			'unexpected_mail_changes' => array_values(array_diff(array_keys($diff), $expected)),
			'lists'           => $this->acyMailListIds($mailId),
			'would_reach'     => $this->acyRecipientCount($this->acyMailListIds($mailId)),
			'vendor_messages' => $this->acyDrainMessages(),
		]);
	}
}
