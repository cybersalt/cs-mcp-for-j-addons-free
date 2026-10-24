<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Outlines;

\defined('_JEXEC') or die;

/**
 * Outline summaries shared by the outline tools. Needs Gantry5BootTrait.
 */
trait OutlineSupportTrait
{
	/**
	 * @param array<int,array<int,array>> $assigned from g5MenuAssignments()
	 */
	protected function outlineSummary(array $theme, array $outline, array $assigned, ?array $default): array
	{
		$folder = $this->g5OutlineFolder($theme, $outline);
		$layout = null;
		$from   = [];
		$error  = null;

		try {
			$src    = $this->g5LayoutSource($theme, $folder['folder']);
			$layout = $src['kind'] === 'preset' ? 'preset ' . $src['preset'] : ($src['custom'] ? 'own layout.yaml' : 'theme-shipped layout.yaml');
			$from   = $this->g5RawInheritSources($src['data']);
		} catch (\Throwable $e) {
			$error = $e->getMessage();
		}

		$style = $outline['style'];
		$id    = (string) $outline['id'];

		return array_filter([
			'id'              => $id,
			'title'           => $outline['title'],
			'kind'            => $outline['kind'],
			'style'           => $style !== null ? [
				'id'            => $style['id'],
				'title'         => $style['title'],
				'home'          => $style['home'],
				'preset'        => $style['preset'],
				'configuration' => $style['configuration'],
			] : null,
			'site_default'    => $style !== null && $default !== null && $default['id'] === $style['id'],
			'config_folder'   => $folder['folder'],
			'config_folder_reason' => $folder['reason'],
			'own_folder'      => $folder['exists'] && ($style === null || $folder['folder'] === $id),
			'layout'          => $layout,
			'layout_inherits_from' => $this->g5Map($from),
			'overrides'       => $this->outlineFiles($theme, $folder['folder']),
			'menu_items_assigned' => $style !== null ? \count($assigned[$style['id']] ?? []) : null,
			'error'           => $error,
		], static fn ($v) => $v !== null);
	}

	/**
	 * Config files in the outline's custom folder, other than the layout and its
	 * index, as relative names ("styles.yaml", "particles/logo.yaml").
	 *
	 * @return string[]
	 */
	protected function outlineFiles(array $theme, string $folder): array
	{
		$dir = $this->g5CustomPath($theme) . '/config/' . $folder;
		$out = [];

		if (!$this->g5SafeName($folder) || !is_dir($dir)) {
			return [];
		}

		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

		foreach ($it as $f) {
			if ($f->isFile() && str_ends_with($f->getFilename(), '.yaml')) {
				$rel = str_replace('\\', '/', substr($f->getPathname(), \strlen($dir) + 1));

				if (!\in_array($rel, ['layout.yaml', 'index.yaml'], true)) {
					$out[] = $rel;
				}
			}
		}

		sort($out);

		return $out;
	}
}
