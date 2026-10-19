<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\Styles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\MaximenuckBootTrait;
use Joomla\CMS\User\User;

final class ClearCompiledCssTool extends AbstractTool
{
	use MaximenuckBootTrait;

	public function getName(): string { return 'clear_maximenuck_compiled_css'; }

	public function getDescription(): string
	{
		return 'Deletes the compiled stylesheet Maximenu CK writes for a module '
			. '(modules/mod_maximenuck/themes/custom/css/maximenuck_<html id>.css) so the next front-end render '
			. 'rebuilds it, and cleans the mod_maximenuck and com_menus cache groups. Needed when a module\'s '
			. '"Load compiled CSS" option is "Yes" (loadcompiledcss = 1): the file is then written once and served '
			. 'for ever, so style CSS and menu-item changes never appear. Harmless for mode "2" (rebuilt on every '
			. 'render anyway) and mode "0" (no file). Give module_id for one module, or omit it for every '
			. 'Maximenu module. The file is linked without a cache-buster, so browsers holding it may need a '
			. 'hard refresh.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'module_id' => ['type' => 'integer', 'description' => 'One mod_maximenuck module; omit for all.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->mxAdminBase() === null) {
			return $this->mxNotInstalledError();
		}

		$onlyId  = isset($arguments['module_id']) ? (int) $arguments['module_id'] : null;
		$modules = $this->mxModules($onlyId);

		if ($onlyId !== null && $modules === []) {
			return ToolResult::json(['ok' => false, 'error' => 'No site mod_maximenuck module with id ' . $onlyId . '.'], true);
		}

		$results = array_map(fn ($m) => $this->mxPurgeCompiledCss($m), $modules);

		return ToolResult::json([
			'ok'                   => true,
			'results'              => $results,
			'deleted'              => \count(array_filter($results, static fn ($r) => ($r['action'] ?? '') === 'deleted')),
			'cache_groups_cleaned' => $this->mxCleanCacheGroups(['mod_maximenuck', 'com_menus']),
			'cache_warning'        => $this->mxCompiledCacheWarning($modules),
		]);
	}
}
