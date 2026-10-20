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

final class UpdateTabTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use TabSupportTrait;

	public function getName(): string { return 'update_cb_tab'; }

	public function getDescription(): string
	{
		return 'Change a Community Builder tab: title, description, enabled, position, displaytype, '
			. 'viewaccesslevel, cssclass. Only the named properties change (the tab is loaded whole through CB\'s '
			. 'TabTable and stored through it). Works on system and plugin tabs too — disabling a plugin tab hides '
			. 'what that plugin renders on profiles. Re-read and verified afterwards. dry_run shows the change.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'tab_id'          => ['type' => 'integer'],
				'title'           => ['type' => 'string'],
				'description'     => ['type' => 'string'],
				'enabled'         => ['type' => 'boolean'],
				'position'        => ['type' => 'string'],
				'displaytype'     => ['type' => 'string'],
				'viewaccesslevel' => ['type' => 'integer'],
				'cssclass'        => ['type' => 'string'],
				'dry_run'         => ['type' => 'boolean'],
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
		$this->cbRequireAccess($actor, 'core.edit', 'com_comprofiler.tabs');

		$tabId = $this->requirePositiveInt($arguments, 'tab_id');
		$tab   = $this->cbFindTab($tabId);

		if ($tab === null) {
			throw new \InvalidArgumentException('No CB tab with id ' . $tabId . '.');
		}

		$props   = $this->cbTabProps($arguments);
		$changes = [];

		foreach ($props as $col => $v) {
			if ((string) $tab[$col] !== (string) $v) {
				$changes[$col] = ['before' => $tab[$col], 'after' => $v];
			}
		}

		if ($changes === []) {
			return ToolResult::json(['ok' => true, 'changed' => false, 'note' => 'Nothing differs from the stored tab.']);
		}

		if ($this->cbBool($arguments, 'dry_run')) {
			return ToolResult::json(['ok' => true, 'dry_run' => true, 'would_change' => $changes]);
		}

		$run = $this->cbRun($actor, static function () use ($tabId, $props): array {
			$t = new \CB\Database\Table\TabTable();

			if (!$t->load($tabId)) {
				return ['ok' => false, 'error' => 'CB could not load tab ' . $tabId . '.'];
			}

			foreach ($props as $col => $v) {
				$t->$col = $v;
			}

			if (!$t->check()) {
				return ['ok' => false, 'error' => (string) $t->getError()];
			}

			if (!$t->store()) {
				return ['ok' => false, 'error' => (string) $t->getError()];
			}

			return ['ok' => true, 'error' => null];
		});

		$after    = $this->cbFindTab($tabId) ?? [];
		$verified = [];

		foreach ($props as $col => $v) {
			$verified[$col] = (string) ($after[$col] ?? '') === (string) $v;
		}

		$ok = $run['result']['ok'] && !\in_array(false, $verified, true);

		return ToolResult::json([
			'ok'                 => $ok,
			'tab_id'             => $tabId,
			'changes'            => $changes,
			'verified_by_reread' => $verified,
			'error'              => $run['result']['error'],
			'cb_messages'        => $run['messages'],
			'plugin_errors'      => $run['plugin_errors'],
		] + $this->cbContextNote(), !$ok);
	}
}
