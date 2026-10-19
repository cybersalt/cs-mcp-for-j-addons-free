<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Lists;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

/**
 * Delete a list through ListClass::delete($ids, true).
 *
 * The vendor delete removes the list's subscription rows and its campaign
 * targeting (mail_has_list) with no trash and no undo; subscribers themselves
 * survive. confirm: true is required and the blast radius is reported first.
 */
final class DeleteListTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'delete_acym_list'; }

	public function getDescription(): string
	{
		return 'Permanently delete an AcyMailing list via ListClass::delete(). This also deletes every '
			. 'subscription to the list (#__acym_user_has_list, including the unsubscribe records that stop '
			. 'people being re-added) and removes the list from every campaign that targets it. Subscribers are '
			. 'not deleted. There is no trash. Without confirm: true the tool only reports the blast radius. Lists '
			. 'owned by a follow-up (type followup) are refused.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['id'],
			'properties' => [
				'id'      => ['type' => 'integer', 'minimum' => 1],
				'confirm' => ['type' => 'boolean', 'description' => 'Must be true to delete.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->acyAdminBase() === null) {
			return $this->notInstalledError();
		}

		$id   = $this->requirePositiveInt($arguments, 'id');
		$list = $this->db->setQuery('SELECT id, name, type FROM ' . $this->acyQn($this->acyTable('list')) . ' WHERE id = ' . $id)->loadAssoc();
		if (!$list) {
			return ToolResult::error('No AcyMailing list with id ' . $id . '.');
		}
		if ($list['type'] === 'followup') {
			return ToolResult::error('List ' . $id . ' belongs to a follow-up sequence; deleting it breaks the follow-up. Delete the follow-up instead (FollowupClass::delete removes its list).');
		}

		$subs  = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->acyQn($this->acyTable('user_has_list')) . ' WHERE list_id = ' . $id)->loadResult();
		$camps = $this->db->setQuery('SELECT ml.mail_id, c.id campaign_id, c.sent, c.draft FROM ' . $this->acyQn($this->acyTable('mail_has_list')) . ' ml LEFT JOIN ' . $this->acyQn($this->acyTable('campaign')) . ' c ON c.mail_id = ml.mail_id WHERE ml.list_id = ' . $id)->loadAssocList() ?: [];

		$radius = ['list' => $list, 'subscription_rows_deleted' => $subs, 'campaign_targets_removed' => $camps];

		if (($arguments['confirm'] ?? false) !== true) {
			return ToolResult::json(['ok' => false, 'deleted' => false, 'would_delete' => $radius, 'message' => 'Not deleted. Re-run with confirm: true.']);
		}
		if (!$this->ensureAcyLoaded()) {
			return ToolResult::error('Could not load the AcyMailing framework.');
		}

		$class   = new \AcyMailing\Classes\ListClass();
		$deleted = $class->delete([$id], true);
		$still   = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->acyQn($this->acyTable('list')) . ' WHERE id = ' . $id)->loadResult();
		$subsLeft = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->acyQn($this->acyTable('user_has_list')) . ' WHERE list_id = ' . $id)->loadResult();

		return ToolResult::json([
			'ok'                        => $still === 0,
			'deleted'                   => $deleted,
			'blast_radius'              => $radius,
			'list_row_gone'             => $still === 0,
			'subscription_rows_remaining' => $subsLeft,
			'vendor_messages'           => $this->acyDrainMessages(),
		]);
	}
}
