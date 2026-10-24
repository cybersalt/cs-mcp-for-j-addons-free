<?php
/**
 * @package     MCP for jDownloads
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\Categories;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjdownloads\Tools\JdownloadsBootTrait;
use Joomla\CMS\User\User;

/**
 * Creates through jDownloads' own CategoryModel::save(), which is safe for a
 * NEW category under the API application: it reads no request state on this
 * path except `task` (absent, so no save2copy) and builds the folder name,
 * nested-set node, asset and folder exactly as the admin does. What it does
 * silently — store the row, then unpublish it if the folder cannot be made —
 * is caught by the checks before and after the call.
 */
final class CreateCategoryTool extends AbstractTool
{
	use JdownloadsBootTrait;
	use CategorySupportTrait;

	public function getName(): string { return 'create_jdownloads_category'; }

	public function getDescription(): string
	{
		return 'Create a jDownloads category, through jDownloads\' own category model, so it gets its nested-set '
			. 'position, permission asset and folder on disk exactly as the admin makes them. The folder is named after '
			. 'the title (jDownloads\' default "create folders automatically" option; folder_name is only accepted when '
			. 'that option is off), cleaned with jDownloads\' own rules and made unique among its siblings. Refuses '
			. 'before writing anything if that folder already exists on disk, the parent\'s folder is missing, the '
			. 'name is unsafe, or it would take the preview / temp-zip folder name. As in the admin, a subcategory '
			. 'is created with its PARENT\'S access level; pass access to change it afterwards (done in the same '
			. 'call). Nothing is emailed.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'title'       => ['type' => 'string', 'description' => 'Category title (max 255).'],
				'parent_id'   => ['type' => 'integer', 'description' => 'Parent category id; 0 or omitted = top level.'],
				'alias'       => ['type' => 'string', 'description' => 'URL alias; made from the title when omitted. Must be unique among siblings.'],
				'description' => ['type' => 'string', 'description' => 'HTML description.'],
				'published'   => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Default 1.'],
				'access'      => ['type' => 'integer', 'description' => 'Joomla viewing access level id. Default: the parent\'s (Public at top level).'],
				'language'    => ['type' => 'string', 'description' => 'Content language code or "*" (default).'],
				'notes'       => ['type' => 'string', 'description' => 'Internal note.'],
				'metadesc'    => ['type' => 'string'],
				'metakey'     => ['type' => 'string'],
				'folder_name' => ['type' => 'string', 'description' => 'Only when the site has "create category folders automatically" switched OFF.'],
			],
			'required'             => ['title'],
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

		$root   = $this->jdRequireUploadRoot();
		$rootId = $this->jdRootId();
		$parent = $this->catResolveParent($arguments['parent_id'] ?? 0, $rootId);
		$pid    = (int) $parent['id'];
		$asset  = $pid === $rootId ? 'com_jdownloads' : 'com_jdownloads.category.' . $pid;

		$this->jdRequireAccess($actor, 'core.create', $asset);

		$published = \array_key_exists('published', $arguments) ? $this->jdParsePublished($arguments['published']) : 1;

		if ($published === 1 && !$actor->authorise('core.edit.state', $asset)) {
			throw new \RuntimeException('Creating a PUBLISHED category needs core.edit.state on ' . $asset . '. Pass published 0, or grant it.');
		}

		$title = $this->requireString($arguments, 'title');
		$this->jdRequireFits('title', $title, 255);

		$language = $this->jdRequireLanguage((string) ($arguments['language'] ?? '*'));
		$access   = \array_key_exists('access', $arguments) ? $this->jdRequireAccessLevel((int) $arguments['access']) : null;

		foreach (['description', 'notes', 'metadesc', 'metakey'] as $f) {
			if (isset($arguments[$f])) {
				$this->jdRequireFits($f, (string) $arguments[$f], 65535);
			}
		}

		// Work out the folder exactly as CategoryModel::save() will, then check the disk.
		$folderName = isset($arguments['folder_name']) ? (string) $arguments['folder_name'] : null;
		$folder     = $this->catUniqueFolderName($pid, $this->catFolderBase($title, $folderName), 0);
		$this->jdRequireSafeSegment($folder, 'The folder name');
		$this->catRequireNotReserved($folder, $pid, $rootId);

		$parentRel = $this->catParentRelPath($parent, $rootId);
		$parentAbs = $parentRel === '' ? $root : $root . '/' . $parentRel;
		$targetAbs = $parentAbs . '/' . $folder;

