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

final class GetQueueStatusTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'get_acym_queue_status'; }

	public function getDescription(): string
	{
		return 'What is waiting in AcyMailing\'s sending queue (#__acym_queue): per mail - subject, campaign, '
			. 'paused or not, rows pending, rows due now vs scheduled later, rows retried and rows that hit the '
			. 'retry limit, and how many belong to inactive/unconfirmed subscribers (the sender skips those). '
			. 'Also the queue settings (type, batch size, pause, retries) and when the cron last ran. Pass mail_id '
			. 'and with_recipients: true to see who is queued (max 200). Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'mail_id'         => ['type' => 'integer', 'minimum' => 1],
				'with_recipients' => ['type' => 'boolean'],
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
		$t    = fn (string $s): string => $this->acyQn($this->acyTable($s));
		$cfg  = $this->acyConfigAll();
		$max  = max(1, (int) ($cfg['queue_try'] ?? 3));
		$now  = $this->db->quote(gmdate('Y-m-d H:i:s'));
		$conf = $this->acyRequiresConfirmation();

		$where = !empty($arguments['mail_id']) ? ' WHERE q.mail_id = ' . (int) $arguments['mail_id'] : '';
		$rows  = $this->db->setQuery(
			'SELECT q.mail_id, m.subject, m.type mail_type, c.id campaign_id, c.active campaign_active, COUNT(*) pending,'
			. ' SUM(q.sending_date <= ' . $now . ') due_now, SUM(q.sending_date > ' . $now . ') scheduled_later,'
			. ' MIN(q.sending_date) next_sending_date, SUM(q.try > 0) retried, SUM(q.try >= ' . $max . ') gave_up,'
			. ' SUM(u.id IS NULL OR u.active = 0' . ($conf ? ' OR u.confirmed = 0' : '') . ') undeliverable'
			. ' FROM ' . $t('queue') . ' q LEFT JOIN ' . $t('mail') . ' m ON m.id = q.mail_id LEFT JOIN ' . $t('campaign') . ' c ON c.mail_id = q.mail_id'
			. ' LEFT JOIN ' . $t('user') . ' u ON u.id = q.user_id' . $where
			. ' GROUP BY q.mail_id, m.subject, m.type, c.id, c.active ORDER BY next_sending_date'
		)->loadAssocList() ?: [];

		$out = [];
		foreach ($rows as $r) {
			$r = $this->acyDecodeMail($r);
			foreach (['mail_id', 'campaign_id', 'pending', 'due_now', 'scheduled_later', 'retried', 'gave_up', 'undeliverable'] as $k) {
				$r[$k] = $r[$k] === null ? null : (int) $r[$k];
			}
			$r['paused'] = $r['campaign_active'] !== null && (int) $r['campaign_active'] === 0;
			unset($r['campaign_active']);
			$out[] = $r;
		}

		$recipients = null;
		if (!empty($arguments['mail_id']) && !empty($arguments['with_recipients'])) {
			$recipients = $this->db->setQuery(
				'SELECT q.user_id, u.email, u.active, u.confirmed, q.sending_date, q.try FROM ' . $t('queue') . ' q LEFT JOIN ' . $t('user') . ' u ON u.id = q.user_id'
				. ' WHERE q.mail_id = ' . (int) $arguments['mail_id'] . ' ORDER BY q.sending_date',
				0,
				200
			)->loadAssocList() ?: [];
		}

		$cronLast = (int) ($cfg['cron_last'] ?? 0);

		return ToolResult::json([
			'total_pending' => array_sum(array_column($out, 'pending')),
			'by_mail'       => $out,
			'recipients'    => $recipients,
			'settings'      => [
				'edition'          => $this->acyLevel(),
				'queue_type'       => $cfg['queue_type'] ?? null,
				'batch_manual'     => (int) ($cfg['queue_nbmail'] ?? 0),
				'batch_auto'       => (int) ($cfg['queue_nbmail_auto'] ?? 0),
				'pause_seconds'    => (int) ($cfg['queue_pause'] ?? 0),
				'max_tries'        => $max,
				'active_cron'      => (int) ($cfg['active_cron'] ?? 0),
				'cron_last_run'    => $cronLast ? gmdate('Y-m-d H:i:s', $cronLast) . ' UTC' : null,
			],
		]);
	}
}
