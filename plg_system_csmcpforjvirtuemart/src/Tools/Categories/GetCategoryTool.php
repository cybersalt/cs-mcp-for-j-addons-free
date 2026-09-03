<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Categories;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Return a category's complete state: base row, every language row, its
 * ancestry, its children and its product assignments.
 */
final class GetCategoryTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'get_virtuemart_category'; }

	public function getDescription(): string
	{
		return 'Return one VirtueMart category in full: the #__virtuemart_categories base row, its row in '
			. 'EVERY active language satellite table, its ancestry up to the root, its direct children, '
			. 'its media links and how many products are assigned to it. Requires id. '
			. 'Use this before update_virtuemart_category, the same way you would use '
			. 'get_virtuemart_product before updating a product: the update tool merges your changes onto '
			. 'this whole state rather than treating your input as the complete record. '
			. 'The parent relationship is reported from BOTH places VirtueMart stores it — '
			. 'category_parent_id on the base row, and the #__virtuemart_category_categories xref that '
			. 'install.sql:196 calls obsolete but models/category.php:806-813 still writes on every save. '
			. 'A disagreement between them is reported explicitly. '
			. 'cat_params is returned raw. It is VirtueMart\'s pipe-parameter format — key=<json>| pairs '
			. 'with a trailing pipe, written by VmTable::storeParams() (helpers/vmtable.php:1464-1484) — '
			. 'and the set of keys it accepts is fixed by the table\'s setParameterable() declaration, so '
			. 'keys outside that whitelist are dropped on write. This add-on does not offer a setter for '
			. 'it.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => ['type' => 'integer', 'description' => 'virtuemart_category_id. Required.'],
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

		if (!$this->vmTableExists('categories')) {
			return $this->vmMissingTableError('categories');
		}

		$id = $this->requirePositiveInt($arguments, 'id');

		$base = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->vmTable('categories')))
				->where($this->db->quoteName('virtuemart_category_id') . ' = ' . $id)
		)->loadAssoc();

		if (!\is_array($base)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No category with virtuemart_category_id ' . $id . '.',
			], true);
		}

		$translations = [];
		$missing      = [];

		foreach ($this->vmActiveLangTags() as $tag) {
			$table = $this->vmLangTable('categories', $tag);

			if (!$this->vmLangTableExists('categories', $tag)) {
				$translations[$tag] = ['table' => $table, 'table_exists' => false, 'row' => null];
				$missing[]          = $tag;

				continue;
			}

			$row = $this->db->setQuery(
				$this->db->getQuery(true)
					->select('*')
					->from($this->db->quoteName($table))
					->where($this->db->quoteName('virtuemart_category_id') . ' = ' . $id)
			)->loadAssoc();

			$translations[$tag] = [
				'table'        => $table,
				'table_exists' => true,
				'row'          => \is_array($row) ? $row : null,
			];

			if (!\is_array($row)) {
				$missing[] = $tag;
				$translations[$tag]['warning'] = 'No row. This category — and any product reachable only '
					. 'through it — is invisible to ' . $tag . ' shoppers. The read join is INNER '
					. '(helpers/vmtable.php:1065-1068).';
			}
		}

		$children = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('virtuemart_category_id'),
					$this->db->quoteName('published'),
					$this->db->quoteName('ordering'),
				])
				->from($this->db->quoteName($this->vmTable('categories')))
				->where($this->db->quoteName('category_parent_id') . ' = ' . $id)
				->order($this->db->quoteName('ordering') . ' ASC')
		)->loadAssocList() ?: [];

		$productCount = 0;

		if ($this->vmTableExists('product_categories')) {
			$productCount = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select('COUNT(*)')
					->from($this->db->quoteName($this->vmTable('product_categories')))
					->where($this->db->quoteName('virtuemart_category_id') . ' = ' . $id)
			)->loadResult();
		}

		$response = [
			'ok'           => true,
			'category'     => $base,
			'translations' => $translations,
			'ancestry'     => $this->ancestry($id),
			'children'     => array_map(
				static fn (array $r): array => [
					'virtuemart_category_id' => (int) $r['virtuemart_category_id'],
					'published'              => (int) $r['published'] === 1,
					'ordering'               => (int) $r['ordering'],
				],
				$children
			),
			'medias'        => $this->vmXrefIds('category_medias', 'virtuemart_category_id', $id, 'virtuemart_media_id'),
			'product_count' => $productCount,
		];

		$xrefParent = null;

		if ($this->vmTableExists('category_categories')) {
			$raw = $this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName('category_parent_id'))
					->from($this->db->quoteName($this->vmTable('category_categories')))
					->where($this->db->quoteName('category_child_id') . ' = ' . $id)
			)->loadResult();

			$xrefParent = $raw === null ? null : (int) $raw;
		}

		$response['parent'] = [
			'category_parent_id'      => (int) $base['category_parent_id'],
			'category_categories_row' => $xrefParent,
		];

		if ($xrefParent === null && $this->vmTableExists('category_categories')) {
			$response['parent']['note'] = 'There is no row for this category in '
				. '#__virtuemart_category_categories. VirtueMart writes one on every save '
				. '(models/category.php:806-813), so its absence means this category was created outside '
				. 'the component. Re-saving through update_virtuemart_category creates it.';
		} elseif ($xrefParent !== null && $xrefParent !== (int) $base['category_parent_id']) {
			$response['parent']['mismatch'] = 'The two disagree. Different parts of the shop read '
				. 'different ones, so the category tree renders differently depending on which query '
				. 'built the view. Re-save through update_virtuemart_category to reconcile.';
		}

		$expectedChildren = $children === [] ? 0 : 1;

		if ((int) $base['has_children'] !== $expectedChildren) {
			$response['flag_mismatch'] = 'has_children is ' . (int) $base['has_children'] . ' but this '
				. 'category has ' . \count($children) . ' child(ren). VirtueMart recomputes this on every '
				. 'save (models/category.php:796-799) and uses it to decide whether to walk the subtree.';
		}

		if ($missing !== []) {
			$response['missing_languages'] = $missing;
			$response['warning'] = 'No language row for: ' . implode(', ', $missing) . '. Shoppers on '
				. 'those languages cannot see this category, and any product whose only category is this '
				. 'one becomes unreachable through browsing.';
		}

		if ((int) $base['published'] !== 1) {
			$response['published_note'] = 'This category is unpublished, so its products are not listed '
				. 'under it regardless of their own state.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/**
	 * Walk up category_parent_id to the root, defensively.
	 *
	 * VirtueMart has no cycle guard on this column, so the walk is bounded.
	 *
	 * @return array<int,int>
	 */
	private function ancestry(int $id): array
	{
		$path    = [];
		$current = $id;

		for ($i = 0; $i < 64; $i++) {
			$parent = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName('category_parent_id'))
					->from($this->db->quoteName($this->vmTable('categories')))
					->where($this->db->quoteName('virtuemart_category_id') . ' = ' . $current)
			)->loadResult();

			if ($parent <= 0 || \in_array($parent, $path, true) || $parent === $id) {
				break;
			}

			$path[]  = $parent;
			$current = $parent;
		}

		return $path;
	}
}
