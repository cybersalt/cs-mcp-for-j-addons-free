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
 * Create a subscriber through UserClass::save() (+ subscribe()).
 *
 * MAIL SIDE EFFECTS ARE OFF UNLESS ASKED FOR. Under the API application
 * acym_isAdmin() is false, so UserClass::save()'s "admin saves never send the
 * opt-in" exemption does not apply: an unconfirmed subscriber created through
 * the class is mailed a double-opt-in confirmation, and subscribe() mails each
 * list's welcome email. Both default off here.
 *
 * LANGUAGE: with no language, UserClass::save() (non-admin branch) copies the
 * CURRENT Joomla user's language — under MCP that is the token owner, not the
 * subscriber. We set AcyMailing's default user language explicitly instead.
 */
final class CreateSubscriberTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'create_acym_subscriber'; }

	public function getDescription(): string
	{
		return 'Create an AcyMailing subscriber via the vendor\'s UserClass (so history, Joomla-account linking and '
			. 'add-on hooks behave as in the admin) and optionally subscribe them to lists. Arguments: email '
			. '(required; stored lower-cased), name (generated from the address if empty and generate_name is on), '
			. 'language, active (default 1), confirmed (default 0 - while require_confirmation is on an unconfirmed '
			. 'subscriber receives NO campaign until they confirm), tracking, source (default "MCP"), list_ids. '
			. 'NOTHING IS MAILED BY DEFAULT: send_confirmation (double opt-in email), send_welcome (list welcome '
			. 'emails) and fire_triggers (AcyMailing add-on / automation hooks, which can queue follow-ups) are all '
			. 'false unless you pass true. Refuses an email that already exists and returns its id.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['email'],
			'properties' => [
				'email'             => ['type' => 'string'],
				'name'              => ['type' => 'string'],
				'language'          => ['type' => 'string', 'description' => 'e.g. en-GB. Defaults to AcyMailing\'s default.'],
				'active'            => ['type' => 'integer', 'enum' => [0, 1]],
				'confirmed'         => ['type' => 'integer', 'enum' => [0, 1]],
				'tracking'          => ['type' => 'integer', 'enum' => [0, 1]],
				'source'            => ['type' => 'string'],
				'list_ids'          => ['type' => 'array', 'items' => ['type' => 'integer']],
				'send_confirmation' => ['type' => 'boolean', 'description' => 'Mail the double opt-in confirmation (only when unconfirmed and require_confirmation is on). Default false.'],
				'send_welcome'      => ['type' => 'boolean', 'description' => 'Mail each list\'s welcome email. Default false.'],
				'fire_triggers'     => ['type' => 'boolean', 'description' => 'Fire onAcymAfterUserCreate / onAcymAfterUserSubscribe for add-ons, automations and follow-ups. Default false.'],
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

		$email = strtolower(trim((string) ($arguments['email'] ?? '')));
		if ($email === '' || !acym_isValidEmail($email)) {
			return ToolResult::error('A valid email is required.');
		}
		$existing = $this->acyFindSubscriber(null, $email);
		if ($existing) {
			return ToolResult::error('Subscriber ' . $email . ' already exists (id ' . $existing['id'] . '). Use update_acym_subscriber / subscribe_acym_subscribers.');
		}

		$lists = $this->acyResolveLists((array) ($arguments['list_ids'] ?? []));
		if ($lists['missing'] !== []) {
			return ToolResult::error('Unknown list id(s): ' . implode(', ', $lists['missing']) . '.');
		}

		$user        = new \stdClass();
		$user->email = $email;
		foreach (['name', 'source'] as $k) {
			if (isset($arguments[$k])) {
				$user->$k = (string) $arguments[$k];
			}
		}
		if (empty($user->source)) {
			$user->source = 'MCP';
		}
		$user->language = !empty($arguments['language']) ? (string) $arguments['language'] : $this->acyDefaultLanguage();
		$user->active   = isset($arguments['active']) ? ((int) $arguments['active'] ? 1 : 0) : 1;
		if (isset($arguments['tracking'])) {
			$user->tracking = (int) $arguments['tracking'] ? 1 : 0;
		}
		$confirmed = !empty($arguments['confirmed']);
		if ($confirmed) {
			$user->confirmed         = 1;
			$user->confirmation_date = gmdate('Y-m-d H:i:s');
		}

		$opts  = [
			'send_confirmation' => (bool) ($arguments['send_confirmation'] ?? false),
			'send_welcome'      => (bool) ($arguments['send_welcome'] ?? false),
			'fire_triggers'     => (bool) ($arguments['fire_triggers'] ?? false),
		];
		$class = $this->acyUserClass($opts);

		$before = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->acyQn($this->acyTable('user')))->loadResult();
		$id     = $this->withSiteRoot(fn () => $class->save($user));
		if (empty($id)) {
			return ToolResult::error('UserClass::save() failed: ' . implode('; ', array_merge($class->errors, $this->acyDrainMessages())));
		}

		$subscribed = false;
		if ($lists['ids'] !== []) {
			$subscribed = $this->withSiteRoot(fn () => $class->subscribe([(int) $id], $lists['ids'], $opts['fire_triggers']));
		}

		$after = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->acyQn($this->acyTable('user')))->loadResult();
		$row   = $this->acyFindSubscriber((int) $id, null);

		return ToolResult::json([
			'ok'                  => true,
			'id'                  => (int) $id,
			'rows_added'          => $after - $before,
			'subscriber'          => $row ? $this->acyPublicSubscriber($row) : null,
			'subscriptions'       => $this->acySubscriptions((int) $id),
			'subscribed_any'      => (bool) $subscribed,
			'mail_side_effects'   => [
				'confirmation_email' => $opts['send_confirmation'] && !$confirmed && $this->acyRequiresConfirmation()
					? ($class->confirmationSentSuccess ? 'sent' : 'attempted, not sent: ' . ($class->confirmationSentError ?? ''))
					: 'not sent',
				'welcome_emails'     => $opts['send_welcome'] ? 'requested' : 'not sent',
				'triggers_fired'     => $opts['fire_triggers'],
			],
			'will_receive_campaigns' => $user->active === 1 && ($confirmed || !$this->acyRequiresConfirmation()),
			'vendor_messages'     => $this->acyDrainMessages(),
		]);
	}
}
