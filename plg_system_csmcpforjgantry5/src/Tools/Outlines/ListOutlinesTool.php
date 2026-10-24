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

final class ListOutlinesTool extends AbstractTool
{
	use Gantry5BootTrait;
	use OutlineSupportTrait;

	public function getName(): string { return 'list_gantry5_outlines'; }

	public function getDescription(): string
	{
		return 'Every outline of a Gantry theme: the Base Outline, the three system outlines (Body Only, Error, Offline) and one per '
			. 'template style, with the config folder each renders (and why), where its layout comes from (own layout.yaml or a '
			. 'preset), which outlines its layout inherits from, which config files it overrides, how many menu items are assigned '
			. 'to it, and whether it is the site default.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => ['theme' => $this->g5ThemeProp()],
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
		$outlines = $this->g5Outlines($theme);
		$assigned = $this->g5MenuAssignments(array_map('intval', array_filter(array_keys($outlines), 'is_numeric')));
		$default  = $this->g5DefaultStyle();
		$out      = [];

		foreach ($outlines as $id => $outline) {
			$out[] = $this->outlineSummary($theme, $outline, $assigned, $default);
		}

		return ToolResult::json([
			'theme'    => $theme['element'],
			'count'    => \count($out),
			'outlines' => $out,
		]);
	}
}
