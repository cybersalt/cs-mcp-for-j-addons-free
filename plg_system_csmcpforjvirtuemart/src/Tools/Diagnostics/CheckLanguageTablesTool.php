<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * The invisible-record detector.
 *
 * Probably the single most valuable tool in this add-on. Seven VirtueMart
 * tables carry per-language satellites (`helpers/tableupdater.php:51-57`), and
 * every read joins them with an INNER JOIN (`helpers/vmtable.php:1065-1068`).
 * A base row with no satellite row for a given language is not "untranslated" —
 * it does not exist for shoppers on that language. Nothing logs it, nothing
 * warns, and on a single-language shop there is not even a fallback pass,
 * because the fallback at `helpers/vmtable.php:1184` requires `langCount > 1`.
 *
 * This audits all seven tables against all active languages in one pass, and
 * also finds the reverse case: orphaned satellite rows whose base row is gone,
 * which matter because each one occupies a slug in a UNIQUE index and silently
 * pushes new records into `-1` suffixes.
 */
final class CheckLanguageTablesTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'check_virtuemart_language_tables'; }

	public function getDescription(): string
	{
		return 'Audit the integrity of VirtueMart\'s per-language satellite tables across the whole shop, '
			. 'and find every record that is INVISIBLE because of them. '
			. 'This is the highest-value diagnostic in this add-on. Seven VirtueMart tables are '
			. 'translatable — products, categories, manufacturers, manufacturercategories, vendors, '
			. 'paymentmethods and shipmentmethods (helpers/tableupdater.php:51-57) — and for each one '
			. 'VirtueMart keeps a satellite table per active language, named <base>_<langsuffix> where '
			. 'the suffix is the tag lowercased with the hyphen turned into an underscore. Everything a '
			. 'human recognises lives there: #__virtuemart_products has no product_name column at all. '
			. 'Every read joins the two with an INNER JOIN (helpers/vmtable.php:1065-1068). So a base row '
			. 'with no satellite row for a language does not appear "untranslated" to those shoppers — it '
			. 'does not appear at all. No listing, no search result, no category page, no error, no log '
			. 'line. On a single-language shop there is not even a fallback pass, because the fallback at '
			. 'helpers/vmtable.php:1184 requires more than one active language. This is the number one '
			. 'reason a product imported or created outside the component never shows up. '
			. 'This tool reports, per table and per language: whether the satellite table exists, how '
			. 'many base rows have no satellite row (the invisible ones, with sample ids), how many '
			. 'satellite rows are ORPHANED with no base row, and how many satellite rows have an empty '
			. 'slug (SEF URLs that 404). '
			. 'The orphans matter more than they look. Each one occupies a value in the satellite '
			. 'table\'s UNIQUE slug index (helpers/tableupdater.php:193-198), so a new record with the '
			. 'same name silently gets "-1" appended for a reason nobody can see. '
			. 'It also finds STALE satellite tables: #__virtuemart_*_<lang> tables for languages no '
			. 'longer in active_languages. VirtueMart creates satellite tables only when the active list '
			. 'GROWS (models/config.php:541-546) and never drops them, so removing a language leaves its '
			. 'tables behind with stale content forever. '
			. 'Fix invisible products with set_virtuemart_product_translation, and invisible categories '
			. 'or manufacturers with update_virtuemart_category / update_virtuemart_manufacturer.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'tables'       => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Restrict the audit to these base table names, e.g. ["products","categories"]. Omit for all seven.'],
				'sample_limit' => ['type' => 'integer', 'description' => 'How many example ids to return per problem. Default 20, max 200.'],
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

		$sampleLimit = max(1, min(200, (int) ($arguments['sample_limit'] ?? 20)));
		$translatable = $this->vmTranslatableTables();

		$requested = (array) ($arguments['tables'] ?? []);

		if ($requested !== []) {
			$filtered = [];

			foreach ($requested as $name) {
				$name = strtolower(trim((string) $name));

				if (isset($translatable[$name])) {
					$filtered[$name] = $translatable[$name];
				}
			}

			if ($filtered === []) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'None of the requested tables are translatable in VirtueMart.',
					'translatable_tables' => array_keys($translatable),
				], true);
			}

			$translatable = $filtered;
		}

		$tags    = $this->vmActiveLangTags();
		$results = [];

		$totalInvisible = 0;
		$totalOrphans   = 0;
		$totalNoSlug    = 0;
		$missingTables  = [];

		foreach ($translatable as $base => $keyColumn) {
			if (!$this->vmTableExists($base)) {
				$results[$base] = ['base_table_exists' => false];

				continue;
			}

			$baseCount = (int) $this->db->setQuery(
				'SELECT COUNT(*) FROM ' . $this->db->quoteName($this->vmTable($base))
			)->loadResult();

			$perLanguage = [];

			foreach ($tags as $tag) {
				$langTable = $this->vmLangTable($base, $tag);

				if (!$this->vmLangTableExists($base, $tag)) {
					$missingTables[]    = $langTable;
					$perLanguage[$tag] = [
						'table'        => $langTable,
						'table_exists' => false,
						'error'        => $tag . ' is in active_languages but this satellite table does '
							. 'not exist, so EVERY row in ' . $this->vmTable($base) . ' is invisible to '
							. $tag . ' shoppers. VirtueMart creates satellite tables only when the active '
							. 'language list grows (models/config.php:541-546), so a language added by '
							. 'editing the config blob directly never gets them. Saving the VirtueMart '
							. 'configuration once in the shop admin creates them.',
					];

					$totalInvisible += $baseCount;

					continue;
				}

				$missing = $this->missingIds($base, $langTable, $keyColumn, $sampleLimit);
				$orphans = $this->orphanIds($base, $langTable, $keyColumn, $sampleLimit);
				$noSlug  = $this->emptySlugIds($langTable, $keyColumn, $sampleLimit);

				$totalInvisible += $missing['count'];
				$totalOrphans   += $orphans['count'];
				$totalNoSlug    += $noSlug['count'];

				$entry = [
					'table'          => $langTable,
					'table_exists'   => true,
					'suffix'         => $this->vmLangSuffix($tag),
					'base_rows'      => $baseCount,
					'language_rows'  => (int) $this->db->setQuery(
						'SELECT COUNT(*) FROM ' . $this->db->quoteName($langTable)
					)->loadResult(),
					'invisible_rows' => $missing['count'],
					'orphan_rows'    => $orphans['count'],
					'empty_slug_rows' => $noSlug['count'],
				];

				if ($missing['count'] > 0) {
					$entry['invisible_sample'] = $missing['ids'];
					$entry['invisible_note']   = $missing['count'] . ' row(s) in '
						. $this->vmTable($base) . ' have no row here. The read join is INNER, so they do '
						. 'not exist at all for ' . $tag . ' shoppers — not hidden, not untranslated, '
						. 'absent — and nothing in VirtueMart reports it.';
				}

				if ($orphans['count'] > 0) {
					$entry['orphan_sample'] = $orphans['ids'];
					$entry['orphan_note']   = $orphans['count'] . ' row(s) here have no matching row in '
						. $this->vmTable($base) . '. VirtueMart declares no foreign keys and does its '
						. 'cascades in PHP, so a delete that skipped the satellite leaves these behind. '
						. 'Each one still occupies a value in this table\'s UNIQUE slug index '
						. '(helpers/tableupdater.php:193-198), which is why a new record with the same '
						. 'name silently ends up with "-1" appended.';
				}

				if ($noSlug['count'] > 0) {
					$entry['empty_slug_sample'] = $noSlug['ids'];
					$entry['empty_slug_note']   = $noSlug['count'] . ' row(s) have an empty slug, so '
						. 'their SEF URLs 404.';
				}

				$perLanguage[$tag] = $entry;
			}

			$results[$base] = [
				'base_table_exists' => true,
				'base_rows'         => $baseCount,
				'key_column'        => $keyColumn,
				'languages'         => $perLanguage,
			];
		}

		$stale = $this->staleLanguageTables(array_keys($translatable), $tags);

		$response = [
			'ok'               => true,
			'active_languages' => $tags,
			'default_language' => $this->vmDefaultLangTag(),
			'tables'           => $results,
			'summary'          => [
				'invisible_rows'         => $totalInvisible,
				'orphaned_language_rows' => $totalOrphans,
				'empty_slug_rows'        => $totalNoSlug,
				'missing_language_tables' => \count($missingTables),
				'stale_language_tables'   => \count($stale),
			],
			'mechanic_note' => 'The satellite table name is <base>_<langsuffix>, where the suffix is '
				. 'strtolower(strtr($tag, "-", "_")) — en-GB becomes en_gb. It is derived at runtime here '
				. 'from the shop\'s own active_languages and never hardcoded. The column set of these '
				. 'tables is GENERATED at install time (helpers/tableupdater.php:95-201), typed by '
				. 'substring match on the field name and sized from configuration keys, so it varies by '
				. 'shop.',
		];

		if ($totalInvisible > 0) {
			$response['verdict'] = $totalInvisible . ' record(s) in this shop are INVISIBLE to shoppers '
				. 'because they have no language row. Fix products with '
				. 'set_virtuemart_product_translation, categories with update_virtuemart_category, '
				. 'manufacturers with update_virtuemart_manufacturer.';
		} elseif ($totalOrphans > 0 || $totalNoSlug > 0 || $stale !== []) {
			$response['verdict'] = 'No invisible records, but there is tidying to do — see the orphan, '
				. 'empty-slug and stale-table figures above.';
		} else {
			$response['verdict'] = 'Every base row has a language row in every active language, every '
				. 'slug is populated, and there are no orphans or stale tables.';
		}

		if ($missingTables !== []) {
			$response['missing_language_tables'] = $missingTables;
		}

		if ($stale !== []) {
			$response['stale_language_tables'] = $stale;
			$response['stale_note'] = 'These #__virtuemart_*_<lang> tables exist for languages that are '
				. 'NOT in active_languages. VirtueMart creates satellite tables only when the active list '
				. 'grows (models/config.php:541-546) and never drops them, so removing a language leaves '
				. 'its tables behind with stale content indefinitely. They are harmless but they are also '
				. 'a trap: their contents look current and are read by nothing.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/**
	 * Base rows with no satellite row — the invisible ones.
	 *
	 * @return array{count:int,ids:array<int,int>}
	 */
	private function missingIds(string $base, string $langTable, string $keyColumn, int $limit): array
	{
		$sql = ' FROM ' . $this->db->quoteName($this->vmTable($base), 'b')
			. ' LEFT JOIN ' . $this->db->quoteName($langTable, 'l')
			. ' ON ' . $this->db->quoteName('b.' . $keyColumn) . ' = ' . $this->db->quoteName('l.' . $keyColumn)
			. ' WHERE ' . $this->db->quoteName('l.' . $keyColumn) . ' IS NULL';

		$count = (int) $this->db->setQuery('SELECT COUNT(*)' . $sql)->loadResult();

		$ids = $count === 0 ? [] : array_map('intval', $this->db->setQuery(
			'SELECT ' . $this->db->quoteName('b.' . $keyColumn) . $sql
			. ' ORDER BY ' . $this->db->quoteName('b.' . $keyColumn) . ' ASC',
			0,
			$limit
		)->loadColumn() ?: []);

		return ['count' => $count, 'ids' => $ids];
	}

	/**
	 * Satellite rows whose base row has gone.
	 *
	 * @return array{count:int,ids:array<int,int>}
	 */
	private function orphanIds(string $base, string $langTable, string $keyColumn, int $limit): array
	{
		$sql = ' FROM ' . $this->db->quoteName($langTable, 'l')
			. ' LEFT JOIN ' . $this->db->quoteName($this->vmTable($base), 'b')
			. ' ON ' . $this->db->quoteName('l.' . $keyColumn) . ' = ' . $this->db->quoteName('b.' . $keyColumn)
			. ' WHERE ' . $this->db->quoteName('b.' . $keyColumn) . ' IS NULL';

		$count = (int) $this->db->setQuery('SELECT COUNT(*)' . $sql)->loadResult();

		$ids = $count === 0 ? [] : array_map('intval', $this->db->setQuery(
			'SELECT ' . $this->db->quoteName('l.' . $keyColumn) . $sql
			. ' ORDER BY ' . $this->db->quoteName('l.' . $keyColumn) . ' ASC',
			0,
			$limit
		)->loadColumn() ?: []);

		return ['count' => $count, 'ids' => $ids];
	}

	/** @return array{count:int,ids:array<int,int>} */
	private function emptySlugIds(string $langTable, string $keyColumn, int $limit): array
	{
		$columns = $this->db->getTableColumns($langTable) ?: [];

		if (!isset($columns['slug'])) {
			return ['count' => 0, 'ids' => []];
		}

		$where = ' WHERE ' . $this->db->quoteName('slug') . ' = ' . $this->db->quote('')
			. ' OR ' . $this->db->quoteName('slug') . ' IS NULL';

		$count = (int) $this->db->setQuery(
			'SELECT COUNT(*) FROM ' . $this->db->quoteName($langTable) . $where
		)->loadResult();

		$ids = $count === 0 ? [] : array_map('intval', $this->db->setQuery(
			'SELECT ' . $this->db->quoteName($keyColumn) . ' FROM ' . $this->db->quoteName($langTable) . $where,
			0,
			$limit
		)->loadColumn() ?: []);

		return ['count' => $count, 'ids' => $ids];
	}

	/**
	 * Satellite tables for languages no longer active.
	 *
	 * @param  array<int,string> $bases
	 * @param  array<int,string> $tags
	 * @return array<int,string>
	 */
	private function staleLanguageTables(array $bases, array $tags): array
	{
		$expected = [];

		foreach ($bases as $base) {
			foreach ($tags as $tag) {
				$expected[] = 'virtuemart_' . $base . '_' . $this->vmLangSuffix($tag);
			}
		}

		$stale = [];

		foreach ($this->vmAllTables() as $table) {
			foreach ($bases as $base) {
				$prefix = 'virtuemart_' . $base . '_';

				if (!str_starts_with($table, $prefix)) {
					continue;
				}

				$suffix = substr($table, \strlen($prefix));

				// A language suffix is xx or xx_yy — anything else is another
				// table that merely shares the prefix.
				if (preg_match('/^[a-z]{2}(_[a-z0-9]{2,3})?$/', $suffix) !== 1) {
					continue;
				}

				if (!\in_array($table, $expected, true)) {
					$stale[] = '#__' . $table;
				}
			}
		}

		return array_values(array_unique($stale));
	}
}
