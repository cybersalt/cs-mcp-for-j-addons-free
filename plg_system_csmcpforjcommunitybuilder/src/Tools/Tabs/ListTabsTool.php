<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Tabs;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

final class ListTabsTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use TabSupportTrait;

	public function getName(): string { return 'list_cb_tabs'; }

	public function getDescription(): string
	{
		return 'List Community Builder profile tabs (Tab Management): title (translated and raw), enabled, '
			. 'position on the profile layout, display type, access level, the three orderings (profile, edit, '
			. 'registration), whether it is a system tab or a plugin tab (rendered by a CB plugin class rather than '
			. 'holding fields), and how many fields sit on it. Ordered by position then ordering. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'enabled'     => ['type' => 'boolean'],
				'custom_only' => ['type' => 'boolean', 'description' => 'Exclude system and plugin tabs.'],
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

		$this->cbRequireAccess($actor, 'core.manage', 'com_comprofiler.tabs');

		$translate = true;

		try {
			$this->cbRun($actor, static fn () => null);
		} catch (\Throwable $e) {
			$translate = false;
		}

		$rows   = $this->db->setQuery(
			'SELECT * FROM ' . $this->db->quoteName('#__comprofiler_tabs') . ' ORDER BY position, ordering'
		)->loadAssocList() ?: [];
		$counts = $this->cbFieldCountsByTab();
		$out    = [];

		foreach ($rows as $t) {
			if (\array_key_exists('enabled', $arguments) && ((int) $t['enabled'] === 1) !== $this->cbBool($arguments, 'enabled')) {
				continue;
			}

			if ($this->cbBool($arguments, 'custom_only') && ((int) $t['sys'] === 1 || (string) $t['pluginclass'] !== '')) {
				continue;
			}

			$out[] = $this->cbTabSummary($t, $translate, $counts);
		}

		return ToolResult::json(['ok' => true, 'count' => \count($out), 'tabs' => $out]);
	}
}
