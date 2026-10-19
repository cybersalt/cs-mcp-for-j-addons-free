<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Subscribers;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

/**
 * Subscribe existing subscribers to lists via UserClass::subscribe().
 *
 * CONSENT GUARD: subscribe() happily flips an unsubscribe record (status 0)
 * back to subscribed. That is how someone who opted out gets mailed again, so
 * this tool skips those rows unless allow_resubscribe: true is passed, and
 * names them.
 */
final class SubscribeSubscribersTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'subscribe_acym_subscribers'; }

	public function getDescription(): string
	{
		return 'Subscribe existing AcyMailing subscribers (subscriber_ids and/or emails, up to 500) to one or more '
			. 'lists via UserClass::subscribe(). Someone who previously UNSUBSCRIBED from a list is skipped and '
			. 'reported unless allow_resubscribe: true - re-adding an opt-out is a consent problem. Already-subscribed '
			. 'pairs are left alone. Mail side effects are off by default: send_welcome (each list\'s welcome email, '
			. 'only to active+confirmed people) and fire_triggers (onAcymAfterUserSubscribe - automations and '
			. 'follow-ups on Enterprise can queue mail from it). Create unknown people with create_acym_subscriber first.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['list_ids'],
			'properties' => [
				'subscriber_ids'    => ['type' => 'array', 'items' => ['type' => 'integer']],
				'emails'            => ['type' => 'array', 'items' => ['type' => 'string']],
				'list_ids'          => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1],
				'allow_resubscribe' => ['type' => 'boolean'],
				'send_welcome'      => ['type' => 'boolean'],
				'fire_triggers'     => ['type' => 'boolean'],
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

		$lists = $this->acyResolveLists((array) ($arguments['list_ids'] ?? []));
		if ($lists['ids'] === []) {
			return ToolResult::error('No valid list_ids. Unknown: ' . implode(', ', $lists['missing']));
		}
		$res = $this->acyResolveSubscribers($arguments);
		if ($res['ids'] === []) {
			return ToolResult::error('No matching subscribers. Not found: ' . implode(', ', $res['missing']));
		}
		if (count($res['ids']) > 500) {
			return ToolResult::error('At most 500 subscribers per call.');
		}

		$allowResub = (bool) ($arguments['allow_resubscribe'] ?? false);
		$trigger    = (bool) ($arguments['fire_triggers'] ?? false);
		$class      = $this->acyUserClass(['send_welcome' => (bool) ($arguments['send_welcome'] ?? false), 'fire_triggers' => $trigger]);
		$ulTable    = $this->acyQn($this->acyTable('user_has_list'));

		$results = [];
		foreach ($res['ids'] as $uid) {
			$existing = [];
			foreach ($this->db->setQuery('SELECT list_id, status FROM ' . $ulTable . ' WHERE user_id = ' . $uid)->loadAssocList() ?: [] as $r) {
				$existing[(int) $r['list_id']] = (int) $r['status'];
			}

			$todo = [];
			$skippedOptOut = [];
			$already = [];
			foreach ($lists['ids'] as $lid) {
				if (($existing[$lid] ?? null) === 1) {
					$already[] = $lid;
				} elseif (($existing[$lid] ?? null) === 0 && !$allowResub) {
					$skippedOptOut[] = $lid;
				} else {
					$todo[] = $lid;
				}
			}

			if ($todo !== []) {
				$this->withSiteRoot(fn () => $class->subscribe([$uid], $todo, $trigger));
			}

			$now = [];
			foreach ($this->db->setQuery('SELECT list_id, status FROM ' . $ulTable . ' WHERE user_id = ' . $uid)->loadAssocList() ?: [] as $r) {
				$now[(int) $r['list_id']] = (int) $r['status'];
			}
			$results[] = [
				'subscriber_id'          => $uid,
				'subscribed'             => array_values(array_filter($todo, static fn ($l) => ($now[$l] ?? null) === 1)),
				'failed'                 => array_values(array_filter($todo, static fn ($l) => ($now[$l] ?? null) !== 1)),
				'already_subscribed'     => $already,
				'skipped_unsubscribed'   => $skippedOptOut,
			];
		}

		return ToolResult::json([
			'ok'              => true,
			'lists'           => $lists['ids'],
			'unknown_lists'   => $lists['missing'],
			'not_found'       => $res['missing'],
			'results'         => $results,
			'welcome_emails'  => !empty($arguments['send_welcome']) ? 'requested (active + confirmed subscribers only)' : 'not sent',
			'triggers_fired'  => $trigger,
			'vendor_messages' => $this->acyDrainMessages(),
		]);
	}
}
