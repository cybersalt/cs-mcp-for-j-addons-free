<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Lists;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

final class ListListsTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'list_acym_lists'; }

	public function getDescription(): string
	{
		return 'List AcyMailing lists with LIVE membership counts: subscribed, unsubscribed, and `eligible` '
			. '(subscribed AND active AND - when require_confirmation is on - confirmed; the exact set a campaign '
			. 'would be queued for). The stored #__acym_list.subscribers column is a cache refreshed only by an '
			. 'admin AJAX call and is returned separately as cached_subscribers so drift is visible. List types: '
			. 'standard (normal), front (front-end management list owned by a Joomla user), followup (hidden list '
			. 'owned by a follow-up sequence; do not edit it directly). Filters: type, active, search; limit/offset.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'type'   => ['type' => 'string', 'enum' => ['standard', 'front', 'followup', 'all'], 'description' => 'Default all.'],
				'active' => ['type' => 'integer', 'enum' => [0, 1]],
				'search' => ['type' => 'string', 'description' => 'Substring of name or display_name.'],
				'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
				'offset' => ['type' => 'integer', 'minimum' => 0],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->acyAdminBase() === null) {
			return $this->notInstalledError();
		}

		$conf = $this->acyRequiresConfirmation();
		$q    = $this->db->getQuery(true)
			->select(['l.id', 'l.name', 'l.display_name', 'l.active', 'l.visible', 'l.type', 'l.color', 'l.creation_date',
				'l.welcome_id', 'l.unsubscribe_id', 'l.cms_user_id', 'l.access', 'l.tracking', 'l.subscribers AS cached_subscribers'])
			->select('COALESCE(SUM(ul.status = 1), 0) AS subscribed')
			->select('COALESCE(SUM(ul.status = 0), 0) AS unsubscribed')
			->select('COALESCE(SUM(ul.status = 1 AND u.active = 1' . ($conf ? ' AND u.confirmed = 1' : '') . '), 0) AS eligible')
			->from($this->acyQn($this->acyTable('list'), 'l'))
			->join('LEFT', $this->acyQn($this->acyTable('user_has_list'), 'ul') . ' ON ul.list_id = l.id')
			->join('LEFT', $this->acyQn($this->acyTable('user'), 'u') . ' ON u.id = ul.user_id')
			->group('l.id')
			->order('l.id ASC');

		$type = (string) ($arguments['type'] ?? 'all');
		if ($type !== 'all' && $type !== '') {
			$q->where('l.type = ' . $this->db->quote($type));
		}
		if (isset($arguments['active'])) {
			$q->where('l.active = ' . ((int) $arguments['active'] ? 1 : 0));
		}
		if (!empty($arguments['search'])) {
			$s = $this->db->quote('%' . $arguments['search'] . '%');
			$q->where('(l.name LIKE ' . $s . ' OR l.display_name LIKE ' . $s . ')');
		}

		$rows = $this->db->setQuery($q, $this->acyOffset($arguments), $this->acyLimit($arguments))->loadAssocList() ?: [];
		foreach ($rows as &$r) {
			foreach (['id', 'active', 'visible', 'welcome_id', 'unsubscribe_id', 'cms_user_id', 'tracking', 'cached_subscribers', 'subscribed', 'unsubscribed', 'eligible'] as $k) {
				$r[$k] = $r[$k] === null ? null : (int) $r[$k];
			}
			$r['cached_counter_stale'] = $r['cached_subscribers'] !== $r['subscribed'];
		}
		unset($r);

		return ToolResult::json([
			'count'                => count($rows),
			'require_confirmation' => $conf,
			'lists'                => $rows,
		]);
	}
}
