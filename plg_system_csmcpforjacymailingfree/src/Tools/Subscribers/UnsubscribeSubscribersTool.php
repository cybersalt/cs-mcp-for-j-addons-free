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

/**
 * Unsubscribe via UserClass::unsubscribe(), which keeps the row with status 0
 * (the opt-out record) and removes pending follow-up mail for those lists.
 *
 * Under the API app the vendor would also mail the list's goodbye email AND
 * the admin "unsubscribed" notification (sendNotification() only skips admin
 * requests). Both are off by default here.
 */
final class UnsubscribeSubscribersTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'unsubscribe_acym_subscribers'; }

	public function getDescription(): string
	{
		return 'Unsubscribe AcyMailing subscribers (subscriber_ids and/or emails) from list_ids, or from every list '
			. 'they are on with all_lists: true, via UserClass::unsubscribe(). The subscription row is kept with '
			. 'status 0 - that is the opt-out record that stops them being re-added - and queued follow-up mail for '
			. 'those lists is removed. Nothing is mailed by default: send_unsubscribe_email (the list\'s goodbye '
			. 'email), notify_admins (the acy_notification_unsub admin alert) and fire_triggers are opt-in.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'subscriber_ids'         => ['type' => 'array', 'items' => ['type' => 'integer']],
				'emails'                 => ['type' => 'array', 'items' => ['type' => 'string']],
				'list_ids'               => ['type' => 'array', 'items' => ['type' => 'integer']],
				'all_lists'              => ['type' => 'boolean'],
				'send_unsubscribe_email' => ['type' => 'boolean'],
				'notify_admins'          => ['type' => 'boolean'],
				'fire_triggers'          => ['type' => 'boolean'],
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

		$res = $this->acyResolveSubscribers($arguments);
		if ($res['ids'] === []) {
			return ToolResult::error('No matching subscribers. Not found: ' . implode(', ', $res['missing']));
		}
		$all = (bool) ($arguments['all_lists'] ?? false);
		$lists = $all ? ['ids' => [], 'missing' => []] : $this->acyResolveLists((array) ($arguments['list_ids'] ?? []));
		if (!$all && $lists['ids'] === []) {
			return ToolResult::error('Pass list_ids (valid ones) or all_lists: true.');
		}

		$class = $this->acyUserClass([
			'send_unsubscribe' => (bool) ($arguments['send_unsubscribe_email'] ?? false),
			'notify_admins'    => (bool) ($arguments['notify_admins'] ?? false),
			'fire_triggers'    => (bool) ($arguments['fire_triggers'] ?? false),
		]);
		$ul = $this->acyQn($this->acyTable('user_has_list'));

		$results = [];
		foreach ($res['ids'] as $uid) {
			$target = $all
				? array_map('intval', $this->db->setQuery('SELECT list_id FROM ' . $ul . ' WHERE status = 1 AND user_id = ' . $uid)->loadColumn() ?: [])
				: $lists['ids'];
			if ($target === []) {
				$results[] = ['subscriber_id' => $uid, 'unsubscribed' => [], 'note' => 'not subscribed to anything targeted'];
				continue;
			}
			$before = $this->db->setQuery('SELECT list_id, status FROM ' . $ul . ' WHERE user_id = ' . $uid)->loadAssocList('list_id') ?: [];
			$this->withSiteRoot(fn () => $class->unsubscribe([$uid], $target));
			$after = $this->db->setQuery('SELECT list_id, status FROM ' . $ul . ' WHERE user_id = ' . $uid)->loadAssocList('list_id') ?: [];

			$done = $notSub = [];
			foreach ($target as $lid) {
				if ((int) ($before[$lid]['status'] ?? -1) !== 1) {
					$notSub[] = $lid;
				} elseif ((int) ($after[$lid]['status'] ?? -1) === 0) {
					$done[] = $lid;
				}
			}
			$results[] = ['subscriber_id' => $uid, 'unsubscribed' => $done, 'was_not_subscribed' => $notSub];
		}

		return ToolResult::json([
			'ok'              => true,
			'not_found'       => $res['missing'],
			'unknown_lists'   => $lists['missing'],
			'results'         => $results,
			'mail_side_effects' => [
				'unsubscribe_emails' => !empty($arguments['send_unsubscribe_email']) ? 'requested' : 'not sent',
				'admin_notification' => !empty($arguments['notify_admins']) ? 'requested' : 'not sent',
			],
			'vendor_messages' => $this->acyDrainMessages(),
		]);
	}
}
