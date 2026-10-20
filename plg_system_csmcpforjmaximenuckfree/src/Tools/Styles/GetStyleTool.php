<?php
/**
 * @package     MCP for Maximenu CK
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\Styles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\MaximenuckBootTrait;
use Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\MaximenuckCssTrait;
use Joomla\CMS\User\User;

final class GetStyleTool extends AbstractTool
{
	use MaximenuckBootTrait;
	use MaximenuckCssTrait;

	public function getName(): string { return 'get_maximenuck_style'; }

	public function getDescription(): string
	{
		return 'One Maximenu CK style in full: the raw custom CSS (customcss column — what you edit), the '
			. 'generated layoutcss (built in the browser by Maximenu\'s style editor from its form fields; '
			. 'shown truncated unless include_layoutcss is true), the stored editor fields (params JSON, '
			. 'decoded), the modules that use it with their rendered HTML ids, and an analysis of how the '
			. 'custom CSS reaches the page: Maximenu emits layoutcss + customcss, replacing |ID| with the '
			. 'module\'s bare HTML id, and its editor ALSO bakes a copy of the custom CSS into the end of '
			. 'layoutcss with |ID| expanded to "div#<id>.maximenuckh ul.maximenuck". The response says whether '
			. 'that baked copy matches the current custom CSS (sync_state) and which |ID| rules are live. '
			. 'Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'style_id'          => ['type' => 'integer', 'description' => 'The #__maximenuck_styles id (a module\'s `styles` param).'],
				'include_layoutcss' => ['type' => 'boolean', 'description' => 'Return layoutcss in full (default false: first 600 bytes and last 600 bytes).'],
				'include_params'    => ['type' => 'boolean', 'description' => 'Return the decoded editor fields (default true).'],
			],
			'required'             => ['style_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->mxAdminBase() === null) {
			return $this->mxNotInstalledError();
		}

		if (!$this->mxTableExists('styles')) {
			return $this->mxMissingTableError('styles');
		}

		$id    = $this->requirePositiveInt($arguments, 'style_id');
		$style = $this->mxLoadStyle($id);

		if ($style === null) {
			return ToolResult::json(['ok' => false, 'error' => 'No Maximenu style with id ' . $id . '. Use list_maximenuck_styles.'], true);
		}

		$layout = (string) $style['layoutcss'];
		$custom = (string) $style['customcss'];
		$plan   = $this->mxPlanCustomCss($layout, $custom, $custom, false);

		$response = [
			'ok'    => true,
			'style' => [
				'id'        => $style['id'],
				'name'      => $style['name'],
				'state'     => $style['state'],
				'emits_css' => \in_array($style['state'], [0, 1], true),
				'customcss' => $custom,
			],
			'layoutcss' => !empty($arguments['include_layoutcss']) ? $layout : [
				'bytes' => \strlen($layout),
				'head'  => substr($layout, 0, 600),
				'tail'  => \strlen($layout) > 600 ? substr($layout, -600) : null,
			],
			'custom_css_analysis' => [
				'sync_state'        => $plan['sync_state'],
				'baked_prefix'      => $plan['prefix_label'],
				'sync_state_meaning' => match ($plan['sync_state']) {
					'in_sync'        => 'layoutcss ends with the baked copy of the current custom CSS (as the editor left it).',
					'in_sync_empty'  => 'No custom CSS; layoutcss carries no baked copy.',
					'out_of_sync'    => 'layoutcss ends with a baked copy of DIFFERENT (older) custom CSS — custom CSS was changed outside the editor. Those old rules still apply until the next editor save.',
					'unknown_prefix' => 'No custom CSS, and the generated rules\' prefix could not be recognised.',
					default          => 'layoutcss is empty (the style was never saved through the editor).',
				},
				'effectiveness'     => $this->mxEffectiveness($custom, $plan['sync_state'] === 'in_sync'),
			],
			'used_by_modules' => array_map(fn ($m) => [
				'module_id'    => $m['id'],
				'title'        => $m['title'],
				'published'    => $m['published'],
				'menu_html_id' => $this->mxMenuHtmlId($m),
				'orientation'  => $this->mxModParam($m, 'orientation', 'horizontal'),
			], $this->mxModulesUsingStyle($id)),
		];

		if (!isset($arguments['include_params']) || $arguments['include_params']) {
			$decoded = json_decode(str_replace('|qq|', '"', (string) $style['params']), true);
			$response['params'] = \is_array($decoded) ? $decoded : ['raw' => $this->mxPreview((string) $style['params'])];
		}

		return ToolResult::json($response);
	}
}
