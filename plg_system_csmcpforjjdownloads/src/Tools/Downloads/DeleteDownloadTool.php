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
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;

/**
 * jDownloads has no trash, and DownloadModel::delete() cannot run under the
 * API application (it prunes every non-admin-client request as "not
 * permitted", `DownloadModel.php:1436-1546`). So this reproduces its steps:
 * onContentBeforeDelete (a plugin may veto), DownloadTable::delete() (row +
 * asset; Joomla's Taggable behaviour drops the tag map), the rating row,
 * onContentAfterDelete (Joomla's fields plugin deletes the custom field
 * values, Smart Search drops the item). The vendor deletes the file too by
 * default; here that is opt-in, and the "unpublish first" step stands in
 * for the missing trash.
 */
final class DeleteDownloadTool extends AbstractTool
{
	use JdownloadsBootTrait;
	use DownloadSupportTrait;

	public function getName(): string { return 'delete_jdownloads_download'; }

	public function getDescription(): string
	{
		return 'Permanently delete one jDownloads download. jDownloads has no trash, so two safety steps stand in for '
			. 'it: the download must already be UNPUBLISHED (set_jdownloads_download_state), and confirm must be true. '
			. 'Removes the row, its permission asset, tags, rating and custom field values. Its file stays on disk unless '
			. 'delete_file is true (jDownloads\' own delete removes it by default; a kept file shows up in '
			. 'list_jdownloads_unassigned_files and can be attached again). Screenshots and preview files are never '
			. 'deleted. Refuses while other downloads use this download\'s file.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'id'          => ['type' => 'integer', 'description' => 'Download id.'],
				'confirm'     => ['type' => 'boolean', 'description' => 'Must be true.'],
				'delete_file' => ['type' => 'boolean', 'description' => 'Also delete the attached file from disk. Default false.'],
			],
			'required'             => ['id', 'confirm'],
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

		$this->jdRequireAccess($actor, 'core.delete', 'com_jdownloads.download.' . $id);

		if (($arguments['confirm'] ?? false) !== true) {
			throw new \InvalidArgumentException('confirm must be true. Nothing was deleted.');
		}

		if ((int) $row['published'] !== 0) {
			throw new \RuntimeException(
				'Download ' . $id . ' is published. jDownloads has no trash, so unpublish it first '
				. '(set_jdownloads_download_state) and then delete. Nothing was deleted.'
			);
		}

		$this->jdRequireNotCheckedOut($row, $actor, 'Download ' . $id);

		$users = $this->db->setQuery('SELECT id, title FROM #__jdownloads_files WHERE other_file_id = ' . $id)->loadAssocList() ?: [];

		if ($users !== []) {
			throw new \RuntimeException(
				'Other downloads use this download\'s file (' . implode(', ', array_map(static fn ($u) => $u['id'] . ' "' . $u['title'] . '"', $users))
				. '). jDownloads refuses this too. Re-point or delete them first. Nothing was deleted.'
			);
		}

		$deleteFile = ($arguments['delete_file'] ?? false) === true;
		$fileAbs    = $this->dlFileAbs($row);

		if ($deleteFile && $fileAbs !== null && is_file($fileAbs)) {
			// Validate before anything is removed.
			$this->jdResolveInsideRoot($fileAbs);
		}

		$table = $this->jdVendorTable('Download');

		if (!$table->load($id)) {
			throw new \RuntimeException('DownloadTable could not load download ' . $id . '.');
		}

		$app     = Factory::getApplication();
		$context = 'com_jdownloads.download';

		PluginHelper::importPlugin('content');

		$veto = $app->triggerEvent('onContentBeforeDelete', [$context, $table]);

		if (\in_array(false, (array) $veto, true)) {
			throw new \RuntimeException('A content plugin refused the deletion (onContentBeforeDelete). Nothing was deleted.');
		}

		if (!$table->delete($id)) {
			$error = method_exists($table, 'getError') ? $table->getError() : '';

			throw new \RuntimeException('DownloadTable::delete() failed: ' . ($error instanceof \Throwable ? $error->getMessage() : (string) $error));
		}

		if ($this->jdTableExists('jdownloads_ratings')) {
			$this->db->setQuery('DELETE FROM #__jdownloads_ratings WHERE file_id = ' . $id)->execute();
		}

		$app->triggerEvent('onContentAfterDelete', [$context, $table]);

		$fileResult = 'none attached';

		if ($fileAbs !== null) {
			if (!is_file($fileAbs)) {
				$fileResult = 'was already missing from disk';
			} elseif ($deleteFile) {
				$fileResult = @unlink($fileAbs) ? 'deleted' : 'could NOT be deleted (permissions?)';
			} else {
				$fileResult = 'kept on disk: ' . $this->jdCategoryRelPath($this->jdCategoryRow((int) $row['catid']) ?? []) . '/' . $row['url_download'];
			}
		}

		$problems = [];

		if ($this->jdDownloadRow($id) !== null) {
			$problems[] = 'the row is still there';
		}

		if ($this->jdAssetRules('com_jdownloads.download.' . $id) !== null) {
			$problems[] = 'the asset row com_jdownloads.download.' . $id . ' is still there';
		}

		if ($this->jdTagIds('com_jdownloads.download', $id) !== []) {
			$problems[] = 'tag map rows are still there';
		}

		$leftOnDisk = array_values(array_filter(array_merge(
			explode('|', (string) $row['images']),
			[(string) $row['preview_filename']]
		)));

		$response = [
			'ok'      => $problems === [],
			'deleted' => ['id' => $id, 'title' => (string) $row['title'], 'downloads_counted' => (int) $row['downloads']],
			'file'    => $fileResult,
		];

		if ($leftOnDisk !== []) {
			$response['left_on_disk'] = ['screenshots_and_preview' => $leftOnDisk];
		}

		if ($problems !== []) {
			$response['problems'] = $problems;
		}

		return ToolResult::json($response, $problems !== []);
	}
}
