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
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Reply to a comment as the MCP token's own Joomla account.
 *
 * Follows JCommentsAJAX::addComment() (site/jcomments.ajax.php:161-772), the
 * code behind the front-end form, for everything that decides what lands in
 * the row:
 *
 *   - text: HTML is stripped there (prepareValues(), line 71) and line breaks
 *     become `<br />` (JCommentsText::nl2br); BBCode the author may not use is
 *     removed (JCommentsBBCode::filter(), and the custom-BBCode filter when
 *     enabled). We refuse HTML instead of stripping it silently.
 *   - author: a registered user's name, username and email are taken from the
 *     account (lines 409-429); ip is REMOTE_ADDR; date is now (UTC);
 *     published is the author's comment.autopublish permission (line 504).
 *   - duplicate guard: the same text, IP, name, user, object and parent is
 *     refused (lines 506-536). That also stops an MCP retry posting twice.
 *   - threading and the "RE: <title>" default title: the vendor table's own
 *     store() (administrator/tables/comment.php:86-125), which we call.
 *
 * Deliberately not reproduced: the merge of a registered user's consecutive
 * comments within merge_time (it would rewrite an earlier comment), captcha,
 * flood and terms-of-use checks (front-end anti-abuse, meaningless for the
 * site's own account), and the onJCommentsCommentBefore/AfterAdd plugin
 * events unless notify is true. The admin editor fires none of them either.
 *
 * The reply is posted under the PARENT's language tag, so it lands in the same
 * #__jcomments_objects row and the same language-filtered list as the comment
 * it answers. There is no stored comment count to update (see the boot trait).
 */
final class CreateReplyTool extends AbstractTool
{
	use JcommentsBootTrait;

	public function getName(): string { return 'create_jcomments_reply'; }

	public function getDescription(): string
	{
		return 'Reply to a JComments comment as the Joomla account this MCP token belongs to. The reply is threaded '
			. 'under the comment exactly as JComments\' own form would thread it (parent, thread, path, level), takes '
			. 'the account\'s name and email, and is published if the account has JComments\' auto-publish permission '
			. '(or per the publish argument). Plain text with optional BBCode; line breaks are kept. Sends NOTHING by '
			. 'default; notify: true runs JComments\' own new-comment notifications (moderators and article '
			. 'subscribers). An identical reply already on the same comment is refused, so a retry cannot post twice.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'comment_id' => ['type' => 'integer', 'description' => 'The comment being answered.'],
				'text'       => ['type' => 'string', 'description' => 'Reply text. Plain text and JComments BBCode ([b], [i], [quote], [url=...]...); no HTML.'],
				'title'      => ['type' => 'string', 'description' => 'Optional title. Empty = JComments\' default, which is "RE: <parent title>" when comment titles are on.'],
				'publish'    => ['type' => 'boolean', 'description' => 'Default: the account\'s comment.autopublish permission, as on the front end. Setting it explicitly needs core.edit.state.'],
				'notify'     => ['type' => 'boolean', 'description' => 'Default false: nothing is queued or sent. True = JComments\' own new-comment mail (moderators if enabled, and subscribers when published).'],
			],
			'required'             => ['comment_id', 'text'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->jcInstalled()) {
			return $this->jcNotInstalledError();
		}

		// What JComments' own reply button requires (site/classes/acl.php canReply()).
		$this->jcRequireAccess($actor, 'comment.comment', 'comment.reply');

		$parentId = $this->requirePositiveInt($arguments, 'comment_id');
		$parent   = $this->jcComment($parentId);

		if ($parent === null) {
			return ToolResult::json(['ok' => false, 'error' => 'No comment with id ' . $parentId . '.'], true);
		}

		if ((int) $parent['deleted'] === 1) {
			return ToolResult::json(['ok' => false, 'error' => 'Comment ' . $parentId . ' is marked deleted; JComments does not allow replies to it.'], true);
		}

		$params   = $this->jcParams();
		$warnings = [];

		// --- Text --------------------------------------------------------------
		$text = trim(str_replace("\r", '', (string) ($arguments['text'] ?? '')));

		if ($text === '') {
			throw new \InvalidArgumentException('text is required.');
		}

		if (strip_tags($text) !== $text) {
			throw new \InvalidArgumentException(
				'text contains HTML (or a "<" that looks like a tag). JComments strips all HTML from comments, so it '
				. 'would be lost. Use plain text and BBCode ([b]bold[/b], [url=https://...]link[/url]); a "<" directly followed '
				. 'by a letter reads as a tag, so put a space after it. Nothing was written.'
			);
		}

		$maxLength = (int) $params->get('comment_maxlength', 0);

		if ($maxLength > 0 && !$actor->authorise('comment.length_check', 'com_jcomments') && mb_strlen($text) > $maxLength) {
			throw new \InvalidArgumentException('text is ' . mb_strlen($text) . ' characters; JComments\' comment_maxlength is ' . $maxLength . ' for this account. Nothing was written.');
		}

		$title = trim((string) ($arguments['title'] ?? ''));

		if ($title !== '' && strip_tags($title) !== $title) {
			throw new \InvalidArgumentException('title contains HTML. Nothing was written.');
		}

		$this->jcRequireFits('title', $title, 255);

		// --- Threading limits JComments never checks ---------------------------
		$path  = (string) $parent['path'] . ',' . (int) $parent['id'];
		$level = (int) $parent['level'] + 1;

		$this->jcRequireFits('the thread path for this reply ("' . $path . '")', $path, 190);

		if ($level > 255) {
			throw new \InvalidArgumentException('This reply would be at depth ' . $level . '; the level column holds 255. Nothing was written.');
		}

		// --- Published ---------------------------------------------------------
		if (\array_key_exists('publish', $arguments)) {
			if (!$actor->authorise('core.edit.state', 'com_jcomments')) {
				throw new \RuntimeException('Setting publish explicitly needs core.edit.state on com_jcomments. Leave it out to follow the account\'s comment.autopublish permission.');
			}

			$published = (bool) $arguments['publish'] ? 1 : 0;
		} else {
			$published = (int) $actor->authorise('comment.autopublish', 'com_jcomments');
		}

		$treeView = (string) $params->get('template_view', 'tree') === 'tree';

		if ((int) $parent['published'] !== 1 && $treeView) {
			$warnings[] = 'The comment you replied to is unpublished. In the tree view JComments builds its tree from published '
				. 'comments only, so this reply will not show on the site until that comment is approved.';
		} elseif ((int) $parent['published'] !== 1) {
			$warnings[] = 'The comment you replied to is unpublished, so readers will see your reply without the comment it answers.';
		}

		if (!$treeView) {
			$warnings[] = 'JComments is set to the flat "list" view (template_view), so replies are shown in date order, not '
				. 'nested under the comment they answer, and visitors see no Reply button.';
		}

		$notify  = ($arguments['notify'] ?? false) === true;
		$queue0  = $this->jcMailQueueCount();
		$db      = $this->db;
		$table   = $db->quoteName($this->jcTable(''));
		$before  = $this->jcCount('');
		$account = Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById((int) $actor->id);
		$ip      = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

		$result = $this->jcWithAdminApp($actor, function () use ($text, $title, $parent, $published, $account, $ip, $params, $notify, $table, $db): array {
			// Vendor text pipeline, minus strip_tags (we refused HTML above).
			$stored = \JCommentsText::nl2br($text);
			$stored = \JCommentsFactory::getBBCode()->filter($stored);
			$filteredNotes = [];

			if ((int) $params->get('enable_custom_bbcode', 0) === 1 && $stored !== '') {
				$len    = \strlen($stored);
				$stored = \JCommentsFactory::getCustomBBCode()->filter($stored);

				if ($stored === '' && $len > 0) {
					return ['error' => 'JComments\' custom-BBCode filter removed the whole text: this account may not use a tag in it.'];
				}
			}

			if (trim(strip_tags($stored)) === '') {
				return ['error' => 'After JComments\' BBCode filter nothing is left of the text.'];
			}

			if ($stored !== \JCommentsText::nl2br($text)) {
				$filteredNotes[] = 'JComments removed BBCode this account is not allowed to use (comment.bbcode.* permissions).';
			}

			if (\strlen($stored) > 65535) {
				return ['error' => 'The stored text would be ' . \strlen($stored) . ' bytes; the comment column (TEXT) holds 65535.'];
			}

			// Duplicate guard, as addComment() runs it.
			$dup = (int) $db->setQuery(
				'SELECT id FROM ' . $table
				. ' WHERE comment = ' . $db->quote($stored)
				. ' AND ip = ' . $db->quote($ip)
				. ' AND name = ' . $db->quote((string) $account->name)
				. ' AND userid = ' . (int) $account->id
				. ' AND object_id = ' . (int) $parent['object_id']
				. ' AND parent = ' . (int) $parent['id']
				. ' AND object_group = ' . $db->quote((string) $parent['object_group']),
				0,
				1
			)->loadResult();

			if ($dup > 0) {
				return ['error' => 'An identical reply by this account to comment ' . (int) $parent['id'] . ' already exists (comment ' . $dup . '). JComments refuses duplicates; nothing was written.', 'duplicate_of' => $dup];
			}

			$comment               = new \JCommentsTableComment($db);
			$comment->id           = 0;
			$comment->parent       = (int) $parent['id'];
			$comment->object_id    = (int) $parent['object_id'];
			$comment->object_group = (string) $parent['object_group'];
			$comment->lang         = (string) $parent['lang'];
			$comment->userid       = (int) $account->id;
			$comment->name         = (string) $account->name;
			$comment->username     = (string) $account->username;
			$comment->email        = (string) $account->email;
			$comment->homepage     = '';
			$comment->title        = $title;
			$comment->comment      = $stored;
			$comment->ip           = $ip;
			$comment->date         = Factory::getDate()->toSql();
			$comment->published    = $published;
			$comment->deleted      = 0;
			$comment->subscribe    = 0;
			$comment->source       = '';

			if (!$comment->store()) {
				return ['error' => 'JComments\' table refused the reply: ' . $comment->getError()];
			}

			$mail = 'none: notify was false, so no JComments event fired and nothing was queued or sent';

			if ($notify) {
				// addComment() after a successful store, lines 636-651. The reply
				// exists by now, so a failure here is reported, not thrown.
				$sent = [];

				try {
					\JCommentsEvent::trigger('onJCommentsCommentAfterAdd', [&$comment]);

					if ((int) $params->get('enable_notification', 0) === 1 && \in_array(1, array_map('intval', (array) $params->get('notification_type', [])), true)) {
						\JComments::sendNotification($comment, true);
						$sent[] = 'moderator notification (notification_email)';
					}

					if ((int) $comment->published === 1) {
						\JComments::sendToSubscribers($comment, true);
						$sent[] = 'article subscribers';
					}

					$mail = $sent === [] ? 'notify was true but JComments had nothing to send (moderator notifications off and reply unpublished)' : 'handed to the JComments notifier, which mails every published subscriber except the author and you: ' . implode(', ', $sent);
				} catch (\Throwable $e) {
					$mail = 'the reply WAS saved, but JComments\' notification failed' . ($sent ? ' after ' . implode(', ', $sent) : '') . ': ' . $e->getMessage();
				}
			}

			return ['id' => (int) $comment->id, 'filtered' => $filteredNotes, 'mail' => $mail];
		});

		if (isset($result['error'])) {
			return ToolResult::json(['ok' => false] + $result, true);
		}

		$this->jcCleanCache();

		// Verify independently: exactly one new row, threaded as expected.
		$row       = $this->jcComment((int) $result['id']);
		$after     = $this->jcCount('');
		$expectTid = (int) $parent['thread_id'] ?: (int) $parent['id'];
		$checks    = [
			'one_row_added' => $after === $before + 1,
			'parent'        => $row !== null && (int) $row['parent'] === (int) $parent['id'],
			'thread_id'     => $row !== null && (int) $row['thread_id'] === $expectTid,
			'level'         => $row !== null && (int) $row['level'] === $level,
			'path'          => $row !== null && (string) $row['path'] === $path,
		];

		$warnings = array_merge($warnings, $result['filtered']);
		$objects  = $row !== null ? $this->jcObjectInfo([$row]) : [];

		if ($row !== null && !($objects[$row['object_group'] . ':' . (int) $row['object_id'] . ':' . $row['lang']]['cached'] ?? false)) {
			$warnings[] = 'There is no #__jcomments_objects row for this article and language yet; JComments adds it the next time the article is viewed.';
		}

		return ToolResult::json([
			'ok'       => !\in_array(false, $checks, true),
			'comment'  => $row !== null ? $this->jcFormatComment($row, $objects, true) : null,
			'checks'   => $checks,
			'mail'     => $notify
				? ['result' => $result['mail'], 'queued_before' => $queue0, 'queued_after' => $this->jcMailQueueCount(), 'note' => 'JComments deletes a queue row once the mailer accepts it, so a row that is still queued failed to send.']
				: $result['mail'],
			'warnings' => $warnings,
		], !\in_array(false, $checks, true) ? false : true);
	}
}
