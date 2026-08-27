<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Pages;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\PagebuilderckBootTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Trash — or, on request, actually delete — a row in `#__pagebuilderck_pages`.
 *
 * The default is a trash, matching `CKModel::trash()`, which sets `state = -2`
 * and nothing else. That is reversible: `set_pagebuilderck_page_state` puts it
 * back.
 *
 * `permanent: true` runs a real `DELETE`, and there is no undo for it inside
 * Page Builder CK. What there might be is a file: the component writes a `.pbck`
 * snapshot to `administrator/components/com_pagebuilderck/backup/<id>_bak/`
 * before every editor save and keeps the last five
 * (`helpers/pagebuilderck.php:263`). Those files are not touched by a delete, so
 * a page that had been edited through the builder at least once is usually
 * recoverable from disk. A page created and only ever written by tooling has no
 * such file. The response says which case this was.
 *
 * Worth knowing before choosing the default: Page Builder CK has no empty-trash
 * action anywhere. `views/pages/view.html.php` offers publish, unpublish, copy,
 * trash and delete on selected rows, and the list model hardcodes `state > -1`
 * (`models/pages.php:70`), so trashed rows are invisible in the UI and stay in
 * the table forever. Trashing is the safe default, not a tidy one.
 */
final class DeletePageTool extends AbstractTool
{
	use PagebuilderckBootTrait;

	public function getName(): string { return 'delete_pagebuilderck_page'; }

	public function getDescription(): string
	{
		return 'Delete a Page Builder CK page. By DEFAULT this TRASHES it — sets state = -2, exactly as '
			. 'Page Builder CK\'s own trash action does — which is reversible with '
			. 'set_pagebuilderck_page_state. Pass permanent: true to run a real DELETE, which is not. '
			. 'Be aware that Page Builder CK has NO empty-trash action anywhere in its interface, and its '
			. 'Pages list hardcodes `state > -1`, so trashed rows are invisible in the admin yet remain in '
			. '#__pagebuilderck_pages indefinitely. On a long-lived site they accumulate. If you are '
			. 'clearing up rather than deferring a decision, permanent: true is the honest choice. '
			. 'A permanent delete is REFUSED when the page is checked out by another user unless force is '
			. 'true, because that user has it open in the builder right now. Trashing is allowed while '
			. 'checked out, since it is reversible, but the response says so. '
			. 'Deleting the row does not remove the .pbck snapshots Page Builder CK writes to '
			. 'administrator/components/com_pagebuilderck/backup/<id>_bak/ before each of its own editor '
			. 'saves (it keeps the last five). The response reports whether such a directory exists, '
			. 'because it is the only recovery route left after a permanent delete. '
			. 'Nothing else in the database references a page id — there is no #__assets node, no menu '
			. 'item foreign key, no content history — so a delete leaves no orphaned rows. It does leave '
			. 'any menu item that pointed at index.php?option=com_pagebuilderck&view=page&id=<id> aiming '
			. 'at nothing; check for one before deleting a published page.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'        => ['type' => 'integer', 'description' => 'Page id. Required.'],
				'permanent' => ['type' => 'boolean', 'description' => 'true to DELETE the row outright. Default false, which trashes it (state = -2).'],
				'force'     => ['type' => 'boolean', 'description' => 'Allow a permanent delete of a page checked out by another user. Default false. Ignored when trashing.'],
			],
			'required' => ['id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->pbckAdminBase() === null) {
			return $this->pbckNotInstalledError();
		}

		if (!$this->pbckTableExists('pages')) {
			return $this->pbckMissingTableError('pages');
		}

