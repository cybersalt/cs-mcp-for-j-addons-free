<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Store;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * States and provinces, which unlike countries default to published.
 *
 * The asymmetry is worth knowing: `#__virtuemart_states.published` is `DEFAULT
 * '1'` (`install.sql:1169`) while `#__virtuemart_countries.published` is
 * `DEFAULT '0'` (`install.sql:241`). So a state can be published under a
 * country that is not, and then it appears nowhere.
 */
final class ListStatesTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'list_virtuemart_states'; }

	public function getDescription(): string
	{
		return 'List states, provinces and regions from #__virtuemart_states, optionally for one country. '
			. 'Note the asymmetry with countries: states default to published = 1 (install.sql:1169) '
			. 'while countries default to published = 0 (install.sql:241). A published state under an '
			. 'unpublished country therefore appears nowhere, because the country never reaches the '
			. 'checkout dropdown in the first place. Rows in that situation are flagged with '
			. 'country_unpublished: true. '
			. 'Filters: country_id, published, search (on state_name, state_3_code or state_2_code). '
			. 'Supports limit (default 50, max 200) and offset. '
			. 'state_3_code and state_2_code are unique per (vendor, country) rather than globally '
			. '(install.sql:1183-1184), so the same code can legitimately exist under two countries. '
			. 'Each row reports how many calc rules are restricted to it. Tax rules scoped by state are '
			. 'how US sales tax and similar regimes are usually configured, so a state that has been '
			. 'unpublished or deleted takes its tax rule out of effect with it. '
			. 'This is read-only. Country and state data is reference data shared with shipping and tax '
			. 'configuration.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'country_id' => ['type' => 'integer', 'description' => 'virtuemart_country_id. Strongly recommended — a shop can hold thousands of states.'],
				'published'  => ['type' => 'boolean'],
				'search'     => ['type' => 'string', 'description' => 'Substring match on state_name, state_3_code or state_2_code.'],
				'limit'      => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'     => ['type' => 'integer'],
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

		if (!$this->vmTableExists('states')) {
			return $this->vmMissingTableError('states');
		}

		$limit  = $this->vmLimit($arguments);
		$offset = $this->vmOffset($arguments);
		$table  = $this->db->quoteName($this->vmTable('states'));

		$where = [];

		if (\array_key_exists('country_id', $arguments)) {
			$where[] = $this->db->quoteName('virtuemart_country_id') . ' = ' . (int) $arguments['country_id'];
		}

		if (\array_key_exists('published', $arguments)) {
			$where[] = $this->db->quoteName('published') . ' = ' . ((bool) $arguments['published'] ? 1 : 0);
		}

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$like    = $this->db->quote('%' . $this->db->escape($search, true) . '%', false);
			$where[] = '(' . implode(' OR ', [
				$this->db->quoteName('state_name') . ' LIKE ' . $like,
				$this->db->quoteName('state_3_code') . ' LIKE ' . $like,
				$this->db->quoteName('state_2_code') . ' LIKE ' . $like,
			]) . ')';
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		$total = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $table . $whereSql)->loadResult();

		$rows = $this->db->setQuery(
			'SELECT * FROM ' . $table . $whereSql
			. ' ORDER BY ' . $this->db->quoteName('virtuemart_country_id') . ' ASC, '
			. $this->db->quoteName('state_name') . ' ASC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$countryIds = array_values(array_unique(array_map(
			static fn (array $r): int => (int) $r['virtuemart_country_id'],
			$rows
		)));

		$countries = [];

		if ($countryIds !== [] && $this->vmTableExists('countries')) {
			$countryRows = $this->db->setQuery(
				$this->db->getQuery(true)
					->select([
						$this->db->quoteName('virtuemart_country_id'),
						$this->db->quoteName('country_name'),
						$this->db->quoteName('published'),
					])
					->from($this->db->quoteName($this->vmTable('countries')))
					->whereIn($this->db->quoteName('virtuemart_country_id'), $countryIds)
			)->loadAssocList() ?: [];

			foreach ($countryRows as $row) {
				$countries[(int) $row['virtuemart_country_id']] = [
					'name'      => (string) $row['country_name'],
					'published' => (int) $row['published'] === 1,
				];
			}
		}

		$stateIds  = array_map(static fn (array $r): int => (int) $r['virtuemart_state_id'], $rows);
		$calcCount = $this->calcCounts($stateIds);

		$out            = [];
		$hiddenByParent = 0;

		foreach ($rows as $row) {
			$id        = (int) $row['virtuemart_state_id'];
			$countryId = (int) $row['virtuemart_country_id'];

			$entry = [
				'virtuemart_state_id'   => $id,
				'state_name'            => (string) $row['state_name'],
				'state_3_code'          => (string) $row['state_3_code'],
				'state_2_code'          => (string) $row['state_2_code'],
				'virtuemart_country_id' => $countryId,
				'country_name'          => $countries[$countryId]['name'] ?? null,
				'virtuemart_worldzone_id' => (int) $row['virtuemart_worldzone_id'],
				'published'             => (int) $row['published'] === 1,
				'ordering'              => (int) $row['ordering'],
				'calc_rules_restricted_to_it' => $calcCount[$id] ?? 0,
			];

			if ((int) $row['published'] === 1 && isset($countries[$countryId]) && !$countries[$countryId]['published']) {
				$hiddenByParent++;
				$entry['country_unpublished'] = true;
				$entry['country_unpublished_note'] = 'This state is published but its country is not, so '
					. 'it appears nowhere in the shop — the country never reaches the checkout dropdown. '
					. 'The defaults differ: states default to published = 1 (install.sql:1169), countries '
					. 'to 0 (install.sql:241).';
			}

			if (!isset($countries[$countryId]) && $countryId > 0) {
				$entry['country_missing'] = 'virtuemart_country_id ' . $countryId . ' has no row in '
					. '#__virtuemart_countries. VirtueMart has no foreign keys, so this dangling '
					. 'reference is legal and simply never resolves.';
			}

			$out[] = $entry;
		}

		$response = [
			'ok'      => true,
			'total'   => $total,
			'limit'   => $limit,
			'offset'  => $offset,
			'showing' => \count($out),
			'states'  => $out,
			'code_note' => 'state_3_code and state_2_code are unique per (vendor, country), not globally '
				. '(install.sql:1183-1184), so the same code under two countries is legitimate.',
		];

		if ($hiddenByParent > 0) {
			$response['published_under_unpublished_country'] = $hiddenByParent;
		}

		if (!\array_key_exists('country_id', $arguments)) {
			$response['scope_note'] = 'No country_id filter was given. A shop can hold several thousand '
				. 'state rows, so pass country_id unless you deliberately want everything.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/**
	 * @param  array<int,int> $ids
	 * @return array<int,int>
	 */
	private function calcCounts(array $ids): array
	{
		if ($ids === [] || !$this->vmTableExists('calc_states')) {
			return [];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('virtuemart_state_id'),
					'COUNT(*) AS ' . $this->db->quoteName('total'),
				])
				->from($this->db->quoteName($this->vmTable('calc_states')))
				->whereIn($this->db->quoteName('virtuemart_state_id'), $ids)
				->group($this->db->quoteName('virtuemart_state_id'))
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$out[(int) $row['virtuemart_state_id']] = (int) $row['total'];
		}

		return $out;
	}
}
