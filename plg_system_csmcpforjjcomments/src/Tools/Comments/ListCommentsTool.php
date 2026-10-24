<?php
/**
 * @package     MCP for JComments
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Comments;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\JcommentsBootTrait;
use Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Spam\SpamSupportTrait;
use Joomla\CMS\User\User;

/**
 * The admin Comments list, as data. Same state filters as the vendor's
 * (administrator/models/comments.php getListQuery()): published, unpublished,
 * reported (has a row in #__jcomments_reports) and deleted (marked deleted).
 */
final class ListCommentsTool extends AbstractTool
{
	use JcommentsBootTrait;
	use SpamSupportTrait;

	public function getName(): string { return 'list_jcomments_comments'; }

	public function getDescription(): string
	{
		return 'List JComments comments, newest first by default. Filter by state (published, unpublished = waiting '
			. 'for approval, reported, deleted, all), by article (object_id; object_group defaults to any, com_content '
			. 'for articles), by author (user_id, or author matching name/username/email), by IP, by date range (UTC), '
			. 'by text, or to the replies of one comment. Each row has an excerpt, the article title, the reply and '
			. 'report counts, and optionally a spam score (with_spam_score) whose reasons get_jcomments_comment and '
			. 'check_jcomments_spam explain.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'state'          => ['type' => 'string', 'enum' => ['all', 'published', 'unpublished', 'reported', 'deleted'], 'description' => 'Default all. "unpublished" is the approval queue.'],
				'object_id'      => ['type' => 'integer', 'description' => 'The commented item, e.g. an article id.'],
				'object_group'   => ['type' => 'string', 'description' => 'Component of the commented item, e.g. com_content. Default: any.'],
				'user_id'        => ['type' => 'integer', 'description' => 'Registered author. 0 = guests only.'],
				'author'         => ['type' => 'string', 'description' => 'Matches name, username or email (contains).'],
				'ip'             => ['type' => 'string', 'description' => 'Exact IP address.'],
				'search'         => ['type' => 'string', 'description' => 'Matches comment text or title (contains).'],
				'date_from'      => ['type' => 'string', 'description' => 'UTC, inclusive. YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.'],
				'date_to'        => ['type' => 'string', 'description' => 'UTC, inclusive (a bare date means the end of that day).'],
				'parent_id'      => ['type' => 'integer', 'description' => 'Only direct replies to this comment.'],
				'top_level_only' => ['type' => 'boolean'],
				'lang'           => ['type' => 'string', 'description' => 'Language tag the comment was posted under, e.g. en-GB.'],
				'order'          => ['type' => 'string', 'enum' => ['newest', 'oldest'], 'description' => 'Default newest.'],
				'with_spam_score' => ['type' => 'boolean', 'description' => 'Add each row\'s spam score and band (slower).'],
				'limit'          => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'         => ['type' => 'integer'],
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
		$state = (string) ($arguments['state'] ?? 'all');

		switch ($state) {
			case 'published':
				$where[] = 'c.published = 1 AND c.deleted = 0';
				break;
			case 'unpublished':
				$where[] = 'c.published = 0 AND c.deleted = 0';
				break;
			case 'deleted':
				$where[] = 'c.deleted = 1';
				break;
			case 'reported':
				$where[] = 'EXISTS (SELECT 1 FROM ' . $db->quoteName($this->jcTable('_reports')) . ' r WHERE r.commentid = c.id)';
				break;
			case 'all':
				break;
			default:
				throw new \InvalidArgumentException('state must be one of all, published, unpublished, reported, deleted.');
		}

		if (isset($arguments['object_id'])) {
			$where[] = 'c.object_id = ' . (int) $arguments['object_id'];
		}

		if (($group = trim((string) ($arguments['object_group'] ?? ''))) !== '') {
			$where[] = 'c.object_group = ' . $db->quote($group);
		}

		if (isset($arguments['user_id'])) {
			$where[] = 'c.userid = ' . max(0, (int) $arguments['user_id']);
		}

		if (($author = trim((string) ($arguments['author'] ?? ''))) !== '') {
			$like    = $this->jcLike($author);
			$where[] = '(c.name LIKE ' . $like . ' OR c.username LIKE ' . $like . ' OR c.email LIKE ' . $like . ')';
		}

		if (($ip = trim((string) ($arguments['ip'] ?? ''))) !== '') {
			$where[] = 'c.ip = ' . $db->quote($ip);
		}

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$like    = $this->jcLike($search);
			$where[] = '(c.comment LIKE ' . $like . ' OR c.title LIKE ' . $like . ')';
		}

		if (($from = $this->jcDate($arguments['date_from'] ?? null, 'date_from')) !== null) {
			$where[] = 'c.date >= ' . $db->quote($from);
		}

		if (($to = $this->jcDate($arguments['date_to'] ?? null, 'date_to')) !== null) {
			// A bare date means "through the end of that day".
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $arguments['date_to']))) {
				$to = substr($to, 0, 10) . ' 23:59:59';
			}

			$where[] = 'c.date <= ' . $db->quote($to);
		}

		if (isset($arguments['parent_id'])) {
			$where[] = 'c.parent = ' . (int) $arguments['parent_id'];
		} elseif (!empty($arguments['top_level_only'])) {
			$where[] = 'c.parent = 0';
		}

		if (($lang = trim((string) ($arguments['lang'] ?? ''))) !== '') {
			$where[] = 'c.lang = ' . $db->quote($lang);
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
		$dir      = ($arguments['order'] ?? 'newest') === 'oldest' ? 'ASC' : 'DESC';
		$limit    = $this->jcLimit($arguments);
		$offset   = $this->jcOffset($arguments);
		$table    = $db->quoteName($this->jcTable(''));

		$total = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $table . ' c' . $whereSql)->loadResult();
		$rows  = $db->setQuery(
			'SELECT c.*, '
			. '(SELECT COUNT(*) FROM ' . $table . ' ch WHERE ch.parent = c.id) AS reply_count, '
			. '(SELECT COUNT(*) FROM ' . $db->quoteName($this->jcTable('_reports')) . ' r WHERE r.commentid = c.id) AS report_count '
			. 'FROM ' . $table . ' c' . $whereSql . ' ORDER BY c.date ' . $dir . ', c.id ' . $dir,
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$objects  = $this->jcObjectInfo($rows);
		$badwords = !empty($arguments['with_spam_score']) ? (string) $this->jcParams()->get('badwords', '') : '';
		$items    = [];

		foreach ($rows as $row) {
			$item            = $this->jcFormatComment($row, $objects);
			$item['replies'] = (int) $row['reply_count'];
			$item['reports'] = (int) $row['report_count'];

			if (!empty($arguments['with_spam_score'])) {
				$assess        = $this->jcSpamAssess($row, $badwords);
				$item['spam']  = ['score' => $assess['score'], 'band' => $assess['band'], 'rules' => array_values(array_unique(array_column($assess['flags'], 'rule')))];
			}

			$items[] = $item;
		}

		return ToolResult::json([
			'ok'       => true,
			'total'    => $total,
			'limit'    => $limit,
			'offset'   => $offset,
			'order'    => $dir === 'DESC' ? 'newest' : 'oldest',
			'note'     => 'Dates are UTC. "unpublished" comments are waiting for approval; "deleted" ones were marked deleted (JComments delete mode "mark") and show as a placeholder.',
			'comments' => $items,
		]);
	}
}
