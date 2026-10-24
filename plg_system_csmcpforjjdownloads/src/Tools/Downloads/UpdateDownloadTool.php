<?php
/**
 * @package     MCP for jDownloads
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Downloads;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\JdownloadsBootTrait;
use Joomla\CMS\User\User;

/**
 * Loads the row into jDownloads' DownloadTable, changes only the named
 * properties and stores it, with the current tags re-supplied. Nothing is
 * bound from a form, so none of the blanking DownloadTable::checkData() does
 * outside the admin form can happen (see JdownloadsBootTrait).
 */
final class UpdateDownloadTool extends AbstractTool
{
	use JdownloadsBootTrait;
	use DownloadSupportTrait;

	public function getName(): string { return 'update_jdownloads_download'; }

	public function getDescription(): string
	{
		return 'Change named fields of one jDownloads download. Only the fields you pass are written; the download '
			. 'counter, file, checksums, screenshots, tags, custom field values and permissions are left exactly as '
			. 'they are (jDownloads\' own admin save cannot be used for this: outside its form it blanks the author and '
			. 'URL fields and deletes the screenshots). Moving to another category_id moves the attached file into that '
			. 'category\'s folder, refusing if a file of the same name is already there. To publish or unpublish use '
			. 'set_jdownloads_download_state; to change the file use attach_jdownloads_file. Refuses while the download '
			. 'is checked out to another user.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => ['id' => ['type' => 'integer', 'description' => 'Download id.']] + $this->dlSchemaProps(),
			'required'             => ['id'],
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

		$id  = $this->requirePositiveInt($arguments, 'id');
		$row = $this->jdDownloadRow($id);

		if ($row === null) {
			throw new \InvalidArgumentException('No jDownloads download with id ' . $id . '.');
		}

		if (array_diff(array_keys($arguments), ['id']) === []) {
			throw new \InvalidArgumentException('Nothing to change: pass at least one field besides id.');
		}

		$this->dlRequireEdit($actor, $row);
		$this->jdRequireNotCheckedOut($row, $actor, 'Download ' . $id);

		$cols     = $this->dlColumns($arguments);
		$oldCatid = (int) $row['catid'];
		$newCatid = \array_key_exists('category_id', $arguments) ? (int) $this->dlRequireCategory((int) $arguments['category_id'])['id'] : $oldCatid;
		$moving   = $newCatid !== $oldCatid;
		$title    = (string) ($cols['title'] ?? $row['title']);

		if ($moving) {
			$this->jdRequireAccess($actor, 'core.create', 'com_jdownloads.category.' . $newCatid);
			$cols['catid'] = $newCatid;
		}

		if (\array_key_exists('alias', $arguments)) {
			$cols['alias'] = $this->dlAlias((string) $arguments['alias'], $title, $newCatid, $id);
		} elseif ($moving) {
			$cols['alias'] = $this->dlAlias(null, (string) $row['alias'], $newCatid, $id);
		}

		if (\array_key_exists('publish_up', $cols) xor \array_key_exists('publish_down', $cols)) {
			$up   = $cols['publish_up'] ?? $row['publish_up'];
			$down = $cols['publish_down'] ?? $row['publish_down'];

			if (!empty($up) && !empty($down) && (string) $down < (string) $up) {
				throw new \InvalidArgumentException('That would put publish_down before publish_up. Nothing was written.');
			}
		}

		// The file follows the category (DownloadTable::checkData() lines 746-765 do the same with File::move()).
		$fileFrom = null;
		$fileTo   = null;

		if ($moving && (string) $row['url_download'] !== '') {
			$fileFrom = $this->dlFileAbs($row);
			$newCat   = $this->jdCategoryRow($newCatid);
			$newDir   = $this->jdCategoryAbsPath($newCat);

			if ($fileFrom === null || !is_file($fileFrom)) {
				throw new \RuntimeException(
					'The download\'s file "' . $row['url_download'] . '" is not in its current category folder, so it cannot be '
					. 'moved with it. Re-attach it (attach_jdownloads_file) or run check_jdownloads_health. Nothing was written.'
				);
			}

			$this->jdResolveInsideRoot($fileFrom);

			if ($newDir === null || !is_dir($newDir)) {
				throw new \RuntimeException('The target category\'s folder is missing on disk. Nothing was written.');
			}

			$fileTo = $newDir . '/' . $row['url_download'];

			if (file_exists($fileTo)) {
				throw new \RuntimeException(
					'"' . $row['url_download'] . '" already exists in the target category\'s folder. Moving would overwrite it '
					. '(jDownloads\' own move does). Rename one of them first. Nothing was written.'
				);
			}
		}

		$countBefore = (int) $this->db->setQuery('SELECT COUNT(*) FROM #__jdownloads_files')->loadResult();

		if ($fileFrom !== null && !@rename($fileFrom, $fileTo)) {
			throw new \RuntimeException('Could not move the file to the new category folder (permissions?). Nothing was written.');
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

			// store() skips NULL properties, so clearing a date needs the null-aware path.
			$clearsDate = \in_array(null, array_intersect_key($cols, array_flip(['file_date', 'publish_up', 'publish_down'])), true);

			if (!$table->store($clearsDate)) {
				$error = method_exists($table, 'getError') ? $table->getError() : '';

				throw new \RuntimeException('DownloadTable::store() failed: ' . ($error instanceof \Throwable ? $error->getMessage() : (string) $error));
			}

			if ($tags !== []) {
				$this->jdApplyTagsFix('com_jdownloads.download', $id);
			}
		} catch (\Throwable $e) {
			if ($fileFrom !== null && is_file($fileTo) && !file_exists($fileFrom)) {
				@rename($fileTo, $fileFrom);
			}

			throw $e;
		}

		$saved    = $this->jdDownloadRow($id);
		$problems = [];

		if ((int) $this->db->setQuery('SELECT COUNT(*) FROM #__jdownloads_files')->loadResult() !== $countBefore) {
			$problems[] = 'the download count changed during an update; something inserted or deleted a row';
		}

		foreach ($cols as $col => $value) {
			if ((string) ($saved[$col] ?? '') !== (string) ($value ?? '')) {
				$problems[] = $col . ' is "' . $saved[$col] . '" after the save, not "' . $value . '"';
			}
		}

		foreach (['downloads', 'url_download', 'md5_value', 'sha1_value', 'images', 'extern_file', 'url_author', 'url_home', 'author'] as $keep) {
			if (!\array_key_exists($keep, $cols) && (string) $saved[$keep] !== (string) $row[$keep]) {
				$problems[] = $keep . ' changed although it was not passed ("' . $row[$keep] . '" -> "' . $saved[$keep] . '")';
			}
		}

		if ($fileTo !== null && !is_file($fileTo)) {
			$problems[] = 'the file is not in the new category folder after the move';
		}

		$changed = [];

		foreach ($saved as $col => $value) {
			if (!\in_array($col, ['modified', 'modified_by', 'checked_out', 'checked_out_time'], true) && (string) ($row[$col] ?? '') !== (string) $value) {
				$changed[] = $col;
			}
		}

		$response = ['ok' => $problems === [], 'updated' => true, 'changed_columns' => $changed, 'download' => $this->dlShape($saved, true)];

		if ($fileTo !== null) {
			$response['file_moved'] = ['to_category' => $newCatid, 'path' => $this->dlShape($saved, true)['file_path']];
		}

		if ($problems !== []) {
			$response['problems'] = $problems;
		}

		return ToolResult::json($response, $problems !== []);
	}
}
