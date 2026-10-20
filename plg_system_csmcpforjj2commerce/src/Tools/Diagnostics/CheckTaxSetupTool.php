<?php
/**
 * @package     MCP for J2Commerce
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

/**
 * Walk the tax chain end to end and report every break that makes J2Commerce
 * charge zero tax without saying so.
 *
 * The chain (`src/Helper/TaxHelper.php:159-366`):
 *
 *   product.taxprofile_id
 *     -> taxrules (taxprofile_id, taxrate_id)
 *       -> taxrates.geozone_id
 *         -> geozonerules (country_id, zone_id; zone 0 = whole country)
 *           -> matched against the customer's billing or shipping address
 *              (config_tax_default), falling back to the STORE address unless
 *              config_tax_default_address = noaddress (src/Helper/CartOrder.php:955-961)
 *
 * Any missing link yields a rate list of zero length, which sums to zero tax.
 * Nothing logs it.
 */
final class CheckTaxSetupTool extends AbstractTool
{
	use J2commerceBootTrait;

	public function getName(): string { return 'check_j2commerce_tax_setup'; }

	public function getDescription(): string
	{
		return 'Audit the J2Commerce tax configuration for every break that makes the shop silently charge '
			. 'ZERO tax. Read-only. '
			. 'J2Commerce resolves tax as product.taxprofile_id -> tax rules -> tax rates -> each rate\'s '
			. 'geozone -> geozone rules (country, zone; zone 0 means the whole country) -> matched against '
			. 'the customer\'s billing or shipping address, falling back to the store address. Any missing '
			. 'link produces an empty rate list, which sums to zero, and nothing warns. '
			. 'Reports: every tax profile with its rules, rates, geozones and the countries/zones each covers; '
			. 'profiles with no rules; rates whose geozone is missing or has no rules; geozones with no rules '
			. 'at all; enabled products with no tax profile, or pointing at a profile that does not exist; '
			. 'the default tax profile setting; whether the store country (the fallback address) is set; and '
			. 'the tax-mode settings (prices include tax, billing vs shipping address, store vs no-address '
			. 'fallback). '
			. 'Two vendor behaviours worth knowing that this surfaces rather than fixes: TaxHelper IGNORES '
			. 'the enabled flag on profiles, rates and geozones, so disabling a rate does not stop it being '
			. 'charged; and the catalogue price display uses the FIRST rule\'s percentage and ignores '
			. 'geozones (src/Helper/ProductHelper.php:4524-4547), so a product page can show a different tax '
			. 'than checkout charges. There is deliberately no tax WRITE tool: geozone and tax-profile saves '
			. 'in J2Commerce delete every rule and reinsert with no transaction, and a partial save wipes '
			. 'the rules. Make tax changes in the J2Commerce admin.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->j2Installed()) {
			return $this->j2NotInstalledError();
		}

		$this->j2RequireAccess($actor, 'j2commerce.viewsetup');

		foreach (['taxprofiles', 'taxrules', 'taxrates', 'geozones', 'geozonerules'] as $t) {
			if (!$this->j2TableExists($t)) {
				return $this->j2MissingTableError($t);
			}
		}

		$db = $this->db;

		$profiles = $db->setQuery(
			'SELECT * FROM ' . $db->quoteName($this->j2Table('taxprofiles')) . ' ORDER BY ordering, j2commerce_taxprofile_id'
		)->loadAssocList('j2commerce_taxprofile_id') ?: [];

		$rules = $db->setQuery(
			'SELECT * FROM ' . $db->quoteName($this->j2Table('taxrules')) . ' ORDER BY ordering'
		)->loadAssocList() ?: [];

		$rates = $db->setQuery(
			'SELECT * FROM ' . $db->quoteName($this->j2Table('taxrates'))
		)->loadAssocList('j2commerce_taxrate_id') ?: [];

		$geozones = $db->setQuery(
			'SELECT * FROM ' . $db->quoteName($this->j2Table('geozones'))
		)->loadAssocList('j2commerce_geozone_id') ?: [];

		$geoRules = $db->setQuery(
			'SELECT r.geozone_id, r.country_id, r.zone_id, c.country_isocode_2, c.country_name, z.zone_code, z.zone_name'
			. ' FROM ' . $db->quoteName($this->j2Table('geozonerules')) . ' r'
			. ' LEFT JOIN ' . $db->quoteName($this->j2Table('countries')) . ' c ON c.j2commerce_country_id = r.country_id'
			. ' LEFT JOIN ' . $db->quoteName($this->j2Table('zones')) . ' z ON z.j2commerce_zone_id = r.zone_id'
		)->loadAssocList() ?: [];

		$coverage = [];

		foreach ($geoRules as $r) {
			$coverage[(int) $r['geozone_id']][] = (string) ($r['country_isocode_2'] ?? ('#' . $r['country_id']))
				. ((int) $r['zone_id'] === 0 ? ' (whole country)' : ' / ' . (string) ($r['zone_code'] ?: $r['zone_name'] ?: '#' . $r['zone_id']));
		}

		$findings = [];
		$out      = [];

		$rulesByProfile = [];

		foreach ($rules as $rule) {
			$rulesByProfile[(int) $rule['taxprofile_id']][] = $rule;
		}

