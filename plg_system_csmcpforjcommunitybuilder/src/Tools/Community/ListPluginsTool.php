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

final class ListPluginsTool extends AbstractTool
{
	use CommunitybuilderBootTrait;

	public function getName(): string { return 'list_cb_plugins'; }

	public function getDescription(): string
	{
		return 'List Community Builder\'s own plugins (Plugin Management, #__comprofiler_plugin — not Joomla '
			. 'plugins): name, element, type (user, templates, language...), published, core or not, access level, '
			. 'version, and whether its folder exists on disk. Plugin settings are NOT returned (they are where '
			. 'third-party API keys live); row 1 "CB Core" holds the CB configuration, read with get_cb_config. '
			. 'Read-only; set_cb_plugin_state publishes or unpublishes.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'type'      => ['type' => 'string', 'description' => 'e.g. user, templates, language.'],
				'published' => ['type' => 'boolean'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cbInstalled()) {
			return $this->cbNotInstalledError();
		}

		$this->cbRequireAccess($actor, 'core.manage', 'com_comprofiler.plugins');

		$rows = $this->db->setQuery(
			'SELECT id, name, element, type, folder, viewaccesslevel, ordering, published, iscore, client_id, manifest_cache FROM '
			. $this->db->quoteName('#__comprofiler_plugin') . ' ORDER BY type, ordering'
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $p) {
			if (isset($arguments['type']) && (string) $p['type'] !== (string) $arguments['type']) {
				continue;
			}

			if (\array_key_exists('published', $arguments) && ((int) $p['published'] === 1) !== $this->cbBool($arguments, 'published')) {
				continue;
			}

			$manifest = json_decode((string) ($p['manifest_cache'] ?? ''), true);

			$out[] = [
				'id'              => (int) $p['id'],
				'name'            => $p['name'],
				'element'         => $p['element'],
				'type'            => $p['type'],
				'folder'          => $p['folder'],
				'published'       => (int) $p['published'] === 1,
				'core'            => (int) $p['iscore'] === 1,
				'viewaccesslevel' => (int) $p['viewaccesslevel'],
				'version'         => \is_array($manifest) ? ($manifest['version'] ?? null) : null,
				'files_present'   => (string) $p['folder'] === '' ? null : is_dir(JPATH_SITE . '/components/com_comprofiler/plugin/' . $p['type'] . '/' . $p['folder']),
			];
		}

		return ToolResult::json(['ok' => true, 'count' => \count($out), 'plugins' => $out]);
	}
}
