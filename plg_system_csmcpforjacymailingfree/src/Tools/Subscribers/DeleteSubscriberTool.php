<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Subscribers;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

/**
 * Hard-delete subscribers through UserClass::delete($ids, true).
 *
 * The vendor delete cascades to subscriptions, queue, field values, history,
 * per-mail stats, scenario runs and uploaded files. It also destroys the
 * UNSUBSCRIBE RECORD: with the row gone, nothing remembers that this person
 * opted out, so a later import or form re-adds them as a fresh subscriber.
 * Deactivating (update_acym_subscriber active: 0) is usually what is wanted.
 */
final class DeleteSubscriberTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'delete_acym_subscriber'; }

	public function getDescription(): string
	{
		return 'Permanently delete one or more AcyMailing subscribers (subscriber_ids and/or emails) via '
			. 'UserClass::delete(), which also deletes their subscriptions, queued mail, field values, history, '
			. 'statistics and scenario runs. No trash, no undo, and it erases the record that they unsubscribed - '
			. 'a later import would silently re-add someone who opted out. Prefer update_acym_subscriber with '
			. 'active: 0. Without confirm: true only the blast radius is reported.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'subscriber_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
				'emails'         => ['type' => 'array', 'items' => ['type' => 'string']],
				'confirm'        => ['type' => 'boolean'],
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

		$res = $this->acyResolveSubscribers($arguments);
		if ($res['ids'] === []) {
			return ToolResult::error('No matching subscribers. Not found: ' . implode(', ', $res['missing']));
		}
		$in = implode(',', $res['ids']);
		$t  = fn (string $s): string => $this->acyQn($this->acyTable($s));
		$n  = fn (string $s, string $col = 'user_id'): int => (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $t($s) . ' WHERE ' . $col . ' IN (' . $in . ')')->loadResult();

		$radius = [
			'subscribers'        => $this->db->setQuery('SELECT id, email FROM ' . $t('user') . ' WHERE id IN (' . $in . ')')->loadAssocList() ?: [],
			'subscription_rows'  => $n('user_has_list'),
			'unsubscribe_records_lost' => (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $t('user_has_list') . ' WHERE status = 0 AND user_id IN (' . $in . ')')->loadResult(),
			'queued_emails'      => $n('queue'),
			'field_values'       => $n('user_has_field'),
			'history_rows'       => $n('history'),
			'stat_rows'          => $n('user_stat'),
			'not_found'          => $res['missing'],
		];

		if (($arguments['confirm'] ?? false) !== true) {
			return ToolResult::json(['ok' => false, 'deleted' => 0, 'would_delete' => $radius, 'message' => 'Not deleted. Re-run with confirm: true (or deactivate instead).']);
		}
		if (!$this->ensureAcyLoaded()) {
			return ToolResult::error('Could not load the AcyMailing framework.');
		}

		$class   = $this->acyUserClass();
		$deleted = $class->delete($res['ids'], true);
		$left    = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $t('user') . ' WHERE id IN (' . $in . ')')->loadResult();

		return ToolResult::json([
			'ok'              => $left === 0,
			'deleted'         => $deleted,
			'rows_remaining'  => $left,
			'subscriptions_remaining' => $n('user_has_list'),
			'blast_radius'    => $radius,
			'vendor_messages' => $this->acyDrainMessages(),
		]);
	}
}
