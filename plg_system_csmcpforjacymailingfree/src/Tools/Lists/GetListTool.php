<?php
/**
 * @package     MCP for AcyMailing
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Lists;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

final class GetListTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'get_acym_list'; }

	public function getDescription(): string
	{
		return 'One AcyMailing list: all columns, live membership counts (subscribed / unsubscribed / eligible '
			. 'for a campaign), its welcome and unsubscribe emails, the campaigns that target it, and - for a '
			. 'list of type followup - the follow-up sequence that owns it.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['id'],
			'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->acyAdminBase() === null) {
			return $this->notInstalledError();
		}

		$id   = $this->requirePositiveInt($arguments, 'id');
		$list = $this->db->setQuery('SELECT * FROM ' . $this->acyQn($this->acyTable('list')) . ' WHERE id = ' . $id)->loadAssoc();
		if (!$list) {
			return ToolResult::error('No AcyMailing list with id ' . $id . '.');
		}

		$conf   = $this->acyRequiresConfirmation();
		$counts = $this->db->setQuery(
			'SELECT COALESCE(SUM(ul.status = 1),0) subscribed, COALESCE(SUM(ul.status = 0),0) unsubscribed,'
			. ' COALESCE(SUM(ul.status = 1 AND u.active = 1' . ($conf ? ' AND u.confirmed = 1' : '') . '),0) eligible,'
			. ' COALESCE(SUM(ul.status = 1 AND u.confirmed = 0),0) subscribed_unconfirmed,'
			. ' COALESCE(SUM(ul.status = 1 AND u.active = 0),0) subscribed_inactive'
			. ' FROM ' . $this->acyQn($this->acyTable('user_has_list')) . ' ul JOIN ' . $this->acyQn($this->acyTable('user')) . ' u ON u.id = ul.user_id'
			. ' WHERE ul.list_id = ' . $id
		)->loadAssoc() ?: [];

		$mailInfo = function ($mailId): ?array {
			if (empty($mailId)) {
				return null;
			}
			$m = $this->acyMailRow((int) $mailId);

			return $m ? ['id' => (int) $m['id'], 'name' => $m['name'], 'subject' => $m['subject'], 'type' => $m['type']] : ['id' => (int) $mailId, 'missing' => true];
		};

		$campaigns = $this->db->setQuery(
			'SELECT c.id AS campaign_id, c.mail_id, c.draft, c.sent, c.active, c.sending_type, c.sending_date, m.subject, m.name'
			. ' FROM ' . $this->acyQn($this->acyTable('mail_has_list')) . ' ml'
			. ' JOIN ' . $this->acyQn($this->acyTable('campaign')) . ' c ON c.mail_id = ml.mail_id'
			. ' JOIN ' . $this->acyQn($this->acyTable('mail')) . ' m ON m.id = ml.mail_id'
			. ' WHERE ml.list_id = ' . $id . ' ORDER BY c.id DESC'
		)->loadAssocList() ?: [];
		$campaigns = array_map(fn (array $c): array => $this->acyDecodeMail($c), $campaigns);

		$followup = null;
		if ($this->acyTableExists('followup')) {
			$followup = $this->db->setQuery('SELECT id, name, active FROM ' . $this->acyQn($this->acyTable('followup')) . ' WHERE list_id = ' . $id)->loadAssoc() ?: null;
		}

		$list['translation_decoded'] = $list['translation'] ? json_decode((string) $list['translation'], true) : null;

		return ToolResult::json([
			'list'                 => $list,
			'live_counts'          => array_map('intval', $counts),
			'require_confirmation' => $conf,
			'welcome_email'        => $mailInfo($list['welcome_id']),
			'unsubscribe_email'    => $mailInfo($list['unsubscribe_id']),
			'campaigns'            => $campaigns,
			'owned_by_followup'    => $followup,
		]);
	}
}
