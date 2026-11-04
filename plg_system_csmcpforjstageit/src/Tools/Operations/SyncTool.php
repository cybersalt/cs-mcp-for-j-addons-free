<?php
/**
 * @package     MCP for StageIt
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Operations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjstageit\Tools\StageItBootTrait;
use Joomla\CMS\User\User;

/**
 * Start a sync (staging → live via a backup snapshot).
 *
 * Sync takes changes made in the staging environment (files + DB) and
 * promotes them to live, snapshotting the current live state to
 * <docroot>/stgbackups/<timestamp>/ first so you can roll back with
 * restore_stageit_backup if the promotion goes wrong.
 *
 * Stages: init → buildStgMap → backupFiles → backupDb → checkBackup →
 * prepLiveFiles → syncLiveFiles → (finalise) → 0. Same chunking pattern
 * as deploy — call continue_stageit_sync with the returned resume_token
 * until done=true.
 */
final class SyncTool extends AbstractTool
{
	use StageItBootTrait;
	use OperationRunTrait;

	public function getName(): string { return 'sync_stageit'; }

	public function getDescription(): string
	{
		return 'Push the StageIt staging copy to live, with StageIt\'s own push steps exactly as its "Sync to live" button runs them: live is backed up to <docroot>/stgbackups/<date>/ first (unless backups are off in the stage\'s StageIt options), then live\'s files and database are replaced by the stage\'s. '
			. 'Applies the same checks as `php cli/joomla.php stageit:push`: a complete stage with the right database prefix and stg_ tables, a writable site, no unfinished earlier run (force overrides), backups on (no_backup overrides). '
			. 'While done is false, call continue_stageit_sync with the resume_token. When done, StageIt records the push in stgbackups/last-push.php, which remove_stageit then accepts. Needs StageIt 6.4.0+ (Joomla 6) or 5.8.0+ (Joomla 5).'
			. 'The final stretch (syncLiveFiles onwards) runs in ONE call however long it takes, because while StageIt swaps live\'s files and database the site\'s own API (and so this MCP endpoint) is briefly unreachable. If that call times out on the client side, StageIt keeps running on the server: wait, then call get_stageit_status (it may answer 404 for a short while) before doing anything else.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'time_budget' => ['type' => 'integer', 'description' => 'Seconds this call may keep starting new steps before it returns a resume_token. Default: min(25, max_execution_time - 10).'],
				'force' => ['type' => 'boolean', 'description' => 'Push even though a previous StageIt run did not finish.'],
				'no_backup' => ['type' => 'boolean', 'description' => 'Allow the push when backups are switched off in the stage\'s StageIt options. Live is then NOT backed up first.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		return $this->runOperation('Sync', 'start', $arguments, $actor);
	}
}
