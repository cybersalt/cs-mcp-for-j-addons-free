<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Products;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Create or repair a product's row in one language satellite table.
 *
 * This is the fix for the single most common way a VirtueMart product silently
 * fails to exist. It is deliberately a create-or-update rather than an update:
 * the whole point is that the row is usually absent.
 *
 * Like every write here it is a read-modify-write. An existing row is loaded
 * first and only the named columns change, so repairing a slug does not blank a
 * translated description.
 */
final class SetProductTranslationTool extends AbstractTool
{
	use VirtuemartBootTrait;
	use ProductWriteTrait;

	public function getName(): string { return 'set_virtuemart_product_translation'; }

	public function getDescription(): string
	{
		return 'Create or update one product\'s row in one VirtueMart language satellite table. Requires '
			. 'id and language; language must be one of the shop\'s active_languages. '
			. 'This is the repair for an invisible product. #__virtuemart_products has no product_name '
			. 'column — name, descriptions, meta fields and slug all live in '
			. '#__virtuemart_products_<langsuffix> — and VirtueMart joins the two with an INNER JOIN '
			. '(helpers/vmtable.php:1065-1068). A base row with no language row is therefore not an '
			. 'untranslated product, it is an absent one: no listing, no search result, no category page, '
			. 'no error, no log line. On a single-language shop there is not even a fallback pass, because '
			. 'the fallback at helpers/vmtable.php:1184 requires more than one active language. '
			. 'Read-modify-write: when a row already exists it is loaded first and only the fields you '
			. 'name are changed, so fixing a slug does not blank a translated description. When no row '
			. 'exists one is created, and product_name is then required. '
			. 'The slug is generated from product_name using VirtueMart\'s own transform '
			. '(helpers/vmtable.php:1754-1776) when you leave it empty, and made unique the way '
			. 'checkCreateUnique() would (:1487-1523) — the slug column carries a UNIQUE KEY on this '
			. 'table, so a collision is a hard insert failure rather than a cosmetic one. Slugs are '
			. 'PER-LANGUAGE; each language table has its own unique index, so the same slug in two '
			. 'languages is fine but two products sharing one in the same language is not. '
			. 'Values are length-checked against the LIVE column definition and refused if they would not '
			. 'fit. These columns are generated at install time from configuration '
			. '(helpers/tableupdater.php:95-201), so their widths vary by shop. VirtueMart itself would '
			. 'truncate silently instead (helpers/vmtable.php:1829).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'             => ['type' => 'integer', 'description' => 'virtuemart_product_id. Required.'],
				'language'       => ['type' => 'string', 'description' => 'Language tag, e.g. "de-DE". Required. Must be one of the shop\'s active_languages.'],
				'product_name'   => ['type' => 'string', 'description' => 'Required when the row does not exist yet.'],
				'product_s_desc' => ['type' => 'string', 'description' => 'Short description.'],
				'product_desc'   => ['type' => 'string', 'description' => 'Long description (HTML).'],
				'metadesc'       => ['type' => 'string'],
				'metakey'        => ['type' => 'string'],
				'customtitle'    => ['type' => 'string'],
				'slug'           => ['type' => 'string', 'description' => 'Leave empty or pass "" to generate from product_name. Unique within this language table only.'],
			],
			'required' => ['id', 'language'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->vmAdminBase() === null) {
			return $this->vmNotInstalledError();
		}

		if (!$this->vmTableExists('products')) {
			return $this->vmMissingTableError('products');
		}

		$id = $this->requirePositiveInt($arguments, 'id');

