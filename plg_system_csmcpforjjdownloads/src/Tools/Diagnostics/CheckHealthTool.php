<?php
/**
 * @package     MCP for jDownloads
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Categories\CategorySupportTrait;
use Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\JdownloadsBootTrait;
use Joomla\CMS\User\User;

/**
 * Site-wide jDownloads audit: database against disk. Strictly read-only;
 * reads directory listings and file sizes, never file contents.
 */
final class CheckHealthTool extends AbstractTool
{
	use JdownloadsBootTrait;
	use CategorySupportTrait;

	private const SCAN_CAP = 5000;

	public function getName(): string { return 'check_jdownloads_health'; }

	public function getDescription(): string
	{
		return 'jDownloads health audit, read-only: the database checked against the disk. Reports whether '
			. 'com_jdownloads is installed and enabled, its version and plugins; the upload folder (set, present, '
			. 'writable, and whether it sits inside the web root WITHOUT jDownloads\' .htaccess protection, which lets '
			. 'anyone fetch files by URL past access levels and the counter); the category tree (one ROOT, nested-set '
			. 'values, levels, and each category\'s stored folder path against its parent\'s); categories whose folder '
			. 'is missing; downloads whose file is missing from disk, published downloads with no file at all, downloads '
			. 'in a category that does not exist or pointing at another download that does not; files in category '
			. 'folders that no download uses, files waiting at the top of the upload folder, folders that belong to no '
			. 'category; rows checked out; and which jDownloads e-mail notifications are switched on (these tools never '
			. 'send mail).';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->jdInstalled()) {
			return $this->jdNotInstalledError();
		}

		$this->jdRequireAccess($actor);

		$db       = $this->db;
		$errors   = [];
		$warnings = [];
		$info     = [];
		$p        = $this->jdParams();

		// ------------------------------------------------------------ component
		$component = $this->jdComponentInfo();

		if (!$component['enabled']) {
			$errors[] = 'com_jdownloads is installed but DISABLED: the front end and admin are off.';
		}

		$plugins = $db->setQuery(
			"SELECT folder, element, enabled FROM #__extensions WHERE type = 'plugin' AND element LIKE 'jdownloads%' ORDER BY folder, element"
		)->loadAssocList() ?: [];

		foreach ($plugins as $pl) {
			if ($pl['folder'] === 'system' && $pl['element'] === 'jdownloads' && (int) $pl['enabled'] !== 1) {
				$warnings[] = 'plg_system_jdownloads is disabled. It checks jDownloads\' settings on save (including the upload folder protection) and trims old log rows.';
			}
		}

		// ------------------------------------------------------------ upload root
		$root   = $this->jdUploadRoot();
		$upload = ['path' => $root];

		if ($root === '') {
			$errors[] = 'The upload folder (Options > files_uploaddir) is not set. No file can be served or attached.';
		} elseif (!is_dir($root)) {
			$errors[] = 'The upload folder ' . $root . ' does not exist.';
			$upload['exists'] = false;
		} else {
			$upload['exists']   = true;
			$upload['writable'] = is_writable($root);
			$real               = str_replace('\\', '/', (string) realpath($root));
			$web                = str_replace('\\', '/', (string) realpath(JPATH_ROOT));
			$upload['inside_web_root'] = $web !== '' && str_starts_with($real . '/', rtrim($web, '/') . '/');
			$upload['htaccess_present'] = is_file($root . '/.htaccess');
			$upload['anti_leech_option'] = (int) $p->get('anti_leech', 0);

			if (!$upload['writable']) {
				$errors[] = 'The upload folder is not writable by PHP: new categories, uploads and attachments will fail.';
			}

			if ($upload['inside_web_root'] && !$upload['htaccess_present']) {
				$warnings[] = 'The upload folder is inside the web root and has no .htaccess, so every file is reachable at '
					. 'a guessable URL (/' . ltrim(substr($real, \strlen(rtrim($web, '/'))), '/') . '/<category folder>/<file>), '
					. 'bypassing access levels, download limits and the download counter. jDownloads writes its deny-all '
					. '.htaccess there when Options > Security > "anti-leech" is switched on, or move the folder outside the web root.';
			}

			foreach ($this->jdReservedFolders() as $reserved) {
				if (!is_dir($root . '/' . $reserved)) {
					$warnings[] = 'jDownloads\' "' . $reserved . '" folder is missing from the upload folder.';
				}
			}
		}