		$id        = $this->requirePositiveInt($arguments, 'id');
		$permanent = (bool) ($arguments['permanent'] ?? false);
		$force     = (bool) ($arguments['force'] ?? false);

		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('id'),
					$this->db->quoteName('title'),
					$this->db->quoteName('state'),
					$this->db->quoteName('checked_out'),
					'LENGTH(' . $this->db->quoteName('htmlcode') . ') AS htmlcode_bytes',
				])
				->from($this->db->quoteName($this->pbckTable('pages')))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();

		if (!\is_array($row)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No Page Builder CK page with id ' . $id . '. Nothing was deleted. Note that '
					. 'list_pagebuilderck_pages hides trashed pages unless you ask for state: trashed, so '
					. 'a page missing from that listing may already be trashed rather than gone.',
			], true);
		}

		$title      = (string) $row['title'];
		$wasState   = (int) $row['state'];
		$checkedOut = $this->pbckCheckedOutBy($row);
		$backup     = $this->backupDirectoryFor($id);

		if ($permanent && $checkedOut !== null && $checkedOut !== (int) $actor->id && !$force) {
			return ToolResult::json([
				'ok'             => false,
				'error'          => 'Refusing to permanently delete page ' . $id . ' ("' . $title . '"): '
					. 'it is checked out by user ' . $checkedOut . ', who has it open in Page Builder CK. '
					. 'Deleting the row under them destroys work they are in the middle of and cannot be '
					. 'undone.',
				'checked_out_by' => $checkedOut,
				'resolution'     => 'Trash it instead (omit permanent), wait for that user to save or '
					. 'cancel, or pass force: true. Page Builder CK has no global check-in, so if the '
					. 'session was abandoned this value will never clear on its own.',
			], true);
		}

		if ($permanent) {
			$this->db->setQuery(
				$this->db->getQuery(true)
					->delete($this->db->quoteName($this->pbckTable('pages')))
					->where($this->db->quoteName('id') . ' = ' . $id)
			)->execute();

			$response = [
				'ok'             => true,
				'id'             => $id,
				'title'          => $title,
				'action'         => 'deleted',
				'previous_state' => $this->pbckStateLabel($wasState),
				'bytes_removed'  => (int) $row['htmlcode_bytes'],
				'reversible'     => false,
			];

			$response['recovery'] = $backup === null
				? 'There is no backup directory for this page at '
					. 'administrator/components/com_pagebuilderck/backup/' . $id . '_bak/. Page Builder CK '
					. 'only writes .pbck snapshots when a page is saved through its own editor, so a page '
					. 'created or only ever written by tooling has none. This content is gone.'
				: sprintf(
					'%d .pbck snapshot(s) remain at administrator/components/com_pagebuilderck/backup/%d_bak/. '
						. 'Page Builder CK wrote them before its own editor saves and a delete does not '
						. 'remove them, so the most recent one is a restore point — but it reflects the '
						. 'page as of the last save made THROUGH THE BUILDER, not as of now.',
					$backup['count'],
					$id
				);
		} else {
			$now = Factory::getDate()->toSql();

			$object           = new \stdClass();
			$object->id       = $id;
			$object->state    = -2;
			// CKModel::trash() sets state alone. Stamping modified as well is a
			// deliberate addition: without it a trashed page still claims it was
			// last changed whenever its content was, and the trash date is
			// unrecoverable.
			$object->modified = $now;

			$this->db->updateObject($this->pbckTable('pages'), $object, 'id');

			$response = [
				'ok'             => true,
				'id'             => $id,
				'title'          => $title,
				'action'         => 'trashed',
				'previous_state' => $this->pbckStateLabel($wasState),
				'modified'       => $now,
				'reversible'     => true,
				'undo'           => 'set_pagebuilderck_page_state with id ' . $id . ' and state '
					. '"published" (or "unpublished") restores it.',
			];

			if ($wasState === -2) {
				$response['already_trashed'] = 'This page was already trashed. Nothing changed except '
					. 'the modified date.';
			}

			if ($checkedOut !== null && $checkedOut !== (int) $actor->id) {
				$response['checked_out_warning'] = 'Page ' . $id . ' is checked out by user '
					. $checkedOut . '. Trashing was allowed because it is reversible, but that user still '
					. 'has the page open, and Page Builder CK hardcodes state = 1 on save — so their next '
					. 'Save will silently un-trash it.';
			}

			$response['note'] = 'Page Builder CK has no empty-trash action, and its Pages list hardcodes '
				. '`state > -1`, so this row is now invisible in the admin but still occupies the table. '
				. 'Use permanent: true when you actually want it gone.';
		}

		$response['orphans'] = 'Nothing else in the database references a Page Builder CK page id: no '
			. '#__assets node, no menu-item foreign key, no content history. Any Joomla menu item pointing '
			. 'at index.php?option=com_pagebuilderck&view=page&id=' . $id . ' now targets a page that is '
			. ($permanent ? 'gone' : 'trashed') . ', and Joomla will not warn you about it.';

		$response['component'] = $this->pbckEditionNotice();

		return ToolResult::json($response);
	}

	/**
	 * The vendor's per-page backup directory, when it exists.
	 *
	 * After a permanent delete this is the only remaining copy of the content, so
	 * its presence or absence belongs in the response rather than in a docblock.
	 *
	 * @return array{count:int}|null
	 */
	private function backupDirectoryFor(int $id): ?array
	{
		$base = $this->pbckAdminBase();

		if ($base === null) {
			return null;
		}

		$path = $base . '/backup/' . $id . '_bak';

		if (!is_dir($path)) {
			return null;
		}

		$files = glob($path . '/*.pbck') ?: [];

		return $files === [] ? null : ['count' => \count($files)];
	}
}
