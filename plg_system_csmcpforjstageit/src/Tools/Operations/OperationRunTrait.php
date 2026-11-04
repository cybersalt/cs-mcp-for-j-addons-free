<?php
/**
 * @package     MCP for StageIt
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Operations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;

/**
 * Build, push, remove and restore, driven the way StageIt's own console
 * plugin drives them (plugins/console/stageit/src/StageItCli.php runSteps()):
 * vbJson::$cli on, then for each step vbJson::_cliBeginStep($jdata), a fresh
 * step object, a fresh time slice in $GLOBALS['start'], and the step's result
 * caught as vbCliStep. Nothing about the operation is re-implemented.
 *
 * The one difference is that an MCP call is a web request, so instead of
 * running to the end like the console does, a call starts steps only while
 * its time budget lasts, then saves the next step and its jdata under a
 * resume token. continue_stageit_* picks up from there.
 *
 * Where each operation runs, as StageIt's admin runs it:
 *   Deploy         live administrator/, live StageIt options
 *   Sync (push)    the STAGE's administrator/, the stage's options
 *                  (stgAjaxSync: ../ = stage, ../../ = live)
 *   Remove         live administrator/, live options; the optimized class
 *                  unless switched off, as StageItController::remove() picks
 *   RestoreBackup  the STAGE's administrator/, as the administrator client:
 *                  stgAjaxRestoreBackup refuses unless stageIt::_isLive() says
 *                  "stage", and _isLive() only reads the folder that way for
 *                  the administrator client.
 *
 * Step names are limited to the ones StageIt's controller lets each task run
 * (controller.php $steps), so a resume token can't call anything else.
 *
 * Requires the using class to also use StageItBootTrait.
 */
trait OperationRunTrait
{
	/**
	 * 'no_pause_from': once this step is reached the call runs to the end,
	 * whatever the budget. A push copies the stage's files over live's in
	 * syncLiveFiles, the stage's configuration.php among them (stage prefix,
	 * live_site = the stage folder), and only finaliseSync writes live's
	 * values back. In between, live's own API, which these MCP calls come in
	 * through, answers 404. StageIt's admin never sees that window because it
	 * pushes from the stage's admin, and its CLI because it is one process.
	 * Seen on the sandbox 2026-11-03: the call after syncLiveFiles got a 404.
	 * A restore replaces live's files and database the same way.
	 *
	 * @var array<string, array{steps: string[], where: string, label: string, no_pause_from: ?string}>
	 */
	private static array $STAGEIT_OPS = [
		'Deploy'        => ['steps' => ['init', 'buildStgMap', 'syncStgFiles', 'buildFileMap', 'copyFiles', 'cloneData', 'finaliseStage'], 'where' => 'live', 'label' => 'build', 'no_pause_from' => null],
		'Sync'          => ['steps' => ['init', 'buildStgMap', 'backupFiles', 'backupDb', 'checkBackup', 'prepLiveFiles', 'syncLiveFiles', 'syncLiveData', 'finaliseSync'], 'where' => 'stage', 'label' => 'push', 'no_pause_from' => 'syncLiveFiles'],
		'Remove'        => ['steps' => ['init', 'removeFiles', 'removeDirectories', 'rmDb'], 'where' => 'live', 'label' => 'remove', 'no_pause_from' => null],
		'RestoreBackup' => ['steps' => ['init', 'buildBkMap', 'prepLive', 'restoreFiles', 'restoreDb', 'finaliseRestore'], 'where' => 'stage', 'label' => 'restore', 'no_pause_from' => 'restoreFiles'],
	];

	/** A step that repeats with identical state this many times is stuck (StageItCli::STALL_LIMIT). */
	private static int $STAGEIT_STALL_LIMIT = 300;

	/** An MCP operation left unfinished for longer than this no longer blocks a new one. */
	private static int $STAGEIT_STATE_STALE = 7200;

