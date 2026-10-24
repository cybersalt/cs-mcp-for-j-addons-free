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

/** Reader reports ("report this comment"), newest first, with the reported comment. */
final class ListReportsTool extends AbstractTool
{
	use JcommentsBootTrait;

	public function getName(): string { return 'list_jcomments_reports'; }

	public function getDescription(): string
	{
		return 'List reader reports on JComments comments ("report this comment"), newest first: who reported, when, '
			. 'the reason, and the reported comment\'s state and excerpt. Filter by comment id. JComments can '
			. 'unpublish a comment automatically after a set number of reports; the response shows that setting.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'comment_id' => ['type' => 'integer'],
				'limit'      => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'     => ['type' => 'integer'],
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

		$db       = $this->db;
		$whereSql = isset($arguments['comment_id']) ? ' WHERE r.commentid = ' . (int) $arguments['comment_id'] : '';
		$limit    = $this->jcLimit($arguments);
		$offset   = $this->jcOffset($arguments);
		$table    = $db->quoteName($this->jcTable('_reports'));

		$total = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $table . ' r' . $whereSql)->loadResult();
		$rows  = $db->setQuery(
			'SELECT r.id, r.commentid, r.userid, r.name, r.ip, r.date, r.reason, r.status, '
			. 'c.published, c.deleted, c.comment, c.object_id, c.object_group '
			. 'FROM ' . $table . ' r LEFT JOIN ' . $db->quoteName($this->jcTable('')) . ' c ON c.id = r.commentid'
			. $whereSql . ' ORDER BY r.date DESC, r.id DESC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$items = [];

		foreach ($rows as $r) {
			$items[] = [
				'id'       => (int) $r['id'],
				'date'     => $r['date'],
				'reporter' => ['user_id' => (int) $r['userid'], 'name' => (string) $r['name'], 'ip' => (string) $r['ip']],
				'reason'   => (string) $r['reason'],
				'status'   => (int) $r['status'],
				'comment'  => $r['published'] === null ? null : [
					'id'      => (int) $r['commentid'],
					'state'   => $this->jcStateLabel($r),
					'object'  => ['id' => (int) $r['object_id'], 'group' => (string) $r['object_group']],
					'excerpt' => $this->jcExcerpt($r['comment'], 150),
				],
			];
		}

		$params = $this->jcParams();

		return ToolResult::json([
			'ok'       => true,
			'settings' => [
				'enable_reports'           => (int) $params->get('enable_reports', 0) === 1,
				'reports_per_comment'      => (int) $params->get('reports_per_comment', 1),
				'reports_before_unpublish' => (int) $params->get('reports_before_unpublish', 0),
			],
			'total'    => $total,
			'limit'    => $limit,
			'offset'   => $offset,
			'reports'  => $items,
		]);
	}
}
