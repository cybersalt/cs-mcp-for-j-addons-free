<?php
/**
 * @package     MCP for JComments
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Moderation;

\defined('_JEXEC') or die;

use Joomla\CMS\User\User;

/**
 * Deleting comments exactly as JComments' admin list does
 * (administrator/models/modellist.php delete()): load the row, then either
 * JCommentsTableComment::delete() or ::markAsDeleted(), chosen by the
 * component's delete_mode option.
 *
 *   delete        tables/comment.php:140-209. Deletes the row, then every
 *                 descendant on the same object (found through parent links,
 *                 JoomlaTuneTree::descendants()), then the votes and reports of
 *                 all of them. A whole sub-thread goes.
 *   mark_deleted  tables/comment.php:211-216. Sets deleted = 1 and blanks the
 *                 title, then store()s; replies stay. With comment titles on,
 *                 store() refills the blank title with "RE: ..." - that is the
 *                 vendor's behaviour and we keep it.
 *
 * The admin list fires no plugin event and sends no mail on delete, and
 * neither do we. It also ignores check-out; we skip a comment someone else
 * has open, as the front end does (site/classes/acl.php canDelete()).
 */
trait DeleteSupportTrait
{
	protected function jcResolveDeleteMode(string $mode): string
	{
		return match ($mode) {
			'', 'setting'   => $this->jcDeleteMode($this->jcParams()),
			'delete'        => 'delete',
			'mark_deleted'  => 'mark_deleted',
			default         => throw new \InvalidArgumentException('mode must be setting, delete or mark_deleted.'),
		};
	}

	/**
	 * @param int[] $ids
	 * @return array<string,mixed>
	 */
	protected function jcDeleteComments(array $ids, string $mode, bool $dryRun, User $actor): array
	{
		$db      = $this->db;
		$results = [];
		$plan    = [];
		$gone    = [];

		// Parents before children, so a child already removed with its parent is reported as such.
		sort($ids);

		foreach ($ids as $id) {
			if (isset($gone[$id])) {
				$results[] = ['id' => $id, 'ok' => true, 'skipped' => 'already removed as a reply of comment ' . $gone[$id]];
				continue;
			}

			$row = $this->jcComment($id);

			if ($row === null) {
				$results[] = ['id' => $id, 'ok' => false, 'error' => 'no such comment'];
				continue;
			}

			if ($this->jcLockedByOther($row, $actor)) {
				$results[] = ['id' => $id, 'ok' => false, 'error' => 'checked out by user ' . (int) $row['checked_out'] . '; check it in first'];
				continue;
			}

			if ($mode === 'mark_deleted' && (int) $row['deleted'] === 1) {
				$results[] = ['id' => $id, 'ok' => true, 'skipped' => 'already marked deleted'];
				continue;
			}

			$descendants = $mode === 'delete' ? $this->jcDescendantIds($row) : [];

			foreach ($descendants as $d) {
				$gone[$d] = $id;
			}

			$plan[] = ['row' => $row, 'descendants' => $descendants];
		}

		if ($dryRun) {
			foreach ($plan as $p) {
				$results[] = [
					'id'                 => (int) $p['row']['id'],
					'ok'                 => true,
					'would'              => $mode === 'delete' ? 'delete' : 'mark deleted',
					'excerpt'            => $this->jcExcerpt($p['row']['comment'], 120),
					'replies_also_deleted' => $p['descendants'],
				];
			}

			usort($results, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

			return ['ok' => !\in_array(false, array_column($results, 'ok'), true), 'dry_run' => true, 'mode' => $mode, 'results' => $results];
		}

		if ($plan !== []) {
			$results = array_merge($results, $this->jcWithAdminApp($actor, function () use ($plan, $mode, $db): array {
				$done = [];

				foreach ($plan as $p) {
					$id    = (int) $p['row']['id'];
					$table = new \JCommentsTableComment($db);

					if (!$table->load($id)) {
						$done[] = ['id' => $id, 'ok' => false, 'error' => 'gone before it could be deleted (removed as a reply of an earlier id?)'];
						continue;
					}

					if ($mode === 'delete') {
						$all     = array_merge([$id], $p['descendants']);
						$votes   = $this->jcTableExists('_votes') ? $this->jcCount('_votes', 'commentid IN (' . implode(',', $all) . ')') : 0;
						$reports = $this->jcTableExists('_reports') ? $this->jcCount('_reports', 'commentid IN (' . implode(',', $all) . ')') : 0;

						if (!$table->delete($id)) {
							$done[] = ['id' => $id, 'ok' => false, 'error' => (string) $table->getError()];
							continue;
						}

						$left = $this->jcCount('', 'id IN (' . implode(',', $all) . ')');

						$done[] = [
							'id'               => $id,
							'ok'               => $left === 0,
							'deleted'          => true,
							'replies_deleted'  => $p['descendants'],
							'votes_removed'    => $votes,
							'reports_removed'  => $reports,
							'rows_still_there' => $left,
						];
					} else {
						$table->markAsDeleted();

						$after  = $this->jcComment($id);
						$done[] = [
							'id'           => $id,
							'ok'           => $after !== null && (int) $after['deleted'] === 1,
							'marked_deleted' => true,
							'title_now'    => $after['title'] ?? null,
							'replies_kept' => $this->jcCount('', 'parent = ' . $id),
						];
					}
				}

				return $done;
			}));

			$this->jcCleanCache();
		}

		usort($results, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

		return [
			'ok'      => !\in_array(false, array_column($results, 'ok'), true),
			'dry_run' => false,
			'mode'    => $mode,
			'mail'    => 'none: JComments sends nothing on delete',
			'results' => $results,
		];
	}
}
