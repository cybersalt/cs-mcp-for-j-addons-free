<?php
/**
 * @package     MCP for StageIt
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Status;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjstageit\Tools\StageItBootTrait;
use Joomla\CMS\User\User;

/**
 * At-a-glance StageIt state: installed? staging present? how many backups?
 * timeouts / max_execution_time so the agent can pick a sensible
 * time_budget for the long-runner tools.
 *
 * Doesn't run any StageIt code — pure filesystem + config inspection. Safe
 * to call any time.
 */
final class GetStatusTool extends AbstractTool
{
	use StageItBootTrait;

	public function getName(): string { return 'get_stageit_status'; }

	public function getDescription(): string
	{
		return 'StageIt\'s state, as `php cli/joomla.php stageit:status --json` reports it: whether a staging copy '
			. 'exists and when it was built, its database prefix and stg_ table count, the last backup and the last '
			. 'push (and whether it was pushed since it was built), leftovers of an unfinished run, the system checks, '
			. 'and whether a push or a remove would be allowed right now and if not, why. Also the PHP time limit, '
			. 'to pick a time_budget for the operation tools. Read-only; no input.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => new \stdClass(),
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (($refusal = $this->stageitRefusal($actor, false)) !== null) {
			return $refusal;
		}

		$base = $this->stageitAdminBase();
		if ($base === null) {
			return ToolResult::json([
				'ok'        => true,
				'installed' => false,
				'msg'       => 'StageIt (com_stageit) is not installed on this site.',
			]);
		}

		// StageIt 6.4.0+ / 5.8.0+: exactly what `php cli/joomla.php stageit:status --json` reports.
		if ($this->ensureStageItLoaded() && $this->stageitHasCliMode() && ($cli = $this->stageitCli()) !== null) {
			return ToolResult::json($this->cliStatus($cli));
		}

		// Docroot is JPATH_ROOT — that's where the "stageit/" and "stgbackups/"
		// folders live (StageIt uses "../" paths from admin, i.e. one level up).
		$stgFolder     = JPATH_ROOT . '/stageit/';
		$backupsFolder = JPATH_ROOT . '/stgbackups/';

		$stagingExists = is_dir($stgFolder);
		$stagingLastTouched = null;
		if ($stagingExists) {
			$idx = $stgFolder . 'index.php';
			$mt  = is_file($idx) ? @filemtime($idx) : @filemtime($stgFolder);
			$stagingLastTouched = $mt ? gmdate('Y-m-d\TH:i:s\Z', $mt) : null;
		}

		$backupCount = 0;
		$backupsList = [];
		if (is_dir($backupsFolder)) {
			$entries = @scandir($backupsFolder) ?: [];
			foreach ($entries as $e) {
				if ($e === '.' || $e === '..' || !is_dir($backupsFolder . $e)) continue;
				$backupCount++;
			}
		}

		$paramsFile = $base . '/params.php';
		$hasParams  = is_file($paramsFile);

		$iniLimit = (int) ini_get('max_execution_time');
		$safeBudget = $this->resolveTimeBudget(null);

		return ToolResult::json([
			'ok'                       => true,
			'installed'                => true,
			'stageit_admin_base'       => $base,
			'docroot'                  => JPATH_ROOT,
			'staging_folder'           => $stgFolder,
			'staging_exists'           => $stagingExists,
			'staging_last_touched'     => $stagingLastTouched,
			'backups_folder'           => $backupsFolder,
			'backup_count'             => $backupCount,
			'params_file_present'      => $hasParams,
			'php_max_execution_time'   => $iniLimit,
			'php_memory_limit'         => ini_get('memory_limit'),
			'safe_time_budget_default' => $safeBudget,
			'note'                     => $safeBudget < 20
				? 'php max_execution_time is tight; long operations may need many continue_* calls.'
				: 'php max_execution_time is comfortable for chunked operations.',
			'stageit_version'          => $this->stageitVersion(),
			'mcp_operations'           => 'unavailable: this StageIt is too old to be driven from MCP. Update to 6.4.0+ (Joomla 6) or 5.8.0+ (Joomla 5).',
		]);
	}

	/** The fields of StatusCommand::doExecute(), plus what an MCP client needs to plan calls. */
	private function cliStatus(object $cli): array
	{
		$built   = $cli->stageBuiltAt();
		$push    = $cli->lastPush();
		$backups = $cli->backups();

		[$pushErrors]   = $this->stageitPushChecks($cli, false, false);
		[$removeErrors] = $this->stageitRemoveChecks($cli, false);

		return [
			'ok'                 => true,
			'installed'          => true,
			'stageit_version'    => $this->stageitVersion(),
			'live_root'          => $cli->liveRoot(),
			'live_dbprefix'      => $cli->livePrefix(),
			'inside_stage'       => $cli->isInsideStage(),
			'stage_root'         => $cli->stageRoot(),
			'stage_exists'       => $cli->stageExists(),
			'stage_built'        => $built ? date('c', $built) : null,
			'stage_dbprefix'     => $cli->stageConfigValue('dbprefix'),
			'stage_has_cli'      => $cli->stageExists() ? $cli->stageHasCli() : null,
			'stg_tables'         => \count($cli->stgTables()),
			'last_backup'        => $backups ? \StageIt\Plugin\Console\StageIt\StageItCli::BACKUP_FOLDER . '/' . $backups[0]['name'] : null,
			'backup_count'       => \count($backups),
			'last_push'          => $push ? date('c', (int) $push['time']) : null,
			'last_push_source'   => $push['source'] ?? null,
			'pushed_since_built' => $cli->pushCompletedSinceBuild(),
			'unfinished'         => $cli->unfinishedOperations(),
			'checks'             => $cli->systemChecks(),
			'push_allowed'       => !$pushErrors,
			'push_blocked_by'    => $pushErrors,
			'remove_allowed'     => !$removeErrors,
			'remove_blocked_by'  => $removeErrors,
			// After a long push or restore call the client may have timed out: this is how that run ended.
			'last_mcp_operation' => $this->loadLastOp(),
			'build_note'         => $cli->stageExists() && !$cli->pushCompletedSinceBuild()
				? 'deploy_stageit refuses to build over this staging copy without force, because it has not been pushed since it was built.'
				: null,
			'php_max_execution_time'   => (int) ini_get('max_execution_time'),
			'safe_time_budget_default' => $this->resolveTimeBudget(null),
		];
	}
}
