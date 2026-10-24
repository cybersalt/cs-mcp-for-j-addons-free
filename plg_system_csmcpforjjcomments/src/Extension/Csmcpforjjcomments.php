<?php
/**
 * @package     MCP for JComments
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjcomments\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * JComments MCP add-on. Built against JComments 5.0.6 (exstreme/Jcomments-4,
 * 2026-09-30) on Joomla 6.2.0, and diffed against 5.0.5.
 *
 * WHY THIS ADD-ON IS FREE. JComments is free (GPL), so MCP access to it is
 * free too.
 *
 * ---------------------------------------------------------------------------
 * FOUR FACTS SHAPED EVERY TOOL HERE
 * ---------------------------------------------------------------------------
 *
 *   1. JCOMMENTS' OWN PUBLISH BUTTON EMAILS PEOPLE. Its admin publish pushes a
 *      "new comment" mail to every subscriber of the article. So every write
 *      here sends nothing and fires no JComments plugin event by default, and
 *      `notify: true` runs the vendor's own sequence when that is wanted.
 *
 *   2. THREADING IS FOUR COLUMNS THE TABLE COMPUTES. parent, thread_id, path
 *      and level are filled by JCommentsTableComment::store() from the parent
 *      row. Replies are stored through that store(), not hand-written SQL.
 *
 *   3. DELETE TAKES THE WHOLE SUB-THREAD (in the default delete mode), or
 *      only marks the row (in "mark" mode). Both run through the vendor table.
 *
 *   4. THERE IS NO SPAM STATE AND NO STORED COUNT. "Marking" spam means
 *      unpublishing or deleting, optionally with the vendor's IP blacklist;
 *      comment counts are live COUNT(*)s, so nothing needs re-counting.
 *
 * ---------------------------------------------------------------------------
 * WHAT WE DELIBERATELY DO NOT BUILD
 * ---------------------------------------------------------------------------
 *
 *   - No editing of other people's comments: changing someone's words is a
 *     different job from moderating them, and the request did not ask for it.
 *   - No settings writer: options are com_config's form, and the moderation
 *     rules are Joomla permissions, which core MCP for J's permission tools
 *     already edit (get_jcomments_settings reports them).
 *   - No top-level comment as the site account (replies only, as requested):
 *     a new top-level comment needs the article's title and link built by
 *     JComments' content plugin through the site router, which the API
 *     application does not have.
 */
final class Csmcpforjjcomments extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Diagnostics.
		\Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Diagnostics\CheckHealthTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Diagnostics\GetSettingsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Diagnostics\QueryTableTool::class,

		// Reading comments.
		\Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Comments\ListCommentsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Comments\GetCommentTool::class,

		// Replying as the site's account.
		\Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Comments\CreateReplyTool::class,

		// Moderation.
		\Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Moderation\SetCommentStateTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Moderation\DeleteCommentsTool::class,

		// Spam.
		\Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Spam\CheckSpamTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Spam\MarkSpamTool::class,

		// Read-only lists.
		\Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Lists\ListObjectsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Lists\ListReportsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Lists\ListSubscriptionsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Lists\ListBlacklistTool::class,
		\Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Lists\ListMailQueueTool::class,
	];

	public static function getSubscribedEvents(): array
	{
		/*
		 * Runs on EVERY request. RegisterToolsEvent lives in com_csmcpforj; a bare
		 * ::EVENT_NAME access would autoload it and fatal the whole site (front end
		 * and administrator) when MCP for Joomla is not installed. class_exists()
		 * returns false instead. Do not "simplify" this.
		 */
		if (!class_exists(RegisterToolsEvent::class)) {
			return [];
		}

		return [RegisterToolsEvent::EVENT_NAME => 'onRegisterTools'];
	}

	public function onRegisterTools(RegisterToolsEvent $event): void
	{
		$registry = $event->getRegistry();
		$db       = $this->getDatabase();

		foreach (self::TOOLS as $toolClass) {
			$registry->register(new $toolClass($db));
		}
	}

	public static function getToolClasses(): array
	{
		return self::TOOLS;
	}
}
