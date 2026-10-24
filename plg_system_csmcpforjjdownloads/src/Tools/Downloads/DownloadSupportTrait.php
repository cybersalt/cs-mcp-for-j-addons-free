<?php
/**
 * @package     MCP for jDownloads
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Downloads;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\ApplicationHelper;

/**
 * Download helpers shared by the download and file tools. Requires
 * JdownloadsBootTrait.
 */
trait DownloadSupportTrait
{
	/**
	 * Argument => [column, max length or type]. These are the fields the
	 * create/update tools write; everything else on the row is left alone.
	 */
	private const DL_FIELDS = [
		'title'            => ['col' => 'title', 'type' => 'string', 'max' => 255],
		'description'      => ['col' => 'description', 'type' => 'string', 'max' => 4294967295],
		'description_long' => ['col' => 'description_long', 'type' => 'string', 'max' => 4294967295],
		'changelog'        => ['col' => 'changelog', 'type' => 'string', 'max' => 65535],
		'version'          => ['col' => 'release', 'type' => 'string', 'max' => 255],
		'file_date'        => ['col' => 'file_date', 'type' => 'date'],
		'size'             => ['col' => 'size', 'type' => 'string', 'max' => 255],
		'author'           => ['col' => 'author', 'type' => 'string', 'max' => 255],
		'author_url'       => ['col' => 'url_author', 'type' => 'url', 'max' => 255],
		'website_url'      => ['col' => 'url_home', 'type' => 'url', 'max' => 255],
		'price'            => ['col' => 'price', 'type' => 'string', 'max' => 20],
		'notes'            => ['col' => 'notes', 'type' => 'string', 'max' => 65535],
		'metadesc'         => ['col' => 'metadesc', 'type' => 'string', 'max' => 65535],
		'metakey'          => ['col' => 'metakey', 'type' => 'string', 'max' => 65535],
		'publish_up'       => ['col' => 'publish_up', 'type' => 'date'],
		'publish_down'     => ['col' => 'publish_down', 'type' => 'date'],
		'featured'         => ['col' => 'featured', 'type' => 'bool'],
	];

	/** JSON-schema properties for the shared fields. */
	protected function dlSchemaProps(): array
	{
		return [
			'title'            => ['type' => 'string', 'description' => 'Title (max 255).'],
			'alias'            => ['type' => 'string', 'description' => 'URL alias; unique within the category.'],
			'category_id'      => ['type' => 'integer', 'description' => 'jDownloads category id. Changing it moves the attached file to the new category\'s folder.'],
			'description'      => ['type' => 'string', 'description' => 'Short description (HTML).'],
			'description_long' => ['type' => 'string', 'description' => 'Long description (HTML).'],
			'changelog'        => ['type' => 'string'],
			'version'          => ['type' => 'string', 'description' => 'Version string (jDownloads "Version", column `release`).'],
			'file_date'        => ['type' => 'string', 'description' => 'File date, YYYY-MM-DD[ HH:MM:SS] UTC; "" clears it.'],
			'license_id'       => ['type' => 'integer', 'description' => 'A list_jdownloads_licenses id; 0 = none.'],
			'author'           => ['type' => 'string'],
			'author_url'       => ['type' => 'string', 'description' => 'http(s) URL or "".'],
			'website_url'      => ['type' => 'string', 'description' => 'Product home page, http(s) URL or "".'],
			'size'             => ['type' => 'string', 'description' => 'Size text as shown ("2.5 MB"). attach_jdownloads_file sets it from the real file.'],
			'price'            => ['type' => 'string', 'description' => 'Display-only price text (max 20).'],
			'access'           => ['type' => 'integer', 'description' => 'Joomla viewing access level id.'],
			'language'         => ['type' => 'string', 'description' => 'Content language code or "*".'],
			'featured'         => ['type' => 'integer', 'enum' => [0, 1]],
			'publish_up'       => ['type' => 'string', 'description' => 'Start publishing, UTC; "" clears.'],
			'publish_down'     => ['type' => 'string', 'description' => 'Finish publishing, UTC; "" clears.'],
			'notes'            => ['type' => 'string', 'description' => 'Internal note.'],
			'metadesc'         => ['type' => 'string'],
			'metakey'          => ['type' => 'string'],
		];
	}

