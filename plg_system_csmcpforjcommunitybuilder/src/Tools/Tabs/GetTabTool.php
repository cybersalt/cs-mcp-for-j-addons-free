<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Tabs;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

final class GetTabTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use TabSupportTrait;

	public function getName(): string { return 'get_cb_tab'; }

	public function getDescription(): string
	{
		return 'One Community Builder tab: all its settings, its description, its decoded params, and the fields '
			. 'on it in order (name, title, type, published). Also lists the positions and display types CB '
			. 'offers, for use with create_cb_tab / update_cb_tab. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => ['tab_id' => ['type' => 'integer']],
			'required'   => ['tab_id'],
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

		$tab = $this->cbFindTab($this->requirePositiveInt($arguments, 'tab_id'));

		if ($tab === null) {
			throw new \InvalidArgumentException('No CB tab with id ' . (int) $arguments['tab_id'] . '.');
		}

		$translate = true;

		try {
			$this->cbRun($actor, static fn () => null);
		} catch (\Throwable $e) {
			$translate = false;
		}

		$fields = [];

		foreach ($this->cbFieldRows() as $f) {
			if ((int) $f['tabid'] === (int) $tab['tabid']) {
				$fields[] = [
					'fieldid'   => (int) $f['fieldid'],
					'name'      => $f['name'],
					'title'     => $translate ? $this->cbText((string) $f['title']) : $f['title'],
					'type'      => $f['type'],
					'published' => (int) $f['published'] === 1,
					'ordering'  => (int) $f['ordering'],
				];
			}
		}

		$params = json_decode((string) ($tab['params'] ?? ''), true);

		return ToolResult::json([
			'ok'          => true,
			'tab'         => $this->cbTabSummary($tab, $translate, [(int) $tab['tabid'] => \count($fields)]) + [
				'description' => $tab['description'],
				'width'       => $tab['width'],
			],
			'params'      => \is_array($params) ? $params : ($tab['params'] === null ? new \stdClass() : ['raw' => $tab['params']]),
			'fields'      => $fields,
			'choices'     => $this->cbTabChoices(),
		]);
	}
}
