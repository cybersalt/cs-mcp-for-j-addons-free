<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Reports;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

/**
 * Per-campaign totals from #__acym_mail_stat — the "global statistics" level
 * Starter shows. Per-subscriber detail, link-level clicks and the click map are
 * Essential features (StatsView adds those tabs only under
 * acym_level(ACYM_ESSENTIAL)) and live in the Pro add-on.
 */
final class GetCampaignStatsTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'get_acym_campaign_stats'; }

	public function getDescription(): string
	{
		return 'Summary statistics for AcyMailing campaigns: recipients queued, sent, failed, unique/total opens, '
			. 'unique/total clicks, bounces, unsubscribes, open/click/bounce rates, and emails still waiting in the '
			. 'queue. Pass campaign_id or mail_id for one, or nothing for the most recent sent campaigns (limit, '
			. 'default 10). Opens are pixel-based and undercount (image blocking) or overcount (privacy proxies). '
			. 'Per-subscriber and per-link statistics are an AcyMailing Essential feature and are in the Pro add-on.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'campaign_id' => ['type' => 'integer', 'minimum' => 1],
				'mail_id'     => ['type' => 'integer', 'minimum' => 1],
				'limit'       => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
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
		$t = fn (string $s): string => $this->acyQn($this->acyTable($s));

		$sql = 'SELECT c.id campaign_id, m.id mail_id, m.name, m.subject, c.sending_type, c.sending_date, c.sent campaign_sent, c.draft,'
			. ' s.total_subscribers, s.sent, s.fail, s.open_unique, s.open_total, s.click_unique, s.click_total, s.bounce_unique, s.unsubscribe_total, s.send_date,'
			. ' (SELECT COUNT(*) FROM ' . $t('queue') . ' q WHERE q.mail_id = m.id) still_queued'
			. ' FROM ' . $t('mail') . ' m LEFT JOIN ' . $t('campaign') . ' c ON c.mail_id = m.id LEFT JOIN ' . $t('mail_stat') . ' s ON s.mail_id = m.id';

		if (!empty($arguments['campaign_id'])) {
			$sql .= ' WHERE c.id = ' . (int) $arguments['campaign_id'];
		} elseif (!empty($arguments['mail_id'])) {
			$sql .= ' WHERE m.id = ' . (int) $arguments['mail_id'];
		} else {
			$sql .= ' WHERE s.mail_id IS NOT NULL ORDER BY s.send_date DESC';
		}

		$rows = $this->db->setQuery($sql, 0, $this->acyLimit($arguments, 10, 100))->loadAssocList() ?: [];
		if ($rows === [] && (!empty($arguments['campaign_id']) || !empty($arguments['mail_id']))) {
			return ToolResult::error('No such campaign / mail.');
		}

		$out = [];
		foreach ($rows as $r) {
			$r    = $this->acyDecodeMail($r);
			$sent = (int) $r['sent'];
			$rate = static fn ($n) => $sent > 0 ? round(100 * (int) $n / $sent, 2) : null;
			$out[] = $r + [
				'has_stats'        => $r['send_date'] !== null || $r['total_subscribers'] !== null,
				'open_rate_pct'    => $rate($r['open_unique']),
				'click_rate_pct'   => $rate($r['click_unique']),
				'bounce_rate_pct'  => $rate($r['bounce_unique']),
				'unsub_rate_pct'   => $rate($r['unsubscribe_total']),
			];
		}

		return ToolResult::json([
			'count'     => count($out),
			'campaigns' => $out,
			'edition'   => $this->acyLevel(),
			'note'      => 'Rates are per email sent. total_subscribers is the number queued when the campaign was sent; cancelling a send resets it to what was actually sent.',
		]);
	}
}
