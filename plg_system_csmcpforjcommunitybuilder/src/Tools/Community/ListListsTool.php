<?php
/**
 * @package     MCP for Community Builder
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Community;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

final class ListListsTool extends AbstractTool
{
	use CommunitybuilderBootTrait;

	public function getName(): string { return 'list_cb_lists'; }

	public function getDescription(): string
	{
		return 'List Community Builder user lists (List Management — the public member directories): title, '
			. 'published, whether it is the default list, who may view it (Joomla view access level), which user '
			. 'groups it lists, and its decoded params (columns, filters, sorting). Read-only.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cbInstalled()) {
			return $this->cbNotInstalledError();
		}

		$this->cbRequireAccess($actor, 'core.manage', 'com_comprofiler.lists');

		$translate = true;

		try {
			$this->cbRun($actor, static fn () => null);
		} catch (\Throwable $e) {
			$translate = false;
		}

		$rows   = $this->db->setQuery('SELECT * FROM ' . $this->db->quoteName('#__comprofiler_lists') . ' ORDER BY ordering')->loadAssocList() ?: [];
		$levels = $this->db->setQuery('SELECT id, title FROM ' . $this->db->quoteName('#__viewlevels'))->loadAssocList('id') ?: [];

		$out = array_map(function (array $l) use ($translate, $levels): array {
			$params = json_decode((string) ($l['params'] ?? ''), true);
			$groups = trim((string) $l['usergroupids']);

			return [
				'listid'          => (int) $l['listid'],
				'title'           => $translate ? $this->cbText((string) $l['title']) : $l['title'],
				'description'     => $l['description'],
				'published'       => (int) $l['published'] === 1,
				'default'         => (int) $l['default'] === 1,
				'viewaccesslevel' => ['id' => (int) $l['viewaccesslevel'], 'title' => $levels[(int) $l['viewaccesslevel']]['title'] ?? null],
				'usergroupids'    => $groups === '' ? [] : array_map('intval', preg_split('/\|\*\||,/', $groups)),
				'params'          => \is_array($params) ? $params : new \stdClass(),
			];
		}, $rows);

		return ToolResult::json(['ok' => true, 'count' => \count($out), 'lists' => $out]);
	}
}
