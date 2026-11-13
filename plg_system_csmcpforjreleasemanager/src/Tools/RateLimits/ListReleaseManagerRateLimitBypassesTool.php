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
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\User\User;

/**
 * The rate limit bypasses in force, and the limits they lift.
 */
final class ListReleaseManagerRateLimitBypassesTool extends AbstractTool
{
	use ReleaseManagerTrait;

	public function getName(): string { return 'list_release_manager_rate_limit_bypasses'; }

	public function getDescription(): string
	{
		return 'List the accounts whose hourly site-linking limits are lifted in Release Manager, with '
			. 'when each bypass ends (UTC), plus the normal limits (new sites per email per hour, '
			. 'requests per IP per hour). Ended bypasses are removed and logged first. Optional email filter.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'email' => ['type' => 'string', 'description' => 'Only this account.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($refusal = $this->requireReleaseManager($actor)) {
			return $refusal;
		}

		if ($refusal = $this->requireBypassSupport()) {
			return $refusal;
		}

		$helper = '\\Cybersalt\\Component\\CsReleaseManager\\Administrator\\Helper\\RateLimitHelper';
		$helper::expireBypasses();

		$q = $this->db->getQuery(true)
			->select($this->db->quoteName(['b.id', 'b.email', 'b.expires', 'b.note', 'b.created', 'b.created_by']))
			->select($this->db->quoteName('u.name', 'created_by_name'))
			->from($this->db->quoteName('#__csrm_rate_limit_bypasses', 'b'))
			->join('LEFT', $this->db->quoteName('#__users', 'u') . ' ON ' . $this->db->quoteName('u.id') . ' = ' . $this->db->quoteName('b.created_by'))
			->order($this->db->quoteName('b.expires') . ' ASC');

		$email = strtolower(trim((string) ($arguments['email'] ?? '')));

		if ($email !== '') {
			$q->where($this->db->quoteName('b.email_hash') . ' = ' . $this->db->quote(hash('sha256', $email)));
		}

		$rows   = $this->db->setQuery($q)->loadAssocList() ?: [];
		$params = ComponentHelper::getParams('com_csreleasemanager');

		return ToolResult::json([
			'limits' => [
				'new_sites_per_email_per_hour' => (int) $params->get('rate_limit_email_per_hour', 30),
				'requests_per_ip_per_hour'     => (int) $params->get('rate_limit_ip_per_hour', 60),
			],
			'count'    => \count($rows),
			'bypasses' => array_map(static fn (array $r): array => [
				'id'              => (int) $r['id'],
				'email'           => (string) $r['email'],
				'expires_utc'     => (string) $r['expires'],
				'note'            => (string) $r['note'],
				'set_utc'         => (string) $r['created'],
				'set_by'          => (string) ($r['created_by_name'] ?? ''),
			], $rows),
		]);
	}
}
