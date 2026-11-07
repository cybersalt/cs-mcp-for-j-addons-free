<?php
/**
 * @package     MCP for Advanced Module Manager
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Modules;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\AmmBootTrait;
use Joomla\CMS\User\User;


final class SetModuleOptionsTool extends AbstractTool
{
	use AmmBootTrait;

	public function getName(): string { return 'set_advancedmodules_module_options'; }

	public function getDescription(): string
	{
		return 'Set Advanced Module Manager\'s own options on one or more front-end modules: category, colour, "hide if empty" '
			. 'and notes. Only the options you send change. Module titles, positions, access and content are core Joomla: use '
			. 'update_module for those. To change where a module shows, use the Condition Set tools.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'module_ids'    => ['type' => 'array', 'items' => ['type' => 'integer']],
				'category'      => ['type' => 'string', 'description' => 'Up to 50 characters. Empty clears it.'],
				'color'         => ['type' => 'string', 'description' => 'Like #dc2a28, or "none".'],
				'hide_if_empty' => ['type' => 'boolean', 'description' => 'Hide the module when its output is empty.'],
				'notes'         => ['type' => 'string', 'description' => 'Plain text, up to 5000 characters.'],
			],
			'required'             => ['module_ids'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ammInstalled()) {
			return $this->ammNotInstalledError();
		}

		$this->ammRefuseUnknown($arguments);

		$ids = $this->ammIds($arguments, 'module_ids');
		$this->ammRequireModuleEdit($actor, $ids);
		$this->ammRequireSiteModules($ids);

		$columns = [];
		$extras  = [];

		if (\array_key_exists('category', $arguments)) {
			$columns['category'] = $this->ammPlainText('category', (string) $arguments['category'], 50);
		}

		if (\array_key_exists('color', $arguments)) {
			$columns['color'] = $this->ammColor((string) $arguments['color']);
		}

		if (\array_key_exists('hide_if_empty', $arguments)) {
			$extras['hideempty'] = $arguments['hide_if_empty'] ? '1' : '0';
		}

		if (\array_key_exists('notes', $arguments)) {
			$extras['notes'] = $this->ammPlainText('notes', (string) $arguments['notes'], 5000);
		}

		if ($columns === [] && $extras === []) {
			throw new \RuntimeException('Nothing was saved. Send category, color, hide_if_empty or notes.');
		}

		foreach ($ids as $id) {
			$this->ammSaveModuleRow($id, $columns, $extras);
		}

		$this->ammClearCaches();

		$out = ['ok' => true, 'module_ids' => $ids, 'saved' => array_keys($columns + $extras)] + $this->ammEffectNotes();

		if (isset($extras['hideempty']) && isset($this->ammComponentParams('com_advancedmodules')->use_hideempty)
			&& !$this->ammComponentParams('com_advancedmodules')->use_hideempty) {
			$out['note'] = 'The "Hide if Empty" option is off in Advanced Module Manager\'s settings, so hide_if_empty does nothing until it is on.';
		}

		return ToolResult::json($out);
	}
}
