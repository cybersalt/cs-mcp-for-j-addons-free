<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Gantry5BootTrait;
use Joomla\CMS\User\User;

final class ClearCacheTool extends AbstractTool
{
	use Gantry5BootTrait;

	public function getName(): string { return 'clear_gantry5_cache'; }

	public function getDescription(): string
	{
		return 'Clear Gantry\'s cache the way Gantry\'s own "Clear Cache" button does: empty cache/gantry5/<theme> (compiled YAML, '
			. 'blueprints, Twig, particle HTML) and cache/gantry5/admin, then clean Joomla\'s _system and com_templates cache groups. '
			. 'With recompile_css=true also delete compiled CSS (for one outline, or all) so the next page view rebuilds it from SCSS; '
			. 'in Gantry\'s production mode that is the only way a changed SCSS file or style shows. Content and settings are not touched.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'theme'         => ['type' => 'string', 'description' => 'Optional: one Gantry theme. Default: every Gantry theme.'],
				'recompile_css' => ['type' => 'boolean', 'description' => 'Also delete compiled CSS. Default false.'],
				'outline'       => ['type' => 'string', 'description' => 'With recompile_css: only this outline\'s CSS. Default: all outlines.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->g5Installed()) {
			return $this->g5NotInstalledError();
		}

		$this->g5RequireAccess($actor);

		$themes = trim((string) ($arguments['theme'] ?? '')) !== '' ? [$this->g5RequireTheme($arguments)] : array_values($this->g5Themes());
		$out    = [];

		foreach ($themes as $theme) {
			$entry = ['theme' => $theme['element']];

			if (!empty($arguments['recompile_css'])) {
				$only = null;

				if (trim((string) ($arguments['outline'] ?? '')) !== '') {
					$outline = $this->g5RequireOutline($theme, $arguments['outline']);
					$only    = [$this->g5OutlineFolder($theme, $outline)['folder']];
				}

				$entry['compiled_css_removed'] = $this->g5InvalidateCss($theme, $only);
			}

			$entry += $this->g5ClearCache($theme);
			$out[]  = $entry;
		}

		return ToolResult::json([
			'ok'     => true,
			'themes' => $out,
			'note'   => !empty($arguments['recompile_css']) ? 'The next page view of each affected outline recompiles its CSS.' : null,
		]);
	}
}
