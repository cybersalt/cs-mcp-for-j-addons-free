<?php
/**
 * @package     MCP for Advanced Module Manager
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Sets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\AmmBootTrait;
use Joomla\CMS\User\User;


final class ListConditionSetsTool extends AbstractTool
{
	use AmmBootTrait;

	public function getName(): string { return 'list_advancedmodules_condition_sets'; }

	public function getDescription(): string
	{
		return 'List Condition Sets: each one\'s rules in plain English, state, and how many modules use it. One set can serve '
			. 'any number of modules, so check here before making a new one. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'search'      => ['type' => 'string', 'description' => 'Part of the name.'],
				'state'       => ['type' => 'string', 'enum' => ['published', 'unpublished', 'trashed', 'all'], 'description' => 'Default: everything except trashed.'],
				'category'    => ['type' => 'string'],
				'unused_only' => ['type' => 'boolean', 'description' => 'Only sets attached to nothing.'],
				'limit'       => ['type' => 'integer', 'description' => 'Default 100, maximum 500.'],
				'offset'      => ['type' => 'integer'],
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

		$this->ammRequire($actor, 'com_conditions');

		$db    = $this->db;
		$where = [match ((string) ($arguments['state'] ?? '')) {
			'published'   => 'c.published = 1',
			'unpublished' => 'c.published = 0',
			'trashed'     => 'c.published = -2',
			'all'         => '1 = 1',
			default       => 'c.published <> -2',
		}];

		if (($arguments['search'] ?? '') !== '') {
			$where[] = 'c.name LIKE ' . $db->quote('%' . $db->escape((string) $arguments['search'], true) . '%');
		}

		if (($arguments['category'] ?? '') !== '') {
			$where[] = 'c.category = ' . $db->quote((string) $arguments['category']);
		}

		if (!empty($arguments['unused_only'])) {
			$where[] = 'NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__conditions_map') . ' map WHERE map.condition_id = c.id)';
		}

		$sqlWhere = ' FROM ' . $db->quoteName('#__conditions', 'c') . ' WHERE ' . implode(' AND ', $where);
		$total    = (int) $db->setQuery('SELECT COUNT(*)' . $sqlWhere)->loadResult();
		$ids      = $db->setQuery('SELECT c.id' . $sqlWhere . ' ORDER BY c.name', $this->ammOffset($arguments), $this->ammLimit($arguments))->loadColumn() ?: [];
		$sets     = $this->ammLoadSets($ids);
		$usage    = $this->ammUsage($ids);
		$out      = [];

		foreach ($ids as $id) {
			$set   = $sets[$id];
			$out[] = [
				'id'            => (int) $id,
				'name'          => $set->name,
				'category'      => $set->category,
				'state'         => $this->ammStateLabel((int) $set->published),
				'effect'        => $this->ammSetEffect($set),
				'modules_using' => \count($usage[$id]['modules']),
				'module_titles' => \array_slice(array_column($usage[$id]['modules'], 'title'), 0, 10),
				'other_uses'    => \count($usage[$id]['other_extensions']),
			];
		}

		return ToolResult::json(['ok' => true, 'total' => $total, 'sets' => $out]);
	}
}