	/**
	 * @param 'Deploy'|'Sync'|'Remove'|'RestoreBackup' $op
	 * @param 'start'|'continue' $mode
	 * @param array<string,mixed> $arguments
	 */
	protected function runOperation(string $op, string $mode, array $arguments, ?User $actor = null): ToolResult
	{
		if (($refusal = $this->stageitRefusal($actor, true)) !== null) {
			return $refusal;
		}

		if (!$this->ensureStageItLoaded()) {
			return $this->notInstalledError();
		}

		if (!$this->stageitHasCliMode()) {
			return $this->cliModeMissingError();
		}

		$cli = $this->stageitCli();

		if ($cli === null) {
			return $this->cliHelperMissingError();
		}

		$def      = self::$STAGEIT_OPS[$op];
		$budget   = $this->resolveTimeBudget(isset($arguments['time_budget']) ? (int) $arguments['time_budget'] : null);
		$warnings = [];
		$who      = $actor !== null ? $actor->username . ' (user ' . (int) $actor->id . ')' : 'an MCP client';

		if ($mode === 'continue') {
			$token = (string) ($arguments['resume_token'] ?? '');
			$state = $this->loadOpState($token);

			if ($state === null) {
				return ToolResult::error('Unknown or expired resume_token. Check get_stageit_status before starting a fresh operation.');
			}

			if (($state['op'] ?? '') !== $op) {
				return ToolResult::error('That token belongs to a different operation (' . ($state['op'] ?? '?') . '). Use continue_stageit_'
					. $this->opToolSuffix((string) ($state['op'] ?? '')) . ' instead.');
			}
		} else {
			if (($busy = $this->activeOpState()) !== null) {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Another StageIt ' . self::$STAGEIT_OPS[$busy['op']]['label'] . ' started through MCP at ' . date('Y-m-d H:i:s', (int) $busy['created'])
						. ' has not finished. Finish it with continue_stageit_' . $this->opToolSuffix($busy['op']) . ' (its resume token was in that call\'s result) '
						. 'before starting another; an MCP operation left alone for ' . (self::$STAGEIT_STATE_STALE / 3600) . ' hours stops blocking.',
				], true);
			}

			[$errors, $warnings, $jdata] = $this->startChecks($op, $cli, $arguments);

			if ($errors !== []) {
				return ToolResult::json(['ok' => false, 'op' => $op, 'blocked_by' => $errors, 'warnings' => $warnings, 'done' => false], true);
			}

			$token = bin2hex(random_bytes(16));
			$state = [
				'op'      => $op,
				'created' => time(),
				'by'      => $who,
				'action'  => 'init',
				'jdata'   => $jdata,
				'sig'     => null,
				'repeats' => 0,
				'steps'   => 0,
			];

