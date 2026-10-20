<?php
/**
 * @package     MCP for AcyMailing
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Subscribers;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

final class GetSubscriberTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'get_acym_subscriber'; }

	public function getDescription(): string
	{
		return 'One AcyMailing subscriber by id or email: the profile (never the secret `key`), every list '
			. 'subscription including unsubscribe records, field values, the linked Joomla account, pending queue '
			. 'entries, delivery totals, and the most recent history entries (created, subscribed, unsubscribed, '
			. 'confirmed, test_sent...).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'id'            => ['type' => 'integer', 'minimum' => 1],
				'email'         => ['type' => 'string'],
				'history_limit' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 200, 'description' => 'Default 20.'],
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

		$row = $this->acyFindSubscriber(isset($arguments['id']) ? (int) $arguments['id'] : null, $arguments['email'] ?? null);
		if (!$row) {
			return ToolResult::error('No AcyMailing subscriber matches that id/email.');
		}
		$id = (int) $row['id'];
		$t  = fn (string $s): string => $this->acyQn($this->acyTable($s));

		$fields = $this->db->setQuery(
			'SELECT f.id field_id, f.name, f.namekey, f.type, f.core, v.value FROM ' . $t('user_has_field') . ' v'
			. ' JOIN ' . $t('field') . ' f ON f.id = v.field_id WHERE v.user_id = ' . $id . ' ORDER BY f.ordering'
		)->loadAssocList() ?: [];

		$queue = $this->db->setQuery(
			'SELECT q.mail_id, q.sending_date, q.try, m.subject FROM ' . $t('queue') . ' q LEFT JOIN ' . $t('mail') . ' m ON m.id = q.mail_id WHERE q.user_id = ' . $id
		)->loadAssocList() ?: [];

		$stats = $this->db->setQuery(
			'SELECT COUNT(*) mails, COALESCE(SUM(sent),0) sent, COALESCE(SUM(fail),0) failed, COALESCE(SUM(open > 0),0) opened, COALESCE(SUM(bounce > 0),0) bounced'
			. ' FROM ' . $t('user_stat') . ' WHERE user_id = ' . $id
		)->loadAssoc() ?: [];

		$hLimit  = (int) ($arguments['history_limit'] ?? 20);
		$history = $hLimit > 0 ? ($this->db->setQuery(
			'SELECT `date`, action, data, source, mail_id, unsubscribe_reason FROM ' . $t('history') . ' WHERE user_id = ' . $id . ' ORDER BY `date` DESC',
			0,
			$hLimit
		)->loadAssocList() ?: []) : [];
		foreach ($history as &$h) {
			$h['date_utc'] = gmdate('Y-m-d H:i:s', (int) $h['date']);
		}
		unset($h);

		$cms = null;
		if ((int) $row['cms_id'] > 0) {
			$cms = $this->db->setQuery('SELECT id, username, name, email, block FROM #__users WHERE id = ' . (int) $row['cms_id'])->loadAssoc() ?: ['id' => (int) $row['cms_id'], 'missing' => true];
		}

		return ToolResult::json([
			'subscriber'           => $this->acyPublicSubscriber($row),
			'subscriptions'        => $this->acySubscriptions($id),
			'fields'               => $fields,
			'joomla_user'          => $cms,
			'queued'               => array_map(fn (array $q): array => $this->acyDecodeMail($q), $queue),
			'delivery_totals'      => array_map('intval', $stats),
			'history'              => $history,
			'require_confirmation' => $this->acyRequiresConfirmation(),
		]);
	}
}
