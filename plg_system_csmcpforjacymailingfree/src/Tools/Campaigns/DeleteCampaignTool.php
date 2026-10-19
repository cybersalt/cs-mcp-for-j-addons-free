<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Campaigns;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

/**
 * Delete a campaign through CampaignClass::delete(), which deletes its mail
 * via MailClass::delete() — and that cascades to the queue, the target lists,
 * EVERY statistic (mail_stat, user_stat, url_click) and the archive.
 */
final class DeleteCampaignTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'delete_acym_campaign'; }

	public function getDescription(): string
	{
		return 'Delete an AcyMailing campaign via CampaignClass::delete(). The vendor deletes the campaign\'s mail '
			. 'and with it any queued sends, the target-list links, the archive copy and ALL statistics for that '
			. 'mail (opens, clicks, per-subscriber delivery records). No trash. Sent campaigns are refused unless '
			. 'allow_sent: true, because their statistics are history. Without confirm: true only the blast '
			. 'radius is reported.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['campaign_id'],
			'properties' => [
				'campaign_id' => ['type' => 'integer', 'minimum' => 1],
				'allow_sent'  => ['type' => 'boolean'],
				'confirm'     => ['type' => 'boolean'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->acyAdminBase() === null) {
			return $this->notInstalledError();
		}

		$cid      = $this->requirePositiveInt($arguments, 'campaign_id');
		$campaign = $this->acyCampaignRow($cid);
		if (!$campaign) {
			return ToolResult::error('No AcyMailing campaign with id ' . $cid . '.');
		}
		$mailId = (int) $campaign['mail_id'];
		$t      = fn (string $s): string => $this->acyQn($this->acyTable($s));
		$n      = fn (string $s): int => (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $t($s) . ' WHERE mail_id = ' . $mailId)->loadResult();
		$mail   = $mailId ? $this->acyMailRow($mailId) : null;

		$radius = [
			'campaign_id'     => $cid,
			'mail_id'         => $mailId,
			'subject'         => $mail['subject'] ?? null,
			'sent'            => (int) $campaign['sent'],
			'queued_removed'  => $n('queue'),
			'list_links'      => $n('mail_has_list'),
			'user_stat_rows'  => $n('user_stat'),
			'click_rows'      => $n('url_click'),
			'has_mail_stat'   => $n('mail_stat') > 0,
		];

		if ((int) $campaign['sent'] === 1 && empty($arguments['allow_sent'])) {
			return ToolResult::json(['ok' => false, 'deleted' => false, 'blast_radius' => $radius, 'message' => 'Campaign has been sent; deleting it destroys its statistics. Pass allow_sent: true as well as confirm: true if that is intended.']);
		}
		if (($arguments['confirm'] ?? false) !== true) {
			return ToolResult::json(['ok' => false, 'deleted' => false, 'would_delete' => $radius, 'message' => 'Not deleted. Re-run with confirm: true.']);
		}
		if (!$this->ensureAcyLoaded()) {
			return ToolResult::error('Could not load the AcyMailing framework.');
		}

		$class   = $this->acyCampaignClass();
		$deleted = $class->delete([$cid]);

		$campLeft = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $t('campaign') . ' WHERE id = ' . $cid)->loadResult();
		$mailLeft = $mailId ? (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $t('mail') . ' WHERE id = ' . $mailId)->loadResult() : 0;

		return ToolResult::json([
			'ok'              => $campLeft === 0 && $mailLeft === 0,
			'deleted'         => $deleted,
			'campaign_row_gone' => $campLeft === 0,
			'mail_row_gone'   => $mailLeft === 0,
			'blast_radius'    => $radius,
			'vendor_messages' => $this->acyDrainMessages(),
		]);
	}
}
