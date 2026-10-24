<?php
/**
 * @package     MCP for jDownloads
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Files;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Downloads\DownloadSupportTrait;
use Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\JdownloadsBootTrait;
use Joomla\CMS\User\User;

/**
 * The MCP equivalent of jDownloads' "select a file from the server to update
 * this download" (`DownloadTable::checkData()` lines 768-809): a file waiting
 * in the TOP of the upload folder (where jDownloads' Files view and its
 * uploader put them) is cleaned-named, moved into the download's category
 * folder, and the row gets its name, size (vendor format), MD5 and SHA-1.
 *
 * Path rules: the source must resolve, with symlinks followed, to a regular
 * file directly in the upload root, or to an unclaimed file already in this
 * download's own category folder. No "..", no absolute paths, no symlinks,
 * no other folder.
 */
final class AttachFileTool extends AbstractTool
{
	use JdownloadsBootTrait;
	use DownloadSupportTrait;

	public function getName(): string { return 'attach_jdownloads_file'; }

	public function getDescription(): string
	{
		return 'Attach a file to a jDownloads download. The file must already be on the server, inside the jDownloads '
			. 'upload folder: either at its top level (where jDownloads\' Files manager and FTP uploads put files; it is '
			. 'then moved into the download\'s category folder, renamed with jDownloads\' file-name rules and made unique '
			. 'there) or already in the download\'s own category folder and not used by another download. Nothing '
			. 'outside the upload folder, no "..", no symbolic links, and never a type jDownloads\' upload settings block '
			. '(or any server-executable type). Sets the file name, size, MD5 and SHA-1 (and the file date if empty). A '
			. 'download that already has a file needs replace: true; the old file is kept on disk unless '
			. 'delete_replaced_file is true. list_jdownloads_unassigned_files shows what can be attached.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'download_id'          => ['type' => 'integer'],
				'source'               => ['type' => 'string', 'description' => 'Path relative to the upload folder: "file.zip" (top level) or "<category folder>/file.zip" (this download\'s own folder).'],
				'replace'              => ['type' => 'boolean', 'description' => 'Required when the download already has a file (or an external URL / another download\'s file).'],
				'delete_replaced_file' => ['type' => 'boolean', 'description' => 'With replace: delete the previous file from disk. Default false (it is kept).'],
			],
			'required'             => ['download_id', 'source'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->jdInstalled()) {
			return $this->jdNotInstalledError();
		}

		$this->jdRequireAccess($actor);

		$id  = $this->requirePositiveInt($arguments, 'download_id');
		$row = $this->jdDownloadRow($id);

		if ($row === null) {
			throw new \InvalidArgumentException('No jDownloads download with id ' . $id . '.');
		}

		$this->dlRequireEdit($actor, $row);
		$this->jdRequireNotCheckedOut($row, $actor, 'Download ' . $id);

		$root   = $this->jdRequireUploadRoot();
		$cat    = $this->jdCategoryRow((int) $row['catid']);
		$catDir = $cat !== null ? $this->jdCategoryAbsPath($cat) : null;

		if ($catDir === null || !is_dir($catDir)) {
			throw new \RuntimeException('The download\'s category folder is missing on disk. Fix that first (check_jdownloads_health). Nothing was written.');
		}

		// ---------------------------------------------------------------- source
		$source = str_replace('\\', '/', trim((string) ($arguments['source'] ?? '')));

		if ($source === '' || str_starts_with($source, '/') || preg_match('#(^|/)\.\.?(/|$)#', $source)
			|| str_contains($source, "\0") || str_contains($source, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $source)) {
			throw new \InvalidArgumentException('source must be a plain path relative to the jDownloads upload folder, with no "..", leading slash or scheme. Nothing was written.');
		}

		$srcAbs  = $root . '/' . $source;
		$srcReal = $this->jdResolveInsideRoot($srcAbs);

		if (!is_file($srcReal)) {
			throw new \InvalidArgumentException('source is not a regular file. Nothing was written.');
		}

		$srcName    = basename($source);
		$srcDirReal = str_replace('\\', '/', (string) realpath(\dirname($srcAbs)));
		$rootReal   = str_replace('\\', '/', (string) realpath($root));
		$catReal    = str_replace('\\', '/', (string) realpath($catDir));

		if (\dirname($srcAbs) !== $root && is_link(\dirname($srcAbs))) {
			throw new \InvalidArgumentException('The source folder is a symbolic link. Refusing. Nothing was written.');
		}

		if ($srcDirReal === $rootReal) {
			$fromRoot = true;
		} elseif ($srcDirReal === $catReal) {
			$fromRoot = false;
		} else {
			throw new \InvalidArgumentException(
				'source must be directly in the upload folder, or in this download\'s own category folder ("'
				. $this->jdCategoryRelPath($cat) . '"). Files in other category folders belong to other categories. Nothing was written.'
			);
		}

		$this->jdRequireSafeSegment($srcName, 'The file name');

		if (\in_array(strtolower($srcName), ['index.html', 'index.htm'], true)) {
			throw new \InvalidArgumentException('Refusing to attach a folder index file. Nothing was written.');
		}

		$this->jdRequireAllowedExtension($srcName);

		if (!$fromRoot) {
			if ($srcName === (string) $row['url_download']) {
				throw new \InvalidArgumentException('That file is already this download\'s file. Nothing was written.');
			}

			$claimed = $this->db->setQuery(
				'SELECT id FROM #__jdownloads_files WHERE catid = ' . (int) $row['catid'] . ' AND url_download = '
				. $this->db->quote($srcName) . ' AND id <> ' . $id
			)->loadColumn() ?: [];

			if ($claimed !== []) {
				throw new \InvalidArgumentException('That file belongs to download ' . implode(', ', $claimed) . '. Nothing was written.');
			}
		}

