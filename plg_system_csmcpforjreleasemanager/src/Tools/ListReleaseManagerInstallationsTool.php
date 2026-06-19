<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class ListReleaseManagerInstallationsTool extends AbstractTool
{
	use ReleaseManagerTrait;

	public function getName(): string { return 'list_release_manager_installations'; }

	public function getDescription(): string
	{
		return 'List recorded extension installations on customer sites. Optional filters: '
			. 'package_id, status (active/inactive/suspended/unlinked), domain (substring), '
			. 'email (substring), limit, offset. Returns installation_id, package_id, '
			. 'domain, ip_address, status, last_update_check, created. Email + email_hash are '
			. 'returned but may be redacted if the buyer revoked notify_owner — caller should '
			. 'treat email as PII.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'package_id' => ['type' => 'integer'],
				'status'     => ['type' => 'string', 'enum' => ['active', 'inactive', 'suspended', 'unlinked']],
				'domain'     => ['type' => 'string'],
				'email'      => ['type' => 'string'],
				'limit'      => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
				'offset'     => ['type' => 'integer', 'minimum' => 0],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($refusal = $this->requireReleaseManager()) {
			return $refusal;
		}

		$limit  = max(1, min(200, (int) ($arguments['limit'] ?? 50)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));

		$q = $this->db->getQuery(true)
			->select($this->db->quoteName([
				'id', 'installation_id', 'package_id', 'email', 'email_hash',
				'domain', 'ip_address', 'status', 'last_update_check',
				'update_check_count', 'failed_check_count', 'notify_owner',
				'created', 'modified',
			]))
			->from($this->db->quoteName('#__csrm_installations'));

		if (!empty($arguments['package_id'])) {
			$q->where($this->db->quoteName('package_id') . ' = ' . (int) $arguments['package_id']);
		}
		if (!empty($arguments['status'])) {
			$q->where($this->db->quoteName('status') . ' = ' . $this->db->quote((string) $arguments['status']));
		}
		if (!empty($arguments['domain'])) {
			$like = '%' . $this->db->escape((string) $arguments['domain'], true) . '%';
			$q->where($this->db->quoteName('domain') . ' LIKE ' . $this->db->quote($like));
		}
		if (!empty($arguments['email'])) {
			$like = '%' . $this->db->escape((string) $arguments['email'], true) . '%';
			$q->where($this->db->quoteName('email') . ' LIKE ' . $this->db->quote($like));
		}

		$q->order($this->db->quoteName('created') . ' DESC');

		$countQ = clone $q;
		$countQ->clear('select')->clear('order')->select('COUNT(*)');
		$total = (int) $this->db->setQuery($countQ)->loadResult();

		$rows = $this->db->setQuery($q, $offset, $limit)->loadAssocList() ?: [];

		return ToolResult::json([
			'total'         => $total,
			'count'         => count($rows),
			'limit'         => $limit,
			'offset'        => $offset,
			'installations' => $rows,
		]);
	}
}
