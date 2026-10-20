<?php
/**
 * @package     MCP for AcyMailing
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Subscribers;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

final class ListSubscribersTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'list_acym_subscribers'; }

	public function getDescription(): string
	{
		return 'Search AcyMailing subscribers (#__acym_user). Filters: search (email or name substring), list_id '
			. 'with list_status (subscribed | unsubscribed | any), active, confirmed, source, cms_linked (has a '
			. 'Joomla account), created_after / created_before (Y-m-d). Returns total and a page (limit/offset, max '
			. '500) with the number of lists each person is subscribed to. The per-subscriber secret `key` is never '
			. 'returned. `active` = 0 means disabled (receives nothing); `confirmed` = 0 means the double opt-in '
			. 'was never completed - excluded from campaigns while require_confirmation is on.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'search'         => ['type' => 'string'],
				'list_id'        => ['type' => 'integer', 'minimum' => 1],
				'list_status'    => ['type' => 'string', 'enum' => ['subscribed', 'unsubscribed', 'any']],
				'active'         => ['type' => 'integer', 'enum' => [0, 1]],
				'confirmed'      => ['type' => 'integer', 'enum' => [0, 1]],
				'source'         => ['type' => 'string'],
				'cms_linked'     => ['type' => 'boolean'],
				'created_after'  => ['type' => 'string'],
				'created_before' => ['type' => 'string'],
				'order_by'       => ['type' => 'string', 'enum' => ['id', 'email', 'name', 'creation_date', 'last_open_date']],
				'order_dir'      => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
				'limit'          => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
				'offset'         => ['type' => 'integer', 'minimum' => 0],
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

		$db = $this->db;
		$q  = $db->getQuery(true)->from($this->acyQn($this->acyTable('user'), 'u'));

		if (!empty($arguments['search'])) {
			$s = $db->quote('%' . $arguments['search'] . '%');
			$q->where('(u.email LIKE ' . $s . ' OR u.name LIKE ' . $s . ')');
		}
		if (!empty($arguments['list_id'])) {
			$status = (string) ($arguments['list_status'] ?? 'subscribed');
			$cond   = 'ulf.user_id = u.id AND ulf.list_id = ' . (int) $arguments['list_id'];
			if ($status === 'subscribed') {
				$cond .= ' AND ulf.status = 1';
			} elseif ($status === 'unsubscribed') {
				$cond .= ' AND ulf.status = 0';
			}
			$q->where('EXISTS (SELECT 1 FROM ' . $this->acyQn($this->acyTable('user_has_list')) . ' ulf WHERE ' . $cond . ')');
		}
		foreach (['active', 'confirmed'] as $k) {
			if (isset($arguments[$k])) {
				$q->where('u.' . $k . ' = ' . ((int) $arguments[$k] ? 1 : 0));
			}
		}
		if (!empty($arguments['source'])) {
			$q->where('u.source = ' . $db->quote((string) $arguments['source']));
		}
		if (isset($arguments['cms_linked'])) {
			$q->where($arguments['cms_linked'] ? 'u.cms_id > 0' : 'u.cms_id = 0');
		}
		if (!empty($arguments['created_after'])) {
			$q->where('u.creation_date >= ' . $db->quote((string) $arguments['created_after']));
		}
		if (!empty($arguments['created_before'])) {
			$q->where('u.creation_date < ' . $db->quote((string) $arguments['created_before']));
		}

		$total = (int) $db->setQuery((clone $q)->select('COUNT(*)'))->loadResult();

		$ob = \in_array($arguments['order_by'] ?? '', ['id', 'email', 'name', 'creation_date', 'last_open_date'], true) ? $arguments['order_by'] : 'id';
		$q->select(['u.id', 'u.email', 'u.name', 'u.active', 'u.confirmed', 'u.cms_id', 'u.source', 'u.language', 'u.tracking',
				'u.creation_date', 'u.confirmation_date', 'u.last_sent_date', 'u.last_open_date', 'u.last_click_date'])
			->select('(SELECT COUNT(*) FROM ' . $this->acyQn($this->acyTable('user_has_list')) . ' x WHERE x.user_id = u.id AND x.status = 1) AS lists_subscribed')
			->order('u.' . $ob . ' ' . (($arguments['order_dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC'));

		$rows = $db->setQuery($q, $this->acyOffset($arguments), $this->acyLimit($arguments))->loadAssocList() ?: [];
		$rows = array_map(fn (array $r): array => $this->acyPublicSubscriber($r) + ['lists_subscribed' => (int) $r['lists_subscribed']], $rows);

		return ToolResult::json([
			'total'       => $total,
			'count'       => count($rows),
			'offset'      => $this->acyOffset($arguments),
			'subscribers' => $rows,
		]);
	}
}
