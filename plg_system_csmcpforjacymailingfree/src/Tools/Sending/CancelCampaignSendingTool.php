<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Sending;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

/**
 * Cancel the unsent remainder of a campaign — a line-for-line reproduction of
 * the admin Queue screen's "Cancel" (Controllers/Queue/Campaigns.php::
 * cancelSending()), which is a controller action with no class method behind
 * it:
 *
 *   DELETE FROM queue WHERE mail_id = X
 *   UPDATE mail_stat SET total_subscribers = sent WHERE mail_id = X
 *   UPDATE campaign SET active = 1 WHERE mail_id = X
 *   if nobody has received it yet (no user_stat rows):
 *       UPDATE campaign SET draft = 1, sent = 0, sending_date = NULL
 *       DELETE FROM mail_stat WHERE mail_id = X
 *
 * So a campaign cancelled before anything went out becomes an editable draft
 * again; one cancelled mid-send stays "sent" with its real totals.
 */
final class CancelCampaignSendingTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'cancel_acym_campaign_sending'; }

	public function getDescription(): string
	{
		return 'Cancel whatever of an AcyMailing campaign is still waiting in the queue - exactly what the admin '
			. 'Queue screen\'s Cancel button does. Emails already sent stay sent. If nothing had been sent yet the '
			. 'campaign returns to an editable draft and its empty statistics row is removed; otherwise it stays '
			. 'sent with total_subscribers corrected to the number actually sent. Requires confirm: true; without it '
			. 'reports how many queued emails would be cancelled.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['campaign_id'],
			'properties' => [
				'campaign_id' => ['type' => 'integer', 'minimum' => 1],
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
		if (!$campaign || empty($campaign['mail_id'])) {
			return ToolResult::error('No AcyMailing campaign with id ' . $cid . '.');
		}
		$mailId  = (int) $campaign['mail_id'];
		$t       = fn (string $s): string => $this->acyQn($this->acyTable($s));
		$queued  = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $t('queue') . ' WHERE mail_id = ' . $mailId)->loadResult();
		$hasStat = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $t('user_stat') . ' WHERE mail_id = ' . $mailId)->loadResult();

		if (($arguments['confirm'] ?? false) !== true) {
			return ToolResult::json([
				'ok' => false, 'cancelled' => 0, 'would_cancel' => $queued, 'already_delivered_to' => $hasStat,
				'would_return_to_draft' => $hasStat === 0,
				'message' => 'Nothing changed. Re-run with confirm: true.',
			]);
		}

		$this->db->setQuery('DELETE FROM ' . $t('queue') . ' WHERE mail_id = ' . $mailId)->execute();
		$removed = $this->db->getAffectedRows();
		$this->db->setQuery('UPDATE ' . $t('mail_stat') . ' SET total_subscribers = sent WHERE mail_id = ' . $mailId)->execute();
		$this->db->setQuery('UPDATE ' . $t('campaign') . ' SET active = 1 WHERE mail_id = ' . $mailId)->execute();
		if ($hasStat === 0) {
			$this->db->setQuery('UPDATE ' . $t('campaign') . " SET draft = 1, sent = 0, sending_date = NULL WHERE mail_id = " . $mailId)->execute();
			$this->db->setQuery('DELETE FROM ' . $t('mail_stat') . ' WHERE mail_id = ' . $mailId)->execute();
		}

		$after = $this->acyCampaignRow($cid);

		return ToolResult::json([
			'ok'               => (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $t('queue') . ' WHERE mail_id = ' . $mailId)->loadResult() === 0,
			'cancelled'        => $removed,
			'already_delivered_to' => $hasStat,
			'returned_to_draft' => $hasStat === 0,
			'campaign_after'   => ['draft' => (int) $after['draft'], 'sent' => (int) $after['sent'], 'active' => (int) $after['active'], 'sending_date' => $after['sending_date']],
		]);
	}
}
