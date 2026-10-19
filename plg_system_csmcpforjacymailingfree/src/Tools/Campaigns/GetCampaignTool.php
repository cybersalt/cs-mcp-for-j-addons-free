<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Campaigns;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

final class GetCampaignTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'get_acym_campaign'; }

	public function getDescription(): string
	{
		return 'One AcyMailing campaign: the campaign row (sending_params decoded), its mail (subject, preheader, '
			. 'from/reply-to, and body unless include_body: false - decoded from AcyMailing\'s double-UTF-8 storage), '
			. 'target lists with names, how many distinct people a send would reach right now (deduplicated '
			. 'across lists exactly as the queue does), queue state and statistics.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['campaign_id'],
			'properties' => [
				'campaign_id'  => ['type' => 'integer', 'minimum' => 1],
				'include_body' => ['type' => 'boolean', 'description' => 'Default true.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

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
		$mail   = $this->acyMailRow($mailId);
		$t      = fn (string $s): string => $this->acyQn($this->acyTable($s));

		$campaign['sending_params'] = json_decode((string) $campaign['sending_params'], true);
		if ($mail && ($arguments['include_body'] ?? true) === false) {
			$mail['body'] = '(omitted, ' . \strlen((string) $mail['body']) . ' bytes)';
		}
		if ($mail) {
			unset($mail['autosave'], $mail['thumbnail']);
		}

		$lists = $this->db->setQuery('SELECT l.id, l.name, l.active FROM ' . $t('mail_has_list') . ' ml JOIN ' . $t('list') . ' l ON l.id = ml.list_id WHERE ml.mail_id = ' . $mailId)->loadAssocList() ?: [];
		$queue = $this->db->setQuery('SELECT COUNT(*) n, MIN(sending_date) next_send, SUM(try > 0) retried FROM ' . $t('queue') . ' WHERE mail_id = ' . $mailId)->loadAssoc() ?: [];
		$stats = $this->db->setQuery('SELECT * FROM ' . $t('mail_stat') . ' WHERE mail_id = ' . $mailId)->loadAssoc() ?: null;
		if ($stats) {
			unset($stats['bounce_details']);
		}

		return ToolResult::json([
			'campaign'    => $campaign,
			'mail'        => $mail,
			'lists'       => $lists,
			'would_reach' => $this->acyRecipientCount(array_map(static fn ($l) => (int) $l['id'], $lists)),
			'queue'       => $queue,
			'stats'       => $stats,
		]);
	}
}