		if (trim((string) ($arguments['language'] ?? '')) === '') {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'language is required. It must be an explicit tag, not a default, because '
					. 'writing a translation into the wrong language table is invisible until a shopper '
					. 'notices.',
				'active_languages' => $this->vmActiveLangTags(),
			], true);
		}

		$tag = $this->vmResolveLangTag($arguments);

		if ($tag instanceof ToolResult) {
			return $tag;
		}

		if (!$this->vmLangTableExists('products', $tag)) {
			return $this->vmMissingTableError('products_' . $this->vmLangSuffix($tag));
		}

		$exists = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName($this->vmTable('products')))
				->where($this->db->quoteName('virtuemart_product_id') . ' = ' . $id)
		)->loadResult();

		if ($exists === 0) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No product with virtuemart_product_id ' . $id . ' in '
					. $this->vmTable('products') . '. A language row without a base row is an orphan that '
					. 'VirtueMart never reads and that occupies a slug in the UNIQUE index. Refusing; '
					. 'nothing was written.',
			], true);
		}

		$langTable = $this->vmLangTable('products', $tag);

		$current = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($langTable))
				->where($this->db->quoteName('virtuemart_product_id') . ' = ' . $id)
		)->loadAssoc();

		$rowExists = \is_array($current);

		$supplied = [];

		foreach ($this->productLangWritable() as $column) {
			if (\array_key_exists($column, $arguments)) {
				$supplied[$column] = $arguments[$column];
			}
		}

		if ($supplied === []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Nothing to write. Supply at least one of product_name, product_s_desc, '
					. 'product_desc, metadesc, metakey, customtitle or slug.',
			], true);
		}

		if (!$rowExists && trim((string) ($supplied['product_name'] ?? '')) === '') {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Product ' . $id . ' has no row in ' . $langTable . ' yet, so one must be '
					. 'created, and product_name is required for that. VirtueMart declares it an '
					. 'obligatory key (tables/products.php:115) and derives the slug from it. Nothing was '
					. 'written.',
			], true);
		}

		$built = $this->buildLangRow($supplied, $rowExists ? $current : [], $langTable, $id);

		if ($built instanceof ToolResult) {
			return $built;
		}

		$fields  = $built['fields'];
		$changed = [];

		foreach ($fields as $column => $value) {
			if ((string) $value !== (string) ($current[$column] ?? '')) {
				$changed[] = (string) $column;
			}
		}

		if ($rowExists && $changed === []) {
			return ToolResult::json([
				'ok'       => true,
				'created'  => false,
				'changed'  => [],
				'language' => $tag,
				'note'     => 'Every supplied value already matches what is stored in ' . $langTable
					. '. Nothing was written.',
			]);
		}

		$this->writeLangRow($langTable, $id, $fields, $rowExists);

		// The base row's modified stamp is the only signal anyone has that the
		// product changed at all; the language tables carry no such columns.
		$stamp              = new \stdClass();
		$stamp->virtuemart_product_id = $id;
		$stamp->modified_on = $this->vmNow();
		$stamp->modified_by = (int) $actor->id;

		$this->db->updateObject($this->vmTable('products'), $stamp, 'virtuemart_product_id');

		$response = [
			'ok'                    => true,
			'virtuemart_product_id' => $id,
			'language'              => ['tag' => $tag, 'suffix' => $this->vmLangSuffix($tag), 'table' => $langTable],
			'created'               => !$rowExists,
			'changed'               => $changed,
			'slug'                  => (string) $fields['slug'],
		];

		if ($built['slug_note'] !== null) {
			$response['slug_note'] = $built['slug_note'];
		}

		if (!$rowExists) {
			$response['visibility_note'] = 'This product previously had NO row in ' . $langTable
				. ', which made it invisible to every shopper browsing in ' . $tag . '. It is now visible '
				. 'to them, subject to the usual conditions — published = 1, at least one published '
				. 'category, and a price row. get_virtuemart_product returns the full checklist.';
		}

		$stillMissing = [];

		foreach ($this->vmActiveLangTags() as $other) {
			if ($other === $tag || !$this->vmLangTableExists('products', $other)) {
				continue;
			}

			$found = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select('COUNT(*)')
					->from($this->db->quoteName($this->vmLangTable('products', $other)))
					->where($this->db->quoteName('virtuemart_product_id') . ' = ' . $id)
			)->loadResult();

			if ($found === 0) {
				$stillMissing[] = $other;
			}
		}

		if ($stillMissing !== []) {
			$response['still_missing_languages'] = $stillMissing;
			$response['still_missing_note'] = 'This product still has no language row for: '
				. implode(', ', $stillMissing) . '. It remains invisible to shoppers on those languages.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}
}
