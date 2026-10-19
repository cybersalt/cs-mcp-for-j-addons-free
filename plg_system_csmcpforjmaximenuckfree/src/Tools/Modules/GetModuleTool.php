<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\Modules;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\MaximenuckBootTrait;
use Joomla\CMS\User\User;

final class GetModuleTool extends AbstractTool
{
	use MaximenuckBootTrait;

	/** Param groups that are style-editor JSON blobs, summarised rather than dumped. */
	private const STYLE_BLOBS = [
		'menustyles', 'level1itemnormalstyles', 'level1itemhoverstyles', 'level1itemactivestyles',
		'level1itemparentstyles', 'level1itemnormalstylesicon', 'level1itemhoverstylesicon',
		'level2menustyles', 'level2itemnormalstyles', 'level2itemhoverstyles', 'level2itemactivestyles',
		'level2itemnormalstylesicon', 'level2itemhoverstylesicon', 'level3menustyles',
		'level3itemnormalstyles', 'level3itemhoverstyles', 'headingstyles', 'fancystyles',
	];

	public function getName(): string { return 'get_maximenuck_module'; }

	public function getDescription(): string
	{
		return 'One Maximenu CK module in full: the layout/CSS summary from list_maximenuck_modules, every '
			. 'module param (the v8-era per-level style blobs are summarised by size, since v9+ takes styling '
			. 'from the style row instead), the style it loads (id, name, state, CSS sizes), and the compiled-CSS '
			. 'file status. Use the menutype here with get_maximenuck_menu_layout to see the mega-menu columns. '
			. 'Module params themselves are ordinary Joomla module params — change them with core '
			. 'update_module. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'module_id' => ['type' => 'integer', 'description' => 'The mod_maximenuck module id.'],
			],
			'required'             => ['module_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->mxAdminBase() === null) {
			return $this->mxNotInstalledError();
		}

		$id      = $this->requirePositiveInt($arguments, 'module_id');
		$modules = $this->mxModules($id);

		if ($modules === []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No site mod_maximenuck module with id ' . $id . '. Use list_maximenuck_modules.',
			], true);
		}

		$m       = $modules[0];
		$summary = $this->mxModuleSummary($m);
		$params  = [];
		$blobs   = [];

		foreach ($m['params'] as $k => $v) {
			if (\in_array($k, self::STYLE_BLOBS, true)) {
				$blobs[$k] = \strlen(\is_string($v) ? $v : (string) json_encode($v));
				continue;
			}

			$params[$k] = $v;
		}

		ksort($params);

		$style = null;

		if ($summary['style_id'] > 0 && $this->mxTableExists('styles')) {
			$row = $this->mxLoadStyle($summary['style_id']);

			$style = $row === null ? ['id' => $summary['style_id'], 'missing' => true] : [
				'id'              => $row['id'],
				'name'            => $row['name'],
				'state'           => $row['state'],
				'emits_css'       => \in_array($row['state'], [0, 1], true),
				'layoutcss_bytes' => \strlen((string) $row['layoutcss']),
				'customcss_bytes' => \strlen((string) $row['customcss']),
			];
		}

		return ToolResult::json([
			'ok'      => true,
			'module'  => $summary + ['access' => (int) $m['access'], 'language' => $m['language']],
			'style'   => $style,
			'params'  => $params,
			'legacy_style_param_blobs_bytes' => $blobs,
			'notes'   => [
				'Rendered HTML id is "' . $summary['menu_html_id'] . '": |ID| in this module\'s style CSS becomes that.',
				'The module `customcss` param is a hidden v8 field; v9+ never outputs it.',
			],
		]);
	}
}
