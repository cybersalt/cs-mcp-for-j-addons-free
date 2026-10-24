<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Themes;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Gantry5BootTrait;
use Joomla\CMS\User\User;

final class ListThemesTool extends AbstractTool
{
	use Gantry5BootTrait;

	public function getName(): string { return 'list_gantry5_themes'; }

	public function getDescription(): string
	{
		return 'Every Gantry 5 theme (site template with gantry/theme.yaml) on the site: name, version, enabled, engine, parent theme, '
			. 'the Gantry version it requires, how many template styles (outlines) it has and which is the site default, and whether '
			. 'its custom/ folder exists and is writable. Start here; the other tools take the theme element as `theme`.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->g5Installed()) {
			return $this->g5NotInstalledError();
		}

		$this->g5RequireAccess($actor);

		$default = $this->g5DefaultStyle();
		$out     = [];

		foreach ($this->g5Themes() as $element => $theme) {
			$styles = $this->g5Styles($element);
			$custom = $this->g5CustomPath($theme);

			$out[] = [
				'theme'            => $element,
				'name'             => $theme['name'],
				'version'          => $theme['version'],
				'enabled'          => (bool) $theme['enabled'],
				'engine'           => $theme['engine'],
				'parent'           => $theme['parent'],
				'requires_gantry'  => $theme['required_gantry'],
				'theme_yaml'       => $this->g5Rel($theme['theme_yaml']),
				'theme_yaml_error' => $theme['theme_yaml_error'],
				'styles'           => array_map(static fn (array $s): array => ['id' => $s['id'], 'title' => $s['title'], 'home' => $s['home']], array_values($styles)),
				'is_site_default'  => $default !== null && $default['template'] === $element,
				'custom_folder'    => ['path' => $this->g5Rel($custom), 'exists' => is_dir($custom), 'writable' => is_dir($custom) && is_writable($custom)],
			];
		}

		return ToolResult::json([
			'gantry_version' => $this->g5LibraryVersion(),
			'site_default_style' => $default,
			'count'          => \count($out),
			'themes'         => $out,
		]);
	}
}