		if (!is_dir($parentAbs)) {
			throw new \RuntimeException(
				'The parent category\'s folder is missing on disk (' . $parentAbs . '). jDownloads would create the category '
				. 'and silently leave it unpublished. Fix the parent first (check_jdownloads_health). Nothing was written.'
			);
		}

		if (file_exists($targetAbs)) {
			throw new \RuntimeException(
				'A folder or file named "' . $folder . '" already exists in ' . $parentAbs . ' but belongs to no category. '
				. 'jDownloads would store the category and silently unpublish it because it cannot create its own folder. '
				. 'Rename or remove that folder, or choose another title. Nothing was written.'
			);
		}

		$alias = $this->catAlias(isset($arguments['alias']) ? (string) $arguments['alias'] : null, $title, $pid, 0);

		$data = [
			'id'              => 0,
			'parent_id'       => $pid,
			'title'           => $title,
			'alias'           => $alias,
			'description'     => (string) ($arguments['description'] ?? ''),
			'notes'           => (string) ($arguments['notes'] ?? ''),
			'metadesc'        => (string) ($arguments['metadesc'] ?? ''),
			'metakey'         => (string) ($arguments['metakey'] ?? ''),
			'pic'             => (string) $this->jdParams()->get('cat_pic_default_filename', 'folder.png'),
			'published'       => $published,
			'access'          => $access ?? 1,
			'language'        => $language,
			'created_user_id' => (int) $actor->id,
			'params'          => [],
			// Read only in "manual folder" mode; there an empty value would become a date-stamp folder name.
			'cat_dir'         => ($folderName !== null && trim($folderName) !== '') ? $folderName : $title,
			// As CategoryModel::createCategory() passes them: no explicit rules, everything inherited.
			'rules'           => ['core.create' => [], 'core.delete' => [], 'core.edit' => [], 'core.edit.state' => [], 'core.edit.own' => [], 'download' => []],
		];

		$countBefore = (int) $this->db->setQuery('SELECT COUNT(*) FROM #__jdownloads_categories')->loadResult();

		$model  = $this->getModel('com_jdownloads', 'Category');
		$result = $this->saveAdminModel($model, $data);
		$id     = $result['id'];

		if ($id <= 0) {
			$after = (int) $this->db->setQuery('SELECT COUNT(*) FROM #__jdownloads_categories')->loadResult();

			throw new \RuntimeException(
				'jDownloads refused the category: ' . ($result['error'] !== '' ? $result['error'] : 'no reason given')
				. ($after !== $countBefore ? ' WARNING: the category count changed (' . $countBefore . ' -> ' . $after . '); inspect with list_jdownloads_categories.' : ' Nothing was written.')
			);
		}

		$row      = $this->jdCategoryRow($id);
		$problems = [];
		$notes    = [];

		if ($row === null) {
			throw new \RuntimeException('jDownloads reported category id ' . $id . ' but the row is not there.');
		}

		$abs = $this->jdCategoryAbsPath($row);

		if ($abs === null || !is_dir($abs)) {
			$problems[] = 'the category folder was not created on disk (' . ($abs ?? '?') . ')';
		}

		if ((int) $row['published'] !== $published) {
			$problems[] = 'jDownloads stored the category with published = ' . $row['published'] . ' (it unpublishes a new category when it cannot create its folder)';
		}

		if ($access !== null && (int) $row['access'] !== $access) {
			// JDCategoryTable::checkData() copies the parent's access onto every new subcategory.
			$this->db->setQuery(
				'UPDATE #__jdownloads_categories SET access = ' . $access . ' WHERE id = ' . $id
			)->execute();
			$notes[] = 'jDownloads gave the new subcategory its parent\'s access level (' . $row['access'] . '), as its admin does; '
				. 'it was then set to the requested ' . $access . '.';
			$row = $this->jdCategoryRow($id);
		}

		$treeProblems = $this->catTreeProblems();

		if ($treeProblems !== []) {
			$problems[] = 'the category tree has integrity problems after the insert: ' . implode('; ', $treeProblems);
		}

		if ($this->jdAssetRules('com_jdownloads.category.' . $id) === null) {
			$problems[] = 'no permission asset row was created';
		}

		$response = [
			'ok'       => $problems === [],
			'created'  => true,
			'category' => $this->catShape($row, true),
		];

		if ($problems !== []) {
			$response['problems'] = $problems;
		}

		if ($notes !== []) {
			$response['notes'] = $notes;
		}

		if (!$result['ok'] && $result['error'] !== '') {
			$response['model_warning'] = $result['error'];
		}

		return ToolResult::json($response, $problems !== []);
	}
}
