<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjstageit\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * StageIt MCP add-on plugin. Registers a tool set for Barnaby Dixon's
 * StageIt Joomla staging/deployment component (com_stageit).
 *
 * StageIt is a filesystem + database mirroring tool, not a table-driven
 * component. Its four big operations — deploy / sync / remove / restore
 * backup — are chunked state-machine flows that a JS loop drives in the
 * admin UI. The MCP tools port that same drive-the-machine loop into PHP
 * so an MCP agent can run them with a resumable resume_token pattern,
 * bounded by a time-budget so each MCP call returns within the PHP
 * execution ceiling.
 *
 * v1.0.0 ships:
 *
 *   Status (3): get_stageit_status, get_stageit_prechecks, list_stageit_backups
 *
 *   Long-runner orchestration (8): start/continue pairs for each of the
 *   four operations. Each start_* returns done=true immediately for small
 *   sites that fit in one budget; done=false + resume_token when it needs
 *   more calls, which the agent then passes to continue_stageit_<op>
 *   repeatedly until done=true.
 *
 * Settings / logs / individual-backup housekeeping tools are v1.1 — see
 * TODO.md in this plugin folder.
 */
final class Csmcpforjstageit extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Status / inspection
		\Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Status\GetStatusTool::class,
		\Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Status\GetPrechecksTool::class,
		\Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Status\ListBackupsTool::class,

		// Deploy (live → staging)
		\Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Operations\DeployTool::class,
		\Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Operations\ContinueDeployTool::class,

		// Sync (staging → live via backup)
		\Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Operations\SyncTool::class,
		\Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Operations\ContinueSyncTool::class,

		// Remove staging
		\Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Operations\RemoveTool::class,
		\Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Operations\ContinueRemoveTool::class,

		// Restore a backup snapshot
		\Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Operations\RestoreBackupTool::class,
		\Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Operations\ContinueRestoreTool::class,
	];

	public static function getSubscribedEvents(): array
	{
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
