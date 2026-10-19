<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\Modules;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\MaximenuckBootTrait;
use Joomla\CMS\User\User;

final class ListModulesTool extends AbstractTool
{
	use MaximenuckBootTrait;

	public function getName(): string { return 'list_maximenuck_modules'; }

	public function getDescription(): string
	{
		return 'Lists every Maximenu CK module (mod_maximenuck, site client) with what decides how it renders: '
			. 'position, published state, source (menu / menubuilder / a Pro source plugin), menutype, the '
			. 'STYLE id it loads its CSS from (the `styles` param — this is the row whose custom CSS you edit), '
			. 'theme, layout, orientation, the rendered HTML id (empty or numeric `menuid` becomes '
			. '"maximenuck<module id>" — that is what |ID| in style CSS turns into), start/end level, '
			. 'testoverflow, and the compiled-CSS mode and file. Mode "1" means the compiled file is written '
			. 'once and served stale for ever — changes need clear_maximenuck_compiled_css. Also flags a '
			. 'non-empty module `customcss` param, which v9+ never outputs. Optional filters: published, '
			. 'menutype, style_id. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'published' => ['type' => 'integer', 'description' => 'Filter by module state (1, 0, -2).'],
				'menutype'  => ['type' => 'string', 'description' => 'Only modules rendering this menutype.'],
				'style_id'  => ['type' => 'integer', 'description' => 'Only modules using this Maximenu style.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->mxAdminBase() === null) {
			return $this->mxNotInstalledError();
		}

		$styleNames = [];

		if ($this->mxTableExists('styles')) {
			foreach ($this->db->setQuery('SELECT id, name, state FROM ' . $this->db->quoteName('#__maximenuck_styles'))->loadAssocList() ?: [] as $r) {
				$styleNames[(int) $r['id']] = ['name' => (string) $r['name'], 'state' => (int) $r['state']];
			}
		}

		$out = [];

		foreach ($this->mxModules() as $m) {
			$s = $this->mxModuleSummary($m);

			if (isset($arguments['published']) && (int) $arguments['published'] !== $s['published']) {
				continue;
			}

			if (!empty($arguments['menutype']) && (string) $arguments['menutype'] !== $s['menutype']) {
				continue;
			}

			if (isset($arguments['style_id']) && (int) $arguments['style_id'] !== $s['style_id']) {
				continue;
			}

			$s['style'] = $s['style_id'] > 0 ? ($styleNames[$s['style_id']] ?? ['missing' => true]) : null;

			if ($s['dead_module_customcss_bytes'] > 0) {
				$s['dead_module_customcss_warning'] = 'This module\'s own customcss param is never output in v9+. '
					. 'Custom CSS belongs on the style row.';
			}

			$out[] = $s;
		}

		return ToolResult::json([
			'ok'      => true,
			'count'   => \count($out),
			'modules' => $out,
		]);
	}
}
