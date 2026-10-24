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
 * Edits go through jDownloads' JDCategoryTable (load, change, store), not
 * CategoryModel::save() — see JdownloadsBootTrait for why the model cannot
 * be used on an existing category. The table supplies the nested-set move
 * (`setLocation()` + `store()` → `moveByReference()`), the asset re-parenting
 * and `modified_*`; `rebuild()` of the moved subtree then rewrites
 * `cat_dir_parent` for it and every descendant, which the vendor's own save
 * skips for a category with no children.
 *
 * The folder is moved with ONE rename() of the category's folder, before the
 * database is touched, and renamed back if the database write fails. A
 * single rename moves the whole subtree intact, which the vendor's
 * copy-then-delete helper does not do beyond one level.
 */
final class UpdateCategoryTool extends AbstractTool
{
	use JdownloadsBootTrait;
	use CategorySupportTrait;

	public function getName(): string { return 'update_jdownloads_category'; }

	public function getDescription(): string
	{
		return 'Change named fields of one jDownloads category; everything else, including its tags, custom field '
			. 'values and permission rules, is left as it is. Changing the title renames the category\'s folder on disk '
			. '(jDownloads names folders after titles), and changing parent_id moves the folder and everything under it; '
			. 'both are checked before anything is written (old folder present, new name free, not into its own '
			. 'branch), done as one rename, and verified afterwards against the database. published goes through '
			. 'jDownloads\' own publish routine, which applies the state to the WHOLE BRANCH below the category and '
			. 'refuses to publish when the folder is missing or a parent category is unpublished. Refuses while the '
			. 'category is checked out to another user. Nothing is emailed.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'id'          => ['type' => 'integer', 'description' => 'Category id.'],
				'title'       => ['type' => 'string', 'description' => 'New title. Renames the folder when the site names folders after titles (the default).'],
				'alias'       => ['type' => 'string', 'description' => 'New alias (unique among siblings).'],
				'parent_id'   => ['type' => 'integer', 'description' => 'Move under this category (0 = top level). Moves the folder with all files and subfolders.'],
				'published'   => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Applies to the whole branch (jDownloads behaviour).'],
				'access'      => ['type' => 'integer', 'description' => 'Joomla viewing access level id. Not propagated to subcategories or downloads.'],
				'language'    => ['type' => 'string'],
				'description' => ['type' => 'string'],
				'notes'       => ['type' => 'string'],
				'metadesc'    => ['type' => 'string'],
				'metakey'     => ['type' => 'string'],
				'folder_name' => ['type' => 'string', 'description' => 'Rename the folder independently of the title. Only when "create category folders automatically" is OFF.'],
			],
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

		$id     = $this->requirePositiveInt($arguments, 'id');
		$rootId = $this->jdRootId();
		$row    = $this->jdCategoryRow($id);

		if ($row === null || $id === $rootId) {
			throw new \InvalidArgumentException('No editable jDownloads category with id ' . $id . '.');
		}

		$fields = array_diff(array_keys($arguments), ['id']);

		if ($fields === []) {
			throw new \InvalidArgumentException('Nothing to change: pass at least one field besides id.');
		}

		$asset = 'com_jdownloads.category.' . $id;
		$own   = (int) $row['created_user_id'] === (int) $actor->id && $actor->authorise('core.edit.own', $asset);

		if (!$actor->authorise('core.edit', $asset) && !$own) {
			throw new \RuntimeException('This user is not granted core.edit (or core.edit.own) on ' . $asset . '.');
		}

		if (\array_key_exists('published', $arguments) && !$actor->authorise('core.edit.state', $asset)) {
			throw new \RuntimeException('Changing published needs core.edit.state on ' . $asset . '.');
		}

		$this->jdRequireNotCheckedOut($row, $actor, 'Category ' . $id);

		// ---------------------------------------------------------------- plan
		$newTitle = \array_key_exists('title', $arguments) ? $this->requireString($arguments, 'title') : (string) $row['title'];
		$this->jdRequireFits('title', $newTitle, 255);

