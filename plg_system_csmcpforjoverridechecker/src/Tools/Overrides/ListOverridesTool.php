<?php
/**
 * @package     MCP for Override Checker
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\Overrides;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\OverrideCheckerBootTrait;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;

final class ListOverridesTool extends AbstractTool
{
	use OverrideCheckerBootTrait;

	public function getName(): string { return 'list_override_checker_overrides'; }

	public function getDescription(): string
	{
		return 'List the template overrides Joomla has flagged after an update (#__template_overrides, the "Changes found" '
			. 'list), newest change first, the same rows Override Checker reviews. Each row gives the override and core '
			. 'file paths and whether each exists, the template, site/admin, checked or not, what triggered it (Joomla '
			. 'Update / Extension Update / Extension Install) and the extension. core_exists false usually means an '
			. 'alternative layout with no core counterpart. Filters match Override Checker\'s REST API.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'template'     => ['type' => 'string', 'description' => 'Template folder name, e.g. "cassiopeia".'],
				'client'       => ['type' => 'string', 'enum' => ['site', 'admin'], 'description' => 'Site or administrator templates.'],
				// No integer enum: Gemini accepts enum on strings only.
				'state'        => ['type' => 'integer', 'description' => '0 = not checked yet, 1 = marked as checked in Joomla\'s Templates admin. Omit for both.'],
				'extension_id' => ['type' => 'integer', 'description' => 'Only rows caused by this extension.'],
				'limit'        => ['type' => 'integer', 'description' => '1-500, default 100.'],
				'offset'       => ['type' => 'integer', 'description' => 'Rows to skip, for paging.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($error = $this->ocCheck()) {
			return $error;
		}

		$this->ocRequireAccess($actor, 'view');

		$db    = $this->db;
		$query = $db->createQuery()
			->select($db->quoteName(['id', 'template', 'hash_id', 'extension_id', 'state', 'action', 'client_id', 'created_date', 'modified_date']))
			->from($db->quoteName('#__template_overrides'));

		if (isset($arguments['template']) && trim((string) $arguments['template']) !== '') {
			$template = trim((string) $arguments['template']);
			$query->where($db->quoteName('template') . ' = :template')->bind(':template', $template);
		}

		if (isset($arguments['client'])) {
			$clientId = $this->ocEnum($arguments, 'client', ['site', 'admin'], 'site') === 'admin' ? 1 : 0;
			$query->where($db->quoteName('client_id') . ' = :client')->bind(':client', $clientId, ParameterType::INTEGER);
		}

		if (isset($arguments['state'])) {
			$state = (int) $arguments['state'];

			if ($state !== 0 && $state !== 1) {
				throw new \InvalidArgumentException('state must be 0 (not checked) or 1 (checked).');
			}

			$query->where($db->quoteName('state') . ' = :state')->bind(':state', $state, ParameterType::INTEGER);
		}

		if (isset($arguments['extension_id'])) {
			$extensionId = (int) $arguments['extension_id'];
			$query->where($db->quoteName('extension_id') . ' = :ext')->bind(':ext', $extensionId, ParameterType::INTEGER);
		}

		$total = (int) $db->setQuery((clone $query)->clear('select')->select('COUNT(*)'))->loadResult();

		$limit  = $this->ocLimit($arguments, 100, 500);
		$offset = $this->ocOffset($arguments);

		// The REST API sorts by modified_date; id breaks ties so paging is stable.
		$query->order($db->quoteName('modified_date') . ' DESC')->order($db->quoteName('id') . ' DESC');

		$rows = $db->setQuery($query, $offset, $limit)->loadAssocList() ?: [];

		$extensions = $this->extensionNames(array_column($rows, 'extension_id'));
		$out        = [];

		foreach ($rows as $row) {
			$item              = $this->ocDescribeOverride($row);
			$item['extension'] = $extensions[(int) $row['extension_id']] ?? null;
			$out[]             = $item;
		}

		return ToolResult::json([
			'ok'        => true,
			'total'     => $total,
			'offset'    => $offset,
			'returned'  => \count($out),
			'more'      => $offset + \count($out) < $total,
			'overrides' => $out,
		]);
	}

	/**
	 * @param array<int|string> $ids
	 * @return array<int,array{name:string,element:string,type:string,enabled:bool}>
	 */
	private function extensionNames(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

		if ($ids === []) {
			return [];
		}

		$db   = $this->db;
		$rows = $db->setQuery(
			$db->createQuery()
				->select($db->quoteName(['extension_id', 'name', 'element', 'type', 'enabled']))
				->from($db->quoteName('#__extensions'))
				->whereIn($db->quoteName('extension_id'), $ids)
		)->loadAssocList('extension_id') ?: [];

		$out = [];

		foreach ($rows as $id => $row) {
			$out[(int) $id] = [
				'name'    => (string) $row['name'],
				'element' => (string) $row['element'],
				'type'    => (string) $row['type'],
				'enabled' => (int) $row['enabled'] === 1,
			];
		}

		return $out;
	}
}
