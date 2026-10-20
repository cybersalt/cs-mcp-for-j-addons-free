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
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Orientation: what is installed, which pieces are switched on, and the handful
 * of facts that change how every other tool should be used.
 */
final class GetComponentInfoTool extends AbstractTool
{
	use J2commerceBootTrait;

	/**
	 * Package members whose state matters to callers, and why.
	 *
	 * @var array<string,string>
	 */
	private const PLUGIN_ROLES = [
		'system/j2commerce'      => 'Boots J2Commerce on every request, imports the j2commerce plugin group, and runs the daily inventory sweep that cancels unpaid orders (onAfterInitialise). If disabled, payment, shipping and app plugins never load.',
		'content/j2commerce'     => 'THE product save path. A product is created and updated only from an article save through this plugin (onContentAfterSave -> ProductService::saveProduct). If disabled, editing a product in the article editor silently saves nothing.',
		'task/j2commerce'        => 'Scheduled tasks: unpaid-order cleanup, cart garbage collection, currency updates, the integration queue.',
		'webservices/j2commerce' => 'J2Commerce\'s own REST routes under /api/index.php/v1/j2commerce/*. Not needed by these MCP tools.',
		'actionlog/j2commerce'   => 'Joomla action-log entries. Note it listens for onJ2CommerceAfterOrderStatusChange, which nothing in 6.6.5 fires, so status changes are never logged.',
		'user/j2commerce'        => 'Adds J2Commerce address fields to the Joomla registration and user forms and saves them as an address on user save. Disabled by default; optional.',
		'finder/j2commerce'      => 'Smart Search indexing of products.',
		'installer/j2commerce'   => 'Adds the download ID to update requests for J2Commerce\'s paid apps (onInstallerBeforePackageDownload).',
	];

	public function getName(): string { return 'get_j2commerce_component_info'; }