		$oldParent = (int) $row['parent_id'];
		$newParent = \array_key_exists('parent_id', $arguments) ? (int) $this->catResolveParent($arguments['parent_id'], $rootId)['id'] : $oldParent;
		$moving    = $newParent !== $oldParent;

		if ($moving) {
			if (\in_array($newParent, $this->catSubtreeIds($row), true)) {
				throw new \InvalidArgumentException('A category cannot be moved into itself or one of its own subcategories. Nothing was written.');
			}

			$targetAsset = $newParent === $rootId ? 'com_jdownloads' : 'com_jdownloads.category.' . $newParent;

			if (!$actor->authorise('core.create', $targetAsset)) {
				throw new \RuntimeException('Moving here needs core.create on ' . $targetAsset . '.');
			}
		}

		$auto       = (int) $this->jdParams()->get('create_auto_cat_dir', 1) === 1;
		$folderArg  = \array_key_exists('folder_name', $arguments) ? (string) $arguments['folder_name'] : null;
		$titleMoved = \array_key_exists('title', $arguments) && $newTitle !== (string) $row['title'];

		if ($folderArg !== null) {
			$base = $this->catFolderBase($newTitle, $folderArg);   // refuses in automatic mode
		} elseif ($auto && $titleMoved) {
			$base = $this->catFolderBase($newTitle, null);
		} else {
			$base = (string) $row['cat_dir'];
		}

		$newFolder = ($base !== (string) $row['cat_dir'] || $moving)
			? $this->catUniqueFolderName($newParent, $base, $id)
			: (string) $row['cat_dir'];

		$structural = $moving || $newFolder !== (string) $row['cat_dir'];

		$oldRel = $this->jdCategoryRelPath($row);
		$newRel = $oldRel;
		$oldAbs = null;
		$newAbs = null;

		if ($structural) {
			$root = $this->jdRequireUploadRoot();
			$this->jdRequireSafeSegment($newFolder, 'The folder name');
			$this->catRequireNotReserved($newFolder, $newParent, $rootId);

			$parentRow = $this->jdCategoryRow($newParent);
			$parentRel = $this->catParentRelPath($parentRow, $rootId);
			$newRel    = $parentRel === '' ? $newFolder : $parentRel . '/' . $newFolder;
			$oldAbs    = $root . '/' . $oldRel;
			$newAbs    = $root . '/' . $newRel;

			if ($oldRel === '' || !is_dir($oldAbs) || is_link($oldAbs)) {
				throw new \RuntimeException(
					'This category\'s folder is missing on disk (' . $oldAbs . '), so it cannot be renamed or moved. '
					. 'Run check_jdownloads_health. Nothing was written.'
				);
			}

			$this->jdResolveInsideRoot($oldAbs);

			if (!is_dir($parentRel === '' ? $root : $root . '/' . $parentRel)) {
				throw new \RuntimeException('The new parent\'s folder is missing on disk. Nothing was written.');
			}

			if (file_exists($newAbs)) {
				throw new \RuntimeException(
					'"' . $newRel . '" already exists on disk but belongs to no category with that name and parent. Moving onto '
					. 'it would merge or overwrite files. Rename or remove it first. Nothing was written.'
				);
			}
		}

		$alias = null;

		if (\array_key_exists('alias', $arguments)) {
			$alias = $this->catAlias((string) $arguments['alias'], $newTitle, $newParent, $id);
		} elseif ($moving) {
			// Keep the alias unless a new sibling already uses it (JDCategoryTable::checkData() would refuse).
			$alias = $this->catAlias(null, (string) $row['alias'], $newParent, $id);
		}

		$set = [];

		if ($titleMoved) {
			$set['title'] = $newTitle;
		}

		if ($alias !== null) {
			$set['alias'] = $alias;
		}

		if ($newFolder !== (string) $row['cat_dir']) {
			$set['cat_dir'] = $newFolder;
		}

