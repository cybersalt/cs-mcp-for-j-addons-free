<?php
/**
 * @package     MCP for JComments
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Moderation;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\JcommentsBootTrait;
use Joomla\CMS\User\User;

/**
 * Approve (publish) or unpublish comments.
 *
 * JComments' admin list does this in JCommentsModelComments::publish()
 * (administrator/models/comments.php): for each row whose state changes it
 * fires onJCommentsCommentBeforePublish, calls Table::publish(), fires
 * onJCommentsCommentAfterPublish and, when publishing, pushes a "new comment"
 * mail to every subscriber of the article. So the vendor's own Publish button
 * emails people.
 *
 * Here the default (notify omitted or false) changes `published` with one
 * targeted UPDATE per comment and does nothing else: no plugin events (a
 * jcomments-group plugin may mail or post on publish) and no queue rows. With
 * notify: true the vendor's whole sequence runs, through its own classes, in
 * an AdministratorApplication.
 *
 * Like Table::publish(), a comment checked out by someone else is skipped.
 */
final class SetCommentStateTool extends AbstractTool
{
	use JcommentsBootTrait;

	public function getName(): string { return 'set_jcomments_comment_state'; }

	public function getDescription(): string
	{
		return 'Approve (publish) or unpublish one or more JComments comments. By default NOTHING is sent and no '
			. 'JComments plugin event fires: only the published flag changes. Pass notify: true to do exactly what '
			. 'JComments\' own admin Publish button does, which fires its publish events and emails every subscriber '
			. 'of the article about a newly published comment. Comments already in the requested state, and comments '
			. 'checked out by another user, are skipped and reported.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'ids'    => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Comment ids (up to 200).'],
				'state'  => ['type' => 'string', 'enum' => ['published', 'unpublished']],
				'notify' => ['type' => 'boolean', 'description' => 'Default false. True = run JComments\' publish events and subscriber mail, as its admin Publish button does.'],
			],
			'required'             => ['ids', 'state'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->jcInstalled()) {
			return $this->jcNotInstalledError();
		}

		$this->jcRequireAccess($actor, 'core.edit.state');

		$ids   = $this->jcIds($arguments);
		$state = (string) ($arguments['state'] ?? '');

		if (!\in_array($state, ['published', 'unpublished'], true)) {
			throw new \InvalidArgumentException('state must be "published" or "unpublished".');
		}

		$value   = $state === 'published' ? 1 : 0;
		$notify  = ($arguments['notify'] ?? false) === true;
		$db      = $this->db;
		$table   = $db->quoteName($this->jcTable(''));
		$queue0  = $this->jcMailQueueCount();
		$results = [];
		$changed = [];

		foreach ($ids as $id) {
			$row = $this->jcComment($id);

			if ($row === null) {
				$results[] = ['id' => $id, 'ok' => false, 'error' => 'no such comment'];
				continue;
			}

			if ($this->jcLockedByOther($row, $actor)) {
				$results[] = ['id' => $id, 'ok' => false, 'error' => 'checked out by user ' . (int) $row['checked_out'] . '; check it in first (JComments skips it too)'];
				continue;
			}

			if ((int) $row['published'] === $value) {
				$results[] = ['id' => $id, 'ok' => true, 'skipped' => 'already ' . $state];
				continue;
			}

			if ($notify) {
				$changed[] = $id;
				continue;
			}

			$db->setQuery('UPDATE ' . $table . ' SET published = ' . $value . ' WHERE id = ' . $id)->execute();

			$after = $this->jcComment($id);
			$entry = ['id' => $id, 'ok' => (int) $after['published'] === $value, 'state' => $this->jcStateLabel($after)];

			if ((int) $after['deleted'] === 1) {
				$entry['note'] = 'this comment is marked deleted; it shows on the site as a "deleted" placeholder whatever its published flag';
			}

			$results[] = $entry;
		}

		$mail = null;

		if ($notify && $changed !== []) {
			$results = array_merge($results, $this->jcWithAdminApp($actor, fn (): array => $this->vendorPublish($changed, $value)));
			$mail    = [
				'queued_before' => $queue0,
				'queued_after'  => $this->jcMailQueueCount(),
				'note'          => 'JComments deletes a queue row once the mailer accepts it, so a row that is still queued failed to send.',
			];
		}

		$this->jcCleanCache();

		usort($results, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

		$ok = !\in_array(false, array_column($results, 'ok'), true);

		return ToolResult::json([
			'ok'      => $ok,
			'state'   => $state,
			'notify'  => $notify,
			'mail'    => $notify
				? ($mail ?? ['note' => 'nothing changed state, so nothing was sent'])
				: 'none: notify was false, so no JComments event fired and no notification was queued or sent',
			'results' => $results,
		], !$ok);
	}

	/**
	 * The vendor's JCommentsModelComments::publish() loop, per comment: events,
	 * Table::publish(), and for a publish JCommentsNotification::push() after
	 * loading the site language for the comment's own language, as it does.
	 *
	 * @param int[] $ids
	 */
	private function vendorPublish(array $ids, int $value): array
	{
		$app      = \Joomla\CMS\Factory::getApplication();
		$results  = [];
		$lastLang = '';

		foreach ($ids as $id) {
			$table = new \JCommentsTableComment($this->db);

			if (!$table->load($id)) {
				$results[] = ['id' => $id, 'ok' => false, 'error' => 'could not load'];
				continue;
			}

			$before = \JCommentsEvent::trigger('onJCommentsCommentBeforePublish', [&$table]);

			if (\in_array(false, $before, true)) {
				$results[] = ['id' => $id, 'ok' => false, 'error' => 'a JComments plugin vetoed the change (onJCommentsCommentBeforePublish)'];
				continue;
			}

			if (!$table->publish([$id], $value, (int) $app->getIdentity()->id)) {
				$results[] = ['id' => $id, 'ok' => false, 'error' => (string) $table->getError()];
				continue;
			}

			\JCommentsEvent::trigger('onJCommentsCommentAfterPublish', [&$table]);

			$mail = 'not applicable to an unpublish';
			$ok   = true;

			if ((int) $table->published === 1) {
				if ($lastLang !== $table->lang) {
					$lastLang = (string) $table->lang;
					$app->getLanguage()->load('com_jcomments', JPATH_SITE, $table->lang);
				}

				// The state change has happened by now; a mail failure must not
				// hide that, or a retry would look like a second publish.
				try {
					\JCommentsNotification::push(['comment' => $table]);
					$mail = 'handed to the JComments notifier, which mails every published subscriber of the article except the comment author and you';
				} catch (\Throwable $e) {
					$ok   = false;
					$mail = 'the comment WAS published, but JComments\' notification failed: ' . $e->getMessage();
				}
			}

			$after     = $this->jcComment($id);
			$results[] = [
				'id'              => $id,
				'ok'              => $ok && (int) $after['published'] === $value,
				'state'           => $this->jcStateLabel($after),
				'events_fired'    => true,
				'subscriber_mail' => $mail,
			];
		}

		return $results;
	}
}
