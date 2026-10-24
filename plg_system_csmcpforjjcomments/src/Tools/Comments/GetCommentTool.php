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
 * One comment, its whole thread in display order, its reports, what is
 * known about the author, and a spam assessment with reasons.
 *
 * The thread is every comment sharing the root's id as thread_id (plus the
 * root), the same set JComments' tree view pages by
 * (site/models/jcomments.php getCommentsList(), pagination 'tree').
 */
final class GetCommentTool extends AbstractTool
{
	use JcommentsBootTrait;
	use SpamSupportTrait;

	public function getName(): string { return 'get_jcomments_comment'; }

	public function getDescription(): string
	{
		return 'Get one JComments comment in full: text, state, article, author (with their other comments and, for '
			. 'registered users, account age), user reports, votes, threading columns, a spam assessment that names '
			. 'every rule that fired, and the whole thread it belongs to (root and every reply, in display order, '
			. 'with depth). Visibility of each thread member is shown, including replies JComments will not display '
			. 'because an ancestor is unpublished.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'id'             => ['type' => 'integer', 'description' => 'Comment id.'],
				'include_thread' => ['type' => 'boolean', 'description' => 'Default true.'],
			],
			'required'             => ['id'],
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

		$id  = $this->requirePositiveInt($arguments, 'id');
		$row = $this->jcComment($id);

		if ($row === null) {
			return ToolResult::json(['ok' => false, 'error' => 'No comment with id ' . $id . '.'], true);
		}

		$db       = $this->db;
		$params   = $this->jcParams();
		$objects  = $this->jcObjectInfo([$row]);
		$comment  = $this->jcFormatComment($row, $objects, true);
		$warnings = [];

		// Reports.
		$comment['reports'] = $db->setQuery(
			'SELECT id, userid, name, date, reason, status FROM ' . $db->quoteName($this->jcTable('_reports'))
			. ' WHERE commentid = ' . $id . ' ORDER BY date'
		)->loadAssocList() ?: [];

		// The commented item, as Joomla sees it now.
		if ($row['object_group'] === 'com_content') {
			$article = $db->setQuery(
				'SELECT id, title, state, access, created_by FROM ' . $db->quoteName('#__content') . ' WHERE id = ' . (int) $row['object_id']
			)->loadAssoc();

			$comment['object']['article'] = $article ?: null;

			if (!$article) {
				$warnings[] = 'Article ' . (int) $row['object_id'] . ' no longer exists, so nobody can see this comment on the site.';
			} elseif ((int) $article['state'] !== 1) {
				$warnings[] = 'Article ' . (int) $row['object_id'] . ' is not published (state ' . (int) $article['state'] . '), so this comment is not visible on the site.';
			}
		}

		if (!$comment['object']['objects_row_exists']) {
			$warnings[] = 'JComments has no #__jcomments_objects row for this article in language "' . $row['lang']
				. '" yet. It creates one the next time the article is viewed; until then the admin list shows no article '
				. 'title and subscriber mail for this article finds no recipients (the recipient query joins that table).';
		}

		// The author.
		$author = ['registered' => (int) $row['userid'] > 0];

		if ((int) $row['userid'] > 0) {
			$user = $db->setQuery(
				'SELECT id, username, name, block, registerDate, lastvisitDate FROM ' . $db->quoteName('#__users') . ' WHERE id = ' . (int) $row['userid']
			)->loadAssoc();

			$author['account'] = $user ?: null;

			if (!$user) {
				$warnings[] = 'The author\'s user account ' . (int) $row['userid'] . ' no longer exists.';
			}

			$author['comments_by_user'] = $this->countsBy('userid = ' . (int) $row['userid']);
		} else {
			$author['comments_by_email'] = trim((string) $row['email']) !== '' ? $this->countsBy('email = ' . $db->quote((string) $row['email'])) : null;
		}

