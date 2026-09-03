<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Manufacturers;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Return one manufacturer's base row, every language row and its product links.
 */
final class GetManufacturerTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'get_virtuemart_manufacturer'; }

	public function getDescription(): string
	{
		return 'Return one VirtueMart manufacturer in full: the #__virtuemart_manufacturers base row, its '
			. 'row in every active language satellite table, its media links, its manufacturer category, '
			. 'and the ids of the products linked to it. Requires id. '
			. 'The base row holds almost nothing — only the manufacturer-category id, meta robot/author, '
			. 'hits and published. Everything a human would recognise (mf_name, mf_email, mf_desc, mf_url, '
			. 'metadesc, metakey, customtitle, slug) is in #__virtuemart_manufacturers_<langsuffix>, per '
			. 'tables/manufacturers.php:65, and is read back with an INNER JOIN so a missing row makes the '
			. 'manufacturer invisible rather than untranslated. '
			. 'Use this before update_virtuemart_manufacturer: that tool merges your changes onto this '
			. 'whole state rather than treating your input as the complete record.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => ['type' => 'integer', 'description' => 'virtuemart_manufacturer_id. Required.'],
			],
			'required' => ['id'],
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

		$id = $this->requirePositiveInt($arguments, 'id');

		$base = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->vmTable('manufacturers')))
				->where($this->db->quoteName('virtuemart_manufacturer_id') . ' = ' . $id)
		)->loadAssoc();

		if (!\is_array($base)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No manufacturer with virtuemart_manufacturer_id ' . $id . '.',
			], true);
		}

		$translations = [];
		$missing      = [];

		foreach ($this->vmActiveLangTags() as $tag) {
			$table = $this->vmLangTable('manufacturers', $tag);

			if (!$this->vmLangTableExists('manufacturers', $tag)) {
				$translations[$tag] = ['table' => $table, 'table_exists' => false, 'row' => null];
				$missing[]          = $tag;

				continue;
			}

			$row = $this->db->setQuery(
				$this->db->getQuery(true)
					->select('*')
					->from($this->db->quoteName($table))
					->where($this->db->quoteName('virtuemart_manufacturer_id') . ' = ' . $id)
			)->loadAssoc();

			$translations[$tag] = [
				'table'        => $table,
				'table_exists' => true,
				'row'          => \is_array($row) ? $row : null,
			];

			if (!\is_array($row)) {
				$missing[]                    = $tag;
				$translations[$tag]['warning'] = 'No row. Invisible to ' . $tag . ' shoppers.';
			}
		}

		$products = $this->vmTableExists('product_manufacturers')
			? array_map('intval', $this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName('virtuemart_product_id'))
					->from($this->db->quoteName($this->vmTable('product_manufacturers')))
					->where($this->db->quoteName('virtuemart_manufacturer_id') . ' = ' . $id)
			)->loadColumn() ?: [])
			: [];

		$response = [
			'ok'            => true,
			'manufacturer'  => $base,
			'translations'  => $translations,
			'medias'        => $this->vmXrefIds('manufacturer_medias', 'virtuemart_manufacturer_id', $id, 'virtuemart_media_id'),
			'product_ids'   => $products,
			'product_count' => \count($products),
		];

		$categoryId = (int) ($base['virtuemart_manufacturercategories_id'] ?? 0);

		if ($categoryId > 0 && $this->vmTableExists('manufacturercategories')) {
			$exists = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select('COUNT(*)')
					->from($this->db->quoteName($this->vmTable('manufacturercategories')))
					->where($this->db->quoteName('virtuemart_manufacturercategories_id') . ' = ' . $categoryId)
			)->loadResult();

			if ($exists === 0) {
				$response['manufacturercategory_warning'] = 'virtuemart_manufacturercategories_id is '
					. $categoryId . ' but no such row exists. VirtueMart declares no foreign keys, so a '
					. 'dangling reference like this inserts cleanly and simply never matches.';
			}
		}

		if ($missing !== []) {
			$response['missing_languages'] = $missing;
			$response['warning'] = 'No language row for: ' . implode(', ', $missing)
				. '. This manufacturer is invisible to shoppers on those languages, and so is its brand '
				. 'page.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}
}
