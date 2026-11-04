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
 * Start restoring a backup snapshot to live. Use this to roll back a sync
 * that went wrong — pick the snapshot from list_stageit_backups by name,
 * pass it here, and StageIt rebuilds live from the snapshot.
 *
 * Stages: init → buildBkMap → prepLive → restoreFiles → restoreDb →
 * finaliseRestore → 0. Chunked internally; may return done=false + a
 * resume_token.
 */
final class RestoreBackupTool extends AbstractTool
{
	use StageItBootTrait;
	use OperationRunTrait;

	public function getName(): string { return 'restore_stageit_backup'; }

	public function getDescription(): string
	{
		return 'Restore a StageIt backup to live. DESTRUCTIVE: replaces live\'s files and database with the named backup from <docroot>/stgbackups/ (list_stageit_backups gives the names). '
			. 'StageIt runs a restore from inside the staging copy, so one must exist. While done is false, call continue_stageit_restore with the resume_token. Needs StageIt 6.4.0+ (Joomla 6) or 5.8.0+ (Joomla 5).'
			. 'The final stretch (restoreFiles onwards) runs in ONE call however long it takes, because while StageIt swaps live\'s files and database the site\'s own API (and so this MCP endpoint) is briefly unreachable. If that call times out on the client side, StageIt keeps running on the server: wait, then call get_stageit_status (it may answer 404 for a short while) before doing anything else.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['backup_name'],
			'properties' => [
				'backup_name' => ['type' => 'string', 'description' => 'Folder name under <docroot>/stgbackups/, as list_stageit_backups returns it.'],
				'time_budget' => ['type' => 'integer', 'description' => 'Seconds this call may keep starting new steps before it returns a resume_token. Default: min(25, max_execution_time - 10).'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		return $this->runOperation('RestoreBackup', 'start', $arguments, $actor);
	}
}