		$author['comments_from_ip'] = trim((string) $row['ip']) !== '' ? $this->countsBy('ip = ' . $db->quote((string) $row['ip'])) : null;
		$author['ip_blacklisted']   = $this->jcIpBlacklisted((string) $row['ip']);
		$author['user_blacklisted'] = $this->jcUserBlacklisted((int) $row['userid']);
		$comment['author_history']  = $author;

		if ($this->jcLockedByOther($row, $actor)) {
			$warnings[] = 'Checked out (open in the editor) by user ' . (int) $row['checked_out'] . ' since ' . $row['checked_out_time']
				. '. JComments refuses to publish or delete it until it is checked in; so do these tools.';
		}

		$comment['spam'] = $this->jcSpamAssess($row, (string) $params->get('badwords', ''));

		$out = ['ok' => true, 'comment' => $comment];

		if (($arguments['include_thread'] ?? true) !== false) {
			$out['thread'] = $this->thread($row, $objects, (string) $params->get('template_view', 'tree') === 'tree');
		}

		if ($warnings !== []) {
			$out['warnings'] = $warnings;
		}

		return ToolResult::json($out);
	}

	/** @return array{published:int,unpublished:int,deleted:int} */
	private function countsBy(string $where): array
	{
		$r = $this->db->setQuery(
			'SELECT SUM(published = 1 AND deleted = 0) AS p, SUM(published = 0 AND deleted = 0) AS u, SUM(deleted = 1) AS d FROM '
			. $this->db->quoteName($this->jcTable('')) . ' WHERE ' . $where
		)->loadAssoc() ?: [];

		return ['published' => (int) ($r['p'] ?? 0), 'unpublished' => (int) ($r['u'] ?? 0), 'deleted' => (int) ($r['d'] ?? 0)];
	}

	/**
	 * The thread in display order (depth-first, siblings by date), each
	 * member marked with whether the front end can show it. In the tree view
	 * JComments builds the tree from published rows only, so a reply under an
	 * unpublished comment is unreachable (JoomlaTuneTree::_buildTree() walks
	 * from 0). In the flat list view (template_view "list", the installed
	 * default) every published row shows, whatever its parent. Seen live:
	 * reply 4 under unpublished 3 rendered in list view.
	 */
	private function thread(array $row, array $objects, bool $treeView): array
	{
		$root = (int) $row['thread_id'] ?: (int) $row['id'];
		$db   = $this->db;
		$rows = $db->setQuery(
			'SELECT * FROM ' . $db->quoteName($this->jcTable('')) . ' WHERE id = ' . $root . ' OR thread_id = ' . $root . ' ORDER BY date, id'
		)->loadAssocList('id') ?: [];

		$children = [];

		foreach ($rows as $r) {
			$children[(int) $r['parent']][] = (int) $r['id'];
		}

		$ordered = [];
		$walk    = function (int $id, bool $visible) use (&$walk, &$ordered, $rows, $children, $objects, $row, $treeView): void {
			if (!isset($rows[$id])) {
				return;
			}

			$r         = $rows[$id];
			$shows     = ($visible || !$treeView) && (int) $r['published'] === 1;
			$entry     = $this->jcFormatComment($r, $objects);
			$entry['displayed_on_site'] = $shows;
			$entry['is_requested']      = (int) $r['id'] === (int) $row['id'];
			$ordered[] = $entry;

			foreach ($children[$id] ?? [] as $c) {
				$walk($c, $shows);
			}
		};

		$walk($root, true);

		// Anything left over has a parent outside the thread (a broken link).
		$orphans = array_values(array_diff(array_map('intval', array_keys($rows)), array_column($ordered, 'id')));

		$out = [
			'root_id' => $root,
			'count'   => \count($rows),
			'view'    => $treeView ? 'tree (a reply shows only while every comment above it is published)' : 'list (flat, by date: every published comment shows, whatever its parent)',
			'comments' => $ordered,
		];

		if ($orphans !== []) {
			$out['orphans'] = $orphans;
			$out['orphans_note'] = 'These carry this thread_id but their parent is not in the thread; JComments cannot place them.';
		}

		return $out;
	}
}