		if (\array_key_exists('access', $arguments)) {
			$set['access'] = $this->jdRequireAccessLevel((int) $arguments['access']);
		}

		if (\array_key_exists('language', $arguments)) {
			$set['language'] = $this->jdRequireLanguage((string) $arguments['language']);
		}

		foreach (self::CAT_TEXT_FIELDS as $arg => $def) {
			if (\array_key_exists($arg, $arguments)) {
				$value = (string) $arguments[$arg];
				$this->jdRequireFits($arg, $value, $def['max']);
				$set[$def['col']] = $value;
			}
		}

		$publish = \array_key_exists('published', $arguments) ? $this->jdParsePublished($arguments['published']) : null;

		// jDownloads' publish routine refuses these two cases (CategoryModel::publish() folder check,
		// JDCategoryTable::publish() ancestor check); refuse them before anything is written.
		if ($publish === 1 && (int) $row['published'] !== 1) {
			$abs = $this->jdCategoryAbsPath($row);

			if (!$structural && ($abs === null || !is_dir($abs))) {
				throw new \RuntimeException('Cannot publish: the category folder is missing on disk, and jDownloads refuses to publish a category without one. Nothing was written.');
			}

			if ($newParent !== $rootId) {
				$p = $this->jdCategoryRow($newParent);

				$lower = (int) $this->db->setQuery(
					'SELECT COUNT(*) FROM #__jdownloads_categories WHERE lft <= ' . (int) $p['lft']
					. ' AND rgt >= ' . (int) $p['rgt'] . ' AND parent_id > 0 AND published < 1'
				)->loadResult();

				if ($lower > 0) {
					throw new \RuntimeException('Cannot publish: a parent category is unpublished, and jDownloads refuses to publish below an unpublished category. Nothing was written.');
				}
			}
		}

		$treeBefore  = $this->catTreeProblems();
		$countBefore = (int) $this->db->setQuery('SELECT COUNT(*) FROM #__jdownloads_categories')->loadResult();
		$notes       = [];
		$problems    = [];
		$moved       = null;

		// ---------------------------------------------------------------- write
		if ($set !== [] || $moving) {
			if ($structural && !@rename($oldAbs, $newAbs)) {
				throw new \RuntimeException('Could not rename ' . $oldAbs . ' to ' . $newAbs . ' (permissions?). Nothing was written.');
			}

			try {
				$table = $this->jdVendorTable('JDCategory');

				if (!$table->load($id)) {
					throw new \RuntimeException('JDCategoryTable could not load category ' . $id . '.');
				}

				foreach ($set as $col => $value) {
					$table->{$col} = $value;
				}

				$tags = $this->jdKeepTags($table, 'com_jdownloads.category', $id);

				if ($moving) {
					$table->setLocation($newParent, 'last-child');
				}

				if (!$table->store()) {
					throw new \RuntimeException('JDCategoryTable::store() failed: ' . $this->errorText($table));
				}

				if ($structural) {
					$fresh = $this->jdCategoryRow($id);

					// The vendor's own subtree rebuild, seeded with the new folder path:
					// rewrites lft/rgt/level and cat_dir_parent for this node and every descendant.
					if ($table->rebuild($id, (int) $fresh['lft'], (int) $fresh['level'], $newRel) === false) {
						$problems[] = 'JDCategoryTable::rebuild() failed after the move: ' . $this->errorText($table)
							. '. Run Categories > Rebuild in the jDownloads admin.';
					}

					$moved = ['from' => $oldRel, 'to' => $newRel];
				}

				if ($tags !== []) {
					$this->jdApplyTagsFix('com_jdownloads.category', $id);
				}
			} catch (\Throwable $e) {
				if ($structural && is_dir($newAbs) && !file_exists($oldAbs)) {
					@rename($newAbs, $oldAbs);
				}

				throw $e;
			}
		}

