<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Tabs;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

/**
 * Create a field-holding tab through CB's `TabTable` (`check()` + `store()`;
 * `OrderedTable` assigns the three orderings). Plugin tabs are created by CB
 * plugins themselves and are not made here.
 */
final class CreateTabTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use TabSupportTrait;

	public function getName(): string { return 'create_cb_tab'; }

	public function getDescription(): string
	{
		return 'Create a Community Builder profile tab to hold fields, through CB\'s own TabTable (CB assigns the '
			. 'profile, edit and registration orderings). Give title and optionally description, enabled (default '
			. 'true), position (CB\'s default cb_tabmain; on the Canvas layout CB installs, canvas_main_middle is '
			. 'where the main tabs sit — get_cb_tab lists all positions), displaytype (default tab; the Canvas '
			. 'tabs use menu), viewaccesslevel (default 1, Public) and cssclass. Re-read afterwards.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'title'           => ['type' => 'string'],
				'description'     => ['type' => 'string'],
				'enabled'         => ['type' => 'boolean'],
				'position'        => ['type' => 'string'],
				'displaytype'     => ['type' => 'string'],
				'viewaccesslevel' => ['type' => 'integer'],
				'cssclass'        => ['type' => 'string'],
			],
			'required'             => ['title'],
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
		$this->cbRequireAccess($actor, 'core.create', 'com_comprofiler.tabs');

		$this->requireString($arguments, 'title');

		$data = array_merge([
			'enabled'         => 1,
			'position'        => 'cb_tabmain',
			'displaytype'     => 'tab',
			'viewaccesslevel' => 1,
		], $this->cbTabProps($arguments), [
			'sys'         => 0,
			'fields'      => 1,
			'pluginclass' => null,
			'pluginid'    => null,
		]);

		$run = $this->cbRun($actor, static function () use ($data): array {
			$tab = new \CB\Database\Table\TabTable();

			if (!$tab->bind($data)) {
				return ['ok' => false, 'error' => 'bind failed: ' . $tab->getError()];
			}

			if (!$tab->check()) {
				return ['ok' => false, 'error' => (string) $tab->getError()];
			}

			if (!$tab->store()) {
				return ['ok' => false, 'error' => (string) $tab->getError()];
			}

			return ['ok' => true, 'error' => null, 'tabid' => (int) $tab->tabid];
		});

		$row = $run['result']['ok'] ? $this->cbFindTab((int) $run['result']['tabid']) : null;
		$ok  = $row !== null;

		return ToolResult::json([
			'ok'            => $ok,
			'tab'           => $row ? $this->cbTabSummary($row, true, []) : null,
			'error'         => $run['result']['error'],
			'cb_messages'   => $run['messages'],
			'plugin_errors' => $run['plugin_errors'],
		] + $this->cbContextNote(), !$ok);
	}
}