		foreach ($profiles as $pid => $profile) {
			$pid      = (int) $pid;
			$entry    = [
				'taxprofile_id' => $pid,
				'name'          => (string) $profile['taxprofile_name'],
				'enabled'       => (int) $profile['enabled'] === 1,
				'rates'         => [],
			];
			$problems = [];

			foreach ($rulesByProfile[$pid] ?? [] as $rule) {
				$rid  = (int) $rule['taxrate_id'];
				$rate = $rates[$rid] ?? null;

				if ($rate === null) {
					$problems[] = 'tax rule ' . (int) $rule['j2commerce_taxrule_id'] . ' points at tax rate ' . $rid . ', which does not exist';
					continue;
				}

				$gid = (int) $rate['geozone_id'];
				$geo = $geozones[$gid] ?? null;

				$rateEntry = [
					'taxrate_id'  => $rid,
					'name'        => (string) $rate['taxrate_name'],
					'tax_percent' => (float) $rate['tax_percent'],
					'enabled'     => (int) $rate['enabled'] === 1,
					'geozone_id'  => $gid,
					'geozone'     => $geo !== null ? (string) $geo['geozone_name'] : null,
					'covers'      => $coverage[$gid] ?? [],
					'address'     => (string) ($rule['address'] ?? ''),
				];

				if ($geo === null) {
					$problems[] = 'rate "' . $rate['taxrate_name'] . '" belongs to geozone ' . $gid . ', which does not exist, so it never matches';
				} elseif (empty($coverage[$gid])) {
					$problems[] = 'rate "' . $rate['taxrate_name'] . '" belongs to geozone "' . $geo['geozone_name'] . '", which has NO rules, so it never matches any address';
				}

				if ((int) $rate['enabled'] !== 1) {
					$rateEntry['note'] = 'Disabled — but TaxHelper ignores the enabled flag, so it is still charged.';
				}

				$entry['rates'][] = $rateEntry;
			}

			if (empty($rulesByProfile[$pid])) {
				$problems[] = 'this profile has no tax rules, so every product on it is charged zero tax';
			}

			if ($problems !== []) {
				$entry['problems'] = $problems;

				foreach ($problems as $text) {
					$findings[] = 'Tax profile "' . $profile['taxprofile_name'] . '" (' . $pid . '): ' . $text . '.';
				}
			}

			$out[] = $entry;
		}

		// Geozones with no rules, whether or not a rate uses them.
		$emptyGeozones = [];

		foreach ($geozones as $gid => $geo) {
			if (empty($coverage[(int) $gid])) {
				$emptyGeozones[] = ['geozone_id' => (int) $gid, 'name' => (string) $geo['geozone_name']];
			}
		}

		// Products.
		$products = $db->quoteName($this->j2Table('products'));
		$profileTable = $db->quoteName($this->j2Table('taxprofiles'));

		$noProfile = (int) $db->setQuery(
			'SELECT COUNT(*) FROM ' . $products . ' WHERE enabled = 1 AND (taxprofile_id IS NULL OR taxprofile_id = 0)'
		)->loadResult();

		$badProfile = (int) $db->setQuery(
			'SELECT COUNT(*) FROM ' . $products . ' p LEFT JOIN ' . $profileTable
			. ' t ON t.j2commerce_taxprofile_id = p.taxprofile_id WHERE p.taxprofile_id > 0 AND t.j2commerce_taxprofile_id IS NULL'
		)->loadResult();

		$enabledProducts = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $products . ' WHERE enabled = 1')->loadResult();

		if ($profiles !== [] && $noProfile > 0) {
			$findings[] = $noProfile . ' of ' . $enabledProducts . ' enabled product(s) have no tax profile and are '
				. 'charged zero tax. Set one with update_j2commerce_product taxprofile_id. If that is intended '
				. '(tax-exempt goods), ignore this.';
		}

		if ($badProfile > 0) {
			$findings[] = $badProfile . ' product(s) point at a tax profile that does not exist, and are charged zero tax.';
		}

		$params  = $this->j2RawParams();
		$country = (int) ($params['country_id'] ?? 0);
		$fallback = (string) ($params['config_tax_default_address'] ?? 'store');

		if ($country === 0 && $fallback !== 'noaddress') {
			$findings[] = 'The store country is not set. When the customer\'s address matches no geozone, '
				. 'J2Commerce falls back to the store address; with no store country that fallback matches '
				. 'nothing. Set country_id with set_j2commerce_config.';
		}

		if ($profiles === []) {
			$findings[] = 'No tax profiles exist, so this shop charges no tax at all. That is correct only if '
				. 'nothing it sells is taxable.';
		}

		$defaultProfile = (int) ($params['default_taxprofile_id'] ?? 0);

		if ($defaultProfile > 0 && !isset($profiles[$defaultProfile])) {
			$findings[] = 'default_taxprofile_id is ' . $defaultProfile . ', which does not exist. New products '
				. 'that inherit it get no tax.';
		}

		return ToolResult::json([
			'ok'       => true,
			'findings' => $findings,
			'settings' => [
				'prices_include_tax'        => (string) ($params['config_including_tax'] ?? '0') === '1',
				'tax_address'               => (string) ($params['config_tax_default'] ?? 'billing'),
				'fallback_when_no_match'    => $fallback,
				'default_taxprofile_id'     => $defaultProfile,
				'store_country_id'          => $country,
				'store_zone_id'             => (int) ($params['zone_id'] ?? 0),
			],
			'tax_profiles'   => $out,
			'empty_geozones' => $emptyGeozones,
			'products'       => [
				'enabled'                => $enabledProducts,
				'enabled_without_profile' => $noProfile,
				'missing_profile'        => $badProfile,
			],
			'vendor_caveats' => [
				'TaxHelper ignores the enabled flag on tax profiles, tax rates and geozones; disabling one does not stop it being charged.',
				'The catalogue price display uses the first rule\'s percentage and ignores geozones (src/Helper/ProductHelper.php:4524-4547); product pages can show a different tax than checkout.',
				'taxrules.address is stored but not consulted by TaxHelper.',
			],
		]);
	}
}