	public function getDescription(): string
	{
		return 'Orientation for a J2Commerce 6 site. Call this first. Returns the J2Commerce version, every '
			. 'installed package member (component, libraries, modules, plugins) with its enabled state and '
			. 'what disabling it breaks, the j2commerce plugin group (payment, shipping, report and app '
			. 'plugins), the store currency and address, Joomla and PHP versions, and whether a legacy '
			. 'J2Store 4 install is also present. '
			. 'Facts this surfaces that change how the other tools behave: '
			. '(1) A J2Commerce product is not a standalone record — it hangs off a Joomla article through '
			. 'product_source=com_content + product_source_id, and the CONTENT plugin is the only thing that '
			. 'saves products from the editor. '
			. '(2) J2Commerce 6 has no Pro edition gate. version.php defines a J2COMMERCE_PRO constant, but '
			. 'isPro() gates no capability whatever its value; the vendor sells paid APPS (gateways, shipping '
			. 'integrations). '
			. 'Every tool here works on any install. '
			. '(3) This is J2Commerce SIX (com_j2commerce, #__j2commerce_* tables). It is not J2Store 4 '
			. '(com_j2store); the two have different schemas and the 6.x installer does not migrate J2Store '
			. 'data — a separate com_j2commercemigrator does. Read-only.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$legacy = $this->j2LegacyJ2StorePresent();

		if (!is_dir($this->j2AdminBase())) {
			return $this->j2NotInstalledError();
		}

		$this->j2RequireAccess($actor, 'j2commerce.viewsetup');

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('extension_id'),
					$this->db->quoteName('type'),
					$this->db->quoteName('element'),
					$this->db->quoteName('folder'),
					$this->db->quoteName('client_id'),
					$this->db->quoteName('enabled'),
					$this->db->quoteName('manifest_cache'),
				])
				->from($this->db->quoteName('#__extensions'))
				->where('(' . implode(' OR ', [
					$this->db->quoteName('element') . ' LIKE ' . $this->db->quote('%j2commerce%'),
					$this->db->quoteName('folder') . ' = ' . $this->db->quote('j2commerce'),
				]) . ')')
				->order($this->db->quoteName('type') . ', ' . $this->db->quoteName('folder') . ', ' . $this->db->quoteName('element'))
		)->loadAssocList() ?: [];

		$members = [];
		$group   = [];
		$versions = [];
		$warnings = [];

		foreach ($rows as $row) {
			$manifest = json_decode((string) $row['manifest_cache'], true);
			$version  = \is_array($manifest) ? (string) ($manifest['version'] ?? '') : '';
			$enabled  = (int) $row['enabled'] === 1;

			$entry = [
				'extension_id' => (int) $row['extension_id'],
				'type'         => (string) $row['type'],
				'element'      => (string) $row['element'],
				'folder'       => (string) $row['folder'],
				'enabled'      => $enabled,
				'version'      => $version,
			];

			if ($version !== '') {
				$versions[$version] = true;
			}

			if ((string) $row['type'] === 'plugin' && (string) $row['folder'] === 'j2commerce') {
				$entry['kind'] = strtok((string) $row['element'], '_') ?: 'app';
				$group[]       = $entry;
				continue;
			}

			$key = (string) $row['folder'] . '/' . (string) $row['element'];

			if ((string) $row['type'] === 'plugin' && isset(self::PLUGIN_ROLES[$key])) {
				$entry['role'] = self::PLUGIN_ROLES[$key];

				if (!$enabled && \in_array($key, ['system/j2commerce', 'content/j2commerce'], true)) {
					$warnings[] = 'plg_' . str_replace('/', '_', $key) . ' is DISABLED. ' . self::PLUGIN_ROLES[$key];
				}
			}

			$members[] = $entry;
		}

		$version = $this->j2Version();

		if (\count($versions) > 1) {
			$warnings[] = 'Package members report different versions (' . implode(', ', array_keys($versions))
				. '). A partial update leaves the component and its plugins out of step; reinstall the '
				. 'full pkg_j2commerce.';
		}

		$params = $this->j2RawParams();
		$config = Factory::getApplication()->getConfig();
		$liveSite = trim((string) $config->get('live_site', ''));

		if ($liveSite === '') {
			$warnings[] = 'live_site is empty in configuration.php. J2Commerce builds email links from '
				. 'live_site and otherwise from Uri::root(), stripping only /administrator '
				. '(src/Helper/EmailHelper.php:3576-3598). Customer emails triggered by an API or MCP call '
				. '(set_j2commerce_order_status with notify) are worth checking for /api/ in their links.';
		}

		$response = [
			'ok'                 => true,
			'j2commerce_version' => $version,
			'installed'          => $this->j2Installed(),
			'edition'            => [
				'j2commerce_pro_constant' => $this->proConstant(),
				'note' => 'J2Commerce 6 has no capability-gated Pro edition. Whatever version.php sets '
					. 'J2COMMERCE_PRO to, isPro() gates no feature: it only selects UTM tags on help links and '
					. 'fills a diagnostics field. Paid functionality ships as separate j2commerce plugins (apps), '
					. 'listed below.',
			],
			'joomla_version'     => JVERSION,
			'php_version'        => PHP_VERSION,
			'requirements'       => 'J2Commerce 6.6.5 requires PHP 8.3 and Joomla 6.0 (script.j2commerce.php:77-78).',
			'store'              => [
				'name'             => (string) ($params['store_name'] ?? ''),
				'admin_email'      => (string) ($params['admin_email'] ?? ''),
				'currency'         => (string) ($params['config_currency'] ?? 'USD'),
				'country_id'       => (int) ($params['country_id'] ?? 0),
				'zone_id'          => (int) ($params['zone_id'] ?? 0),
				'prices_include_tax' => (string) ($params['config_including_tax'] ?? '0') === '1',
				'inventory_enabled'  => (string) ($params['enable_inventory'] ?? '0') === '1',
				'inventory_note'     => 'In 6.6.5 enable_inventory only gates the automatic cancellation of unpaid orders; stock checks and order stock movements follow each variant\'s manage_stock regardless (InventoryHelper::isManagingStock hardcodes the global switch on).',
				'guest_checkout'     => (string) ($params['allow_guest_checkout'] ?? '0') === '1',
				'catalog_mode'       => (string) ($params['catalog_mode'] ?? '0') === '1',
			],
			'live_site'          => $liveSite,
			'package_members'    => $members,
			'j2commerce_plugins' => $group,
			'legacy_j2store'     => $legacy,
		];

		if ($legacy) {
			$response['legacy_note'] = 'J2Store 4 tables (#__j2store_*) are also on this database. They are '
				. 'untouched by J2Commerce 6 and by these tools.';
		}

		if ((int) ($params['country_id'] ?? 0) === 0) {
			$warnings[] = 'The store country is not set. When a shopper\'s address matches no geozone, '
				. 'J2Commerce falls back to the STORE address for tax (src/Helper/CartOrder.php:955-961); '
				. 'with no store country that fallback matches nothing and tax silently comes out as zero. '
				. 'Run check_j2commerce_tax_setup.';
		}

		if ($warnings !== []) {
			$response['warnings'] = $warnings;
		}

		$response['next_steps'] = 'check_j2commerce_health for orphaned and stale data; '
			. 'check_j2commerce_tax_setup for silent zero-tax causes.';

		return ToolResult::json($response);
	}

	/** J2COMMERCE_PRO as written in version.php, read by regex so nothing is define()d into this request. */
	private function proConstant(): ?bool
	{
		$raw = (string) @file_get_contents($this->j2AdminBase() . '/version.php');

		if (preg_match("/define\\(\\s*'J2COMMERCE_PRO'\\s*,\\s*(true|false)\\s*\\)/i", $raw, $m) === 1) {
			return strtolower($m[1]) === 'true';
		}

		return null;
	}
}
