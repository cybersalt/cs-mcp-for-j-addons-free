<?php
/**
 * @package     MCP for AcyMailing
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Reports;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

/**
 * Membership counts, list overlap and the deduplicated recipient count for a
 * set of lists — the number AcyMailing will actually queue.
 */
final class GetListMembershipReportTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'get_acym_list_membership_report'; }

	public function getDescription(): string
	{
		return 'Dedupe report for a set of AcyMailing lists (default: every standard list). For each list: '
			. 'subscribed / unsubscribed / eligible. Across the set: total memberships vs DISTINCT eligible people '
			. '(what a campaign to all of them would queue - AcyMailing\'s queue primary key (mail_id, user_id) '
			. 'collapses someone on several lists to one email), how many people sit on 2, 3, ... lists, and a '
			. 'pairwise overlap matrix. This is the number to compare with Events Booking\'s per-event Mass Mail, '
			. 'which cannot dedupe across events. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'list_ids'     => ['type' => 'array', 'items' => ['type' => 'integer']],
				'with_overlap' => ['type' => 'boolean', 'description' => 'Include the pairwise overlap matrix (default true, max 30 lists).'],
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
		if (!empty($arguments['list_ids'])) {
			$lists = $this->acyResolveLists((array) $arguments['list_ids']);
			if ($lists['ids'] === []) {
				return ToolResult::error('None of those list ids exist.');
			}
			$ids = $lists['ids'];
		} else {
			$ids   = array_map('intval', $this->db->setQuery('SELECT id FROM ' . $t('list') . " WHERE type = 'standard' ORDER BY id")->loadColumn() ?: []);
			$lists = ['ids' => $ids, 'missing' => []];
		}
		if ($ids === []) {
			return ToolResult::json(['lists' => [], 'note' => 'No lists.']);
		}
		$in   = implode(',', $ids);
		$conf = $this->acyRequiresConfirmation();
		$elig = 'ul.status = 1 AND u.active = 1' . ($conf ? ' AND u.confirmed = 1' : '');

		$per = $this->db->setQuery(
			'SELECT l.id, l.name, COALESCE(SUM(ul.status = 1),0) subscribed, COALESCE(SUM(ul.status = 0),0) unsubscribed, COALESCE(SUM(' . $elig . '),0) eligible'
			. ' FROM ' . $t('list') . ' l LEFT JOIN ' . $t('user_has_list') . ' ul ON ul.list_id = l.id LEFT JOIN ' . $t('user') . ' u ON u.id = ul.user_id'
			. ' WHERE l.id IN (' . $in . ') GROUP BY l.id ORDER BY l.id'
		)->loadAssocList() ?: [];

		$rc = $this->acyRecipientCount($ids);

		$hist = $this->db->setQuery(
			'SELECT n AS lists_per_person, COUNT(*) people FROM (SELECT ul.user_id, COUNT(*) n FROM ' . $t('user_has_list') . ' ul JOIN ' . $t('user') . ' u ON u.id = ul.user_id'
			. ' WHERE ul.list_id IN (' . $in . ') AND ' . $elig . ' GROUP BY ul.user_id) x GROUP BY n ORDER BY n'
		)->loadAssocList() ?: [];

		$overlap = null;
		if (($arguments['with_overlap'] ?? true) && count($ids) > 1 && count($ids) <= 30) {
			$overlap = $this->db->setQuery(
				'SELECT a.list_id list_a, b.list_id list_b, COUNT(*) shared_eligible FROM ' . $t('user_has_list') . ' a'
				. ' JOIN ' . $t('user_has_list') . ' b ON b.user_id = a.user_id AND b.list_id > a.list_id'
				. ' JOIN ' . $t('user') . ' u ON u.id = a.user_id'
				. ' WHERE a.list_id IN (' . $in . ') AND b.list_id IN (' . $in . ') AND a.status = 1 AND b.status = 1 AND u.active = 1' . ($conf ? ' AND u.confirmed = 1' : '')
				. ' GROUP BY a.list_id, b.list_id ORDER BY shared_eligible DESC'
			)->loadAssocList() ?: [];
		}

		return ToolResult::json([
			'lists'                       => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => $r['name'], 'subscribed' => (int) $r['subscribed'], 'unsubscribed' => (int) $r['unsubscribed'], 'eligible' => (int) $r['eligible']], $per),
			'unknown_list_ids'            => $lists['missing'],
			'total_eligible_memberships'  => $rc['naive_sum'],
			'distinct_eligible_people'    => $rc['distinct_recipients'],
			'duplicate_sends_avoided'     => $rc['duplicates_avoided'],
			'lists_per_person_histogram'  => array_map('array_map', array_fill(0, count($hist), 'intval'), $hist),
			'overlap'                     => $overlap,
			'require_confirmation'        => $conf,
			'eligibility_rule'            => 'subscribed to the list, subscriber active' . ($conf ? ', and confirmed (require_confirmation is on)' : ''),
		]);
	}
}
