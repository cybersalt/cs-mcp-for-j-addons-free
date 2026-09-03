<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Store;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Currencies, and which one every price row is actually denominated in.
 *
 * `#__virtuemart_product_prices.product_currency` is a `smallint` FK into this
 * table with nothing enforcing it, so the useful part of this listing is the
 * cross-reference: how many price rows point at each currency, and how many
 * point at an id that does not exist.
 */
final class ListCurrenciesTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'list_virtuemart_currencies'; }

	public function getDescription(): string
	{
		return 'List currencies from #__virtuemart_currencies, with the vendor currency identified and '
			. 'the number of product price rows denominated in each one. '
			. 'Use this to find the virtuemart_currency_id that set_virtuemart_product_price and '
			. 'create_virtuemart_product need. It is a numeric id, not the ISO code — product_currency on '
			. 'a price row is a smallint FK into this table, and VirtueMart declares no foreign key '
			. 'behind it, so an id that does not exist stores cleanly and simply never resolves. Price '
			. 'rows pointing at a missing currency are counted and reported. '
			. 'currency_exchange_rate is decimal(12,5) and is what VirtueMart multiplies by when '
			. 'converting; it is maintained either by hand or by a currency-converter plugin, and a stale '
			. 'rate silently mis-prices everything in that currency. The rate is shown as stored with no '
			. 'attempt to judge whether it is current. '
			. 'currency_code_3 carries a UNIQUE KEY (install.sql:329) but currency_code_2 does not. '
			. 'currency_decimal_place, currency_decimal_symbol, currency_thousands and the positive / '
			. 'negative style strings control formatting only; they have no effect on stored values. '
			. 'This is read-only. Currency configuration is shop infrastructure, and an incorrect '
			. 'exchange rate re-prices a whole catalogue without touching a single product.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'published' => ['type' => 'boolean'],
				'search'    => ['type' => 'string', 'description' => 'Substring match on currency_name, currency_code_3 or currency_code_2.'],
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

		if (!$this->vmTableExists('currencies')) {
			return $this->vmMissingTableError('currencies');
		}

		$limit  = $this->vmLimit($arguments);
		$offset = $this->vmOffset($arguments);
		$table  = $this->db->quoteName($this->vmTable('currencies'));

		$where = [];

		if (\array_key_exists('published', $arguments)) {
			$where[] = $this->db->quoteName('published') . ' = ' . ((bool) $arguments['published'] ? 1 : 0);
		}

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$like    = $this->db->quote('%' . $this->db->escape($search, true) . '%', false);
			$where[] = '(' . implode(' OR ', [
				$this->db->quoteName('currency_name') . ' LIKE ' . $like,
				$this->db->quoteName('currency_code_3') . ' LIKE ' . $like,
				$this->db->quoteName('currency_code_2') . ' LIKE ' . $like,
			]) . ')';
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		$total = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $table . $whereSql)->loadResult();

		$rows = $this->db->setQuery(
			'SELECT * FROM ' . $table . $whereSql
			. ' ORDER BY ' . $this->db->quoteName('ordering') . ' ASC, '
			. $this->db->quoteName('currency_code_3') . ' ASC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$priceCounts   = $this->priceCounts();
		$vendorRows    = $this->vmTableExists('vendors')
			? $this->db->setQuery(
				$this->db->getQuery(true)
					->select([
						$this->db->quoteName('virtuemart_vendor_id'),
						$this->db->quoteName('vendor_currency'),
					])
					->from($this->db->quoteName($this->vmTable('vendors')))
			)->loadAssocList() ?: []
			: [];

		$vendorCurrencies = [];

		foreach ($vendorRows as $row) {
			$vendorCurrencies[(int) $row['vendor_currency']][] = (int) $row['virtuemart_vendor_id'];
		}

		$out = [];

		foreach ($rows as $row) {
			$id = (int) $row['virtuemart_currency_id'];

			$entry = [
				'virtuemart_currency_id'  => $id,
				'currency_name'           => (string) $row['currency_name'],
				'currency_code_3'         => (string) $row['currency_code_3'],
				'currency_code_2'         => (string) $row['currency_code_2'],
				'currency_symbol'         => (string) $row['currency_symbol'],
				'currency_exchange_rate'  => $row['currency_exchange_rate'],
				'currency_decimal_place'  => (string) $row['currency_decimal_place'],
				'currency_decimal_symbol' => (string) $row['currency_decimal_symbol'],
				'currency_thousands'      => (string) $row['currency_thousands'],
				'published'               => (int) $row['published'] === 1,
				'shared'                  => (int) $row['shared'] === 1,
				'ordering'                => (int) $row['ordering'],
				'price_row_count'         => $priceCounts[$id] ?? 0,
			];

			if (isset($vendorCurrencies[$id])) {
				$entry['is_vendor_currency_for'] = $vendorCurrencies[$id];
			}

			if ((int) $row['published'] !== 1 && ($priceCounts[$id] ?? 0) > 0) {
				$entry['warning'] = 'This currency is UNPUBLISHED but ' . $priceCounts[$id]
					. ' price row(s) are denominated in it. Those prices cannot be converted or '
					. 'displayed correctly.';
			}

			$out[] = $entry;
		}

		$known    = array_map('intval', $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('virtuemart_currency_id'))
				->from($this->db->quoteName($this->vmTable('currencies')))
		)->loadColumn() ?: []);

		$dangling = [];

		foreach ($priceCounts as $currencyId => $count) {
			if ($currencyId > 0 && !\in_array($currencyId, $known, true)) {
				$dangling[] = ['product_currency' => $currencyId, 'price_rows' => $count];
			}
		}

		$response = [
			'ok'         => true,
			'total'      => $total,
			'limit'      => $limit,
			'offset'     => $offset,
			'showing'    => \count($out),
			'currencies' => $out,
			'id_note'    => 'product_currency on a price row is this table\'s numeric '
				. 'virtuemart_currency_id, NOT the ISO code. That is the value '
				. 'set_virtuemart_product_price expects.',
			'rate_note'  => 'currency_exchange_rate is shown exactly as stored. VirtueMart does not '
				. 'refresh it on its own — a currency-converter plugin or a human does — and a stale rate '
				. 'silently mis-prices everything denominated in that currency.',
		];

		if ($dangling !== []) {
			$response['dangling_currency_references'] = $dangling;
			$response['dangling_note'] = 'Price rows point at currency ids that do not exist. VirtueMart '
				. 'declares no foreign keys, so these stored cleanly and simply never resolve.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/** @return array<int,int> */
	private function priceCounts(): array
	{
		if (!$this->vmTableExists('product_prices')) {
			return [];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('product_currency'),
					'COUNT(*) AS ' . $this->db->quoteName('total'),
				])
				->from($this->db->quoteName($this->vmTable('product_prices')))
				->group($this->db->quoteName('product_currency'))
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$out[(int) $row['product_currency']] = (int) $row['total'];
		}

		return $out;
	}
}
