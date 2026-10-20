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

/**
 * Publish or unpublish a CB plugin through `PluginTable` (`check()` refuses to
 * unpublish language and template plugins and CB Core, id 1 —
 * `libraries/CBLib/CB/Database/Table/PluginTable.php:246-266`).
 */
final class SetPluginStateTool extends AbstractTool
{
	use CommunitybuilderBootTrait;

	public function getName(): string { return 'set_cb_plugin_state'; }

	public function getDescription(): string
	{
		return 'Publish or unpublish one of Community Builder\'s own plugins (#__comprofiler_plugin), through CB\'s '
			. 'PluginTable so its rules apply: CB refuses to unpublish CB Core (id 1), language plugins and '
			. 'template plugins. Unpublishing a plugin removes its tabs, field types and features from profiles '
			. 'at once — e.g. CB Connections. Re-read afterwards.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'plugin_id' => ['type' => 'integer'],
				'published' => ['type' => 'boolean'],
			],
			'required'             => ['plugin_id', 'published'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cbInstalled()) {
			return $this->cbNotInstalledError();
		}

		$this->cbRequireAccess($actor, 'core.manage', 'com_comprofiler.plugins');
		$this->cbRequireAccess($actor, 'core.edit.state', 'com_comprofiler.plugins');

		$id    = $this->requirePositiveInt($arguments, 'plugin_id');
		$state = $this->cbBool($arguments, 'published') ? 1 : 0;

		$row = $this->db->setQuery(
			'SELECT id, name, element, type, published FROM ' . $this->db->quoteName('#__comprofiler_plugin') . ' WHERE id = ' . $id
		)->loadAssoc();

		if (!$row) {
			throw new \InvalidArgumentException('No CB plugin with id ' . $id . ' (list_cb_plugins).');
		}

		if ((int) $row['published'] === $state) {
			return ToolResult::json(['ok' => true, 'changed' => false, 'note' => 'Already ' . ($state ? 'published' : 'unpublished') . '.']);
		}

		$run = $this->cbRun($actor, static function () use ($id, $state): array {
			$p = new \CB\Database\Table\PluginTable();

			if (!$p->load($id)) {
				return ['ok' => false, 'error' => 'CB could not load plugin ' . $id . '.'];
			}

			$p->published = $state;

			if (!$p->check()) {
				return ['ok' => false, 'error' => (string) $p->getError()];
			}

			if (!$p->store()) {
				return ['ok' => false, 'error' => (string) $p->getError()];
			}

			return ['ok' => true, 'error' => null];
		});

		$after = (int) $this->db->setQuery('SELECT published FROM ' . $this->db->quoteName('#__comprofiler_plugin') . ' WHERE id = ' . $id)->loadResult();
		$ok    = $run['result']['ok'] && $after === $state;

		return ToolResult::json([
			'ok'             => $ok,
			'plugin'         => ['id' => $id, 'name' => $row['name'], 'element' => $row['element'], 'type' => $row['type']],
			'published_after' => $after === 1,
			'error'          => $run['result']['error'],
			'cb_messages'    => $run['messages'],
			'plugin_errors'  => $run['plugin_errors'],
		] + $this->cbContextNote(), !$ok);
	}
}
