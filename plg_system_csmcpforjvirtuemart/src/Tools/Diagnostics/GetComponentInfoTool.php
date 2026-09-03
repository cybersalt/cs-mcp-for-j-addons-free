<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Orientation: version, edition, languages, table inventory, and the Joomla 6
 * compatibility verdict.
 *
 * The J6 block is the substantial part. VirtueMart 4.x has zero version gating
 * for legacy classes and hard-fatals at the first line of its own bootstrap
 * without them, so whether the compat plugin is enabled AND has the right
 * parameter set is a load-bearing fact about the site. It is REPORTED here, not
 * enforced: every tool in this add-on except set_virtuemart_order_status works
 * directly against the database and needs none of VirtueMart's PHP.
 */
final class GetComponentInfoTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'get_virtuemart_component_info'; }

	public function getDescription(): string
	{
		return 'Orientation for a VirtueMart shop: installed version, edition, the shop\'s language '
			. 'configuration, its table inventory, and a full Joomla 6 compatibility verdict. Call this '
			. 'first on an unfamiliar site. '
			. 'EDITION: VirtueMart is GPL with a single free edition. There is no Pro build, no licence '
			. 'key and no vendor feature gate for this add-on to honour or route around — unlike several '
			. 'other MCP add-ons in this family. Everything here works on any VirtueMart install. '
			. 'LANGUAGES: reports the shop\'s vmDefLang, its active_languages list, and the language '
			. 'suffix each one resolves to. That suffix is the whole mechanic behind VirtueMart\'s '
			. 'satellite tables and is derived at runtime, never hardcoded. '
			. 'TABLES: counts #__virtuemart_* tables. VirtueMart\'s own installed-check calls the install '
			. 'broken below 55 (models/config.php:604-622). Note install.sql defines only 53 — '
			. '#__virtuemart_configs, #__virtuemart_userinfos and #__virtuemart_order_userinfos are '
			. 'created at runtime and are not in it — so an inventory built from install.sql is always '
			. 'wrong. '
			. 'JOOMLA 6: VirtueMart 4.x has ZERO version gating for legacy classes and ships no shim. Its '
			. 'bootstrap calls JFactory at helpers/config.php:367 before anything else, '
			. 'plugins/vmplugin.php:24 is "abstract class vmPlugin extends JPlugin", and there are 816 '
			. 'JFactory:: calls across the package. Without the Backward Compatibility plugin\'s class '
			. 'aliases every VirtueMart request is fatal — including its own installer. Detection is a '
			. 'TWO-PART check and the trap is that the two plugins have opposite defaults: '
			. 'plg_behaviour_compat defaults classes_aliases to "1", plg_behaviour_compat6 defaults it to '
			. '"0", so an empty params blob means ON for one and OFF for the other. '
			. 'And the compat plugin alone is NOT SUFFICIENT on Joomla 6. helpers/vmdefines.php:165 uses '
			. 'JPATH_PLATFORM, which Joomla 6 removed from core; compat6 redefines it as its own '
			. 'directory rather than <root>/libraries, so VMPATH_LIBS points at the plugin folder and '
			. 'TCPDF invoice generation fails with COM_VIRTUEMART_TCPDF_NINSTALLED even though the rest '
			. 'of the shop works. That is reported here. '
			. 'This add-on REPORTS all of it and gates nothing on it.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => new \stdClass(),
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$base = $this->vmAdminBase();

		if ($base === null) {
			return $this->vmNotInstalledError();
		}

		$version = $this->vmVersion();
		$tags    = $this->vmActiveLangTags();

		$languages = [];

		foreach ($tags as $tag) {
			$languages[$tag] = [
				'suffix'            => $this->vmLangSuffix($tag),
				'products_table'    => $this->vmLangTable('products', $tag),
				'tables_present'    => [],
			];

			foreach (array_keys($this->vmTranslatableTables()) as $translatable) {
				$languages[$tag]['tables_present'][$translatable] = $this->vmLangTableExists($translatable, $tag);
			}
		}

		$tables = $this->vmAllTables();

		$counts = [];

		foreach (['products', 'categories', 'manufacturers', 'orders', 'order_items', 'vmusers', 'customs', 'calcs', 'currencies', 'vendors'] as $table) {
			if ($this->vmTableExists($table)) {
				$counts[$table] = (int) $this->db->setQuery(
					'SELECT COUNT(*) FROM ' . $this->db->quoteName($this->vmTable($table))
				)->loadResult();
			}
		}

		$response = [
			'ok'        => true,
			'component' => [
				'name'         => 'VirtueMart',
				'element'      => 'com_virtuemart',
				'version'      => $version,
				'admin_path'   => $base,
				'site_path'    => $this->vmSiteBase(),
				'edition'      => 'GPL, single free edition',
				'edition_note' => 'VirtueMart has no Pro build, no licence key and no feature gate. This '
					. 'add-on is free for the same reason: there is no vendor paywall to honour and '
					. 'nothing to unlock.',
			],
			'languages' => [
				'joomla_site_default'   => $this->vmDefaultLangTag(),
				'virtuemart_active'     => $tags,
				'per_language'          => $languages,
				'suffix_rule'           => 'strtolower(strtr($tag, "-", "_")) — en-GB becomes en_gb. '
					. 'Applied identically at helpers/vmlanguage.php:64 and :156, '
					. 'helpers/tableupdater.php:75 and :206, and helpers/vmtable.php:377-380.',
				'translatable_tables'   => array_keys($this->vmTranslatableTables()),
				'why_it_matters'        => 'Everything a human recognises about a product, category or '
					. 'manufacturer lives in these satellite tables, and the read join is INNER '
					. '(helpers/vmtable.php:1065-1068). A base row with no satellite row is invisible, '
					. 'not untranslated. Run check_virtuemart_language_tables.',
			],
			'tables'    => [
				'count'    => \count($tables),
				'expected' => 'VirtueMart calls its own install broken below 55 tables '
					. '(models/config.php:604-622). install.sql defines only 53 — #__virtuemart_configs '
					. '(models/config.php:640-651), #__virtuemart_userinfos and '
					. '#__virtuemart_order_userinfos (install_essential_data.sql:78 and :119) are created '
					. 'at runtime — plus one satellite table per translatable table per active language.',
				'names'    => $tables,
			],
			'row_counts' => $counts,
			'joomla_compat' => $this->vmCompatStatus(),
		];

		if ($version !== null && version_compare($version, '4.8.0', '<')) {
			$response['security_warning'] = $this->securityWarning($version);
		}

		$multix = (string) $this->vmConfigGet('multix', 'none');

		$response['vendor_mode'] = [
			'multix'       => $multix,
			'multi_vendor' => $multix !== 'none',
			'note'         => $multix === 'none'
				? 'Single-vendor. VirtueMart forces virtuemart_vendor_id to 1 on every store '
					. '(helpers/vmtable.php:1526-1603), so that column is decoration.'
				: 'Multi-vendor. virtuemart_vendor_id is ownership and a save that changes it can be '
					. 'refused by the ACL mid-write.',
		];

		$response['config_note'] = 'The shop configuration is a single pipe-delimited blob in '
			. '#__virtuemart_configs row 1. get_virtuemart_config reads and parses it. There is '
			. 'deliberately NO setter: VirtueMartModelConfig::store() re-reads virtuemart.cfg from disk '
			. 'and calls setParams() on it (models/config.php:413-420 against '
			. 'helpers/config.php:610-627), which replaces the parameter set wholesale — so a partial '
			. 'write resets every setting the caller did not name back to the shipped default.';

		$response['write_model_note'] = 'Every tool in this add-on except set_virtuemart_order_status '
			. 'works directly against the database and loads none of VirtueMart\'s PHP. That is why a '
			. 'broken Joomla 6 compat setup does not stop them, and why no partial write can trigger '
			. 'VirtueMart\'s destructive replace-set behaviour.';

		return ToolResult::json($response);
	}

	/** @return array<string,string> */
	private function securityWarning(string $version): array
	{
		$out = [
			'installed_version' => $version,
			'recommended'       => '4.8.0 or later',
		];

		$out['skrill'] = 'VirtueMart 4.8.0 (17 August 2026) fixed a CRITICAL vulnerability in the Skrill '
			. 'payment plugin. The vendor advisory says to update or REMOVE THE PLUGIN ENTIRELY. If this '
			. 'shop has the Skrill payment method installed, that is the first thing to deal with.';

		if (version_compare($version, '4.4.10', '<')) {
			$out['rce_chain'] = 'This version predates 4.4.10 and carries the CVE-2025-25228 / '
				. 'CVE-2025-25229 / CVE-2025-25230 cluster: an authenticated SQL injection in backend '
				. 'product management, an unrestricted file upload in the product-image section, and a '
				. 'CSRF that BYPASSES the token check on that same upload. Chained, those are remote code '
				. 'execution. Public proof-of-concept code exists. Update immediately.';
		}

		$out['token_surface'] = '4.8.0 also "added token validation to backend controllers and frontend '
			. 'forms" and extended media upload mime checking from media files to any file type. On an '
			. 'earlier version the CSRF and upload surfaces are both wider than the current baseline.';

		return $out;
	}
}
