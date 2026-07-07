<?php

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
		return 'At-a-glance StageIt state. Returns: installed (yes/no), staging_exists '
			. '(does the stageit/ mirror folder exist under the docroot), '
			. 'staging_last_touched (mtime of the mirror, if it exists), backup_count '
			. '(number of backup snapshots under stgbackups/), and the server\'s '
			. 'php_max_execution_time / safe_time_budget so the agent can pick a '
			. 'reasonable time_budget for deploy/sync/remove/restore. No input required.';
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
		$base = $this->stageitAdminBase();
		if ($base === null) {
			return ToolResult::json([
				'ok'        => true,
				'installed' => false,
				'msg'       => 'StageIt (com_stageit) is not installed on this site.',
			]);
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
		]);
	}
}
