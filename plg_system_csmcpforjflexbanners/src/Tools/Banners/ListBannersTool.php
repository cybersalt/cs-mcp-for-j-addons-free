<?php
/**
 * @package     MCP for FlexBanners
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Banners;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

final class ListBannersTool extends AbstractTool
{
	use FlexbannersBootTrait;

	public function getName(): string { return 'list_flexbanners'; }

	public function getDescription(): string
	{
		return 'List FlexBanners banners with their location, size, category, client, click-through URL, '
			. 'impressions and clicks, and a one-line display verdict per banner (does mod_flexbanners show it, '
			. 'and if not the first reason why). Filters: location_id, state (published/unpublished/archived/'
			. 'trashed/all; default published+unpublished, as in the admin list), client_id, catid, finished, '
			. 'search (name), not_displaying (only banners the module will not show). Ordered by id, which is '
			. 'also the order mod_flexbanners shows them in unless its ordering is random.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'location_id'    => ['type' => 'integer'],
				'state'          => ['type' => 'string', 'enum' => ['published', 'unpublished', 'archived', 'trashed', 'all']],
				'client_id'      => ['type' => 'integer'],
				'catid'          => ['type' => 'integer'],
				'finished'       => ['type' => 'boolean'],
				'search'         => ['type' => 'string'],
				'not_displaying' => ['type' => 'boolean', 'description' => 'Only banners that will not appear for an anonymous visitor.'],
				'limit'          => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'         => ['type' => 'integer'],
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
		$where = [];

		$state = (string) ($arguments['state'] ?? '');

		if ($state === '') {
			$where[] = 'a.state IN (0, 1)';
		} elseif ($state !== 'all') {
			$where[] = 'a.state = ' . $this->fbParseState($state);
		}

		foreach (['location_id' => 'locationid', 'client_id' => 'clientid', 'catid' => 'catid'] as $arg => $col) {
			if (\array_key_exists($arg, $arguments)) {
				$where[] = 'a.' . $col . ' = ' . (int) $arguments[$arg];
			}
		}

		if (\array_key_exists('finished', $arguments)) {
			$where[] = 'a.finished = ' . (!empty($arguments['finished']) ? 1 : 0);
		}

		if (trim((string) ($arguments['search'] ?? '')) !== '') {
			$where[] = 'a.name LIKE ' . $this->fbLike(trim((string) $arguments['search']));
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		$rows = $db->setQuery(
			'SELECT a.* FROM ' . $db->quoteName($this->fbTable('')) . ' AS a' . $whereSql . ' ORDER BY a.id'
		)->loadAssocList() ?: [];

		$sizes      = $this->fbLookup('size', 'sizeid');
		$links      = $this->fbLookup('link', 'linkid');
		$locations  = $this->fbLookup('locations', 'locationid');
		$clients    = $this->fbLookup('client', 'clientid');
		$categories = $this->fbCategories(array_column($rows, 'catid'));
		$modules    = $this->fbModules();
		$enforces   = $this->fbModuleEnforcesLimits();

		$items = [];

		foreach ($rows as $row) {
			$audit = $this->fbDisplayAudit($row, $sizes, $categories, $links, $modules, $enforces);

			if (!empty($arguments['not_displaying']) && $audit['problems'] === []) {
				continue;
			}

			$loc    = (int) $row['locationid'];
			$size   = (int) $row['sizeid'];
			$link   = (int) $row['linkid'];
			$client = (int) $row['clientid'];
			$type   = (int) $row['type'];

			$items[] = [
				'id'          => (int) $row['id'],
				'name'        => (string) $row['name'],
				'state'       => $this->fbStateLabel((int) $row['state']),
				'finished'    => (int) $row['finished'] === 1,
				'type'        => self::FB_TYPES[$type] ?? (string) $type,
				'location'    => $loc . (isset($locations[$loc]) ? ' ' . $locations[$loc]['name'] : ' (missing)'),
				'size'        => isset($sizes[$size]) ? $size . ' ' . $sizes[$size]['width'] . 'x' . $sizes[$size]['height'] : $size . ' (missing)',
				'catid'       => (int) $row['catid'],
				'client'      => $client . (isset($clients[$client]) ? ' ' . $clients[$client]['name'] : ''),
				'image'       => $type === 3 ? $row['cloud_imageurl'] : $row['imageurl'],
				'link_url'    => isset($links[$link]) ? (string) $links[$link]['linkurl'] : null,
				'language'    => (string) $row['language'],
				'impressions' => (int) $row['impmade'],
				'clicks'      => (int) $row['clicks'],
				'displays'    => $audit['problems'] === [],
				'why_not'     => $audit['problems'][0] ?? null,
				'shown_by_modules' => $audit['modules'],
			];
		}

		$total  = \count($items);
		$limit  = $this->fbLimit($arguments);
		$offset = $this->fbOffset($arguments);

		return ToolResult::json([
			'ok'      => true,
			'total'   => $total,
			'limit'   => $limit,
			'offset'  => $offset,
			'banners' => \array_slice($items, $offset, $limit),
		]);
	}
}
