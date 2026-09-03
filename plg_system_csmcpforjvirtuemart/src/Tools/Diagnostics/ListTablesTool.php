<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * The live table inventory, with columns on request.
 *
 * `install.sql` is not the schema of record and never was: three tables are
 * created at runtime, the language satellites are generated, and
 * `#__virtuemart_userinfos` / `#__virtuemart_order_userinfos` are reshaped by
 * the userfields screen with real `ALTER TABLE` statements. Anything that needs
 * to know a column set must ask the database, which is what this does.
 */
final class ListTablesTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'list_virtuemart_tables'; }

	public function getDescription(): string
	{
		return 'List every #__virtuemart_* table present on this site, with row counts, and optionally '
			. 'the live column definition of each. '
			. 'install.sql is NOT the schema of record and never was. It defines 53 tables, but three '
			. 'more are created at runtime and are absent from it: #__virtuemart_configs '
			. '(models/config.php:640-651), #__virtuemart_userinfos and #__virtuemart_order_userinfos '
			. '(install/install_essential_data.sql:78 and :119). On top of that there is one satellite '
			. 'table per translatable table per active language, and their column sets are GENERATED at '
			. 'install time (helpers/tableupdater.php:95-201) — typed by substring match on the field '
			. 'name and sized from configuration keys, so they differ between shops. '
			. 'The userinfos tables are stranger still: VirtueMart\'s userfields screen issues real ALTER '
			. 'TABLE statements against them (models/userfields.php:300, :306), and DELETING a userfield '
			. 'does not drop its column — VmTable::_modifyColumn() RENAMES it to '
			. '<name>_DELETED_<unixtime> (helpers/vmtable.php:2748-2751). Columns matching that pattern '
			. 'are flagged. '
			. 'Tables are grouped by domain (catalog, orders, customers, pricing, payment, shipment, '
			. 'media, geography, ratings, multi-vendor, infrastructure, language satellites) so an '
			. 'unfamiliar shop is navigable. '
			. 'VirtueMart calls its own install broken below 55 tables (models/config.php:604-622), and '
			. 'the total is reported against that threshold.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'include_columns' => ['type' => 'boolean', 'description' => 'Include each table\'s live column definitions. Default false — this is a lot of output on a multi-language shop.'],
				'table'           => ['type' => 'string', 'description' => 'Restrict to one table. Accepts "products" or "virtuemart_products" or "#__virtuemart_products". Implies include_columns.'],
				'include_counts'  => ['type' => 'boolean', 'description' => 'Include row counts. Default true.'],
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

		$tables = $this->vmAllTables();

		if ($tables === []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No #__virtuemart_* tables exist on this site, so VirtueMart\'s files are '
					. 'present but its database is not installed.',
			], true);
		}

		$one = trim((string) ($arguments['table'] ?? ''));

		if ($one !== '') {
			$normalised = $this->normalise($one);

			if (!\in_array($normalised, $tables, true)) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'No table "#__' . $normalised . '" on this site.',
					'available' => $tables,
				], true);
			}

			$tables = [$normalised];
		}

		$withColumns = $one !== '' || (bool) ($arguments['include_columns'] ?? false);
		$withCounts  = (bool) ($arguments['include_counts'] ?? true);

		$grouped  = [];
		$zombies  = [];
		$total    = 0;

		foreach ($tables as $table) {
			$short = substr($table, \strlen('virtuemart_'));
			$group = $this->groupFor($short);

			$entry = ['table' => '#__' . $table, 'short_name' => $short];

			if ($withCounts) {
				$entry['rows'] = (int) $this->db->setQuery(
					'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__' . $table)
				)->loadResult();

				$total += $entry['rows'];
			}

			if ($withColumns) {
				$columns    = $this->db->getTableColumns('#__' . $table, true) ?: [];
				$definition = [];

				foreach ($columns as $name => $meta) {
					$definition[(string) $name] = (string) ($meta->Type ?? '');

					if (preg_match('/_DELETED_\d+$/', (string) $name) === 1) {
						$zombies[] = '#__' . $table . '.' . $name;
					}
				}

				$entry['columns']      = $definition;
				$entry['column_count'] = \count($definition);
			}

			if ($this->isRuntimeCreated($short)) {
				$entry['not_in_install_sql'] = true;
				$entry['creation_note']      = $this->creationNote($short);
			}

			$grouped[$group][] = $entry;
		}

		ksort($grouped);

		$response = [
			'ok'          => true,
			'table_count' => \count($tables),
			'groups'      => $grouped,
		];

		if ($withCounts) {
			$response['total_rows'] = $total;
		}

		$allCount = \count($this->vmAllTables());

		$response['install_check'] = [
			'tables_present' => $allCount,
			'threshold'      => 55,
			'verdict'        => $allCount < 55
				? 'VirtueMart\'s own checkVirtuemartInstalled() (models/config.php:604-622) counts '
					. 'tables matching the prefix and calls the install BROKEN below 55. This shop has '
					. $allCount . '.'
				: $allCount . ' tables, above VirtueMart\'s own broken-install threshold of 55.',
		];

		$response['schema_note'] = 'install.sql defines 53 tables and is not the schema of record. Three '
			. 'more are created at runtime, the language satellites are generated per active language, '
			. 'and the userinfos tables are reshaped by the userfields screen. Always read the live '
			. 'schema, never install.sql.';

		if ($zombies !== []) {
			$response['deleted_userfield_columns'] = $zombies;
			$response['deleted_userfield_note'] = 'These columns end in _DELETED_<unixtime>. VirtueMart '
				. 'RENAMES a userfield column rather than dropping it when the field is deleted '
				. '(helpers/vmtable.php:2748-2751), so they hold data from fields the shop no longer '
				. 'uses. The table updater is explicitly forbidden from removing them '
				. '(helpers/tableupdater.php:867).';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	private function normalise(string $name): string
	{
		$name = trim($name);
		$name = preg_replace('/^#__/', '', $name) ?? $name;
		$name = strtolower($name);

		return str_starts_with($name, 'virtuemart_') ? $name : 'virtuemart_' . $name;
	}

	private function isRuntimeCreated(string $short): bool
	{
		return \in_array($short, ['configs', 'userinfos', 'order_userinfos'], true);
	}

	private function creationNote(string $short): string
	{
		return match ($short) {
			'configs'         => 'Created by getCreateConfigTableQuery() at models/config.php:640-651. '
				. 'Holds the entire shop configuration in one pipe-delimited blob, in row 1.',
			'userinfos'       => 'Created by install/install_essential_data.sql:78 and then reshaped by '
				. 'the userfields screen, which issues real ALTER TABLE statements '
				. '(models/userfields.php:300).',
			'order_userinfos' => 'Created by install/install_essential_data.sql:119. Its columns mirror '
				. 'userinfos, altered in step at models/userfields.php:306, and it additionally has an '
				. 'email column that userinfos does not.',
			default           => '',
		};
	}

	private function groupFor(string $short): string
	{
		if (preg_match('/_(products|categories|manufacturers|manufacturercategories|vendors|paymentmethods|shipmentmethods)_[a-z]{2}(_[a-z0-9]{2,3})?$/', 'x_' . $short) === 1) {
			return 'language satellites';
		}

		foreach ($this->vmTranslatableTables() as $base => $key) {
			if (preg_match('/^' . preg_quote($base, '/') . '_[a-z]{2}(_[a-z0-9]{2,3})?$/', $short) === 1) {
				return 'language satellites';
			}
		}

		return match (true) {
			str_starts_with($short, 'order') || $short === 'invoices' || $short === 'carts' => 'orders',
			str_starts_with($short, 'product') || $short === 'products' || str_starts_with($short, 'categor') => 'catalog',
			str_starts_with($short, 'manufacturer') => 'catalog',
			str_starts_with($short, 'custom')    => 'custom fields',
			str_starts_with($short, 'calc') || $short === 'coupons' => 'pricing',
			str_starts_with($short, 'payment')   => 'payment',
			str_starts_with($short, 'shipment')  => 'shipment',
			str_contains($short, 'media')        => 'media',
			\in_array($short, ['countries', 'states', 'worldzones', 'currencies'], true) => 'geography and currency',
			str_starts_with($short, 'rating')    => 'ratings and reviews',
			str_starts_with($short, 'vendor')    => 'multi-vendor',
			str_starts_with($short, 'vmuser') || str_starts_with($short, 'user') || str_starts_with($short, 'shoppergroup') || $short === 'waitingusers' => 'customers',
			default                              => 'infrastructure',
		};
	}
}
