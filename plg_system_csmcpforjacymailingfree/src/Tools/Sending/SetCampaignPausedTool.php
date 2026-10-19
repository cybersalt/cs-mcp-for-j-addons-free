<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Sending;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

/**
 * Pause / resume a campaign's queue via QueueClass::unpauseCampaign(), the
 * method behind the Queue screen's play/pause button. "Paused" is simply
 * campaign.active = 0: the queue processors skip rows whose campaign is
 * inactive, and the rows stay put.
 */
final class SetCampaignPausedTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'set_acym_campaign_paused'; }

	public function getDescription(): string
	{
		return 'Pause (paused: true) or resume (paused: false) the queued sending of an AcyMailing campaign via '
			. 'QueueClass::unpauseCampaign(), the Queue screen\'s play/pause button. Pausing keeps the queued rows; '
			. 'nothing sends for that campaign until resumed. Resuming lets the queue processor (admin page on '
			. 'Starter, cron on Essential+) continue sending, so it requires confirm: true.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['campaign_id', 'paused'],
			'properties' => [
				'campaign_id' => ['type' => 'integer', 'minimum' => 1],
				'paused'      => ['type' => 'boolean'],
				'confirm'     => ['type' => 'boolean', 'description' => 'Required to resume.'],
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
		if (!$this->ensureAcyLoaded()) {
			return ToolResult::error('Could not load the AcyMailing framework.');
		}

		$cid      = $this->requirePositiveInt($arguments, 'campaign_id');
		$campaign = $this->acyCampaignRow($cid);
		if (!$campaign) {
			return ToolResult::error('No AcyMailing campaign with id ' . $cid . '.');
		}
		$paused = (bool) $arguments['paused'];
		$queued = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->acyQn($this->acyTable('queue')) . ' WHERE mail_id = ' . (int) $campaign['mail_id'])->loadResult();

		if (!$paused && ($arguments['confirm'] ?? false) !== true) {
			return $this->acyConfirmRequired('Resuming lets ' . $queued . ' queued email(s) be sent.');
		}
		if ($campaign['sending_type'] === 'auto') {
			return ToolResult::error('Campaign ' . $cid . ' is an automatic campaign; its active flag means "enabled", not "paused". That is an Enterprise feature.');
		}

		(new \AcyMailing\Classes\QueueClass())->unpauseCampaign($cid, $paused ? 0 : 1);
		$msgs  = $this->acyDrainMessages();
		$after = $this->acyCampaignRow($cid);

		return ToolResult::json([
			'ok'              => (int) $after['active'] === ($paused ? 0 : 1),
			'campaign_id'     => $cid,
			'paused'          => (int) $after['active'] === 0,
			'queued_rows'     => $queued,
			'vendor_messages' => $msgs,
		]);
	}
}
