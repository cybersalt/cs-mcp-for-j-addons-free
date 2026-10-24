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
use Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\JdownloadsBootTrait;
use Joomla\CMS\User\User;

/**
 * Read-only. Lists files names and sizes only; never reads file contents.
 */
final class ListUnassignedFilesTool extends AbstractTool
{
	use JdownloadsBootTrait;

	public function getName(): string { return 'list_jdownloads_unassigned_files'; }

	public function getDescription(): string
	{
		return 'Files on disk that no jDownloads download uses, i.e. what attach_jdownloads_file can attach: files '
			. 'waiting at the top of the upload folder (jDownloads\' Files manager / FTP uploads) and, unless '
			. 'include_category_folders is false, files in category folders that no download in that category points at '
			. '(for example left behind by a deletion or a replaced file). Names, sizes and dates only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'category_id'              => ['type' => 'integer', 'description' => 'Only this category\'s folder (and skip the upload root).'],
				'include_category_folders' => ['type' => 'boolean', 'description' => 'Default true.'],
				'limit'                    => ['type' => 'integer', 'description' => 'Max files per group. Default 200, max 1000.'],
			],
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

		$root  = $this->jdRequireUploadRoot();
		$limit = $this->jdLimit($arguments, 200, 1000);
		$db    = $this->db;
		$out   = ['ok' => true, 'upload_root' => $root];

		$describe = static function (string $abs): array {
			return ['bytes' => (int) @filesize($abs), 'modified' => gmdate('Y-m-d H:i:s', (int) @filemtime($abs))];
		};

		$onlyCat = \array_key_exists('category_id', $arguments) ? (int) $arguments['category_id'] : 0;

		if ($onlyCat === 0) {
			$waiting = [];

			foreach ($this->jdFilesIn($root, $limit) as $name) {
				$waiting[] = ['source' => $name] + $describe($root . '/' . $name);
			}

			$out['upload_root_files'] = $waiting;
		}

		if (($arguments['include_category_folders'] ?? true) !== false || $onlyCat > 0) {
			$where = 'parent_id > 0' . ($onlyCat > 0 ? ' AND id = ' . $onlyCat : '');
			$cats  = $db->setQuery('SELECT * FROM #__jdownloads_categories WHERE ' . $where . ' ORDER BY lft')->loadAssocList() ?: [];

			if ($onlyCat > 0 && $cats === []) {
				throw new \InvalidArgumentException('No jDownloads category with id ' . $onlyCat . '.');
			}

			$orphans = [];

			foreach ($cats as $cat) {
				$abs = $this->jdCategoryAbsPath($cat);

				if ($abs === null || !is_dir($abs)) {
					continue;
				}

				$used = array_flip($db->setQuery(
					'SELECT url_download FROM #__jdownloads_files WHERE catid = ' . (int) $cat['id'] . " AND url_download <> ''"
				)->loadColumn() ?: []);

				foreach ($this->jdFilesIn($abs) as $name) {
					if (isset($used[$name])) {
						continue;
					}

					$orphans[] = [
						'source'      => $this->jdCategoryRelPath($cat) . '/' . $name,
						'category_id' => (int) $cat['id'],
					] + $describe($abs . '/' . $name);

					if (\count($orphans) >= $limit) {
						break 2;
					}
				}
			}

			$out['category_folder_files_without_download'] = $orphans;
			$out['note'] = 'A file in a category folder can only be attached to a download IN that category.';
		}

		return ToolResult::json($out);
	}
}
