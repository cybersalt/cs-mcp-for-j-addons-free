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
 * Update a subscriber's own columns through UserClass::save().
 *
 * AcymClass::save() updates only the properties present on the object, and
 * FieldClass::store() with no fields is a no-op, so a partial save is safe
 * here (unlike lists). active 1->0 / 0->1 maintains deactivation_date and
 * confirmed 0->1 stamps confirmation_date, exactly as the vendor does.
 */
final class UpdateSubscriberTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'update_acym_subscriber'; }

	public function getDescription(): string
	{
		return 'Update an AcyMailing subscriber (by id or current email) through UserClass::save(). Fields: '
			. 'new_email, name, language, active (0 = disabled, receives nothing), confirmed (1 = double opt-in '
			. 'done), tracking. Only the given columns are written; the before/after diff is returned. Confirming '
			. 'here does NOT send the welcome emails that a subscriber clicking the confirmation link would get. '
			. 'fire_triggers (default false) fires onAcymBeforeUserModify / onAcymAfterUserModify / '
			. 'onAcymAfterUserConfirm for add-ons and automations. Changing the email of a subscriber linked to a '
			. 'Joomla account does not change the Joomla account. Confirming through MCP stamps confirmation_ip '
			. 'with the MCP client\'s IP (the vendor\'s own behaviour for an admin confirm), so that IP is not '
			. 'evidence of the subscriber\'s consent.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'id'            => ['type' => 'integer', 'minimum' => 1],
				'email'         => ['type' => 'string', 'description' => 'Current email, to look the subscriber up (alternative to id).'],
				'new_email'     => ['type' => 'string'],
				'name'          => ['type' => 'string'],
				'language'      => ['type' => 'string'],
				'active'        => ['type' => 'integer', 'enum' => [0, 1]],
				'confirmed'     => ['type' => 'integer', 'enum' => [0, 1]],
				'tracking'      => ['type' => 'integer', 'enum' => [0, 1]],
				'fire_triggers' => ['type' => 'boolean'],
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

		$before = $this->acyFindSubscriber(isset($arguments['id']) ? (int) $arguments['id'] : null, $arguments['email'] ?? null);
		if (!$before) {
			return ToolResult::error('No AcyMailing subscriber matches that id/email.');
		}
		$id = (int) $before['id'];

		$user     = new \stdClass();
		$user->id = $id;
		$changes  = [];

		if (isset($arguments['new_email'])) {
			$new = strtolower(trim((string) $arguments['new_email']));
			if (!acym_isValidEmail($new)) {
				return ToolResult::error('new_email is not a valid address.');
			}
			$clash = $this->acyFindSubscriber(null, $new);
			if ($clash && (int) $clash['id'] !== $id) {
				return ToolResult::error($new . ' already belongs to subscriber ' . $clash['id'] . ' (the email index is unique and case-insensitive).');
			}
			$user->email = $new;
			$changes[]   = 'email';
		}
		foreach (['name', 'language'] as $k) {
			if (array_key_exists($k, $arguments)) {
				$user->$k  = (string) $arguments[$k];
				$changes[] = $k;
			}
		}
		foreach (['active', 'confirmed', 'tracking'] as $k) {
			if (array_key_exists($k, $arguments)) {
				$user->$k  = (int) $arguments[$k] ? 1 : 0;
				$changes[] = $k;
			}
		}
		if ($changes === []) {
			return ToolResult::error('Nothing to update.');
		}

		$class = $this->acyUserClass(['fire_triggers' => (bool) ($arguments['fire_triggers'] ?? false)]);
		$count = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->acyQn($this->acyTable('user')))->loadResult();
		$saved = $this->withSiteRoot(fn () => $class->save($user));

		if ((int) $saved !== $id) {
			return ToolResult::error('UserClass::save() did not update subscriber ' . $id . ': ' . implode('; ', array_merge($class->errors, $this->acyDrainMessages())));
		}

		$after   = $this->acyFindSubscriber($id, null) ?? [];
		$countAf = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->acyQn($this->acyTable('user')))->loadResult();
		$diff    = [];
		foreach ($after as $k => $v) {
			if ($k !== 'key' && (string) $v !== (string) ($before[$k] ?? '')) {
				$diff[$k] = ['from' => $before[$k] ?? null, 'to' => $v];
			}
		}

		return ToolResult::json([
			'ok'               => true,
			'id'               => $id,
			'row_count_stable' => $count === $countAf,
			'changed'          => $diff,
			'subscriber'       => $this->acyPublicSubscriber($after),
			'vendor_messages'  => $this->acyDrainMessages(),
		]);
	}
}
