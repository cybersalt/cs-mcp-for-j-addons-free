<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Categories;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Update a category by read-modify-write, and keep both copies of the parent
 * relationship in step.
 */
final class UpdateCategoryTool extends AbstractTool
{
	use VirtuemartBootTrait;
	use CategoryWriteTrait;

	public function getName(): string { return 'update_virtuemart_category'; }

	public function getDescription(): string
	{
		return 'Update an existing VirtueMart category. Requires id (virtuemart_category_id). '
			. 'READ-MODIFY-WRITE: the category\'s whole current row is loaded first, your fields are '
			. 'merged onto it, and the merged whole is written back, so columns you do not name keep '
			. 'their value. Product assignments and media links are never touched by this tool. '
			. 'Language fields (category_name, category_description, metadesc, metakey, customtitle, slug) '
			. 'are written to the language given by `language`, defaulting to the shop language. They do '
			. 'NOT propagate to other languages. A category with no row in a language\'s satellite table '
			. 'is invisible to those shoppers — the read join is INNER '
			. '(helpers/vmtable.php:1065-1068) — and so is any product whose only category it is. If the '
			. 'row is missing for the chosen language it is created, provided category_name is supplied. '
			. 'Changing category_name does NOT regenerate the slug, because rewriting a live category URL '
			. 'silently breaks inbound links. Pass slug: "" to regenerate deliberately. '
			. 'Changing category_parent_id also rewrites the #__virtuemart_category_categories xref, which '
			. 'install.sql:196 calls obsolete but models/category.php:806-813 still writes on every save. '
			. 'Both the old and new parent have their has_children flag recomputed. A move that would '
			. 'create a cycle is refused: VirtueMart has no guard for it and every recursive tree walk '
			. 'would loop until PHP gave up. '
			. 'cat_params is not settable. It is VirtueMart\'s pipe-parameter blob '
			. '(helpers/vmtable.php:1464-1484) whose accepted keys are fixed by a setParameterable() '
			. 'declaration, and a hand-built value with an unexpected key is silently dropped on the next '
			. 'component save.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'                   => ['type' => 'integer', 'description' => 'virtuemart_category_id. Required.'],
				'language'             => ['type' => 'string', 'description' => 'Which language satellite table the language fields go to. Defaults to the shop language.'],
				'category_name'        => ['type' => 'string'],
				'category_description' => ['type' => 'string'],
				'metadesc'             => ['type' => 'string'],
				'metakey'              => ['type' => 'string'],
				'customtitle'          => ['type' => 'string'],
				'slug'                 => ['type' => 'string', 'description' => 'Pass "" to regenerate from category_name.'],
				'category_parent_id'   => ['type' => 'integer', 'description' => '0 for top level. Refused if it would create a cycle.'],
				'published'            => ['type' => 'boolean'],
				'ordering'             => ['type' => 'integer'],
				'shared'               => ['type' => 'boolean'],
				'category_template'    => ['type' => 'string'],
				'category_layout'      => ['type' => 'string'],
				'category_product_layout' => ['type' => 'string'],
				'products_per_row'     => ['type' => 'string'],
				'limit_list_step'      => ['type' => 'string'],
				'limit_list_initial'   => ['type' => 'integer'],
				'metarobot'            => ['type' => 'string'],
				'metaauthor'           => ['type' => 'string'],
			],
			'required' => ['id'],
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

		$id  = $this->requirePositiveInt($arguments, 'id');
		$tag = $this->vmResolveLangTag($arguments);

		if ($tag instanceof ToolResult) {
			return $tag;
		}

		if (!$this->vmLangTableExists('categories', $tag)) {
			return $this->vmMissingTableError('categories_' . $this->vmLangSuffix($tag));
		}

