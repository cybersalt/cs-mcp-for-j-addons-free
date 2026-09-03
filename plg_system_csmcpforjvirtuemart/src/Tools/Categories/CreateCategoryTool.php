<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Categories;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Create a category, writing every row it needs to be real: base row, one
 * language row per active language, and the parent xref VirtueMart keeps
 * alongside `category_parent_id`.
 */
final class CreateCategoryTool extends AbstractTool
{
	use VirtuemartBootTrait;
	use CategoryWriteTrait;

	public function getName(): string { return 'create_virtuemart_category'; }

	public function getDescription(): string
	{
		return 'Create a VirtueMart category. Requires category_name. '
			. 'Writes, in one operation: the #__virtuemart_categories base row, a row in the language '
			. 'satellite table for EVERY language in the shop\'s active_languages, and the '
			. '#__virtuemart_category_categories parent xref. '
			. 'The language rows are not optional. #__virtuemart_categories has no category_name column; '
			. 'the name, description, meta fields and slug live in #__virtuemart_categories_<langsuffix> '
			. 'and VirtueMart reads them back with an INNER JOIN (helpers/vmtable.php:1065-1068), so a '
			. 'category without them is invisible — and so is every product whose only category it is. '
			. 'The parent xref is not optional either, even though install.sql:196 annotates '
			. '#__virtuemart_category_categories as "Obsolete since vm3.6.12". VirtueMart still writes it '
			. 'on every save (models/category.php:806-813) and parts of the shop still read it, so a '
			. 'category created without it has a tree position that depends on which query you ask. '
			. 'published defaults to TRUE here, matching the SQL column default for categories '
			. '(install.sql:180) — which is the opposite of the products table, where the default is 0 '
			. '(install.sql:842). VirtueMart is genuinely inconsistent about this, so the value is always '
			. 'set explicitly rather than left to a default. '
			. 'A parent assignment that would create a cycle is refused. VirtueMart has no guard for that '
			. 'and every recursive walk of the tree would loop.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'category_name'        => ['type' => 'string', 'description' => 'Required. Written to every active language unless overridden in `translations`.'],
				'category_description' => ['type' => 'string', 'description' => 'HTML description. Goes to the language table.'],
				'metadesc'             => ['type' => 'string'],
				'metakey'              => ['type' => 'string'],
				'customtitle'          => ['type' => 'string'],
				'slug'                 => ['type' => 'string', 'description' => 'Leave empty to generate from category_name.'],
				'translations'         => [
					'type'                 => 'object',
					'description'          => 'Per-language overrides keyed by language tag, e.g. {"fr-FR": {"category_name": "..."}}.',
					'additionalProperties' => ['type' => 'object'],
				],
				'category_parent_id'      => ['type' => 'integer', 'description' => '0 for a top-level category. Must exist and must not create a cycle.'],
				'published'               => ['type' => 'boolean', 'description' => 'Default true, matching the SQL column default for categories.'],
				'ordering'                => ['type' => 'integer'],
				'shared'                  => ['type' => 'boolean'],
				'category_template'       => ['type' => 'string'],
				'category_layout'         => ['type' => 'string'],
				'category_product_layout' => ['type' => 'string'],
				'products_per_row'        => ['type' => 'string', 'description' => 'Stored as varchar(1) — a single digit.'],
				'limit_list_step'         => ['type' => 'string'],
				'limit_list_initial'      => ['type' => 'integer'],
				'metarobot'               => ['type' => 'string'],
				'metaauthor'              => ['type' => 'string'],
			],
			'required' => ['category_name'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->vmAdminBase() === null) {
			return $this->vmNotInstalledError();
		}

		if (!$this->vmTableExists('categories')) {
			return $this->vmMissingTableError('categories');
		}

		$tags = $this->vmActiveLangTags();