		// ---------------------------------------------------------------- replace?
		$hasFile = (string) $row['url_download'] !== '' || (int) $row['other_file_id'] > 0 || trim((string) $row['extern_file']) !== '';

		if ($hasFile && ($arguments['replace'] ?? false) !== true) {
			throw new \RuntimeException(
				'Download ' . $id . ' already has a file ("' . ($row['url_download'] ?: ($row['extern_file'] ?: 'the file of download ' . $row['other_file_id']))
				. '"). Pass replace: true to swap it. Nothing was written.'
			);
		}

		$oldAbs = $this->dlFileAbs($row, $cat);

		// ---------------------------------------------------------------- target name
		if ($fromRoot) {
			// DownloadTable::checkData() lines 595-614: clean the name, then number it until it is free.
			$ext   = (string) pathinfo($srcName, PATHINFO_EXTENSION);
			$base  = $this->jdCleanName((string) pathinfo($srcName, PATHINFO_FILENAME));
			$clean = $ext !== '' ? $base . '.' . $ext : $base;
			$this->jdRequireSafeSegment($clean, 'The cleaned file name');

			$only   = (string) pathinfo($clean, PATHINFO_FILENAME);
			$target = $clean;
			$num    = 0;

			while (file_exists($catDir . '/' . $target)) {
				$target = $only . $num++ . ($ext !== '' ? '.' . $ext : '');

				if ($num > 5000) {
					throw new \RuntimeException('Could not find a free file name in the category folder. Nothing was written.');
				}
			}

			$this->jdRequireFits('file name', $target, 400);
			$targetAbs = $catDir . '/' . $target;
		} else {
			$target    = $srcName;
			$targetAbs = $srcReal;
		}

		// ---------------------------------------------------------------- write
		if ($fromRoot && !@rename($srcReal, $targetAbs)) {
			throw new \RuntimeException('Could not move the file into the category folder (permissions?). Nothing was written.');
		}

		$params = $this->jdParams();
		$cols   = [
			'url_download'  => $target,
			'size'          => $this->jdFormatSize($targetAbs),
			'md5_value'     => (string) md5_file($targetAbs),
			'sha1_value'    => (string) sha1_file($targetAbs),
			'other_file_id' => 0,
			'extern_file'   => '',
			'extern_site'   => 0,
		];

		if (empty($row['file_date'])) {
			$cols['file_date'] = $this->jdNow();
		}

		if ((int) $params->get('use_file_type_symbols', 1) === 1) {
			$helper  = $this->jdHelper();
			$iconDir = (string) $helper::getFileTypeIconPath($params->get('selected_file_type_icon_set'));
			$icon    = strtolower((string) pathinfo($target, PATHINFO_EXTENSION)) . '.png';

			$cols['file_pic'] = is_file($iconDir . $icon) ? $icon : (string) $params->get('file_pic_default_filename', 'unknown.png');
		}

		try {
			$table = $this->jdVendorTable('Download');

			if (!$table->load($id)) {
				throw new \RuntimeException('DownloadTable could not load download ' . $id . '.');
			}

			foreach ($cols as $col => $value) {
				$table->{$col} = $value;
			}

			$tags = $this->jdKeepTags($table, 'com_jdownloads.download', $id);

			if (!$table->store()) {
				$error = method_exists($table, 'getError') ? $table->getError() : '';

				throw new \RuntimeException('DownloadTable::store() failed: ' . ($error instanceof \Throwable ? $error->getMessage() : (string) $error));
			}

			if ($tags !== []) {
				$this->jdApplyTagsFix('com_jdownloads.download', $id);
			}
		} catch (\Throwable $e) {
			if ($fromRoot && is_file($targetAbs) && !file_exists($srcReal)) {
				@rename($targetAbs, $srcReal);
			}

			throw $e;
		}

		$replaced = null;

		if ($hasFile && $oldAbs !== null && $oldAbs !== $targetAbs) {
			if (($arguments['delete_replaced_file'] ?? false) === true && is_file($oldAbs)) {
				$this->jdResolveInsideRoot($oldAbs);
				$replaced = @unlink($oldAbs) ? 'previous file deleted' : 'previous file could NOT be deleted';
			} else {
				$replaced = is_file($oldAbs) ? 'previous file kept on disk (it now belongs to no download): ' . $row['url_download'] : 'previous file was not on disk';
			}
		}

		$saved    = $this->jdDownloadRow($id);
		$problems = [];

		foreach ($cols as $col => $value) {
			if ((string) $saved[$col] !== (string) $value) {
				$problems[] = $col . ' is "' . $saved[$col] . '" after the save, not "' . $value . '"';
			}
		}

		if (!is_file($targetAbs)) {
			$problems[] = 'the file is not at ' . $targetAbs . ' after the save';
		}

		foreach (['downloads', 'title', 'description', 'author', 'images'] as $keep) {
			if ((string) $saved[$keep] !== (string) $row[$keep]) {
				$problems[] = $keep . ' changed although it was not passed';
			}
		}

		$response = [
			'ok'       => $problems === [],
			'attached' => [
				'file_name'   => $target,
				'from'        => $source,
				'moved'       => $fromRoot,
				'renamed'     => $fromRoot && $target !== $srcName,
				'bytes'       => (int) filesize($targetAbs),
				'size'        => $cols['size'],
				'md5'         => $cols['md5_value'],
				'sha1'        => $cols['sha1_value'],
			],
			'download' => $this->dlShape($saved, true),
		];

		if ($replaced !== null) {
			$response['replaced'] = $replaced;
		}

		if ($problems !== []) {
			$response['problems'] = $problems;
		}

		return ToolResult::json($response, $problems !== []);
	}
}
