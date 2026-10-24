<?php
/**
 * @package     MCP for jDownloads
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Lookups;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\JdownloadsBootTrait;
use Joomla\CMS\Access\Access;
use Joomla\CMS\User\User;

/**
 * Joomla ACL as jDownloads uses it: actions from its access.xml on the
 * component, a category or a download asset. Read-only.
 */
final class ListPermissionsTool extends AbstractTool
{
	use JdownloadsBootTrait;

	private const ACTIONS = [
		'component' => ['core.admin', 'core.options', 'core.manage', 'core.create', 'core.delete', 'core.edit', 'core.edit.state', 'core.edit.own', 'download', 'core.edit.value', 'edit.user.limits'],
		'category'  => ['core.create', 'core.delete', 'core.edit', 'core.edit.state', 'core.edit.own', 'download'],
		'download'  => ['core.delete', 'core.edit', 'core.edit.state', 'core.edit.own', 'download'],
	];

	public function getName(): string { return 'list_jdownloads_permissions'; }

	public function getDescription(): string
	{
		return 'Read-only. The jDownloads permissions on the component (default), one category (category_id) or one '
			. 'download (download_id): the explicit rules stored on that asset, and for every Joomla user group whether '
			. 'each jDownloads action (download, create, edit, edit state, edit own, delete, and at component level '
			. 'admin/options/manage) ends up allowed, denied or not set once inheritance from parent categories and the '
			. 'component is applied.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'category_id' => ['type' => 'integer'],
				'download_id' => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->jdInstalled()) {
			return $this->jdNotInstalledError();
		}

		$this->jdRequireAccess($actor);

		$catId = (int) ($arguments['category_id'] ?? 0);
		$dlId  = (int) ($arguments['download_id'] ?? 0);

		if ($catId > 0 && $dlId > 0) {
			throw new \InvalidArgumentException('Pass category_id or download_id, not both.');
		}

		if ($dlId > 0) {
			if ($this->jdDownloadRow($dlId) === null) {
				throw new \InvalidArgumentException('No jDownloads download with id ' . $dlId . '.');
			}

			$asset   = 'com_jdownloads.download.' . $dlId;
			$section = 'download';
		} elseif ($catId > 0) {
			$row = $this->jdCategoryRow($catId);

			if ($row === null || (int) $row['parent_id'] === 0) {
				throw new \InvalidArgumentException('No jDownloads category with id ' . $catId . '.');
			}

			$asset   = 'com_jdownloads.category.' . $catId;
			$section = 'category';
		} else {
			$asset   = 'com_jdownloads';
			$section = 'component';
		}

		$explicit = $this->jdAssetRules($asset);
		$groups   = $this->db->setQuery('SELECT id, title FROM #__usergroups ORDER BY lft')->loadAssocList() ?: [];
		$matrix   = [];

		// When the item's asset row is missing, Joomla falls back to the component.
		$checkOn = $explicit === null ? 'com_jdownloads' : $asset;

		foreach ($groups as $g) {
			$entry = ['group_id' => (int) $g['id'], 'group_title' => (string) $g['title']];

			// A group with core.admin on the site root passes every check (User::authorise()
			// short-circuits on it), whatever the individual rules say.
			$superUser = Access::checkGroup((int) $g['id'], 'core.admin') === true;

			foreach (self::ACTIONS[$section] as $action) {
				$result = Access::checkGroup((int) $g['id'], $action, $checkOn);
				$entry[$action] = $superUser ? 'allowed (super user)' : ($result === true ? 'allowed' : ($result === false ? 'denied' : 'not set (denied)'));
			}

			$matrix[] = $entry;
		}

		$response = [
			'ok'             => true,
			'asset'          => $asset,
			'explicit_rules' => $explicit,
			'effective'      => $matrix,
		];

		if ($explicit === null) {
			$response['warning'] = 'This item has no asset row; Joomla falls back to the component\'s permissions. Saving it in the jDownloads admin creates one.';
		}

		return ToolResult::json($response);
	}
}