		foreach ($tags as $tag) {
			if (!$this->vmLangTableExists('categories', $tag)) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Refusing to create a category: ' . $this->vmLangTable('categories', $tag)
						. ' does not exist, but ' . $tag . ' is in this shop\'s active_languages. The base '
						. 'row alone would be invisible to those shoppers with no error anywhere.',
					'active_languages' => $tags,
				], true);
			}
		}

		$parentId = (int) ($arguments['category_parent_id'] ?? 0);

		if ($parentId > 0) {
			$exists = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select('COUNT(*)')
					->from($this->db->quoteName($this->vmTable('categories')))
					->where($this->db->quoteName('virtuemart_category_id') . ' = ' . $parentId)
			)->loadResult();

			if ($exists === 0) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'category_parent_id ' . $parentId . ' does not exist. VirtueMart has no '
						. 'foreign keys, so this would have inserted cleanly and produced a category '
						. 'nobody can reach. Refusing; nothing was written.',
				], true);
			}
		}

		// --- validate the language rows BEFORE writing anything ---------------
		$langPlan = [];

		foreach ($tags as $tag) {
			$built = $this->buildCategoryLangRow(
				$this->langInputFor($arguments, $tag, \count($tags) > 1),
				[],
				$this->vmLangTable('categories', $tag),
				0
			);

			if ($built instanceof ToolResult) {
				return $built;
			}

			$langPlan[$tag] = $built;
		}

		// --- write ------------------------------------------------------------
		$now = $this->vmNow();

		$row                       = new \stdClass();
		$row->category_parent_id   = $parentId;
		$row->virtuemart_vendor_id = $this->defaultVendorId();
		$row->published            = $this->vmNormalisePublished($arguments['published'] ?? true) ?? 1;
		$row->ordering             = (int) ($arguments['ordering'] ?? 0);
		$row->shared               = (bool) ($arguments['shared'] ?? false) ? 1 : 0;
		$row->has_children         = 0;
		$row->has_medias           = 0;
		$row->hits                 = 0;
		$row->created_on           = $now;
		$row->created_by           = (int) $actor->id;
		$row->modified_on          = $now;
		$row->modified_by          = (int) $actor->id;

		foreach (['category_template', 'category_layout', 'category_product_layout', 'products_per_row', 'limit_list_step', 'metarobot', 'metaauthor'] as $column) {
			if (\array_key_exists($column, $arguments)) {
				$row->{$column} = (string) $arguments[$column];
			}
		}

		if (\array_key_exists('limit_list_initial', $arguments)) {
			$row->limit_list_initial = (int) $arguments['limit_list_initial'];
		}

		$this->db->insertObject($this->vmTable('categories'), $row, 'virtuemart_category_id');

		$categoryId = (int) $row->virtuemart_category_id;

		if ($categoryId <= 0) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'The base row insert returned no id. Nothing further was written.',
			], true);
		}

		$slugNotes = [];

		foreach ($langPlan as $tag => $plan) {
			$this->writeCategoryLangRow($this->vmLangTable('categories', (string) $tag), $categoryId, $plan['fields'], false);

			if ($plan['slug_note'] !== null) {
				$slugNotes[$tag] = $plan['slug_note'];
			}
		}

		$this->syncCategoryParentXref($categoryId, $parentId, (int) $row->ordering);

		if ($parentId > 0) {
			$this->refreshCategoryFlags($parentId);
		}

		$response = [
			'ok'                     => true,
			'virtuemart_category_id' => $categoryId,
			'category_name'          => (string) $arguments['category_name'],
			'category_parent_id'     => $parentId,
			'published'              => (int) $row->published === 1,
			'languages_written'      => array_keys($langPlan),
			'slugs'                  => array_map(
				static fn (array $plan): string => (string) $plan['fields']['slug'],
				$langPlan
			),
			'parent_xref_written'    => $this->vmTableExists('category_categories'),
		];

		if ($slugNotes !== []) {
			$response['slug_notes'] = $slugNotes;
		}

		$response['direct_write_note'] = $this->vmDirectWriteNotice();
		$response['component']         = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/** @return array<string,mixed> */
	private function langInputFor(array $arguments, string $tag, bool $multiLanguage): array
	{
		$supplied = [];

		foreach ($this->categoryLangWritable() as $column) {
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
					if (\in_array((string) $column, $this->categoryLangWritable(), true)) {
						$supplied[(string) $column] = $value;
					}
				}
			}
		}

		// One slug cannot be shared across languages when each language table
		// enforces its own UNIQUE index — but the collision is per table, so a
		// shared value is only a problem when it also collides. Regenerating per
		// language from the (possibly translated) name is the safer default.
		if ($multiLanguage && !isset($overrides[$tag]['slug'])) {
			unset($supplied['slug']);
		}

		return $supplied;
	}

	private function defaultVendorId(): int
	{
		$id = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('MIN(' . $this->db->quoteName('virtuemart_vendor_id') . ')')
				->from($this->db->quoteName($this->vmTable('vendors')))
		)->loadResult();

		return $id > 0 ? $id : 1;
	}
}
