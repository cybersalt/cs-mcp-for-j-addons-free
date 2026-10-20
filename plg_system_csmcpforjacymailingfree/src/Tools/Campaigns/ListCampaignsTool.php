<?php
/**
 * @package     MCP for AcyMailing
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Campaigns;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

final class ListCampaignsTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'list_acym_campaigns'; }

	public function getDescription(): string
	{
		return 'List AcyMailing campaigns with a derived status: draft (editable, inert), scheduled (draft=0, '
			. 'sending_type scheduled, waiting for its date - Essential), sending (queued rows remain), paused '
			. '(queued rows remain but active=0), sent, auto (triggered campaigns - Enterprise). Each row has the '
			. 'subject (decoded), target lists, queued count and headline stats. Filters: status, search '
			. '(subject/name), sending_type; limit/offset. Generated children of automatic campaigns are hidden '
			. 'unless include_generated: true.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'status'            => ['type' => 'string', 'enum' => ['draft', 'scheduled', 'sending', 'paused', 'sent', 'auto', 'any']],
				'sending_type'      => ['type' => 'string', 'enum' => ['now', 'scheduled', 'auto']],
				'search'            => ['type' => 'string'],
				'include_generated' => ['type' => 'boolean'],
				'limit'             => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
				'offset'            => ['type' => 'integer', 'minimum' => 0],
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

		$sql = 'SELECT c.id campaign_id, c.mail_id, c.draft, c.active, c.sent, c.sending_type, c.sending_date, c.parent_id, c.visible,'
			. ' m.name, m.subject, m.creation_date, m.creator_id,'
			. ' (SELECT COUNT(*) FROM ' . $t('queue') . ' q WHERE q.mail_id = c.mail_id) queued,'
			. ' (SELECT GROUP_CONCAT(list_id) FROM ' . $t('mail_has_list') . ' ml WHERE ml.mail_id = c.mail_id) list_ids,'
			. ' s.sent sent_count, s.fail, s.open_unique, s.click_unique'
			. ' FROM ' . $t('campaign') . ' c LEFT JOIN ' . $t('mail') . ' m ON m.id = c.mail_id LEFT JOIN ' . $t('mail_stat') . ' s ON s.mail_id = c.mail_id WHERE 1=1';
		if (empty($arguments['include_generated'])) {
			$sql .= ' AND c.parent_id IS NULL';
		}
		if (!empty($arguments['sending_type'])) {
			$sql .= ' AND c.sending_type = ' . $this->db->quote((string) $arguments['sending_type']);
		}
		$sql .= ' ORDER BY c.id DESC';

		$rows   = $this->db->setQuery($sql)->loadAssocList() ?: [];
		$status = (string) ($arguments['status'] ?? 'any');
		$search = strtolower(trim((string) ($arguments['search'] ?? '')));
		$out    = [];

		foreach ($rows as $r) {
			$r = $this->acyDecodeMail($r);
			$r['status'] = $this->derive($r);
			if ($status !== 'any' && $r['status'] !== $status) {
				continue;
			}
			if ($search !== '' && !str_contains(strtolower((string) $r['subject'] . ' ' . $r['name']), $search)) {
				continue;
			}
			$r['list_ids'] = $r['list_ids'] ? array_map('intval', explode(',', (string) $r['list_ids'])) : [];
			foreach (['campaign_id', 'mail_id', 'draft', 'active', 'sent', 'queued', 'visible'] as $k) {
				$r[$k] = (int) $r[$k];
			}
			$out[] = $r;
		}

		$total = count($out);
		$out   = array_slice($out, $this->acyOffset($arguments), $this->acyLimit($arguments));

		return ToolResult::json(['total' => $total, 'count' => count($out), 'campaigns' => $out]);
	}

	private function derive(array $r): string
	{
		if ($r['sending_type'] === 'auto') {
			return 'auto';
		}
		if ((int) $r['draft'] === 1) {
			return 'draft';
		}
		if ((int) $r['queued'] > 0) {
			return (int) $r['active'] === 1 ? 'sending' : 'paused';
		}
		if ($r['sending_type'] === 'scheduled' && (int) $r['sent'] === 0) {
			return 'scheduled';
		}

		return (int) $r['sent'] === 1 ? 'sent' : 'draft';
	}
}
