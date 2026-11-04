<?php
/**
 * @package     MCP for Override Checker
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjoverridechecker\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * Override Checker MCP add-on. Built against Cybersalt Override Checker 2.6.1
 * (2026-11-03); needs 2.6.1 or later. cybersalt/cs-mcp-for-j-addons-free#10.
 *
 * WHY IT EXISTS. Override Checker's copy-paste prompt works only for an AI that
 * can call the site's REST API itself with an X-Joomla-Token header. Terminal
 * agents can; browser chats mostly cannot (claude.ai's sandbox blocks the host,
 * ChatGPT cannot send the header, long reviews time out). MCP for J already
 * solves the connection for all of them, so this reuses it.
 *
 * WHY IT IS FREE. Override Checker is free, so MCP access to it is free too.
 *
 * ---------------------------------------------------------------------------
 * THREE FACTS SHAPED EVERY TOOL HERE
 * ---------------------------------------------------------------------------
 *
 *   1. OVERRIDE CHECKER'S HELPERS DO EVERY WRITE. applyFix() and restore()
 *      back the file up before writing and refuse anything outside
 *      templates/<tpl>/html/. Nothing here writes a file itself.
 *
 *   2. A FIX IS ONLY AS GOOD AS THE READ IT WAS BUILT FROM. Reads return the
 *      whole file, and a fix is refused unless it names the sha256 of the bytes
 *      on disk (see ApplyFixTool). A .php fix that does not parse is refused.
 *
 *   3. ONE ROW AT A TIME. There is no dismiss-all. Override Checker removed it
 *      from its own chat in 2.3.3 after a misread instruction cleared 70 rows.
 *
 * ---------------------------------------------------------------------------
 * WHAT WE DELIBERATELY DO NOT BUILD
 * ---------------------------------------------------------------------------
 *
 *   - No dismiss-all and no rescan: both are per-template choices in Override
 *     Checker's dashboard, where the owner sees what they clear.
 *   - No automated AI scan: that spends the owner's own AI provider key.
 *   - No backup or session deletion, and no purge: retention is the scheduled
 *     task's job (plg_task_csoverridechecker).
 *   - No Options or permission editing: core MCP for J's component tools do it.
 */
final class Csmcpforjoverridechecker extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Status.
		\Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\Status\GetStatusTool::class,

		// Flagged overrides.
		\Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\Overrides\ListOverridesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\Overrides\GetOverrideFileTool::class,
		\Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\Overrides\GetCoreFileTool::class,
		\Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\Overrides\ApplyFixTool::class,
		\Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\Overrides\DismissOverrideTool::class,

		// Backups.
		\Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\Backups\ListBackupsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\Backups\GetBackupTool::class,
		\Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\Backups\RestoreBackupTool::class,

		// Review sessions.
		\Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\Sessions\ListSessionsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\Sessions\GetSessionTool::class,
		\Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\Sessions\SaveSessionTool::class,
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
