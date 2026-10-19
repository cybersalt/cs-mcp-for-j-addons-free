<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Clients;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

final class ListClientsTool extends AbstractTool
{
	use FlexbannersBootTrait;
	use ClientSupportTrait;

	public function getName(): string { return 'list_flexbanners_clients'; }

	public function getDescription(): string
	{
		return 'List FlexBanners clients (advertisers) with contact details, the linked Joomla user who can see '
			. 'their statistics on the front end, and how many banners each has.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => ['search' => ['type' => 'string']],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->fbInstalled()) {
			return $this->fbNotInstalledError();
		}

		$this->fbRequireAccess($actor);

		$db    = $this->db;
		$where = trim((string) ($arguments['search'] ?? '')) !== ''
			? ' WHERE name LIKE ' . $this->fbLike(trim((string) $arguments['search']))
			: '';

		$rows   = $db->setQuery('SELECT * FROM ' . $db->quoteName($this->fbTable('client')) . $where . ' ORDER BY clientid')->loadAssocList() ?: [];
		$counts = $db->setQuery('SELECT clientid, COUNT(*) AS n FROM ' . $db->quoteName($this->fbTable('')) . ' GROUP BY clientid')->loadAssocList('clientid') ?: [];

		$out = [];

		foreach ($rows as $r) {
			$out[] = $this->clientShape($r, (int) ($counts[$r['clientid']]['n'] ?? 0));
		}

		return ToolResult::json(['ok' => true, 'count' => \count($out), 'clients' => $out]);
	}
}
