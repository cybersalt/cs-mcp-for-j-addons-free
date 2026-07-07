<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Operations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;

/**
 * Shared start / continue implementation used by all four StageIt long-runners.
 * Each pair of tools (start + continue) is a thin wrapper that calls
 * runOperation() with a mode and op name.
 *
 * Requires the using class to also use StageItBootTrait.
 */
trait OperationRunTrait
{
	/**
	 * @param 'Deploy'|'Sync'|'Remove'|'RestoreBackup' $op
	 * @param 'start'|'continue' $mode
	 * @param array<string,mixed> $arguments
	 */
	protected function runOperation(string $op, string $mode, array $arguments): ToolResult
	{
		if (!$this->ensureStageItLoaded()) {
			return $this->notInstalledError();
		}

		$budget = $this->resolveTimeBudget(isset($arguments['time_budget']) ? (int) $arguments['time_budget'] : null);

		if ($mode === 'continue') {
			$token = (string) ($arguments['resume_token'] ?? '');
			if ($token === '') {
				return ToolResult::error('resume_token is required for continue_stageit_' . strtolower($op) . '.');
			}
			$state = $this->loadMcpState($token);
			if ($state === null) {
				return ToolResult::error('Unknown or expired resume_token — start a fresh operation.');
			}
			if (($state['op'] ?? '') !== $op) {
				return ToolResult::error('Token was created for a different operation (' . ($state['op'] ?? '?')
					. '), not ' . $op . '. Use continue_stageit_' . strtolower((string) $state['op']) . ' instead.');
			}
			$initialAction = (string) ($state['action'] ?? '');
			if ($initialAction === '' || $initialAction === '0') {
				$this->clearMcpState($token);
				return ToolResult::json([
					'ok'   => true,
					'done' => true,
					'msg'  => 'Operation was already complete when this token was saved.',
				]);
			}
		} else {
			// Fresh start. For RestoreBackup, backup_name has to be set so the
			// AJAX class's constructor + init can locate the backup folder.
			// StageIt reads the target via $_POST['backup'] in stgAjaxRestoreBackup.
			if ($op === 'RestoreBackup') {
				$backupName = (string) ($arguments['backup_name'] ?? '');
				if ($backupName === '') {
					return ToolResult::error('backup_name is required for restore_stageit_backup.');
				}
				if (str_contains($backupName, '/') || str_contains($backupName, '\\') || str_contains($backupName, '..')) {
					return ToolResult::error('Invalid backup_name — must be a plain folder name under stgbackups/.');
				}
				$backupsFolder = JPATH_ROOT . '/stgbackups/' . $backupName;
				if (!is_dir($backupsFolder)) {
					return ToolResult::error('Backup "' . $backupName . '" not found under stgbackups/. Use list_stageit_backups to see available names.');
				}
				$_POST['backup'] = $backupName;
			}
			// Fresh operation → action='init'.
			\vbJson::_emptyVars();
			\vbJson::_setVar('action', 'init');
			$initialAction = 'init';
		}

		$result = $this->driveStateMachine($op, $initialAction, $budget);

		// Compose response.
		$isError = !empty($result['error']);
		$done    = (bool) $result['done'] && !$isError;

		$response = [
			'ok'         => !$isError,
			'op'         => $op,
			'done'       => $done,
			'progress'   => $result['progress'],
			'msg'        => $result['msg'] ?: ($done ? $op . ' complete.' : $op . ' in progress.'),
			'stages_run' => $result['stages_run'],
			'elapsed'    => $result['elapsed'],
			'budget'     => $budget,
		];

		if ($isError) {
			$response['error'] = $result['error'];
		}

		if ($done) {
			// Clear the state file if resuming.
			if ($mode === 'continue') {
				$this->clearMcpState((string) $arguments['resume_token']);
			}
		} elseif (!$isError) {
			// Not done yet — snapshot state and hand back a resume token.
			// On continue, reuse the same token (agent keeps calling with the
			// same value); on start, mint a new one.
			$existingToken = ($mode === 'continue') ? (string) $arguments['resume_token'] : null;
			$token = $this->saveMcpState($op, $existingToken);
			$response['resume_token']  = $token;
			$response['next_action']   = $result['action'];
			$response['next_tool']     = 'continue_stageit_' . strtolower($op);
			$response['note']          = 'Not done yet — call ' . $response['next_tool']
				. '({resume_token: "' . $token . '"}) until done=true.';
		}

		return ToolResult::json($response);
	}
}
