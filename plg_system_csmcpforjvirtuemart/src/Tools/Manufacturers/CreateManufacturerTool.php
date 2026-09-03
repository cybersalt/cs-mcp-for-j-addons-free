<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Manufacturers;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Create a manufacturer, with a language row for every active language.
 */
final class CreateManufacturerTool extends AbstractTool
{
	use VirtuemartBootTrait;
	use ManufacturerWriteTrait;

	public function getName(): string { return 'create_virtuemart_manufacturer'; }

	public function getDescription(): string
	{
		return 'Create a VirtueMart manufacturer. Requires mf_name. '
			. 'Writes the #__virtuemart_manufacturers base row plus a row in the language satellite table '
			. 'for EVERY language in the shop\'s active_languages. The base row holds almost nothing — '
			. 'only the manufacturer-category id, meta robot/author, hits and published. mf_name, '
			. 'mf_email, mf_desc, mf_url, metadesc, metakey, customtitle and slug are all in '
			. '#__virtuemart_manufacturers_<langsuffix> (tables/manufacturers.php:65) and are read back '
			. 'with an INNER JOIN, so a base row without them is an invisible manufacturer. '
			. 'The slug is generated from mf_name with VirtueMart\'s own transform '
			. '(helpers/vmtable.php:1754-1776) and de-duplicated the way checkCreateUnique() would, '
			. 'because the slug column carries a UNIQUE KEY on the language table. '
			. 'mf_email is validated. VirtueMart does not validate it and stores whatever it is given, so '
			. 'an invalid address just fails silently later at send time. '
			. 'published defaults to true, matching the SQL column default (install.sql:415). '
			. 'This does NOT link any product. Use set_virtuemart_product_manufacturers for that — and '
			. 'note that #__virtuemart_product_manufacturers is one of the two xrefs VirtueMart deletes '
			. 'unconditionally on any partial product save (models/product.php:2937).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'mf_name'      => ['type' => 'string', 'description' => 'Required. Written to every active language unless overridden in `translations`.'],
				'mf_desc'      => ['type' => 'string', 'description' => 'Description (HTML).'],
				'mf_url'       => ['type' => 'string', 'description' => 'Manufacturer website.'],
				'mf_email'     => ['type' => 'string', 'description' => 'Validated as an email address.'],
				'metadesc'     => ['type' => 'string'],
				'metakey'      => ['type' => 'string'],
				'customtitle'  => ['type' => 'string'],
				'slug'         => ['type' => 'string', 'description' => 'Leave empty to generate from mf_name.'],
				'translations' => [
					'type'                 => 'object',
					'description'          => 'Per-language overrides keyed by language tag.',
					'additionalProperties' => ['type' => 'object'],
				],
				'virtuemart_manufacturercategories_id' => ['type' => 'integer', 'description' => 'Manufacturer category. Must exist if non-zero.'],
				'published'  => ['type' => 'boolean', 'description' => 'Default true.'],
				'metarobot'  => ['type' => 'string'],
				'metaauthor' => ['type' => 'string'],
			],
			'required' => ['mf_name'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->vmAdminBase() === null) {
			return $this->vmNotInstalledError();
		}

		if (!$this->vmTableExists('manufacturers')) {
			return $this->vmMissingTableError('manufacturers');
		}

		$tags = $this->vmActiveLangTags();

		foreach ($tags as $tag) {
			if (!$this->vmLangTableExists('manufacturers', $tag)) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Refusing: ' . $this->vmLangTable('manufacturers', $tag) . ' does not '
						. 'exist but ' . $tag . ' is in this shop\'s active_languages. The base row alone '
						. 'would be invisible with no error anywhere.',
					'active_languages' => $tags,
				], true);
			}
		}

		$categoryId = (int) ($arguments['virtuemart_manufacturercategories_id'] ?? 0);

		if ($categoryId > 0 && $this->vmTableExists('manufacturercategories')) {
			$exists = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select('COUNT(*)')
					->from($this->db->quoteName($this->vmTable('manufacturercategories')))
					->where($this->db->quoteName('virtuemart_manufacturercategories_id') . ' = ' . $categoryId)
			)->loadResult();

			if ($exists === 0) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'virtuemart_manufacturercategories_id ' . $categoryId . ' does not exist. '
						. 'VirtueMart has no foreign keys so this would have inserted cleanly and never '
						. 'matched. Refusing; nothing was written.',
				], true);
			}
		}

		$langPlan = [];

		foreach ($tags as $tag) {
			$built = $this->buildManufacturerLangRow(
				$this->langInputFor($arguments, $tag, \count($tags) > 1),
				[],
				$this->vmLangTable('manufacturers', $tag),
				0
			);

			if ($built instanceof ToolResult) {
				return $built;
			}

			$langPlan[$tag] = $built;
		}

		$now = $this->vmNow();

		$row                                       = new \stdClass();
		$row->virtuemart_manufacturercategories_id = $categoryId;
		$row->published                            = $this->vmNormalisePublished($arguments['published'] ?? true) ?? 1;
		$row->hits                                 = 0;
		$row->metarobot                            = (string) ($arguments['metarobot'] ?? '');
		$row->metaauthor                           = (string) ($arguments['metaauthor'] ?? '');
		$row->created_on                           = $now;
		$row->created_by                           = (int) $actor->id;
		$row->modified_on                          = $now;
		$row->modified_by                          = (int) $actor->id;

		$this->db->insertObject($this->vmTable('manufacturers'), $row, 'virtuemart_manufacturer_id');

		$manufacturerId = (int) $row->virtuemart_manufacturer_id;

		if ($manufacturerId <= 0) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'The base row insert returned no id. Nothing further was written.',
			], true);
		}

		$slugNotes = [];

		foreach ($langPlan as $tag => $plan) {
			$this->writeManufacturerLangRow($this->vmLangTable('manufacturers', (string) $tag), $manufacturerId, $plan['fields'], false);

			if ($plan['slug_note'] !== null) {
				$slugNotes[$tag] = $plan['slug_note'];
			}
		}

		$response = [
			'ok'                         => true,
			'virtuemart_manufacturer_id' => $manufacturerId,
			'mf_name'                    => (string) $arguments['mf_name'],
			'published'                  => (int) $row->published === 1,
			'languages_written'          => array_keys($langPlan),
			'slugs'                      => array_map(
				static fn (array $plan): string => (string) $plan['fields']['slug'],
				$langPlan
			),
		];

		if ($slugNotes !== []) {
			$response['slug_notes'] = $slugNotes;
		}

		$response['next_step'] = 'No products are linked yet. Use set_virtuemart_product_manufacturers.';
		$response['direct_write_note'] = $this->vmDirectWriteNotice();
		$response['component']         = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/** @return array<string,mixed> */
	private function langInputFor(array $arguments, string $tag, bool $multiLanguage): array
	{
		$supplied = [];

		foreach ($this->manufacturerLangWritable() as $column) {
			if (\array_key_exists($column, $arguments)) {
				$supplied[$column] = $arguments[$column];
			}
		}

		$overrides = $arguments['translations'] ?? [];

		if (\is_array($overrides)) {
			foreach ($overrides as $overrideTag => $values) {
				if (strcasecmp((string) $overrideTag, $tag) !== 0 || !\is_array($values)) {
					continue;
				}

				foreach ($values as $column => $value) {
					if (\in_array((string) $column, $this->manufacturerLangWritable(), true)) {
						$supplied[(string) $column] = $value;
					}
				}
			}
		}

		if ($multiLanguage && !isset($overrides[$tag]['slug'])) {
			unset($supplied['slug']);
		}

		return $supplied;
	}
}
