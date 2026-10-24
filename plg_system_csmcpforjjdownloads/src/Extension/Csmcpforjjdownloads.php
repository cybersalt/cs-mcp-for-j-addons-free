<?php
/**
 * @package     MCP for jDownloads
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjdownloads\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * jDownloads MCP add-on. Built against jDownloads 4.1.7 Stable (Arno Betz,
 * 2026-07-24) on Joomla 6.2.0.
 *
 * WHY THIS ADD-ON IS FREE. jDownloads is free (GPL, donation-supported) with
 * no paid edition, so MCP access to it is free too.
 *
 * ---------------------------------------------------------------------------
 * FOUR FACTS SHAPED EVERY TOOL HERE
 * ---------------------------------------------------------------------------
 *
 *   1. EVERY CATEGORY IS ALSO A FOLDER. jDownloads keeps its own nested set of
 *      categories and a folder per category under its upload folder; a
 *      download's file lives in its category's folder. Renaming or moving a
 *      category, or moving a download, is a disk operation too. The tools
 *      check the disk before writing and verify it after.
 *
 *   2. ITS ADMIN SAVE ROUTINES READ THE POSTED FORM. Driven from outside the
 *      form, the download save blanks fields, deletes screenshots and resets
 *      permissions, and the category save mis-moves folders. Downloads are
 *      written through the vendor's DownloadTable; categories are created by
 *      the vendor's CategoryModel and edited through its JDCategoryTable. See
 *      JdownloadsBootTrait for line references.
 *
 *   3. THERE IS NO TRASH. A delete is final and jDownloads' own delete also
 *      removes the file. delete_jdownloads_download requires the download to
 *      be unpublished first and keeps the file unless asked.
 *
 *   4. FILES COME FROM THE UPLOAD FOLDER ONLY. attach_jdownloads_file takes a
 *      file already inside the upload folder, never an arbitrary server path,
 *      never through a symlink, never a type jDownloads' upload settings block.
 *
 * ---------------------------------------------------------------------------
 * WHAT WE DELIBERATELY DO NOT BUILD (v1)
 * ---------------------------------------------------------------------------
 *
 *   - No file upload over MCP: put the file in the upload folder (jDownloads'
 *     Files manager, FTP), then attach it.
 *   - No category delete: jDownloads deletes the folder with it.
 *   - No password, tag, custom field, screenshot or preview-file editing.
 *   - No writes to licences, user-group settings or permissions (read-only).
 */
final class Csmcpforjjdownloads extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Diagnostics.
		\Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Diagnostics\CheckHealthTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Diagnostics\QueryTableTool::class,

		// Categories (jDownloads' own nested set, one folder each).
		\Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Categories\ListCategoriesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Categories\GetCategoryTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Categories\CreateCategoryTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Categories\UpdateCategoryTool::class,

		// Downloads.
		\Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Downloads\ListDownloadsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Downloads\GetDownloadTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Downloads\CreateDownloadTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Downloads\UpdateDownloadTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Downloads\SetDownloadStateTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Downloads\DeleteDownloadTool::class,

		// Files on disk.
		\Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Files\AttachFileTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Files\ListUnassignedFilesTool::class,

		// Read-only lookups.
		\Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Lookups\ListLicensesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Lookups\ListGroupsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Lookups\ListPermissionsTool::class,
	];

	public static function getSubscribedEvents(): array
	{
		/*
		 * Runs on EVERY request. RegisterToolsEvent lives in com_csmcpforj; a bare
		 * ::EVENT_NAME access would autoload it and fatal the whole site (front end
		 * and administrator) when MCP for Joomla is not installed. class_exists()
		 * returns false instead. Do not "simplify" this.
		 */
		if (!class_exists(RegisterToolsEvent::class)) {
			return [];
		}

		return [RegisterToolsEvent::EVENT_NAME => 'onRegisterTools'];
	}

	public function onRegisterTools(RegisterToolsEvent $event): void
	{
		$registry = $event->getRegistry();
		$db       = $this->getDatabase();

		foreach (self::TOOLS as $toolClass) {
			$registry->register(new $toolClass($db));
		}
	}

	public static function getToolClasses(): array
	{
		return self::TOOLS;
	}
}