	/**
	 * Validated column values for the arguments given. Only keys present in
	 * $arguments are returned, so an update writes nothing it was not asked to.
	 *
	 * @return array<string,mixed>
	 */
	protected function dlColumns(array $arguments): array
	{
		$cols = [];

		foreach (self::DL_FIELDS as $arg => $def) {
			if (!\array_key_exists($arg, $arguments)) {
				continue;
			}

			$value = $arguments[$arg];

			switch ($def['type']) {
				case 'date':
					$cols[$def['col']] = $this->jdDate($value, $arg);
					break;

				case 'bool':
					$cols[$def['col']] = (int) (bool) $value;
					break;

				case 'url':
					$value = trim((string) $value);

					if ($value !== '' && !preg_match('#^https?://[^\s/]+#i', $value)) {
						throw new \InvalidArgumentException($arg . ' must be an http:// or https:// URL, or "". Nothing was written.');
					}

					$this->jdRequireFits($arg, $value, $def['max']);
					$cols[$def['col']] = $value;
					break;

				default:
					$value = (string) $value;

					if ($def['max'] <= 65535) {
						$this->jdRequireFits($arg, $value, $def['max']);
					}

					$cols[$def['col']] = $value;
			}
		}

		if (\array_key_exists('title', $arguments) && trim((string) $arguments['title']) === '') {
			throw new \InvalidArgumentException('title cannot be empty. Nothing was written.');
		}

		if (\array_key_exists('license_id', $arguments)) {
			$lid = (int) $arguments['license_id'];

			if ($lid > 0 && (int) $this->db->setQuery('SELECT COUNT(*) FROM #__jdownloads_licenses WHERE id = ' . $lid)->loadResult() === 0) {
				throw new \InvalidArgumentException('license_id ' . $lid . ' is not a jDownloads licence (list_jdownloads_licenses). Nothing was written.');
			}

			// The column is a varchar; jDownloads stores the id, or '' for none.
			$cols['license'] = $lid > 0 ? (string) $lid : '';
		}

		if (\array_key_exists('access', $arguments)) {
			$cols['access'] = $this->jdRequireAccessLevel((int) $arguments['access']);
		}

		if (\array_key_exists('language', $arguments)) {
			$cols['language'] = $this->jdRequireLanguage((string) $arguments['language']);
		}

		$up   = \array_key_exists('publish_up', $cols) ? $cols['publish_up'] : null;
		$down = \array_key_exists('publish_down', $cols) ? $cols['publish_down'] : null;

		if ($up !== null && $down !== null && $down < $up) {
			throw new \InvalidArgumentException('publish_down is before publish_up. Nothing was written.');
		}

		return $cols;
	}

	/** An alias unique within the category, as DownloadTable::checkData() requires. */
	protected function dlAlias(?string $requested, string $title, int $catid, int $exceptId): string
	{
		$explicit = $requested !== null && trim($requested) !== '';
		$alias    = ApplicationHelper::stringURLSafe($explicit ? (string) $requested : $title);

		if (trim(str_replace('-', '', $alias)) === '') {
			$alias = gmdate('Y-m-d-H-i-s');
		}

		$this->jdRequireFits('alias', $alias, 191);

		$taken = fn (string $a): bool => (int) $this->db->setQuery(
			'SELECT COUNT(*) FROM #__jdownloads_files WHERE catid = ' . $catid . ' AND alias = ' . $this->db->quote($a) . ' AND id <> ' . $exceptId
		)->loadResult() > 0;

		if (!$taken($alias)) {
			return $alias;
		}

		if ($explicit) {
			throw new \InvalidArgumentException(
				'alias "' . $alias . '" is already used by another download in that category (jDownloads requires it to be '
				. 'unique there). Nothing was written.'
			);
		}

		$n = 2;

		while ($taken($alias . '-' . $n)) {
			$n++;
		}

		return $alias . '-' . $n;
	}

	/** A category a download can live in: real, not ROOT. */
	protected function dlRequireCategory(int $catid): array
	{
		$cat = $this->jdCategoryRow($catid);

		if ($cat === null || (int) $cat['parent_id'] === 0) {
			throw new \InvalidArgumentException('category_id ' . $catid . ' is not a jDownloads category. Nothing was written.');
		}

		return $cat;
	}

