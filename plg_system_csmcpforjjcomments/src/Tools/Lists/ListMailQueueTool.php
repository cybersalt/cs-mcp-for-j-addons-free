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
 * JComments' own outgoing-mail queue (#__jcomments_mailq). A row is written
 * by JCommentsNotification::push() and deleted once Joomla's mailer accepts
 * it; a failed send stays with `attempts` incremented. Up to ten rows are
 * sent whenever a notification is pushed, on about 30% of page views that
 * render comments (site/jcomments.php show(), rand(0,100) <= 30), on a
 * quick-moderation link click, and by the `notifications-cron` task
 * (site/src/Controller/DisplayController.php, guarded by the site secret).
 * The body is never returned: it carries unsubscribe and
 * quick-moderation hashes that act on comments by URL.
 */
final class ListMailQueueTool extends AbstractTool
{
	use JcommentsBootTrait;

	public function getName(): string { return 'list_jcomments_mail_queue'; }

	public function getDescription(): string
	{
		return 'List JComments\' outgoing notification queue: recipient, subject, when queued, priority and failed '
			. 'attempts. A row disappears when the mail is sent, so anything listed has not been sent yet (and rows '
			. 'with attempts > 0 failed). Read-only; message bodies are not shown because they carry moderation and '
			. 'unsubscribe links.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'limit'  => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset' => ['type' => 'integer'],
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

		$db     = $this->db;
		$table  = $db->quoteName($this->jcTable('_mailq'));
		$limit  = $this->jcLimit($arguments);
		$offset = $this->jcOffset($arguments);

		$summary = $db->setQuery(
			'SELECT COUNT(*) AS total, SUM(attempts > 0) AS failed, MIN(created) AS oldest FROM ' . $table
		)->loadAssoc() ?: [];

		$rows = $db->setQuery(
			'SELECT id, name, email, subject, created, attempts, priority, (session_id IS NOT NULL) AS locked FROM ' . $table
			. ' ORDER BY priority DESC, id ASC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		foreach ($rows as &$r) {
			$r['locked_by_a_send_in_progress'] = (int) $r['locked'] === 1;
			unset($r['locked']);
		}

		unset($r);

		return ToolResult::json([
			'ok'      => true,
			'total'   => (int) ($summary['total'] ?? 0),
			'failed'  => (int) ($summary['failed'] ?? 0),
			'oldest'  => $summary['oldest'] ?? null,
			'note'    => 'JComments sends up to 10 queued rows whenever a notification is pushed, on roughly 30% of page views that show comments, on a quick-moderation link click, and from its notifications-cron task. A row still here has not been sent.',
			'limit'   => $limit,
			'offset'  => $offset,
			'messages' => $rows,
		]);
	}
}
