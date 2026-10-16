<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Store;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

final class ListCurrenciesTool extends AbstractTool
{
	use J2commerceBootTrait;

	public function getName(): string { return 'list_j2commerce_currencies'; }

	public function getDescription(): string
	{
		return 'List J2Commerce currencies: code, title, symbol and position, decimals and separators, exchange '
			. 'rate, enabled, last modified, and how many orders were placed in each. The store base currency '
			. '(config_currency) is marked; every other rate is relative to it, so the base must be enabled at '
			. 'exactly 1. Rates change only when someone edits them or the Currency Updater app runs (its '
			. 'scheduled task is skipped unless config_currency_auto is on); a rate\'s modified_on shows how '
			. 'stale it is, and a stale rate silently mis-prices everything shown in that currency. Prices are '
			. 'stored in the base currency; other currencies are display conversions, while each order records '
			. 'the currency and rate it was placed in. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => ['enabled_only' => ['type' => 'boolean']],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->j2Installed()) {
			return $this->j2NotInstalledError();
		}

		$this->j2RequireAccess($actor, 'j2commerce.viewsetup');

		$db     = $this->db;
		$base   = $this->j2StoreCurrency();
		$params = $this->j2RawParams();

		$counts = [];

		foreach ($db->setQuery(
			'SELECT currency_code, COUNT(*) AS n FROM ' . $db->quoteName($this->j2Table('orders')) . ' WHERE order_state_id <> 5 GROUP BY currency_code'
		)->loadAssocList() ?: [] as $r) {
			$counts[(string) $r['currency_code']] = (int) $r['n'];
		}

		$rows = $db->setQuery(
			'SELECT * FROM ' . $db->quoteName($this->j2Table('currencies'))
			. ((bool) ($arguments['enabled_only'] ?? false) ? ' WHERE enabled = 1' : '')
			. ' ORDER BY enabled DESC, ordering, currency_code'
		)->loadAssocList() ?: [];

		$out      = [];
		$warnings = [];

		foreach ($rows as $r) {
			$code   = (string) $r['currency_code'];
			$isBase = $code === $base;
			$entry  = [
				'currency_id' => (int) $r['j2commerce_currency_id'],
				'code'        => $code,
				'title'       => (string) $r['currency_title'],
				'symbol'      => (string) $r['currency_symbol'],
				'position'    => (string) $r['currency_position'],
				'decimals'    => (int) $r['currency_num_decimals'],
				'decimal_sep' => (string) $r['currency_decimal'],
				'thousands_sep' => (string) $r['currency_thousands'],
				'rate'        => (float) $r['currency_value'],
				'enabled'     => (int) $r['enabled'] === 1,
				'is_base'     => $isBase,
				'modified_on' => $r['modified_on'],
				'orders'      => $counts[$code] ?? 0,
			];

			if ($isBase && ((float) $r['currency_value'] !== 1.0 || (int) $r['enabled'] !== 1)) {
				$warnings[] = 'The base currency ' . $code . ' must be enabled at rate 1; it is '
					. ((int) $r['enabled'] === 1 ? 'enabled' : 'DISABLED') . ' at ' . $r['currency_value'] . '.';
			}

			if (!$isBase && (int) $r['enabled'] === 1 && (float) $r['currency_value'] === 1.0) {
				$entry['suspicious_rate'] = 'Rate is exactly 1 against ' . $base . ' — usually never set, so prices '
					. 'show the base figure with this currency\'s symbol.';
			}

			$out[] = $entry;
		}

		if (!\in_array($base, array_column($out, 'code'), true) && !(bool) ($arguments['enabled_only'] ?? false)) {
			$warnings[] = 'config_currency is ' . $base . ' but no currency row has that code.';
		}

		$response = [
			'ok'            => true,
			'base_currency' => $base,
			'auto_update'   => (string) ($params['config_currency_auto'] ?? '0') === '1',
			'currencies'    => $out,
		];

		if ($warnings !== []) {
			$response['warnings'] = $warnings;
		}

		return ToolResult::json($response);
	}
}
