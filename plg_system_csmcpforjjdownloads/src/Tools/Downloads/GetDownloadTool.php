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

final class GetDownloadTool extends AbstractTool
{
	use JdownloadsBootTrait;
	use DownloadSupportTrait;

	public function getName(): string { return 'get_jdownloads_download'; }

	public function getDescription(): string
	{
		return 'One jDownloads download in full: all metadata except its password (has_password says whether one is '
			. 'set), the file\'s path inside the upload folder and whether it is on disk with its real byte size and '
			. 'stored checksums, the download counter and the last 10 download-log entries (IP addresses left out), '
			. 'rating, tags, custom field values and the explicit permission rules on its asset.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => ['id' => ['type' => 'integer', 'description' => 'Download id.']],
			'required'             => ['id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

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

		$db = $this->db;

		$log = $this->jdTableExists('jdownloads_logs')
			? ($db->setQuery(
				'SELECT id, type, log_datetime, log_user, log_file_name, log_file_size FROM #__jdownloads_logs'
				. ' WHERE log_file_id = ' . $id . ' ORDER BY id DESC',
				0,
				10
			)->loadAssocList() ?: [])
			: [];

		$rating = $this->jdTableExists('jdownloads_ratings')
			? $db->setQuery('SELECT rating_sum, rating_count FROM #__jdownloads_ratings WHERE file_id = ' . $id)->loadAssoc()
			: null;

		$fields = $db->setQuery(
			'SELECT f.name, f.title, v.value FROM #__fields_values v INNER JOIN #__fields f ON f.id = v.field_id'
			. ' WHERE f.context = ' . $db->quote('com_jdownloads.download') . ' AND v.item_id = ' . $db->quote((string) $id)
		)->loadAssocList() ?: [];

		$usedBy = $db->setQuery('SELECT id, title FROM #__jdownloads_files WHERE other_file_id = ' . $id)->loadAssocList() ?: [];

		return ToolResult::json([
			'ok'                        => true,
			'download'                  => $this->dlShape($row, true),
			'recent_log'                => $log,
			'rating'                    => $rating ?: null,
			'tags'                      => $this->jdTags('com_jdownloads.download', $id),
			'custom_fields'             => $fields,
			'downloads_using_this_file' => $usedBy,
			'asset_rules'               => $this->jdAssetRules('com_jdownloads.download.' . $id),
		]);
	}
}
