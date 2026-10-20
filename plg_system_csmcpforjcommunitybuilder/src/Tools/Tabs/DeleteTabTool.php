<?php
/**
 * @package     MCP for Community Builder
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Tabs;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

/**
 * Delete a tab through `TabTable::canDelete()` + `delete()`
 * (`libraries/CBLib/CB/Database/Table/TabTable.php`): CB refuses system tabs,
 * tabs owned by an installed plugin, and tabs that still hold fields.
 */
final class DeleteTabTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use TabSupportTrait;

	public function getName(): string { return 'delete_cb_tab'; }

	public function getDescription(): string
	{
		return 'Delete an empty custom Community Builder tab through CB\'s own TabTable::canDelete() / delete(). CB '
			. 'refuses system tabs, plugin tabs and any tab that still holds fields (move them with '
			. 'update_cb_field tab_id first). Requires confirm: true.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'tab_id'  => ['type' => 'integer'],
				'confirm' => ['type' => 'boolean'],
			],
			'required'             => ['tab_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cbInstalled()) {
			return $this->cbNotInstalledError();
		}

		$this->cbRequireAccess($actor, 'core.manage', 'com_comprofiler.tabs');
		$this->cbRequireAccess($actor, 'core.delete', 'com_comprofiler.tabs');

		$tabId = $this->requirePositiveInt($arguments, 'tab_id');
		$tab   = $this->cbFindTab($tabId);

		if ($tab === null) {
			throw new \InvalidArgumentException('No CB tab with id ' . $tabId . '.');
		}

		$fieldCount = (int) ($this->cbFieldCountsByTab()[$tabId] ?? 0);

		if (!$this->cbBool($arguments, 'confirm')) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Not deleted: pass confirm: true.',
				'tab'   => ['tabid' => $tabId, 'title' => $tab['title'], 'system' => (int) $tab['sys'] === 1, 'plugin_tab' => (string) $tab['pluginclass'] !== '', 'fields_on_it' => $fieldCount],
			], true);
		}

		$run = $this->cbRun($actor, static function () use ($tabId): array {
			$t = new \CB\Database\Table\TabTable();

			if (!$t->load($tabId)) {
				return ['ok' => false, 'error' => 'CB could not load tab ' . $tabId . '.'];
			}

			if (!$t->canDelete()) {
				return ['ok' => false, 'error' => (string) $t->getError()];
			}

			if (!$t->delete()) {
				return ['ok' => false, 'error' => (string) $t->getError()];
			}

			return ['ok' => true, 'error' => null];
		});

		$gone = $this->cbFindTab($tabId) === null;
		$ok   = $run['result']['ok'] && $gone;

		return ToolResult::json([
			'ok'            => $ok,
			'tab_id'        => $tabId,
			'deleted'       => $gone,
			'error'         => $run['result']['error'],
			'cb_messages'   => $run['messages'],
			'plugin_errors' => $run['plugin_errors'],
		] + $this->cbContextNote(), !$ok);
	}
}
