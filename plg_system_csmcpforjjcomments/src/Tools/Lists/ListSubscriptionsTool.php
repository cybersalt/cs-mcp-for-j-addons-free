<?php
/**
 * @package     MCP for JComments
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Lists;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\JcommentsBootTrait;
use Joomla\CMS\User\User;

/**
 * Who gets "new comment" mail for an item. The unsubscribe hash is never
 * returned: it is a bearer secret (anyone holding it can cancel the
 * subscription through site/controllers/subscriptions.php).
 */
final class ListSubscriptionsTool extends AbstractTool
{
	use JcommentsBootTrait;

	public function getName(): string { return 'list_jcomments_subscriptions'; }

	public function getDescription(): string
	{
		return 'List JComments subscriptions: who is emailed about new comments on which item. Filter by item '
			. '(object_id), email/name, or state. Shows whether each would actually receive mail: JComments only mails '
			. 'published subscriptions on items that have a #__jcomments_objects row. Read-only; the unsubscribe hash '
			. 'is not shown.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'object_id'    => ['type' => 'integer'],
				'object_group' => ['type' => 'string'],
				'search'       => ['type' => 'string', 'description' => 'Matches email or name (contains).'],
				'state'        => ['type' => 'string', 'enum' => ['all', 'published', 'unpublished']],
				'limit'        => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'       => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->jcInstalled()) {
			return $this->jcNotInstalledError();
		}

		$this->jcRequireAccess($actor);

		$db    = $this->db;
		$where = [];

		if (isset($arguments['object_id'])) {
			$where[] = 's.object_id = ' . (int) $arguments['object_id'];
		}

		if (($g = trim((string) ($arguments['object_group'] ?? ''))) !== '') {
			$where[] = 's.object_group = ' . $db->quote($g);
		}

		if (($q = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$like    = $this->jcLike($q);
			$where[] = '(s.email LIKE ' . $like . ' OR s.name LIKE ' . $like . ')';
		}

		$state = (string) ($arguments['state'] ?? 'all');

		if ($state === 'published') {
			$where[] = 's.published = 1';
		} elseif ($state === 'unpublished') {
			$where[] = 's.published = 0';
		}

		$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
		$limit    = $this->jcLimit($arguments);
		$offset   = $this->jcOffset($arguments);
		$table    = $db->quoteName($this->jcTable('_subscriptions'));

		$total = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $table . ' s' . $whereSql)->loadResult();
		$rows  = $db->setQuery(
			'SELECT s.id, s.object_id, s.object_group, s.lang, s.userid, s.name, s.email, s.published, s.source, '
			. '(SELECT COUNT(*) FROM ' . $db->quoteName($this->jcTable('_objects')) . ' o WHERE o.object_id = s.object_id AND o.object_group = s.object_group) AS objects_rows '
			. 'FROM ' . $table . ' s' . $whereSql . ' ORDER BY s.id DESC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$info  = $this->jcObjectInfo($rows);
		$items = [];

		foreach ($rows as $r) {
			$items[] = [
				'id'           => (int) $r['id'],
				'object'       => ['id' => (int) $r['object_id'], 'group' => (string) $r['object_group'], 'title' => $info[$r['object_group'] . ':' . (int) $r['object_id']]['title'] ?? null],
				'lang'         => (string) $r['lang'],
				'user_id'      => (int) $r['userid'],
				'name'         => (string) $r['name'],
				'email'        => (string) $r['email'],
				'published'    => (int) $r['published'] === 1,
				'receives_mail' => (int) $r['published'] === 1 && (int) $r['objects_rows'] > 0,
				'source'       => (string) $r['source'],
			];
		}

		return ToolResult::json(['ok' => true, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'subscriptions' => $items]);
	}
}