	/** Absolute path of the download's own file, or null when it has none. */
	protected function dlFileAbs(array $row, ?array $category = null): ?string
	{
		$name = (string) ($row['url_download'] ?? '');

		if ($name === '') {
			return null;
		}

		$category ??= $this->jdCategoryRow((int) $row['catid']);
		$dir      = $category !== null ? $this->jdCategoryAbsPath($category) : null;

		return $dir !== null ? $dir . '/' . $name : null;
	}

	/** Can this user edit this download (core.edit, or core.edit.own on their own)? */
	protected function dlRequireEdit(\Joomla\CMS\User\User $actor, array $row): void
	{
		$asset = 'com_jdownloads.download.' . (int) $row['id'];
		$own   = (int) $row['created_by'] === (int) $actor->id && $actor->authorise('core.edit.own', $asset);

		if (!$actor->authorise('core.edit', $asset) && !$own) {
			throw new \RuntimeException('This user is not granted core.edit (or core.edit.own) on ' . $asset . '.');
		}
	}

	protected function dlShape(array $row, bool $full = false): array
	{
		$cat     = $this->jdCategoryRow((int) $row['catid']);
		$abs     = $this->dlFileAbs($row, $cat);
		$licence = (int) $row['license'] > 0
			? $this->db->setQuery('SELECT title FROM #__jdownloads_licenses WHERE id = ' . (int) $row['license'])->loadResult()
			: null;

		$fileSource = 'none';

		if ((string) $row['url_download'] !== '') {
			$fileSource = 'own_file';
		} elseif ((int) $row['other_file_id'] > 0) {
			$fileSource = 'file_of_download_' . (int) $row['other_file_id'];
		} elseif (trim((string) $row['extern_file']) !== '') {
			$fileSource = 'external_url';
		}

		$out = [
			'id'             => (int) $row['id'],
			'title'          => (string) $row['title'],
			'alias'          => (string) $row['alias'],
			'category_id'    => (int) $row['catid'],
			'category_title' => $cat['title'] ?? null,
			'published'      => (int) $row['published'],
			'featured'       => (int) $row['featured'],
			'access'         => (int) $row['access'],
			'language'       => (string) $row['language'],
			'version'        => (string) $row['release'],
			'file_name'      => (string) $row['url_download'],
			'file_source'    => $fileSource,
			'file_exists'    => $abs !== null ? is_file($abs) : null,
			'size'           => (string) $row['size'],
			'file_date'      => $row['file_date'],
			'license_id'     => (int) $row['license'],
			'license_title'  => $licence !== null ? $this->jdText((string) $licence) : null,
			'author'         => (string) $row['author'],
			'downloads'      => (int) $row['downloads'],
			'views'          => (int) $row['views'],
			'created'        => $row['created'],
			'modified'       => $row['modified'],
			'has_password'   => (string) $row['password'] !== '',
		];

		if ($full) {
			$out += [
				'description'        => (string) $row['description'],
				'description_long'   => (string) $row['description_long'],
				'changelog'          => (string) $row['changelog'],
				'author_url'         => (string) $row['url_author'],
				'website_url'        => (string) $row['url_home'],
				'external_file'      => (string) $row['extern_file'],
				'other_file_id'      => (int) $row['other_file_id'],
				'file_path'          => $abs !== null ? $this->jdCategoryRelPath($cat ?? []) . '/' . $row['url_download'] : null,
				'file_bytes_on_disk' => ($abs !== null && is_file($abs)) ? (int) filesize($abs) : null,
				'md5'                => (string) $row['md5_value'],
				'sha1'               => (string) $row['sha1_value'],
				'preview_filename'   => (string) $row['preview_filename'],
				'images'             => array_values(array_filter(explode('|', (string) $row['images']))),
				'file_pic'           => (string) $row['file_pic'],
				'price'              => (string) $row['price'],
				'publish_up'         => $row['publish_up'],
				'publish_down'       => $row['publish_down'],
				'created_by'         => (int) $row['created_by'],
				'modified_by'        => (int) $row['modified_by'],
				'submitted_by'       => (int) $row['submitted_by'],
				'user_access'        => (int) $row['user_access'],
				'ordering'           => (int) $row['ordering'],
				'notes'              => (string) $row['notes'],
				'metadesc'           => (string) $row['metadesc'],
				'metakey'            => (string) $row['metakey'],
				'checked_out'        => (int) $row['checked_out'],
				'asset_id'           => (int) $row['asset_id'],
			];
		}

		return $out;
	}
}