			\vbLog::_audit('MCP: stageit ' . $def['label'] . ' started by ' . $who);
		}

		if (!$cli->lock()) {
			return ToolResult::error('Another StageIt command is running on this site right now. Try again when it has finished.');
		}

		$run = $this->runStepsForBudget($op, $cli, $state, $budget);

		$response = [
			'ok'        => $run['error'] === null,
			'op'        => $op,
			'done'      => $run['done'],
			'progress'  => $run['progress'],
			'msg'       => $run['msg'] !== '' ? $run['msg'] : ($run['done'] ? 'Finished.' : 'In progress.'),
			'steps_run' => $run['steps_run'],
			'elapsed'   => $run['elapsed'],
			'budget'    => $budget,
		];

		if ($warnings !== []) {
			$response['warnings'] = $warnings;
		}

		if ($run['error'] !== null) {
			$this->clearOpState($token);
			\vbLog::_audit('MCP: stageit ' . $def['label'] . ' FAILED at ' . $run['error']['step'] . ': ' . $run['error']['message']);
			\vbLog::_('ERROR: MCP stageit ' . $def['label'] . ' failed at ' . $run['error']['step'] . ': ' . $run['error']['message']);
			$response['error']       = $run['error']['message'];
			$response['failed_step'] = $run['error']['step'];
			$response['note']        = 'StageIt stopped. The full error and stack trace are in StageIt\'s error log (com_stageit.log.php in the site\'s log folder). '
				. 'Run get_stageit_status to see what was left behind before trying again.';
			$this->saveLastOp(['op' => $op, 'by' => $state['by'] ?? $who, 'started' => date('c', (int) $state['created']), 'finished' => date('c'),
				'ok' => false, 'failed_step' => $run['error']['step'], 'error' => $run['error']['message']]);

			return ToolResult::json($response, true);
		}

		if ($run['done']) {
			$this->clearOpState($token);
			$check = $this->verifyFinished($op, $cli, $state);
			$response['verified'] = $check;
			$this->saveLastOp(['op' => $op, 'by' => $state['by'] ?? $who, 'started' => date('c', (int) $state['created']), 'finished' => date('c'),
				'ok' => $check['ok'], 'problems' => $check['problems'], 'facts' => $check['facts']]);

			if ($check['ok']) {
				\vbLog::_audit('MCP: stageit ' . $def['label'] . ' finished OK');
			} else {
				\vbLog::_audit('MCP: stageit ' . $def['label'] . ' finished but ' . implode('; ', $check['problems']));
				$response['ok'] = false;

				return ToolResult::json($response, true);
			}

			return ToolResult::json($response);
		}

		$this->saveOpState($token, $state);
		$response['resume_token'] = $token;
		$response['next_step']    = $state['action'];
		$response['next_tool']    = 'continue_stageit_' . $this->opToolSuffix($op);
		$response['note']         = 'Not finished. Call ' . $response['next_tool'] . '({resume_token: "' . $token . '"}) until done is true.';

		return ToolResult::json($response);
	}

	/**
	 * What must be true before an operation starts, plus the first jdata.
	 *
	 * @return array{0: string[], 1: string[], 2: array<string,mixed>}
	 */
	private function startChecks(string $op, object $cli, array $arguments): array
	{
		$force = ($arguments['force'] ?? false) === true;

		switch ($op) {
			case 'Sync':
				[$errors, $warnings] = $this->stageitPushChecks($cli, $force, ($arguments['no_backup'] ?? false) === true);

				return [$errors, $warnings, []];

			case 'Remove':
				[$errors, $warnings] = $this->stageitRemoveChecks($cli, $force);

				return [$errors, $warnings, []];

			case 'Deploy':
				$errors   = [];
				$warnings = [];

				if ($cli->isInsideStage()) {
					return [['This is the staging copy. Run StageIt from the live site.'], [], []];
				}

				// Building over a stage replaces it. Same rule as removing one:
				// don't throw away work that never reached live.
				if ($cli->stageExists() && !$cli->pushCompletedSinceBuild()) {
					$msg = 'A staging copy already exists (built ' . ($cli->stageBuiltAt() ? date('Y-m-d H:i:s', (int) $cli->stageBuiltAt()) : 'incompletely')
						. ') and has not been pushed to live since. Building again replaces it with a fresh copy of live.';

					if ($force) {
						$warnings[] = $msg . ' Rebuilding anyway because force was given: changes made on the stage will be lost.';
					} else {
						$errors[] = $msg . ' Push it first (sync_stageit), or pass force: true to rebuild anyway.';
					}
				}

				$unfinished = $cli->unfinishedOperations();

				if ($unfinished) {
					$msg = 'A previous StageIt operation did not finish (' . implode(', ', $unfinished) . ').';

					if ($force) {
						$warnings[] = $msg . ' Continuing because force was given.';
					} else {
						$errors[] = $msg . ' Check the StageIt log, then call again with force: true to continue anyway.';
					}
				}

				foreach ($cli->systemChecks() as $check) {
					if (!$check['ok']) {
						$errors[] = $check['label'] . ': ' . $check['detail'];
					}
				}

				return [$errors, $warnings, []];

			case 'RestoreBackup':
				$name = (string) ($arguments['backup_name'] ?? '');

				if ($name === '' || $name !== basename($name) || str_contains($name, '..') || str_contains($name, '\\')) {
					return [['backup_name must be a plain folder name from list_stageit_backups.'], [], []];
				}

				if (!is_dir($cli->backupRoot() . '/' . $name)) {
					return [['There is no backup "' . $name . '" in ' . $cli->backupRoot() . '/. Use list_stageit_backups.'], [], []];
				}

				if (!$cli->stageExists()) {
					return [['StageIt restores a backup from inside the staging copy, and there is no staging copy. Build one first (deploy_stageit).'], [], []];
				}

				// stgAjaxRestoreBackup reads the folder from jdata, relative to the
				// stage's administrator/ folder, as the admin page sends it.
				return [[], [], ['folder' => '../../' . \StageIt\Plugin\Console\StageIt\StageItCli::BACKUP_FOLDER . '/' . $name]];
		}

		return [['Unknown operation ' . $op], [], []];
	}

	/**
	 * StageItCli::runSteps(), stopping before a step that would start after
	 * the budget. Updates $state in place for the next call.
	 *
	 * @return array{done: bool, progress: int, msg: string, steps_run: string[], elapsed: float, error: ?array{step: string, message: string}}
	 */
	private function runStepsForBudget(string $op, object $cli, array &$state, int $budget): array
	{
		$def     = self::$STAGEIT_OPS[$op];
		$started = microtime(true);
		$out    = ['done' => false, 'progress' => 0, 'msg' => '', 'steps_run' => [], 'elapsed' => 0.0, 'error' => null];
		$cwd     = $def['where'] === 'stage' ? $cli->stageRoot() . '/administrator' : JPATH_ADMINISTRATOR;

		if (!is_dir($cwd)) {
			$out['error'] = ['step' => (string) $state['action'], 'message' => 'Folder not found: ' . $cwd];

			return $out;
		}

		$ran = [];

		@set_time_limit(0);
		@ignore_user_abort(true);

		// Core handles JSON-RPC batches, so later tools in this request inherit
		// whatever the run leaves behind: put it all back afterwards.
		$savedGlobals = $this->saveRunGlobals();

		// A push copies the stage's StageIt over live's part-way through:
		// load every class the run needs before that happens (StageItCli::preload()).
		foreach (['vbJson', 'vbAjax', 'vbFiles', 'vbLog', 'vbParams', 'vbDb', 'vbAssist', 'vbCliStep', 'stgAjaxSync', 'stgAjaxDeploy', 'stgAjaxRemove',
			'stgAjaxRemoveOptimized', 'stgAjaxRestoreBackup', 'stgFilemanFix', 'stgAkeebaBypass', 'stgStats', 'stgRobots'] as $preload) {
			class_exists($preload);
		}

		if ($def['where'] === 'stage') {
			$cli->useStageParams();
		} else {
			$cli->useLiveParams();
		}

		$class = 'stgAjax' . $op;

		if ($op === 'Remove') {
			$config = \vbParams::_getParams();
			$class  = (!isset($config->enable_optimized_removal) || $config->enable_optimized_removal) && class_exists('stgAjaxRemoveOptimized')
				? 'stgAjaxRemoveOptimized' : 'stgAjaxRemove';
		}

		$oldCwd = getcwd();

		// StageIt's "../" paths mean the site root only from this folder. From
		// anywhere else they point at the wrong site, so never start a step.
		if (!@chdir($cwd)) {
			$this->restoreRunGlobals($savedGlobals);
			$cli->useLiveParams();
			$out['error'] = ['step' => (string) $state['action'], 'message' => 'Could not change to ' . $cwd];

			return $out;
		}

		$_GET['task'] = $op;
		\vbJson::$cli = true;

		try {
			while (true) {
				$action = (string) $state['action'];

				if (!\in_array($action, $def['steps'], true)) {
					$out['error'] = ['step' => $action, 'message' => 'StageIt has no "' . $action . '" step for this operation.'];
					break;
				}

				$noPause = $def['no_pause_from'] !== null
					&& array_search($action, $def['steps'], true) >= array_search($def['no_pause_from'], $def['steps'], true);

				if ($ran !== [] && !$noPause && microtime(true) - $started >= $budget) {
					break;
				}

				\vbJson::_cliBeginStep(\is_array($state['jdata']) ? $state['jdata'] : []);
				$_GET['action']   = $action;
				$GLOBALS['start'] = microtime(true);
				$vars             = null;

				try {
					$runStep = static function () use ($class, $action): void {
						$step = new $class();
						$step->$action();
					};

					if ($op === 'RestoreBackup') {
						$this->asAdministratorClient($runStep);
					} else {
						$runStep();
					}
				} catch (\vbCliStep $signal) {
					$vars = $signal->vars;
				} catch (\Throwable $e) {
					$out['error'] = ['step' => $action, 'message' => $e->getMessage()];
					break;
				}

				$ran[] = $action;
				$state['steps']++;

				if ($vars === null) {
					$out['error'] = ['step' => $action, 'message' => 'The step ended without reporting a result.'];
					break;
				}

				if (!empty($vars['error'])) {
					$short        = preg_replace('/^StageIt Error:\s*/', '', explode("\n\nStack Trace:", (string) $vars['error'])[0]);
					$out['error'] = ['step' => $action, 'message' => trim((string) $short)];
					break;
				}

				$out['progress'] = (int) round((float) $vars['progress']);
				$out['msg']      = trim(html_entity_decode(strip_tags((string) $vars['msg']), ENT_QUOTES));
				$next            = (string) $vars['action'];

				if ($out['progress'] >= 100 || $next === '0') {
					$out['done'] = true;
					break;
				}

				if ($next === '') {
					$out['error'] = ['step' => $action, 'message' => 'The step did not say what to do next.'];
					break;
				}

				$jdata = \is_array($vars['jdata']) ? $vars['jdata'] : [];
				$sig   = md5($next . json_encode($jdata));

				if ($sig === $state['sig']) {
					if (++$state['repeats'] >= self::$STAGEIT_STALL_LIMIT) {
						$out['error'] = ['step' => $next, 'message' => 'No progress after ' . self::$STAGEIT_STALL_LIMIT . ' attempts; check the StageIt log.'];
						break;
					}
				} else {
					$state['sig']     = $sig;
					$state['repeats'] = 0;
				}

				$state['action'] = $next;
				$state['jdata']  = $jdata;
			}
		} finally {
			\vbJson::$cli = false;

			if ($oldCwd !== false) {
				@chdir($oldCwd);
			}

			$cli->useLiveParams();
			$this->restoreRunGlobals($savedGlobals);
		}

		$out['steps_run'] = $ran;
		$out['elapsed']   = round(microtime(true) - $started, 3);

		return $out;
	}

	/**
	 * What a run changes for the rest of the request: the superglobals StageIt
	 * reads its step from, $GLOBALS['start'], the error settings vbAjax sets
	 * when it loads, the connection's foreign_key_checks if a step stops between
	 * turning them off and on, and the sql_mode StageIt sets before every statement.
	 *
	 * @return array<string,mixed>
	 */
	private function saveRunGlobals(): array
	{
		$saved = [
			'get'        => $_GET,
			'post'       => $_POST,
			'has_start'  => \array_key_exists('start', $GLOBALS),
			'start'      => $GLOBALS['start'] ?? null,
			'reporting'  => error_reporting(),
			'display'    => ini_get('display_errors'),
			'key_checks' => null,
			'sql_mode'   => null,
		];

		try {
			$db = Factory::getContainer()->get(DatabaseInterface::class);
			$saved['key_checks'] = (int) $db->setQuery('SELECT @@SESSION.foreign_key_checks')->loadResult();
			$saved['sql_mode']   = (string) $db->setQuery('SELECT @@SESSION.sql_mode')->loadResult();
		} catch (\Throwable $e) {
			// Nothing to put back then.
		}

		return $saved;
	}

	/** @param array<string,mixed> $saved From saveRunGlobals(). */
	private function restoreRunGlobals(array $saved): void
	{
		$_GET  = $saved['get'];
		$_POST = $saved['post'];

		if ($saved['has_start']) {
			$GLOBALS['start'] = $saved['start'];
		} else {
			unset($GLOBALS['start']);
		}

		error_reporting($saved['reporting']);

		if ($saved['display'] !== false) {
			@ini_set('display_errors', (string) $saved['display']);
		}

		if ($saved['key_checks'] !== null) {
			try {
				$db = Factory::getContainer()->get(DatabaseInterface::class);
				$db->setQuery('SET foreign_key_checks = ' . (int) $saved['key_checks'])->execute();
				$db->setQuery('SET SESSION sql_mode = ' . $db->quote((string) $saved['sql_mode']))->execute();
			} catch (\Throwable $e) {
				// The connection is gone, and the setting with it.
			}
		}
	}

	/**
	 * After a finished run, check the end state the admin buttons leave,
	 * as `stageit:remove` does after removing.
	 *
	 * @return array{ok: bool, problems: string[], facts: array<string,mixed>}
	 */
	private function verifyFinished(string $op, object $cli, array $state): array
	{
		$problems = [];
		$facts    = [];

		switch ($op) {
			case 'Deploy':
				$facts = [
					'stage_complete' => $cli->stageComplete(),
					'stage_dbprefix' => $cli->stageConfigValue('dbprefix'),
					'stg_tables'     => \count($cli->stgTables()),
				];

				if (!$facts['stage_complete']) {
					$problems[] = 'the staging copy has no configuration.php';
				}

				if ($facts['stage_dbprefix'] !== \stageIt::_getStgPrefix() . $cli->livePrefix()) {
					$problems[] = 'the staging copy\'s database prefix is "' . $facts['stage_dbprefix'] . '"';
				}

				if ($facts['stg_tables'] === 0) {
					$problems[] = 'no ' . \stageIt::_getStgPrefix() . ' tables were created';
				}
				break;

			case 'Sync':
				$push    = $cli->lastPush();
				$backups = $cli->backups();
				$facts   = [
					'last_push'     => $push !== null ? date('c', (int) $push['time']) : null,
					'live_dbprefix' => $cli->liveConfigValue('dbprefix'),
					'backup'        => $backups ? \StageIt\Plugin\Console\StageIt\StageItCli::BACKUP_FOLDER . '/' . $backups[0]['name'] : null,
				];

				if ($push === null || (int) $push['time'] < (int) $state['created']) {
					$problems[] = 'StageIt did not record the push as completed (stgbackups/last-push.php)';
				}

				if ($facts['live_dbprefix'] !== $cli->livePrefix()) {
					$problems[] = 'live\'s configuration.php now has database prefix "' . $facts['live_dbprefix'] . '"';
				}
				break;

			case 'Remove':
				$facts = ['stage_folder_left' => is_dir($cli->stageRoot()), 'stg_tables_left' => \count($cli->stgTables())];

				if ($facts['stage_folder_left']) {
					$problems[] = $cli->stageRoot() . '/ still exists';
				}

				if ($facts['stg_tables_left'] > 0) {
					$problems[] = $facts['stg_tables_left'] . ' ' . \stageIt::_getStgPrefix() . ' tables remain';
				}
				break;
		}

		return ['ok' => $problems === [], 'problems' => $problems, 'facts' => $facts];
	}

	/**
	 * Run $fn with an AdministratorApplication as the global application, so
	 * StageIt's stageIt::_isAdmin() is true and _isLive() reads the folder as
	 * the admin page does. Only the restore needs it.
	 */
	private function asAdministratorClient(callable $fn): void
	{
		$original = Factory::$application;
		$level    = ob_get_level();

		try {
			$container = Factory::getContainer();
			$admin     = new \Joomla\CMS\Application\AdministratorApplication($original->getInput(), $container->get('config'), null, $container);
			$admin->setDispatcher($container->get(\Joomla\Event\DispatcherInterface::class));
			$admin->setSession($container->get(\Joomla\Session\SessionInterface::class));
			$admin->loadIdentity($original->getIdentity());
			Factory::$application = $admin;

			$fn();
		} finally {
			Factory::$application = $original;

			while (ob_get_level() > $level) {
				ob_end_clean();
			}
		}
	}

	private function opToolSuffix(string $op): string
	{
		return $op === 'RestoreBackup' ? 'restore' : strtolower($op);
	}

	// -----------------------------------------------------------------------
	// Resume state, between MCP calls
	// -----------------------------------------------------------------------

	private function stateFilePath(string $token): string
	{
		return JPATH_ADMINISTRATOR . '/logs/stageit_mcp_state_' . $token . '.php';
	}

	/** Saved as PHP with a die() guard, like StageIt's own last-push.php, in case the log folder is web-reachable. */
	private function saveOpState(string $token, array $state): void
	{
		$state['updated'] = time();
		@mkdir(\dirname($this->stateFilePath($token)), 0755, true);
		file_put_contents($this->stateFilePath($token), "<?php die('Forbidden.'); ?>\n" . json_encode($state, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG));
	}

	private function loadOpState(string $token): ?array
	{
		if (!preg_match('/^[a-f0-9]{32}$/', $token) || !is_file($this->stateFilePath($token))) {
			return null;
		}

		$lines = explode("\n", (string) file_get_contents($this->stateFilePath($token)), 2);
		$state = json_decode($lines[1] ?? '', true);

		return \is_array($state) && isset($state['op'], $state['action'], self::$STAGEIT_OPS[$state['op']]) ? $state : null;
	}

	private function clearOpState(string $token): void
	{
		if (preg_match('/^[a-f0-9]{32}$/', $token)) {
			@unlink($this->stateFilePath($token));
		}
	}

	/** The newest unfinished MCP operation that is not stale, if any. */
	private function activeOpState(): ?array
	{
		$active = null;

		foreach ((array) glob(JPATH_ADMINISTRATOR . '/logs/stageit_mcp_state_*.php') as $file) {
			if (!preg_match('/stageit_mcp_state_([a-f0-9]{32})\.php$/', (string) $file, $m)) {
				continue;
			}

			$state = $this->loadOpState($m[1]);

			if ($state !== null && time() - (int) ($state['updated'] ?? $state['created']) < self::$STAGEIT_STATE_STALE
				&& ($active === null || (int) $state['created'] > (int) $active['created'])) {
				$active = $state;
			}
		}

		return $active;
	}
}
