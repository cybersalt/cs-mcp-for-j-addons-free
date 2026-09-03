<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Manufacturers;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;

/**
 * Shared write machinery for the manufacturer tools.
 *
 * Manufacturers follow the same base-row / language-row split as products and
 * categories, with a smaller base row: everything a human recognises is in the
 * satellite table declared at `tables/manufacturers.php:65`.
 */
trait ManufacturerWriteTrait
{
	/** @return array<int,string> */
	protected function manufacturerBaseWritable(): array
	{
		return [
			'virtuemart_manufacturercategories_id',
			'metarobot',
			'metaauthor',
			'published',
		];
	}

	/**
	 * `tables/manufacturers.php:65` plus the auto-appended `slug`.
	 *
	 * @return array<int,string>
	 */
	protected function manufacturerLangWritable(): array
	{
		return [
			'mf_name',
			'mf_email',
			'mf_desc',
			'mf_url',
			'metadesc',
			'metakey',
			'customtitle',
			'slug',
		];
	}

	/**
	 * @param  array<string,mixed> $supplied
	 * @param  array<string,mixed> $existing
	 * @return array{fields:array<string,mixed>,slug_note:?string}|ToolResult
	 */
	protected function buildManufacturerLangRow(array $supplied, array $existing, string $langTable, int $manufacturerId): array|ToolResult
	{
		$fields = [];

		foreach ($this->manufacturerLangWritable() as $column) {
			if (\array_key_exists($column, $supplied)) {
				$fields[$column] = (string) $supplied[$column];
			} elseif (\array_key_exists($column, $existing)) {
				$fields[$column] = (string) $existing[$column];
			} else {
				$fields[$column] = '';
			}
		}

		if (trim($fields['mf_name']) === '') {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'mf_name is empty for ' . $langTable . '. VirtueMart derives the slug from it '
					. 'and aborts the whole save when both are empty (helpers/vmtable.php:1741-1744). '
					. 'Refusing; nothing was written.',
			], true);
		}

		$slugIn         = trim($fields['slug']);
		$fields['slug'] = $this->vmSlugify($slugIn === '' ? $fields['mf_name'] : $slugIn);

		$slugNote = $slugIn === ''
			? 'slug was generated from mf_name using VirtueMart\'s own transform '
				. '(helpers/vmtable.php:1754-1776).'
			: ($fields['slug'] === $slugIn ? null : 'The supplied slug was normalised to "' . $fields['slug'] . '".');

		if ($fields['slug'] === '') {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'The slug reduced to an empty string, so SEF routing for this manufacturer '
					. 'would 404. Supply an explicit slug. Refusing; nothing was written.',
			], true);
		}

		$unique = $this->vmUniqueSlug($fields['slug'], $langTable, 'virtuemart_manufacturer_id', $manufacturerId);

		if (!$unique['unique']) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Could not find a unique slug after 40 attempts (the limit in '
					. 'helpers/vmtable.php:1487-1523). The slug column carries a UNIQUE KEY on '
					. $langTable . '. Refusing; nothing was written.',
			], true);
		}

		if ($unique['slug'] !== $fields['slug']) {
			$slugNote = ($slugNote === null ? '' : $slugNote . ' ')
				. 'It collided and was made unique as "' . $unique['slug'] . '".';
			$fields['slug'] = $unique['slug'];
		}

		if (trim($fields['mf_email']) !== '' && !filter_var($fields['mf_email'], \FILTER_VALIDATE_EMAIL)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'mf_email "' . $fields['mf_email'] . '" is not a valid address. VirtueMart '
					. 'does not validate it and stores whatever it is given, so an invalid value simply '
					. 'fails later at send time. Refusing; nothing was written.',
			], true);
		}

		foreach ($fields as $column => $value) {
			$limit = $this->manufacturerColumnLimit($langTable, (string) $column);

			if ($limit === null) {
				continue;
			}

			$refusal = $this->vmAssertFits((string) $value, $column . ' (in ' . $langTable . ')', $limit);

			if ($refusal !== null) {
				return $refusal;
			}
		}

		return ['fields' => $fields, 'slug_note' => $slugNote];
	}

	protected function manufacturerColumnLimit(string $table, string $column): ?int
	{
		$columns = $this->db->getTableColumns($table, true) ?: [];

		if (!isset($columns[$column])) {
			return null;
		}

		$type = strtolower((string) ($columns[$column]->Type ?? ''));

		if (preg_match('/^(var)?char\((\d+)\)/', $type, $m) === 1) {
			return (int) $m[2];
		}

		return match (true) {
			str_starts_with($type, 'tinytext')   => 255,
			str_starts_with($type, 'mediumtext') => 16777215,
			str_starts_with($type, 'longtext')   => 4294967295,
			str_starts_with($type, 'text')       => 65535,
			default                              => null,
		};
	}

	/** @param array<string,mixed> $fields */
	protected function writeManufacturerLangRow(string $langTable, int $manufacturerId, array $fields, bool $rowExists): void
	{
		$object                             = new \stdClass();
		$object->virtuemart_manufacturer_id = $manufacturerId;

		foreach ($fields as $column => $value) {
			$object->{$column} = $value;
		}

		if ($rowExists) {
			$this->db->updateObject($langTable, $object, 'virtuemart_manufacturer_id');

			return;
		}

		$this->db->insertObject($langTable, $object);
	}
}
