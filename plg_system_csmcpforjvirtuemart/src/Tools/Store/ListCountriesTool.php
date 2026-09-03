<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Store;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Countries, with the fact that actually bites: `published` defaults to 0.
 *
 * `#__virtuemart_countries.published` is `NOT NULL DEFAULT '0'`
 * (`install.sql:241`) and the shipped data ships most countries unpublished.
 * An unpublished country does not appear in the checkout address dropdown, so
 * "customers in country X cannot check out" is nearly always this and nothing
 * more interesting.
 */
final class ListCountriesTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'list_virtuemart_countries'; }

	public function getDescription(): string
	{
		return 'List countries from #__virtuemart_countries. '
			. 'THE USEFUL FACT: published defaults to 0 on this table (install.sql:241) and VirtueMart '
			. 'ships most countries unpublished. An unpublished country does not appear in the checkout '
			. 'address dropdown at all, so "customers in country X cannot complete checkout" is almost '
			. 'always exactly this. The response reports how many of the shop\'s countries are published. '
			. 'Filters: published, search (on country_name, country_3_code or country_2_code), '
			. 'worldzone_id. Supports limit (default 50, max 200) and offset. '
			. 'country_3_code, country_2_code and country_num_code each carry a UNIQUE KEY. '
			. 'virtuemart_worldzone_id groups countries into shipping zones used by shipment plugins. '
			. 'Each row reports how many calc rules are restricted to it '
			. '(#__virtuemart_calc_countries) and how many states it has. A calc rule restricted to a '
			. 'country whose has_countries flag is 0 will not have that restriction applied — the flag is '
			. 'a join hint, not a fact; list_virtuemart_calc_rules flags those mismatches. '
			. 'This is read-only. The country and state tables are reference data shared with shipping '
			. 'and tax configuration.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'published'    => ['type' => 'boolean'],
				'search'       => ['type' => 'string', 'description' => 'Substring match on country_name, country_3_code or country_2_code.'],
				'worldzone_id' => ['type' => 'integer'],
				'limit'        => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'       => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->vmAdminBase() === null) {
			return $this->vmNotInstalledError();
		}

		if (!$this->vmTableExists('countries')) {
			return $this->vmMissingTableError('countries');
		}

		$limit  = $this->vmLimit($arguments);
		$offset = $this->vmOffset($arguments);
		$table  = $this->db->quoteName($this->vmTable('countries'));

		$where = [];

		if (\array_key_exists('published', $arguments)) {
			$where[] = $this->db->quoteName('published') . ' = ' . ((bool) $arguments['published'] ? 1 : 0);
		}

		if (\array_key_exists('worldzone_id', $arguments)) {
			$where[] = $this->db->quoteName('virtuemart_worldzone_id') . ' = ' . (int) $arguments['worldzone_id'];
		}

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$like    = $this->db->quote('%' . $this->db->escape($search, true) . '%', false);
			$where[] = '(' . implode(' OR ', [
				$this->db->quoteName('country_name') . ' LIKE ' . $like,
				$this->db->quoteName('country_3_code') . ' LIKE ' . $like,
				$this->db->quoteName('country_2_code') . ' LIKE ' . $like,
			]) . ')';
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		$total = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $table . $whereSql)->loadResult();

		$rows = $this->db->setQuery(
			'SELECT * FROM ' . $table . $whereSql
			. ' ORDER BY ' . $this->db->quoteName('published') . ' DESC, '
			. $this->db->quoteName('country_name') . ' ASC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$ids        = array_map(static fn (array $r): int => (int) $r['virtuemart_country_id'], $rows);
		$stateCount = $this->countBy('states', 'virtuemart_country_id', $ids);
		$calcCount  = $this->countBy('calc_countries', 'virtuemart_country_id', $ids);

		$out = [];

		foreach ($rows as $row) {
			$id = (int) $row['virtuemart_country_id'];

			$out[] = [
				'virtuemart_country_id'   => $id,
				'country_name'            => (string) $row['country_name'],
				'country_3_code'          => (string) $row['country_3_code'],
				'country_2_code'          => (string) $row['country_2_code'],
				'country_num_code'        => (string) $row['country_num_code'],
				'virtuemart_worldzone_id' => (int) $row['virtuemart_worldzone_id'],
				'published'               => (int) $row['published'] === 1,
				'ordering'                => (int) $row['ordering'],
				'state_count'             => $stateCount[$id] ?? 0,
				'calc_rules_restricted_to_it' => $calcCount[$id] ?? 0,
			];
		}

		$publishedTotal = (int) $this->db->setQuery(
			'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $this->db->quoteName('published') . ' = 1'
		)->loadResult();

		$allTotal = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $table)->loadResult();

		$response = [
			'ok'        => true,
			'total'     => $total,
			'limit'     => $limit,
			'offset'    => $offset,
			'showing'   => \count($out),
			'countries' => $out,
			'published_summary' => [
				'published' => $publishedTotal,
				'total'     => $allTotal,
			],
			'published_note' => 'published defaults to 0 on this table (install.sql:241) and VirtueMart '
				. 'ships most countries unpublished. An unpublished country does not appear in the '
				. 'checkout address dropdown, which is nearly always the whole explanation when customers '
				. 'in a given country cannot complete an order. This shop has ' . $publishedTotal
				. ' of ' . $allTotal . ' countries published.',
		];

		if ($publishedTotal === 0) {
			$response['warning'] = 'NO countries are published. No shopper can complete a checkout '
				. 'address anywhere in this shop.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/**
	 * @param  array<int,int> $ids
	 * @return array<int,int>
	 */
	private function countBy(string $table, string $column, array $ids): array
	{
		if ($ids === [] || !$this->vmTableExists($table)
			|| !\in_array($column, $this->vmColumns($table), true)) {
			return [];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName($column),
					'COUNT(*) AS ' . $this->db->quoteName('total'),
				])
				->from($this->db->quoteName($this->vmTable($table)))
				->whereIn($this->db->quoteName($column), $ids)
				->group($this->db->quoteName($column))
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$out[(int) $row[$column]] = (int) $row['total'];
		}

		return $out;
	}
}
