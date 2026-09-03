<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Manufacturers;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Update a manufacturer by read-modify-write, including creating a missing
 * language row.
 */
final class UpdateManufacturerTool extends AbstractTool
{
	use VirtuemartBootTrait;
	use ManufacturerWriteTrait;

	public function getName(): string { return 'update_virtuemart_manufacturer'; }

	public function getDescription(): string
	{
		return 'Update an existing VirtueMart manufacturer. Requires id (virtuemart_manufacturer_id). '
			. 'READ-MODIFY-WRITE: the whole current row is loaded, your fields are merged onto it, and the '
			. 'merged whole is written back. Product links and media links are never touched. '
			. 'Language fields (mf_name, mf_email, mf_desc, mf_url, metadesc, metakey, customtitle, slug) '
			. 'are written to the language given by `language`, defaulting to the shop language, and do '
			. 'NOT propagate to other languages. If the row for that language is missing it is created, '
			. 'provided mf_name is supplied — a manufacturer with no language row is invisible to those '
			. 'shoppers because the read join is INNER (helpers/vmtable.php:1065-1068). '
			. 'Changing mf_name does NOT regenerate the slug, since that would rewrite a live brand URL. '
			. 'Pass slug: "" to regenerate deliberately. '
			. 'mf_email is validated before anything is written. VirtueMart itself does not validate it.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'          => ['type' => 'integer', 'description' => 'virtuemart_manufacturer_id. Required.'],
				'language'    => ['type' => 'string', 'description' => 'Which language satellite table the language fields go to. Defaults to the shop language.'],
				'mf_name'     => ['type' => 'string'],
				'mf_desc'     => ['type' => 'string'],
				'mf_url'      => ['type' => 'string'],
				'mf_email'    => ['type' => 'string'],
				'metadesc'    => ['type' => 'string'],
				'metakey'     => ['type' => 'string'],
				'customtitle' => ['type' => 'string'],
				'slug'        => ['type' => 'string', 'description' => 'Pass "" to regenerate from mf_name.'],
				'virtuemart_manufacturercategories_id' => ['type' => 'integer'],
				'published'   => ['type' => 'boolean'],
				'metarobot'   => ['type' => 'string'],
				'metaauthor'  => ['type' => 'string'],
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

		if (!$this->vmTableExists('manufacturers')) {
			return $this->vmMissingTableError('manufacturers');
		}

		$id  = $this->requirePositiveInt($arguments, 'id');
		$tag = $this->vmResolveLangTag($arguments);

		if ($tag instanceof ToolResult) {
			return $tag;
		}

		if (!$this->vmLangTableExists('manufacturers', $tag)) {
			return $this->vmMissingTableError('manufacturers_' . $this->vmLangSuffix($tag));
		}

		$current = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->vmTable('manufacturers')))
				->where($this->db->quoteName('virtuemart_manufacturer_id') . ' = ' . $id)
		)->loadAssoc();

		if (!\is_array($current)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No manufacturer with virtuemart_manufacturer_id ' . $id . '. Nothing was '
					. 'written.',
			], true);
		}

		$deltas = [];

		foreach ($this->manufacturerBaseWritable() as $column) {
			if (!\array_key_exists($column, $arguments)) {
				continue;
			}

			$deltas[$column] = match ($column) {
				'virtuemart_manufacturercategories_id' => (int) $arguments[$column],
				'published' => $this->vmNormalisePublished($arguments[$column]) ?? (int) $current['published'],
				default     => (string) $arguments[$column],
			};
		}

		$merged = $this->vmMergeForWrite($current, $deltas, $this->manufacturerBaseWritable());

		$categoryId = (int) $merged['data']['virtuemart_manufacturercategories_id'];

		if ($categoryId > 0
			&& $categoryId !== (int) $current['virtuemart_manufacturercategories_id']
			&& $this->vmTableExists('manufacturercategories')) {
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
						. 'Refusing; nothing was written.',
				], true);
			}
		}

		$langTable    = $this->vmLangTable('manufacturers', $tag);
		$existingLang = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($langTable))
				->where($this->db->quoteName('virtuemart_manufacturer_id') . ' = ' . $id)
		)->loadAssoc();

		$langExists   = \is_array($existingLang);
		$langSupplied = [];

		foreach ($this->manufacturerLangWritable() as $column) {
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
					'error' => 'Manufacturer ' . $id . ' has no row in ' . $langTable . ' and no language '
						. 'fields were supplied, so one cannot be created. It is invisible to ' . $tag
						. ' shoppers. Supply at least mf_name.',
				], true);
			}

			$built = $this->buildManufacturerLangRow($langSupplied, $langExists ? $existingLang : [], $langTable, $id);

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
				'virtuemart_manufacturer_id' => $id,
			]);
		}

		$now = $this->vmNow();

		if ($merged['changed'] !== []) {
			$row                             = new \stdClass();
			$row->virtuemart_manufacturer_id = $id;
			$row->modified_on                = $now;
			$row->modified_by                = (int) $actor->id;

			foreach ($this->manufacturerBaseWritable() as $column) {
				if (\array_key_exists($column, $merged['data'])) {
					$row->{$column} = $merged['data'][$column];
				}
			}

			$this->db->updateObject($this->vmTable('manufacturers'), $row, 'virtuemart_manufacturer_id', true);
		}

		if ($langFields !== null && ($langChanged !== [] || !$langExists)) {
			$this->writeManufacturerLangRow($langTable, $id, $langFields, $langExists);
		}

		$response = [
			'ok'                         => true,
			'virtuemart_manufacturer_id' => $id,
			'language'                   => ['tag' => $tag, 'table' => $langTable],
			'changed_base_columns'       => $merged['changed'],
			'changed_lang_columns'       => $langChanged,
			'modified_on'                => $merged['changed'] === [] ? null : $now,
		];

		if ($merged['rejected'] !== []) {
			$response['rejected_columns'] = $merged['rejected'];
		}

		if ($slugNote !== null) {
			$response['slug_note'] = $slugNote;
		}

		if (!$langExists && $langFields !== null) {
			$response['language_row_created'] = 'This manufacturer had no row in ' . $langTable
				. ' and one was created. It was invisible to ' . $tag . ' shoppers until now.';
		}

		if (\array_key_exists('mf_name', $arguments) && !\array_key_exists('slug', $arguments)) {
			$response['slug_unchanged_note'] = 'mf_name changed but the slug was left alone, because '
				. 'rewriting a live brand URL breaks inbound links. Pass slug: "" to regenerate.';
		}

		$response['direct_write_note'] = $this->vmDirectWriteNotice();
		$response['component']         = $this->vmComponentNotice();

		return ToolResult::json($response);
	}
}
