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


final class ListModulesTool extends AbstractTool
{
	use AmmBootTrait;

	public function getName(): string { return 'list_advancedmodules_modules'; }

	public function getDescription(): string
	{
		return 'List front-end modules with what actually decides where each one shows: its Condition Set in plain English, '
			. 'or that it has none, plus the Joomla access, menu, language and publishing settings being ignored. Filter by title, position, '
			. 'state, set or category. Core list_modules shows the settings; this shows the result. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'search'   => ['type' => 'string', 'description' => 'Part of the title.'],
				'position' => ['type' => 'string', 'description' => 'Exact template position.'],
				'state'    => ['type' => 'string', 'enum' => ['published', 'unpublished', 'trashed', 'all'], 'description' => 'Default: everything except trashed.'],
				'has_set'  => ['type' => 'boolean', 'description' => 'true for modules with a Condition Set, false for those without.'],
				'set_id'   => ['type' => 'integer', 'description' => 'Only modules attached to this set.'],
				'category' => ['type' => 'string', 'description' => 'Exact Advanced Module Manager category.'],
				'limit'    => ['type' => 'integer', 'description' => 'Default 100, maximum 500.'],
				'offset'   => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ammInstalled()) {
			return $this->ammNotInstalledError();
		}

		$this->ammRequire($actor, 'com_advancedmodules');
		$this->ammRequire($actor, 'com_conditions');

		$db    = $this->db;
		$where = ['m.client_id = 0'];
		$state = (string) ($arguments['state'] ?? '');

		$where[] = match ($state) {
			'published'   => 'm.published = 1',
			'unpublished' => 'm.published = 0',
			'trashed'     => 'm.published = -2',
			'all'         => '1 = 1',
			default       => 'm.published <> -2',
		};

		if (($arguments['search'] ?? '') !== '') {
			$where[] = 'm.title LIKE ' . $db->quote('%' . $db->escape((string) $arguments['search'], true) . '%');
		}

		if (($arguments['position'] ?? '') !== '') {
			$where[] = 'm.position = ' . $db->quote((string) $arguments['position']);
		}

		if (($arguments['category'] ?? '') !== '') {
			$where[] = 'am.category = ' . $db->quote((string) $arguments['category']);
		}

		if (isset($arguments['has_set'])) {
			$where[] = $arguments['has_set'] ? 'map.condition_id IS NOT NULL' : 'map.condition_id IS NULL';
		}

		if (!empty($arguments['set_id'])) {
			$where[] = 'map.condition_id = ' . (int) $arguments['set_id'];
		}

		$sqlWhere = ' WHERE ' . implode(' AND ', $where);
		$total    = (int) $db->setQuery('SELECT COUNT(*)' . $this->ammModuleFrom() . $sqlWhere)->loadResult();
		$modules  = $db->setQuery(
			'SELECT ' . self::AMM_MODULE_SELECT . $this->ammModuleFrom() . $sqlWhere . ' ORDER BY m.position, m.ordering, m.id',
			$this->ammOffset($arguments),
			$this->ammLimit($arguments)
		)->loadObjectList('id') ?: [];

		$sets        = $this->ammLoadSets(array_column($modules, 'condition_id'));
		$assignments = $this->ammCoreAssignments(array_keys($modules));
		$levels      = $this->ammPublicLevels();
		$pluginOn    = $this->ammPluginEnabled();
		$out         = [];

		foreach ($modules as $id => $module) {
			$out[] = $this->ammFormatModule($module, $sets[$module->condition_id] ?? null, $assignments[$id], $levels, $pluginOn);
		}

		return ToolResult::json(['ok' => true, 'total' => $total, 'plugin_enabled' => $pluginOn, 'modules' => $out]);
	}
}
