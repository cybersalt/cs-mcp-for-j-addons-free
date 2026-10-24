<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Outlines;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Gantry5BootTrait;
use Joomla\CMS\User\User;

final class GetOutlineTool extends AbstractTool
{
	use Gantry5BootTrait;
	use OutlineSupportTrait;

	public function getName(): string { return 'get_gantry5_outline'; }

	public function getDescription(): string
	{
		return 'One outline in full: its template style, the menu items assigned to it, and its INHERITANCE CHAIN. For settings '
			. '(particle defaults, styles, page settings) that is: this outline\'s own files, then the Base Outline, then the theme\'s '
			. 'shipped config, then the blueprint defaults, with every file listed and every key this outline overrides. For the '
			. 'layout: which outlines it takes sections or particles from, and which outlines take sections or particles from it '
			. '(so a change here reaches them). Plus its compiled CSS files and any layout problems.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'theme'   => $this->g5ThemeProp(),
				'outline' => $this->g5OutlineProp(),
			],
			'required'             => ['outline'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->g5Installed()) {
			return $this->g5NotInstalledError();
		}

		$this->g5RequireAccess($actor);

		$theme    = $this->g5RequireTheme($arguments);
		$outline  = $this->g5RequireOutline($theme, $arguments['outline'] ?? null);
		$folder   = $this->g5OutlineFolder($theme, $outline)['folder'];
		$assigned = $outline['style'] !== null ? $this->g5MenuAssignments([(int) $outline['id']]) : [];
		$default  = $this->g5DefaultStyle();
		$summary  = $this->outlineSummary($theme, $outline, $assigned, $default);

		// Settings chain: each config folder that takes part, highest priority first.
		$chain = [];

		foreach (($folder === 'default' ? ['default'] : [$folder, 'default']) as $f) {
			foreach ($this->g5ConfigDirs($theme, $f) as $dir) {
				$isCustom = str_starts_with(str_replace('\\', '/', $dir), str_replace('\\', '/', $this->g5CustomPath($theme)));
				$files    = [];

				foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
					if ($file->isFile() && str_ends_with($file->getFilename(), '.yaml')) {
						$rel = str_replace('\\', '/', substr($file->getPathname(), \strlen($dir) + 1));

						if (\in_array($rel, ['layout.yaml', 'index.yaml'], true)) {
							continue;
						}

						try {
							$keys = array_keys($this->g5FlattenData($this->g5ParseYamlFile($file->getPathname())));
						} catch (\Throwable $e) {
							$keys = ['YAML error: ' . $e->getMessage()];
						}

						$files[$rel] = $keys;
					}
				}

				ksort($files);

				$chain[] = [
					'level'  => $f === 'default' ? ($isCustom ? 'Base Outline' : 'theme defaults') : ($isCustom ? 'this outline' : 'theme defaults for this outline'),
					'folder' => $this->g5Rel($dir),
					'files'  => $files,
				];
			}
		}

		$chain[] = ['level' => 'blueprint defaults', 'folder' => 'particles/*.yaml and blueprints/ of the theme and engine', 'files' => []];

		$layoutFrom = [];
		$problems   = [];

		try {
			$resolved = $this->g5ResolvedLayout($theme, $folder);
			$problems = $resolved['problems'];

			foreach ($this->g5LayoutItems($resolved['nodes']) as $item) {
				if (!empty($item['inherited_from'])) {
					$layoutFrom[$item['inherited_from']['outline']][] = $item['id'];
				}
			}
		} catch (\Throwable $e) {
			$problems[] = $e->getMessage();
		}

		$css = array_values(array_filter($this->g5CompiledCssFiles($theme), static fn (array $c): bool => $c['outline'] === $folder));

		return ToolResult::json($summary + [
			'menu_items'       => $outline['style'] !== null ? ($assigned[(int) $outline['id']] ?? []) : [],
			'used_for'         => $this->usedFor($outline, $default),
			'settings_chain'   => $chain,
			'settings_rule'    => 'A key in a higher level wins; levels merge key by key where the blueprint nests keys, and replace whole values otherwise (lists such as social items).',
			'layout_takes_from' => $this->g5Map($layoutFrom),
			'layout_given_to'  => $this->g5Map($this->g5InheritedBy($theme, $folder)),
			'compiled_css'     => $css,
			'layout_problems'  => $problems,
		]);
	}

	private function usedFor(array $outline, ?array $default): string
	{
		return match ((string) $outline['id']) {
			'default'    => 'Not rendered directly: the Base Outline every other outline falls back to for settings, and which layouts usually inherit from.',
			'_body_only' => 'Pages rendered with tmpl=component (modal and print views).',
			'_error'     => 'The error page.',
			'_offline'   => 'The offline page.',
			default      => $default !== null && $default['id'] === (int) $outline['id']
				? 'Pages whose menu item is assigned to this style, plus every page whose menu item names no style (this is the site default).'
				: 'Pages whose menu item is assigned to this style (menu_items).',
		};
	}
}