		// ------------------------------------------------------------ categories
		$cats       = $db->setQuery('SELECT * FROM #__jdownloads_categories ORDER BY lft')->loadAssocList('id') ?: [];
		$treeIssues = $this->catTreeProblems();

		foreach ($treeIssues as $t) {
			$errors[] = 'Category tree: ' . $t;
		}

		$missingFolders = [];
		$catPaths       = [];

		foreach ($cats as $id => $c) {
			if ((int) $c['parent_id'] === 0) {
				continue;
			}

			$rel             = $this->jdCategoryRelPath($c);
			$catPaths[$rel]  = (int) $id;
			$abs             = $root !== '' ? $root . '/' . $rel : null;

			if ($abs === null || !is_dir($abs)) {
				$missingFolders[] = ['id' => (int) $id, 'title' => (string) $c['title'], 'folder' => $rel, 'published' => (int) $c['published']];
			}
		}

		if ($missingFolders !== [] && $root !== '') {
			$errors[] = \count($missingFolders) . ' categor' . (\count($missingFolders) === 1 ? 'y has' : 'ies have') . ' no folder on disk; their downloads cannot be served and jDownloads will not publish them.';
		}

		// ------------------------------------------------------------ downloads
		$downloads = $db->setQuery(
			'SELECT id, title, catid, published, url_download, other_file_id, extern_file, checked_out FROM #__jdownloads_files'
		)->loadAssocList('id') ?: [];

		$missingFiles = [];
		$noFile       = [];
		$badCategory  = [];
		$badOther     = [];
		$used         = [];

		foreach ($downloads as $id => $d) {
			$cat = $cats[(int) $d['catid']] ?? null;

			if ($cat === null || (int) $cat['parent_id'] === 0) {
				$badCategory[] = ['id' => (int) $id, 'title' => (string) $d['title'], 'catid' => (int) $d['catid']];
				continue;
			}

			$name = (string) $d['url_download'];

			if ($name !== '') {
				$rel             = $this->jdCategoryRelPath($cat);
				$used[$rel][$name] = true;

				if ($root !== '' && !is_file($root . '/' . $rel . '/' . $name)) {
					$missingFiles[] = ['id' => (int) $id, 'title' => (string) $d['title'], 'published' => (int) $d['published'], 'expected' => $rel . '/' . $name];
				}
			} elseif ((int) $d['other_file_id'] > 0) {
				if (!isset($downloads[(int) $d['other_file_id']])) {
					$badOther[] = ['id' => (int) $id, 'title' => (string) $d['title'], 'other_file_id' => (int) $d['other_file_id']];
				}
			} elseif (trim((string) $d['extern_file']) === '' && (int) $d['published'] === 1) {
				$noFile[] = ['id' => (int) $id, 'title' => (string) $d['title']];
			}
		}

		if ($missingFiles !== []) {
			$errors[] = \count($missingFiles) . ' download(s) point at a file that is not on disk (visitors get an error).';
		}

		if ($badCategory !== []) {
			$errors[] = \count($badCategory) . ' download(s) are in a category that does not exist.';
		}

		if ($badOther !== []) {
			$errors[] = \count($badOther) . ' download(s) use the file of another download that no longer exists.';
		}

		if ($noFile !== []) {
			$warnings[] = \count($noFile) . ' published download(s) have no file, no external URL and no linked download.';
		}

		// ------------------------------------------------------------ disk scan
		$orphanFiles   = [];
		$orphanFolders = [];
		$waiting       = [];
		$truncated     = false;

