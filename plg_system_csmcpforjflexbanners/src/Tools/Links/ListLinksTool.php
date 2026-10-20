<?php
/**
 * @package     MCP for FlexBanners
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Links;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

final class ListLinksTool extends AbstractTool
{
	use FlexbannersBootTrait;

	public function getName(): string { return 'list_flexbanners_links'; }

	public function getDescription(): string
	{
		return 'List FlexBanners click-through links (#__flexbannerslink). A banner does not store its URL: it '
			. 'stores linkid, and a click on /component/flexbanners/click/<banner id> counts the click and redirects '
			. 'to the link\'s URL. One link can serve several banners. Shows which banners use each link, and links '
			. 'no banner uses.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'search' => ['type' => 'string', 'description' => 'Match name or URL.'],
				'unused' => ['type' => 'boolean', 'description' => 'Only links no banner uses.'],
				'limit'  => ['type' => 'integer'],
				'offset' => ['type' => 'integer'],
			],
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
		$where = '';

		if (trim((string) ($arguments['search'] ?? '')) !== '') {
			$like  = $this->fbLike(trim((string) $arguments['search']));
			$where = ' WHERE l.name LIKE ' . $like . ' OR l.linkurl LIKE ' . $like;
		}

		$rows = $db->setQuery('SELECT l.* FROM ' . $db->quoteName($this->fbTable('link')) . ' AS l' . $where . ' ORDER BY l.linkid')->loadAssocList() ?: [];

		$users = [];

		foreach ($db->setQuery('SELECT id, linkid FROM ' . $db->quoteName($this->fbTable('')))->loadAssocList() ?: [] as $b) {
			$users[(int) $b['linkid']][] = (int) $b['id'];
		}

		$out = [];

		foreach ($rows as $r) {
			$id = (int) $r['linkid'];

			if (!empty($arguments['unused']) && isset($users[$id])) {
				continue;
			}

			$out[] = [
				'id'         => $id,
				'name'       => (string) $r['name'],
				'url'        => (string) $r['linkurl'],
				'client_id'  => (int) $r['clientid'],
				'state'      => $this->fbStateLabel((int) $r['state']),
				'banner_ids' => $users[$id] ?? [],
			];
		}

		$limit  = $this->fbLimit($arguments);
		$offset = $this->fbOffset($arguments);

		return ToolResult::json(['ok' => true, 'total' => \count($out), 'links' => \array_slice($out, $offset, $limit)]);
	}
}