		$current = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->vmTable('categories')))
				->where($this->db->quoteName('virtuemart_category_id') . ' = ' . $id)
		)->loadAssoc();

		if (!\is_array($current)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No category with virtuemart_category_id ' . $id . '. Nothing was written.',
			], true);
		}

		$oldParent = (int) $current['category_parent_id'];

		$deltas = [];

		foreach ($this->categoryBaseWritable() as $column) {
			if (!\array_key_exists($column, $arguments)) {
				continue;
			}

			$deltas[$column] = match ($column) {
				'category_parent_id', 'ordering', 'limit_list_initial' => (int) $arguments[$column],
				'shared'    => (bool) $arguments[$column] ? 1 : 0,
				'published' => $this->vmNormalisePublished($arguments[$column]) ?? (int) $current['published'],
				default     => (string) $arguments[$column],
			};
		}

		$merged   = $this->vmMergeForWrite($current, $deltas, $this->categoryBaseWritable());
		$newParent = (int) $merged['data']['category_parent_id'];

		if ($newParent !== $oldParent) {
			if ($newParent > 0) {
				$exists = (int) $this->db->setQuery(
					$this->db->getQuery(true)
						->select('COUNT(*)')
						->from($this->db->quoteName($this->vmTable('categories')))
						->where($this->db->quoteName('virtuemart_category_id') . ' = ' . $newParent)
				)->loadResult();

				if ($exists === 0) {
					return ToolResult::json([
						'ok'    => false,
						'error' => 'category_parent_id ' . $newParent . ' does not exist. Refusing; '
							. 'nothing was written.',
					], true);
				}
			}

			$cycle = $this->assertNoCategoryCycle($id, $newParent);

			if ($cycle !== null) {
				return $cycle;
			}
		}

		// --- language row ------------------------------------------------------
		$langTable    = $this->vmLangTable('categories', $tag);
		$existingLang = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($langTable))
				->where($this->db->quoteName('virtuemart_category_id') . ' = ' . $id)
		)->loadAssoc();

		$langExists = \is_array($existingLang);

		$langSupplied = [];

		foreach ($this->categoryLangWritable() as $column) {
			if (\array_key_exists($column, $arguments)) {
				$langSupplied[$column] = $arguments[$column];
			}
		}

		$langFields  = null;
		$langChanged = [];
		$slugNote    = null;

		if ($langSupplied !== [] || !$langExists) {
			if (!$langExists && $langSupplied === []) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Category ' . $id . ' has no row in ' . $langTable . ' and no language '
						. 'fields were supplied, so one cannot be created. It is currently invisible to '
						. $tag . ' shoppers. Supply at least category_name.',
				], true);
			}

			$built = $this->buildCategoryLangRow($langSupplied, $langExists ? $existingLang : [], $langTable, $id);

			if ($built instanceof ToolResult) {
				return $built;
			}

			$langFields = $built['fields'];
			$slugNote   = $built['slug_note'];

			foreach ($langFields as $column => $value) {
				if ((string) $value !== (string) ($existingLang[$column] ?? '')) {
					$langChanged[] = (string) $column;
				}
			}
		}

		if ($merged['changed'] === [] && $langChanged === []) {
			return ToolResult::json([
				'ok'      => true,
				'changed' => [],
				'note'    => 'Nothing to do: every supplied value already matches what is stored.',
				'virtuemart_category_id' => $id,
			]);
		}

		// --- write ------------------------------------------------------------
		$now = $this->vmNow();

		if ($merged['changed'] !== []) {
			$row                         = new \stdClass();
			$row->virtuemart_category_id = $id;
			$row->modified_on            = $now;
			$row->modified_by            = (int) $actor->id;

			foreach ($this->categoryBaseWritable() as $column) {
				if (\array_key_exists($column, $merged['data'])) {
					$row->{$column} = $merged['data'][$column];
				}
			}

			$this->db->updateObject($this->vmTable('categories'), $row, 'virtuemart_category_id', true);
		}

		if ($langFields !== null && ($langChanged !== [] || !$langExists)) {
			$this->writeCategoryLangRow($langTable, $id, $langFields, $langExists);
		}

		$this->syncCategoryParentXref($id, $newParent, (int) $merged['data']['ordering']);

		$flags = $this->refreshCategoryFlags($id);

		if ($newParent !== $oldParent) {
			if ($oldParent > 0) {
				$this->refreshCategoryFlags($oldParent);
			}

			if ($newParent > 0) {
				$this->refreshCategoryFlags($newParent);
			}
		}

		$response = [
			'ok'                     => true,
			'virtuemart_category_id' => $id,
			'language'               => ['tag' => $tag, 'table' => $langTable],
			'changed_base_columns'   => $merged['changed'],
			'changed_lang_columns'   => $langChanged,
			'flags'                  => $flags,
			'modified_on'            => $merged['changed'] === [] ? null : $now,
		];

		if ($merged['rejected'] !== []) {
			$response['rejected_columns'] = $merged['rejected'];
		}

		if ($slugNote !== null) {
			$response['slug_note'] = $slugNote;
		}

		if ($newParent !== $oldParent) {
			$response['parent_moved'] = [
				'from' => $oldParent,
				'to'   => $newParent,
				'note' => 'Both copies of the relationship were updated: category_parent_id on the base '
					. 'row and the #__virtuemart_category_categories xref that models/category.php:806-813 '
					. 'writes on every save. has_children was recomputed for the old and new parent.',
			];
		}

		if (!$langExists && $langFields !== null) {
			$response['language_row_created'] = 'This category had no row in ' . $langTable
				. ' and one was created. It was invisible to ' . $tag . ' shoppers until now.';
		}

		if (\array_key_exists('category_name', $arguments) && !\array_key_exists('slug', $arguments)) {
			$response['slug_unchanged_note'] = 'category_name changed but the slug was left alone, '
				. 'because rewriting a live category URL breaks inbound links and search results. Pass '
				. 'slug: "" to regenerate.';
		}

		$response['direct_write_note'] = $this->vmDirectWriteNotice();
		$response['component']         = $this->vmComponentNotice();

		return ToolResult::json($response);
	}
}
