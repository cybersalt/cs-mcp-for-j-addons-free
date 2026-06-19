<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class ListReleaseManagerActivityLogTool extends AbstractTool
{
	use ReleaseManagerTrait;

	public function getName(): string { return 'list_release_manager_activity_log'; }

	public function getDescription(): string
	{
		return 'List cs-release-manager activity-log entries. Optional filters: event_type '
			. '(e.g. "download", "update_check", "blacklist_hit"), result (success/denied/error), '
			. 'package_id, ip_address, installation_id, limit, offset. Newest-first.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'event_type'       => ['type' => 'string'],
				'result'           => ['type' => 'string', 'enum' => ['success', 'denied', 'error']],
				'package_id'       => ['type' => 'integer'],
				'ip_address'       => ['type' => 'string'],
				'installation_id'  => ['type' => 'string'],
				'limit'            => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
				'offset'           => ['type' => 'integer', 'minimum' => 0],
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
				'id', 'event_type', 'installation_id', 'email_hash', 'domain',
				'ip_address', 'package_id', 'details', 'result', 'created',
			]))
			->from($this->db->quoteName('#__csrm_activity_log'));

		if (!empty($arguments['event_type'])) {
			$q->where($this->db->quoteName('event_type') . ' = ' . $this->db->quote((string) $arguments['event_type']));
		}
		if (!empty($arguments['result'])) {
			$q->where($this->db->quoteName('result') . ' = ' . $this->db->quote((string) $arguments['result']));
		}
		if (!empty($arguments['package_id'])) {
			$q->where($this->db->quoteName('package_id') . ' = ' . (int) $arguments['package_id']);
		}
		if (!empty($arguments['ip_address'])) {
			$q->where($this->db->quoteName('ip_address') . ' = ' . $this->db->quote((string) $arguments['ip_address']));
		}
		if (!empty($arguments['installation_id'])) {
			$q->where($this->db->quoteName('installation_id') . ' = ' . $this->db->quote((string) $arguments['installation_id']));
		}

		$q->order($this->db->quoteName('created') . ' DESC');

		$countQ = clone $q;
		$countQ->clear('select')->clear('order')->select('COUNT(*)');
		$total = (int) $this->db->setQuery($countQ)->loadResult();

		$rows = $this->db->setQuery($q, $offset, $limit)->loadAssocList() ?: [];

		// Decode details JSON for convenience
		foreach ($rows as &$r) {
			if (!empty($r['details'])) {
				$decoded = json_decode((string) $r['details'], true);
				$r['details__parsed'] = is_array($decoded) ? $decoded : null;
			} else {
				$r['details__parsed'] = null;
			}
		}
		unset($r);

		return ToolResult::json([
			'total'   => $total,
			'count'   => count($rows),
			'limit'   => $limit,
			'offset'  => $offset,
			'entries' => $rows,
		]);
	}
}
