<?php
/**
 * @package     MCP for Cybersalt Release Manager
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\RateLimits;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\ReleaseManagerTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Switch Release Manager's hourly link limits off for one account until a set
 * time, or end that early (cs-release-manager#5). For an agency rolling an
 * extension out to many client sites at once.
 *
 * Goes through Release Manager's own RateLimitHelper, so the change is logged
 * in its activity log exactly as one made on its Rate Limit Bypasses screen.
 */
final class SetReleaseManagerRateLimitBypassTool extends AbstractTool
{
	use ReleaseManagerTrait;

	public function getName(): string { return 'set_release_manager_rate_limit_bypass'; }

	public function getDescription(): string
	{
		return 'Lift Release Manager\'s hourly site-linking limits for one account until a set time, '
			. 'or end a bypass now with clear:true. Normally one email can link a limited number of new '
			. 'sites per hour (Options > Rate Limiting); under a bypass it can link any number, and the '
			. 'per-server limit is ten times the normal one. Name the account by email or user_id (a Joomla '
			. 'user on this site; needs access to Users). Changing an existing bypass needs Edit. Give either hours '
			. '(from now) or until (a date-time, UTC unless it carries an offset, e.g. "2026-11-20 18:00" '
			. 'or "2026-11-20T18:00:00-07:00"). Setting it again for the same email moves the end time. '
			. 'Bypasses end on their own. Needs Release Manager 1.18.0 or later.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'email'   => ['type' => 'string', 'description' => 'The account email the sites link with.'],
				'user_id' => ['type' => 'integer', 'description' => 'A Joomla user on this site; their email is used.'],
				'hours'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8784, 'description' => 'Bypass length from now, in hours (24 = one day, 168 = one week).'],
				'until'   => ['type' => 'string', 'description' => 'End date-time instead of hours. UTC unless an offset is given.'],
				'note'    => ['type' => 'string', 'description' => 'Optional reason, shown on the admin screen.'],
				'clear'   => ['type' => 'boolean', 'description' => 'true ends this account\'s bypass now.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$clear = !empty($arguments['clear']);

		if ($refusal = $this->requireReleaseManager($actor, $clear ? 'core.delete' : 'core.create')) {
			return $refusal;
		}

		if ($refusal = $this->requireBypassSupport()) {
			return $refusal;
		}

		$email = trim((string) ($arguments['email'] ?? ''));

		if ($email === '' && !empty($arguments['user_id'])) {
			// Looking a user up by id reveals their address: only for accounts that can see users.
			if (!$actor->authorise('core.manage', 'com_users')) {
				return ToolResult::error('Looking an account up by user_id needs access to Users. Give the email instead.');
			}

			$email = (string) $this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName('email'))
					->from($this->db->quoteName('#__users'))
					->where($this->db->quoteName('id') . ' = ' . (int) $arguments['user_id'])
			)->loadResult();

			if ($email === '') {
				return ToolResult::error('No Joomla user with id ' . (int) $arguments['user_id'] . '.');
			}
		}

		if ($email === '') {
			return ToolResult::error('Give email or user_id.');
		}

		$helper  = '\\Cybersalt\\Component\\CsReleaseManager\\Administrator\\Helper\\RateLimitHelper';
		$hash    = hash('sha256', strtolower($email));
		$current = $helper::activeBypass($hash);

		if ($clear) {
			if ($current === null) {
				return ToolResult::json(['ok' => true, 'email' => strtolower($email), 'bypass' => null, 'note' => 'No bypass was in force for this email.']);
			}

			$helper::clearBypass((int) $current->id, (int) $actor->id, 'via MCP');

			return ToolResult::json(['ok' => true, 'email' => strtolower($email), 'bypass' => null, 'ended' => true]);
		}

		// A row for this address, even an expired one, gets changed rather than added.
		$existing = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('id'))
				->from($this->db->quoteName('#__csrm_rate_limit_bypasses'))
				->where($this->db->quoteName('email_hash') . ' = ' . $this->db->quote($hash))
		)->loadResult();

		if ($existing && ($refusal = $this->requireReleaseManager($actor, 'core.edit'))) {
			return $refusal;
		}

		$hasHours = isset($arguments['hours']);
		$hasUntil = trim((string) ($arguments['until'] ?? '')) !== '';

		if ($hasHours === $hasUntil) {
			return ToolResult::error('Give exactly one of hours or until (or clear:true).');
		}

		if ($hasHours) {
			$hours = (int) $arguments['hours'];

			if ($hours < 1 || $hours > 8784) {
				return ToolResult::error('hours must be between 1 and 8784 (one year).');
			}

			$expires = 'now +' . $hours . ' hours';
		} else {
			try {
				$expires = Factory::getDate(trim((string) $arguments['until']))->toSql();
			} catch (\Exception $e) {
				return ToolResult::error('until is not a date-time I can read: "' . (string) $arguments['until'] . '".');
			}
		}

		try {
			$id = $helper::setBypass($email, $expires, (string) ($arguments['note'] ?? ''), (int) $actor->id);
		} catch (\InvalidArgumentException $e) {
			return ToolResult::error($e->getMessage());
		}

		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName('#__csrm_rate_limit_bypasses'))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();

		return ToolResult::json([
			'ok'          => true,
			'replaced'    => $current !== null,
			'email'       => (string) $row['email'],
			'expires_utc' => (string) $row['expires'],
			'note'        => (string) $row['note'],
			'id'          => (int) $row['id'],
		]);
	}
}
