<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Pricing;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * List the calculation rules that turn a base price into a sales price.
 *
 * Read-only, deliberately. A calc rule applies to every product matching its
 * category / manufacturer / shopper-group / country / state scope, so a
 * one-character mistake in `calc_value_mathop` re-prices an entire catalogue.
 * These rules also carry the shop's tax configuration, which is a compliance
 * matter rather than a content one.
 */
final class ListCalcRulesTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'list_virtuemart_calc_rules'; }

	public function getDescription(): string
	{
		return 'List the calculation rules in #__virtuemart_calcs — the tax, discount, margin and '
			. 'commission rules that turn a product\'s stored base price into the figure a shopper '
			. 'actually sees. '
			. 'This is READ-ONLY and stays that way. A calc rule applies to every product inside its '
			. 'scope, so a single wrong character in calc_value_mathop re-prices an entire catalogue, and '
			. 'these rules are also where a shop\'s VAT configuration lives — a compliance artefact, not '
			. 'content. Edit them in the VirtueMart admin. '
			. 'Filters: kind (Tax, VatTax, DBTax, DATax, Discount, DiscountBill, Marge, Commission, '
			. 'shipment, payment — VirtueMart treats calc_kind as free text, so the values present depend '
			. 'on the shop), published, and search on calc_name. '
			. 'Each rule reports its scope: the category, manufacturer, shopper-group, country and state '
			. 'restrictions held in the five #__virtuemart_calc_* xref tables. A rule with no rows in a '
			. 'given xref is unrestricted on that axis, which is the opposite of how the has_* columns '
			. 'read — those are join hints, so a rule whose has_countries says 0 will not have its '
			. 'country restriction applied even if rows exist. Mismatches are flagged. '
			. 'publish_up / publish_down schedule a rule. A rule outside its window is reported as '
			. 'inactive; VirtueMart will not apply it, which usually shows up as prices that suddenly '
			. 'lost their tax.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'kind'      => ['type' => 'string', 'description' => 'Filter on calc_kind, e.g. "VatTax" or "Discount". Free text in VirtueMart, so exact match.'],
				'published' => ['type' => 'boolean'],
				'search'    => ['type' => 'string', 'description' => 'Case-insensitive substring match on calc_name.'],
				'limit'     => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'    => ['type' => 'integer'],
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

		if (!$this->vmTableExists('calcs')) {
			return $this->vmMissingTableError('calcs');
		}

		$limit  = $this->vmLimit($arguments);
		$offset = $this->vmOffset($arguments);

		$query = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName($this->vmTable('calcs')))
			->order($this->db->quoteName('ordering') . ' ASC, '
				. $this->db->quoteName('virtuemart_calc_id') . ' ASC');

		$count = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName($this->vmTable('calcs')));

		if (($kind = trim((string) ($arguments['kind'] ?? ''))) !== '') {
			$clause = $this->db->quoteName('calc_kind') . ' = ' . $this->db->quote($kind);
			$query->where($clause);
			$count->where($clause);
		}

		if (\array_key_exists('published', $arguments)) {
			$clause = $this->db->quoteName('published') . ' = ' . ((bool) $arguments['published'] ? 1 : 0);
			$query->where($clause);
			$count->where($clause);
		}

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$clause = $this->db->quoteName('calc_name') . ' LIKE '
				. $this->db->quote('%' . $this->db->escape($search, true) . '%', false);
			$query->where($clause);
			$count->where($clause);
		}

		$total = (int) $this->db->setQuery($count)->loadResult();
		$rows  = $this->db->setQuery($query, $offset, $limit)->loadAssocList() ?: [];

		$scopeMap = [
			'has_categories'    => ['calc_categories', 'virtuemart_category_id'],
			'has_shoppergroups' => ['calc_shoppergroups', 'virtuemart_shoppergroup_id'],
			'has_manufacturers' => ['calc_manufacturers', 'virtuemart_manufacturer_id'],
			'has_countries'     => ['calc_countries', 'virtuemart_country_id'],
			'has_states'        => ['calc_states', 'virtuemart_state_id'],
		];

		$now  = time();
		$out  = [];

		foreach ($rows as $row) {
			$id = (int) $row['virtuemart_calc_id'];

			$entry = [
				'virtuemart_calc_id'     => $id,
				'calc_name'              => (string) $row['calc_name'],
				'calc_descr'             => (string) $row['calc_descr'],
				'calc_kind'              => (string) $row['calc_kind'],
				'calc_value_mathop'      => (string) $row['calc_value_mathop'],
				'calc_value'             => $row['calc_value'],
				'calc_currency'          => (int) $row['calc_currency'],
				'published'              => (int) $row['published'] === 1,
				'ordering'               => (int) $row['ordering'],
				'shared'                 => (int) $row['shared'] === 1,
				'for_override'           => (int) $row['for_override'] === 1,
				'calc_shopper_published' => (int) $row['calc_shopper_published'] === 1,
				'publish_up'             => $row['publish_up'],
				'publish_down'           => $row['publish_down'],
				'calc_params'            => (string) $row['calc_params'],
			];

			$scope     = [];
			$mismatches = [];

			foreach ($scopeMap as $flag => [$table, $column]) {
				if (!$this->vmTableExists($table)) {
					continue;
				}

				$ids = array_map('intval', $this->db->setQuery(
					$this->db->getQuery(true)
						->select($this->db->quoteName($column))
						->from($this->db->quoteName($this->vmTable($table)))
						->where($this->db->quoteName('virtuemart_calc_id') . ' = ' . $id)
				)->loadColumn() ?: []);

				$scope[$column] = $ids === [] ? 'unrestricted' : $ids;

				$claims = (int) ($row[$flag] ?? 0) === 1;

				if ($claims !== ($ids !== [])) {
					$mismatches[] = $flag . ' is ' . (int) ($row[$flag] ?? 0) . ' but #__virtuemart_'
						. $table . ' holds ' . \count($ids) . ' row(s). These are join hints: when the '
						. 'flag reads 0 VirtueMart skips the restriction entirely, so the rule applies '
						. 'more widely than the data says it should.';
				}
			}

			$entry['scope'] = $scope;

			if ($mismatches !== []) {
				$entry['flag_mismatch'] = $mismatches;
			}

			$up   = $this->timestamp((string) ($row['publish_up'] ?? ''));
			$down = $this->timestamp((string) ($row['publish_down'] ?? ''));

			if ($down !== null && $down < $now) {
				$entry['inactive'] = 'publish_down is in the past, so VirtueMart is not applying this '
					. 'rule. A tax rule in this state shows up as prices that quietly lost their tax.';
			} elseif ($up !== null && $up > $now) {
				$entry['inactive'] = 'publish_up is in the future, so this rule is not applying yet.';
			}

			$out[] = $entry;
		}

		return ToolResult::json([
			'ok'      => true,
			'total'   => $total,
			'limit'   => $limit,
			'offset'  => $offset,
			'showing' => \count($out),
			'rules'   => $out,
			'read_only_note' => 'There is no setter for calc rules in this add-on. Each rule applies to '
				. 'every product in its scope, so an error here re-prices a whole catalogue, and these '
				. 'rows carry the shop\'s tax configuration.',
			'application_note' => 'These rules are applied at DISPLAY time by calculationHelper '
				. '(helpers/calculationh.php:404-498) on top of the base price in '
				. '#__virtuemart_product_prices. Nothing precomputes or caches the result, so changing a '
				. 'rule changes every affected price immediately.',
			'rounding_note' => 'calculationHelper rounds internally to 9 digits by default '
				. '(helpers/calculationh.php:59, :1887), while the columns that store money are '
				. 'decimal(15,6), (15,5) and (12,2) depending on which one. Do not reconcile figures '
				. 'across them to the last decimal place.',
			'component' => $this->vmComponentNotice(),
		]);
	}

	private function timestamp(string $value): ?int
	{
		if ($value === '' || str_starts_with($value, '0000-00-00')) {
			return null;
		}

		$time = strtotime($value);

		return $time === false ? null : $time;
	}
}