		if ($root !== '' && is_dir($root)) {
			$waiting = $this->jdFilesIn($root, 500);

			foreach ($catPaths as $rel => $cid) {
				foreach ($this->jdFilesIn($root . '/' . $rel) as $name) {
					if (!isset($used[$rel][$name])) {
						$orphanFiles[] = $rel . '/' . $name;

						if (\count($orphanFiles) >= 500) {
							$truncated = true;
							break 2;
						}
					}
				}
			}

			$reserved = array_flip($this->jdReservedFolders());
			$scanned  = 0;

			try {
				$it = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
					\RecursiveIteratorIterator::SELF_FIRST
				);

				foreach ($it as $entry) {
					if (++$scanned > self::SCAN_CAP) {
						$truncated = true;
						break;
					}

					if (!$entry->isDir() || $entry->isLink()) {
						continue;
					}

					$rel = ltrim(str_replace('\\', '/', substr($entry->getPathname(), \strlen($root))), '/');
					$top = explode('/', $rel)[0];

					if (isset($reserved[$top]) || isset($catPaths[$rel])) {
						continue;
					}

					$orphanFolders[] = $rel;
				}
			} catch (\Throwable $e) {
				$warnings[] = 'Could not scan the upload folder: ' . $e->getMessage();
			}
		}

		if ($orphanFiles !== []) {
			$warnings[] = \count($orphanFiles) . ($truncated ? '+' : '') . ' file(s) in category folders belong to no download (attach them, or delete them).';
		}

		if ($orphanFolders !== []) {
			$warnings[] = \count($orphanFolders) . ' folder(s) under the upload folder belong to no category. jDownloads\' "scan" can turn them into categories, or they are leftovers.';
		}

		if ($waiting !== []) {
			$info[] = \count($waiting) . ' file(s) are waiting at the top of the upload folder to be attached (list_jdownloads_unassigned_files).';
		}

		// ------------------------------------------------------------ checked out
		$checkedOut = [
			'categories' => array_values(array_map(static fn ($c) => ['id' => (int) $c['id'], 'by' => (int) $c['checked_out']], array_filter($cats, static fn ($c) => (int) $c['checked_out'] > 0))),
			'downloads'  => array_values(array_map(static fn ($d) => ['id' => (int) $d['id'], 'by' => (int) $d['checked_out']], array_filter($downloads, static fn ($d) => (int) $d['checked_out'] > 0))),
		];

		// ------------------------------------------------------------ mail
		$mail = [
			'mail_on_every_download'    => (int) $p->get('send_mailto_option', 0) === 1,
			'mail_on_front_end_upload'  => (int) $p->get('send_mailto_option_upload', 0) === 1,
			'report_form_recipients_set' => trim((string) $p->get('send_mailto_report', '')) !== '',
			'note'                      => 'jDownloads sends these itself on front-end downloads, uploads and reports. No tool in this add-on sends mail.',
		];

		$licences = $this->jdTableExists('jdownloads_licenses') ? (int) $db->setQuery('SELECT COUNT(*) FROM #__jdownloads_licenses')->loadResult() : 0;

		return ToolResult::json([
			'ok'        => $errors === [],
			'component' => $component + ['plugins' => $plugins],
			'counts'    => [
				'categories' => max(0, \count($cats) - 1),
				'downloads'  => \count($downloads),
				'published'  => \count(array_filter($downloads, static fn ($d) => (int) $d['published'] === 1)),
				'licenses'   => $licences,
				'total_download_count' => (int) $db->setQuery('SELECT COALESCE(SUM(downloads),0) FROM #__jdownloads_files')->loadResult(),
			],
			'errors'    => $errors,
			'warnings'  => $warnings,
			'info'      => $info,
			'upload_folder'                 => $upload,
			'categories_missing_folder'     => \array_slice($missingFolders, 0, 100),
			'downloads_missing_file'        => \array_slice($missingFiles, 0, 100),
			'published_downloads_no_file'   => \array_slice($noFile, 0, 100),
			'downloads_in_missing_category' => \array_slice($badCategory, 0, 100),
			'downloads_bad_linked_file'     => \array_slice($badOther, 0, 100),
			'orphan_files'                  => \array_slice($orphanFiles, 0, 100),
			'orphan_folders'                => \array_slice($orphanFolders, 0, 100),
			'files_waiting_in_upload_root'  => \array_slice($waiting, 0, 100),
			'scan_truncated'                => $truncated,
			'checked_out'                   => $checkedOut,
			'notifications'                 => $mail,
		]);
	}
}
