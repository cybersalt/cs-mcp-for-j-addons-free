<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Manufacturers;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * List manufacturers, LEFT-joined to their language table.
 *
 * Manufacturers are the third translatable catalog entity and behave exactly
 * like products and categories: the base table has no name column, and the read
 * join is INNER.
 */
final class ListManufacturersTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'list_virtuemart_manufacturers'; }

	public function getDescription(): string
	{
		return 'List VirtueMart manufacturers from #__virtuemart_manufacturers, joined to the language '
			. 'satellite table for the requested language. '
			. 'Manufacturers are translatable in the same way products and categories are '
			. '(helpers/tableupdater.php:51-57). The base table has NO name column: mf_name, mf_email, '
			. 'mf_desc, mf_url, metadesc, metakey, customtitle and slug all live in '
			. '#__virtuemart_manufacturers_<langsuffix> and are read back with an INNER JOIN '
			. '(helpers/vmtable.php:1065-1068). This listing uses a LEFT JOIN so a manufacturer missing '
			. 'its language row is reported rather than hidden. '
			. 'Filters: search (on mf_name), published, manufacturercategory_id, language, '
			. 'only_invisible. Supports limit (default 50, max 200) and offset. '
			. 'product_count is the number of rows in #__virtuemart_product_manufacturers pointing at '
			. 'each manufacturer. That xref is one of the two VirtueMart deletes unconditionally on any '
			. 'partial product save (models/product.php:2937, no guard and no sentinel), so a '
			. 'manufacturer that mysteriously lost all its products is usually the symptom of a partial '
			. 'call to ProductModel::store() somewhere else.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'search'                   => ['type' => 'string', 'description' => 'Case-insensitive substring match on mf_name.'],
				'published'                => ['type' => 'boolean'],
				'manufacturercategory_id'  => ['type' => 'integer', 'description' => 'Filter by virtuemart_manufacturercategories_id.'],
				'language'                 => ['type' => 'string', 'description' => 'Language tag. Defaults to the shop language.'],
				'only_invisible'           => ['type' => 'boolean', 'description' => 'Return ONLY manufacturers with no row in the chosen language table.'],
				'limit'                    => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'                   => ['type' => 'integer'],
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

		if (!$this->vmTableExists('manufacturers')) {
			return $this->vmMissingTableError('manufacturers');
		}

		$tag = $this->vmResolveLangTag($arguments);

		if ($tag instanceof ToolResult) {
			return $tag;
		}

		if (!$this->vmLangTableExists('manufacturers', $tag)) {
			return $this->vmMissingTableError('manufacturers_' . $this->vmLangSuffix($tag));
		}

		$limit  = $this->vmLimit($arguments);
		$offset = $this->vmOffset($arguments);

		$from = ' FROM ' . $this->db->quoteName($this->vmTable('manufacturers'), 'm')
			. ' LEFT JOIN ' . $this->db->quoteName($this->vmLangTable('manufacturers', $tag), 'ml')
			. ' ON ' . $this->db->quoteName('m.virtuemart_manufacturer_id')
			. ' = ' . $this->db->quoteName('ml.virtuemart_manufacturer_id');

		$where = [];

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$where[] = $this->db->quoteName('ml.mf_name') . ' LIKE '
				. $this->db->quote('%' . $this->db->escape($search, true) . '%', false);
		}

		if (\array_key_exists('published', $arguments)) {
			$where[] = $this->db->quoteName('m.published') . ' = ' . ((bool) $arguments['published'] ? 1 : 0);
		}

		if (\array_key_exists('manufacturercategory_id', $arguments)) {
			$where[] = $this->db->quoteName('m.virtuemart_manufacturercategories_id') . ' = '
				. (int) $arguments['manufacturercategory_id'];
		}

		if ((bool) ($arguments['only_invisible'] ?? false)) {
			$where[] = $this->db->quoteName('ml.virtuemart_manufacturer_id') . ' IS NULL';
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		$total = (int) $this->db->setQuery('SELECT COUNT(*)' . $from . $whereSql)->loadResult();

		$select = [
			$this->db->quoteName('m.virtuemart_manufacturer_id'),
			$this->db->quoteName('m.virtuemart_manufacturercategories_id'),
			$this->db->quoteName('m.published'),
			$this->db->quoteName('m.hits'),
			$this->db->quoteName('m.created_on'),
			$this->db->quoteName('m.modified_on'),
			$this->db->quoteName('ml.mf_name'),
			$this->db->quoteName('ml.mf_url'),
			$this->db->quoteName('ml.mf_email'),
			$this->db->quoteName('ml.slug'),
		];

		$rows = $this->db->setQuery(
			'SELECT ' . implode(', ', $select) . $from . $whereSql
			. ' ORDER BY ' . $this->db->quoteName('m.virtuemart_manufacturer_id') . ' ASC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$ids    = array_map(static fn (array $r): int => (int) $r['virtuemart_manufacturer_id'], $rows);
		$counts = $this->productCounts($ids);

		$out       = [];
		$invisible = 0;

		foreach ($rows as $row) {
			$id      = (int) $row['virtuemart_manufacturer_id'];
			$hasLang = $row['mf_name'] !== null;

			$entry = [
				'virtuemart_manufacturer_id' => $id,
				'mf_name'                    => $hasLang ? (string) $row['mf_name'] : null,
				'slug'                       => $hasLang ? (string) $row['slug'] : null,
				'mf_url'                     => $hasLang ? (string) $row['mf_url'] : null,
				'mf_email'                   => $hasLang ? (string) $row['mf_email'] : null,
				'published'                  => (int) $row['published'] === 1,
				'virtuemart_manufacturercategories_id' => (int) $row['virtuemart_manufacturercategories_id'],
				'hits'                       => (int) $row['hits'],
				'product_count'              => $counts[$id] ?? 0,
				'language'                   => $tag,
			];

			if (!$hasLang) {
				$invisible++;
				$entry['invisible'] = true;
				$entry['invisible_reason'] = 'No row in ' . $this->vmLangTable('manufacturers', $tag)
					. '. The read join is INNER, so this manufacturer does not appear in the shop for '
					. $tag . ' shoppers and its filter/brand pages return nothing.';
			}

			$out[] = $entry;
		}

		$response = [
			'ok'            => true,
			'total'         => $total,
			'limit'         => $limit,
			'offset'        => $offset,
			'showing'       => \count($out),
			'language'      => ['tag' => $tag, 'table' => $this->vmLangTable('manufacturers', $tag)],
			'manufacturers' => $out,
		];

		if ($invisible > 0) {
			$response['invisible_count'] = $invisible;
			$response['warning'] = $invisible . ' manufacturers in this page have no ' . $tag
				. ' language row and are invisible to shoppers.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/**
	 * @param  array<int,int> $ids
	 * @return array<int,int>
	 */
	private function productCounts(array $ids): array
	{
		if ($ids === [] || !$this->vmTableExists('product_manufacturers')) {
			return [];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('virtuemart_manufacturer_id'),
					'COUNT(*) AS ' . $this->db->quoteName('total'),
				])
				->from($this->db->quoteName($this->vmTable('product_manufacturers')))
				->whereIn($this->db->quoteName('virtuemart_manufacturer_id'), $ids)
				->group($this->db->quoteName('virtuemart_manufacturer_id'))
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$out[(int) $row['virtuemart_manufacturer_id']] = (int) $row['total'];
		}

		return $out;
	}
}
