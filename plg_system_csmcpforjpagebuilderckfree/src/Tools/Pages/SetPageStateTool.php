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
 * Set `state` on a row in `#__pagebuilderck_pages`.
 *
 * `state` is one of the six columns Page Builder CK's save controller hardcodes
 * — `controllers/page.php:61` sets `$data['state'] = 1` on EVERY save, with no
 * form field behind it. So unpublishing a page through this tool holds only
 * until someone opens it in the builder and clicks Save, at which point it
 * republishes itself with no message and no audit trail. That is not a quirk
 * worth a footnote; it is the single most surprising thing about writing to this
 * table, and it is stated in the response every time.
 *
 * The practical consequence: if a page must stay off the site, unpublishing it
 * here is a temporary measure. Removing or unpublishing the menu item that
 * points at it is the durable one.
 *
 * Two further facts about the state values. Archived (2) is accepted by the
 * column but Page Builder CK's Pages screen filters on `state > -1`
 * (`models/pages.php:70`) with no archived filter option, so an archived page
 * appears in the listing looking published-ish and is only distinguishable by
 * this tool. Trashed (-2) hides the row from that listing entirely, and there is
 * no empty-trash action anywhere in the component.
 */
final class SetPageStateTool extends AbstractTool
{
	use PagebuilderckBootTrait;

	public function getName(): string { return 'set_pagebuilderck_page_state'; }

	public function getDescription(): string
	{
		return 'Set the published state of a Page Builder CK page: published (1), unpublished (0), '
			. 'trashed (-2) or archived (2). Accepts the word or the integer. Also stamps `modified`. '
			. 'CRITICAL WARNING: `state` is one of the six columns Page Builder CK\'s own save controller '
			. 'hardcodes. It writes state = 1 on every single save, unconditionally, with no form field '
			. 'behind it. So an unpublish or an archive done here survives only until the next time a '
			. 'human opens the page in the builder and clicks Save — at which point the page silently '
			. 'republishes itself. If a page must stay off the site, unpublish or remove the MENU ITEM '
			. 'that points at index.php?option=com_pagebuilderck&view=page&id=<id> as well; that is the '
			. 'durable control. '
			. 'archived (2) is stored faithfully but Page Builder CK\'s Pages screen has no archived '
			. 'filter and lists everything with state > -1, so an archived page still appears in the '
			. 'admin listing. trashed (-2) hides it from that listing entirely, and the component has no '
			. 'empty-trash action, so trashed rows stay in the table forever — use '
			. 'delete_pagebuilderck_page with permanent: true to actually remove one. '
			. 'This does not check the page out or in, and does not create a .pbck backup — Page Builder '
			. 'CK only writes those on its own editor save. The response reports checked_out, because a '
			. 'page someone has open in the builder is a page whose state is about to be reset to 1.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'    => ['type' => 'integer', 'description' => 'Page id. Required.'],
				'state' => ['type' => 'string', 'description' => 'published | unpublished | trashed | archived, or the integer 1, 0, -2, 2. Required.'],
			],
			'required' => ['id', 'state'],
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

		$id    = $this->requirePositiveInt($arguments, 'id');
		$state = $this->pbckNormaliseState($arguments['state'] ?? null);

		if ($state === null) {
			return ToolResult::json([
				'ok'    => false,
				'error' => sprintf(
					'Unrecognised state "%s". Use published, unpublished, trashed, archived, or the '
						. 'integer 1, 0, -2, 2.',
					(string) ($arguments['state'] ?? '')
				),
			], true);
		}

		if (!\in_array($state, [1, 0, -2, 2], true)) {
			// The column is a plain int and would accept anything, but nothing in
			// Page Builder CK knows what to do with a fifth value: the list model's
			// `state > -1` would include it and the toolbar would show it as
			// unpublished. Better refused than stored.
			return ToolResult::json([
				'ok'    => false,
				'error' => 'State ' . $state . ' is not one Page Builder CK understands. The column would '
					. 'accept it, but the component only ever tests for 1, 0, -2 and 2, so the page would '
					. 'end up in a state no screen can display or undo. Refusing.',
			], true);
		}

		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('id'),
					$this->db->quoteName('title'),
					$this->db->quoteName('state'),
					$this->db->quoteName('checked_out'),
				])
				->from($this->db->quoteName($this->pbckTable('pages')))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();

		if (!\is_array($row)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'No Page Builder CK page with id ' . $id . '. Nothing was changed.',
			], true);
		}

		$was = (int) $row['state'];
		$now = Factory::getDate()->toSql();

		$object           = new \stdClass();
		$object->id       = $id;
		$object->state    = $state;
		$object->modified = $now;

		$this->db->updateObject($this->pbckTable('pages'), $object, 'id');

		$response = [
			'ok'             => true,
			'id'             => $id,
			'title'          => (string) $row['title'],
			'state'          => $this->pbckStateLabel($state),
			'previous_state' => $this->pbckStateLabel($was),
			'modified'       => $now,
			'changed'        => $was !== $state,
		];

		if ($was === $state) {
			$response['note'] = 'The page was already ' . $this->pbckStateLabel($state)
				. '. Only the modified date changed.';
		}

		// state is in EDITOR_CLOBBERED, so this notice is always produced. That is
		// correct: there is no state value this table can hold that the builder's
		// next save will not overwrite with 1.
		$clobberNotice = $this->pbckEditorClobberNotice(['state']);

		if ($clobberNotice !== null) {
			$response['editor_clobber'] = $clobberNotice;
		}

		if ($state !== 1) {
			$response['durability_warning'] = 'This page is now '
				. $this->pbckStateLabel($state) . ', but Page Builder CK writes state = 1 on every save '
				. 'it performs. The moment anyone opens page ' . $id . ' in the builder and clicks Save, '
				. 'it republishes itself with no confirmation and nothing in the logs. To keep it off the '
				. 'site reliably, also unpublish or delete the menu item pointing at '
				. 'index.php?option=com_pagebuilderck&view=page&id=' . $id . '.';
		}

		if ($state === 2) {
			$response['archived_note'] = 'Page Builder CK has no archived filter on its Pages screen and '
				. 'lists everything with state > -1, so this page still appears in the admin listing and '
				. 'looks much like a published one. Only this tool and get_pagebuilderck_page report the '
				. 'difference.';
		}

		if ($state === -2) {
			$response['trashed_note'] = 'The page is now hidden from the admin Pages listing, which '
				. 'hardcodes state > -1. Page Builder CK has no empty-trash action, so the row stays in '
				. 'the table indefinitely. Use delete_pagebuilderck_page with permanent: true to remove '
				. 'it for real.';
		}

		$checkedOut = $this->pbckCheckedOutBy($row);

		if ($checkedOut !== null) {
			$response['checked_out_by'] = $checkedOut;
			$response['checked_out_warning'] = 'Page ' . $id . ' is checked out by user ' . $checkedOut
				. ', meaning it is open in the builder right now. Their Save will reset state to 1 — this '
				. 'change is very likely to be undone within minutes.';
		}

		$response['component'] = $this->pbckEditionNotice();

		return ToolResult::json($response);
	}
}