		if ($publish !== null && $publish !== (int) $row['published']) {
			$current = $this->jdCategoryRow($id);

			if ($problems === []) {
				$model = $this->getModel('com_jdownloads', 'Category');
				$pks   = [$id];

				if (!$model->publish($pks, $publish)) {
					$problems[] = 'jDownloads\' publish routine refused: '
						. ((method_exists($model, 'getError') ? (string) $model->getError() : '') ?: 'no reason given (a subcategory checked out by another user?)');
				} else {
					$branch = \count($this->catSubtreeIds($current)) - 1;

					if ($branch > 0) {
						$notes[] = 'jDownloads applied published = ' . $publish . ' to the ' . $branch . ' subcategor' . ($branch === 1 ? 'y' : 'ies') . ' below it as well.';
					}
				}
			}
		}

		// ---------------------------------------------------------------- verify
		$saved = $this->jdCategoryRow($id);

		if ((int) $this->db->setQuery('SELECT COUNT(*) FROM #__jdownloads_categories')->loadResult() !== $countBefore) {
			$problems[] = 'the category count changed during an update; something inserted or deleted a row';
		}

		foreach ($set as $col => $value) {
			if ((string) $saved[$col] !== (string) $value) {
				$problems[] = $col . ' is "' . $saved[$col] . '" after the save, not "' . $value . '"';
			}
		}

		if ($publish !== null && $problems === [] && (int) $saved['published'] !== $publish) {
			$problems[] = 'published is ' . $saved['published'] . ' after the save, not ' . $publish;
		}

		if ($moving && (int) $saved['parent_id'] !== $newParent) {
			$problems[] = 'parent_id is ' . $saved['parent_id'] . ' after the save, not ' . $newParent;
		}

		if ($structural) {
			if ($this->jdCategoryRelPath($saved) !== $newRel) {
				$problems[] = 'the database now points at folder "' . $this->jdCategoryRelPath($saved) . '" but the folder was moved to "' . $newRel . '"';
			}

			if (!is_dir($newAbs) || file_exists($oldAbs)) {
				$problems[] = 'the folder is not where expected after the move (new exists: ' . (is_dir($newAbs) ? 'yes' : 'no') . ', old still exists: ' . (file_exists($oldAbs) ? 'yes' : 'no') . ')';
			}

			foreach ($this->catSubtreeIds($saved) as $cid) {
				$c = $this->jdCategoryRow($cid);
				$a = $this->jdCategoryAbsPath($c);

				if ($a === null || !is_dir($a)) {
					$problems[] = 'category ' . $cid . '\'s folder "' . $this->jdCategoryRelPath($c) . '" is not on disk after the move';
				}
			}
		}

		$newTreeProblems = array_values(array_diff($this->catTreeProblems(), $treeBefore));

		if ($newTreeProblems !== []) {
			$problems[] = 'new category-tree integrity problems: ' . implode('; ', $newTreeProblems);
		}

		$changed = [];

		foreach ($saved as $col => $value) {
			if (\in_array($col, ['modified_time', 'modified_user_id', 'checked_out', 'checked_out_time'], true)) {
				continue;
			}

			if ((string) ($row[$col] ?? '') !== (string) $value) {
				$changed[] = $col;
			}
		}

		$response = [
			'ok'              => $problems === [],
			'updated'         => true,
			'changed_columns' => $changed,
			'category'        => $this->catShape($saved, true),
		];

		if ($moved !== null) {
			$response['folder_moved'] = $moved;
		}

		if ($problems !== []) {
			$response['problems'] = $problems;
		}

		if ($notes !== []) {
			$response['notes'] = $notes;
		}

		if ($treeBefore !== []) {
			$response['pre_existing_tree_problems'] = $treeBefore;
		}

		return ToolResult::json($response, $problems !== []);
	}

	private function errorText(object $table): string
	{
		$error = method_exists($table, 'getError') ? $table->getError() : '';

		if ($error instanceof \Throwable) {
			return $error->getMessage();
		}

		return (string) $error !== '' ? (string) $error : 'no reason given';
	}
}
