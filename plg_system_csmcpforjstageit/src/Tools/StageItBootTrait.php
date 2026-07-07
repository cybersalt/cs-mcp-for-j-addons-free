<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjstageit\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;

/**
 * Bootstrap helpers for driving StageIt's chunked state-machine from inside
 * an MCP tool.
 *
 * StageIt (com_stageit, Barnaby Dixon) is not a table-driven Joomla component.
 * It's a filesystem + database mirroring tool. The four big operations —
 * deploy / sync / remove / restoreBackup — are structured as state-machine
 * AJAX flows: each operation has an ordered list of stages, each stage saves
 * mypos/chunkoffset state into a JSON blob, and stages loop under a time
 * budget (`vbAjax::_checkTime()`) until either done or time-out.
 *
 * StageIt's admin UI drives the loop from JavaScript: repeatedly POST the
 * previous response back as the next request. Chunk boundaries and resume
 * points are all internal to StageIt already; the JS layer is just a while()
 * loop with an HTTP call inside.
 *
 * This trait moves that same drive-the-machine loop into PHP so the MCP tool
 * can run inside a single PHP request as long as budget allows. When budget
 * runs out mid-operation, we snapshot StageIt's internal state to a JSON file
 * on disk and return a resume_token. The next MCP call restores the state
 * and continues the loop from exactly where it left off.
 *
 * Three hard details this trait handles for the tool authors:
 *
 *   1. **chdir into StageIt admin.** StageIt's classes use "../" and "../../"
 *      relative paths that assume cwd is administrator/components/com_stageit/.
 *      Under the MCP API endpoint cwd is elsewhere. We chdir once, run stages,
 *      restore cwd in finally.
 *
 *   2. **Suppress saveJson()'s die() + inner chain.** vbAjax::saveJson() calls
 *      vbJson::_saveJson() which echoes and die()s — fatal inside an MCP tool.
 *      It also chains recursively into the next stage. Both behaviours belong
 *      to the browser JS loop, not us. The Steppable* factories in this trait
 *      return a subclass whose saveJson() is a no-op — the tool loop calls
 *      each stage explicitly, reads vbJson::$vars after, and decides itself
 *      whether to run the next stage.
 *
 *   3. **State persistence between MCP calls.** vbJson uses a static class
 *      property to hold state within one PHP request. Between requests it's
 *      empty. We snapshot the state (action, progress, msg, jdata) to
 *      administrator/logs/stageit_mcp_state_<token>.json between calls, and
 *      restore it on continue_*.
 */
trait StageItBootTrait
{
	/**
	 * StageIt component base path, or null if StageIt isn't installed.
	 */
	protected function stageitAdminBase(): ?string
	{
		$path = JPATH_ADMINISTRATOR . '/components/com_stageit';
		return is_dir($path) ? $path : null;
	}

	/**
	 * Standard error response for "StageIt isn't on this site."
	 */
	protected function notInstalledError(): ToolResult
	{
		return ToolResult::error(
			'StageIt (com_stageit) is not installed on this site, or the install is incomplete.'
		);
	}

	/**
	 * Load StageIt's classes into the process. Autoloader is registered by
	 * stageIt.class.php for the `stg` prefix; the framework classes have to
	 * be require_once'd because they're loaded via a legacy entrypoint.
	 * Idempotent — safe to call from every tool's run().
	 */
	protected function ensureStageItLoaded(): bool
	{
		$base = $this->stageitAdminBase();
		if ($base === null) {
			return false;
		}

		static $loaded = false;
		if ($loaded) {
			return true;
		}

		// Framework classes StageIt's classes call into. Order matters: vbLog
		// and vbParams get called from stageIt's initParams; vbAjax needs
		// vbParams already loaded because its constructor reads them.
		foreach (['vbAjax', 'vbAssist', 'vbArchive', 'vbDb', 'vbFiles', 'vbJson', 'vbLog', 'vbParams'] as $cls) {
			$file = $base . '/framework/' . $cls . '.class.php';
			if (is_file($file)) {
				require_once $file;
			}
		}

		// stageIt.class.php registers the spl_autoload_register for the stg*
		// classes and defines the stageIt class itself.
		require_once $base . '/classes/stageIt.class.php';

		// Now that spl_autoload is registered, we can pre-fault the four
		// AJAX classes so autoload doesn't run mid-loop.
		foreach (['stgAjaxDeploy', 'stgAjaxSync', 'stgAjaxRemove', 'stgAjaxRestoreBackup'] as $cls) {
			if (!class_exists($cls, true)) {
				// Autoload should have fired; if it didn't, StageIt install is broken.
				return false;
			}
		}

		// Initialize params — most stages depend on `$this->config` from
		// vbParams. Same call the StageIt controller makes in its constructor.
		\stageIt::_initParams();

		// The vbAjax::_checkTime() budget reads from static $GLOBALS['start']
		// set at framework include time. If we've been sitting in the request
		// for a while before booting StageIt, that start-time is stale and
		// StageIt will think budget is already exhausted. Reset to now so the
		// per-stage budget check reflects "time since we started the stage".
		if (isset($GLOBALS['start'])) {
			$GLOBALS['start'] = microtime(true);
		}
		if (isset($GLOBALS['recurs'])) {
			$GLOBALS['recurs'] = 0;
		}

		$loaded = true;
		return true;
	}

	/**
	 * Push the StageIt admin directory as cwd for the duration of $fn.
	 * StageIt's relative paths assume this cwd; from an MCP API request cwd
	 * is elsewhere. Restore original cwd in finally so downstream code isn't
	 * disturbed.
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	protected function withStageItCwd(callable $fn)
	{
		$base = $this->stageitAdminBase();
		if ($base === null) {
			throw new \RuntimeException('StageIt admin base not present.');
		}
		$original = getcwd();
		if (chdir($base) === false) {
			throw new \RuntimeException('Failed to chdir into ' . $base);
		}
		try {
			return $fn();
		} finally {
			if ($original !== false) {
				@chdir($original);
			}
		}
	}

	/**
	 * Return a Steppable* subclass instance for the given operation. The
	 * subclass overrides saveJson() so it does NOT die and does NOT recursively
	 * chain into the next stage — the tool loop handles both concerns.
	 *
	 * @param 'Deploy'|'Sync'|'Remove'|'RestoreBackup' $op
	 */
	protected function makeSteppableAjax(string $op): object
	{
		$class = 'stgAjax' . $op;
		if (!class_exists($class, false)) {
			throw new \RuntimeException($class . ' not loaded — call ensureStageItLoaded() first.');
		}

		// The subclass has to inherit from the specific StageIt AJAX class,
		// so we generate one on the fly (once per op) using eval. Doing this
		// with anonymous classes doesn't work here because we need class_exists
		// to succeed under a stable name if we ever wanted to reuse it, and
		// because saveJson() is protected — an anonymous subclass would still
		// need eval or a runtime scope override to reach it. Straight eval of
		// a named subclass is the cleanest option and only runs once per op
		// per request.
		$subName = 'Steppable_' . $op;
		if (!class_exists($subName, false)) {
			$code = "class $subName extends $class {"
				. "public bool \$didStep = false;"
				. "protected function saveJson(\$skip = 0) { \$this->didStep = true; }"
				. "}";
			eval($code);
		}
		return new $subName();
	}

	/**
	 * Snapshot vbJson state to disk, keyed by a resume token. Called when
	 * the outer loop hits the time budget mid-operation. On the first save
	 * pass null for $reuseToken to mint a new one; on subsequent continues
	 * pass the existing token to overwrite in place (same token stays
	 * valid across the whole operation lifetime).
	 */
	protected function saveMcpState(string $op, ?string $reuseToken = null): string
	{
		$token = $reuseToken !== null && preg_match('/^[a-f0-9]{32}$/', $reuseToken)
			? $reuseToken
			: bin2hex(random_bytes(16));
		$file  = $this->stateFilePath($token);
		$state = [
			'op'       => $op,
			'created'  => time(),
			'action'   => \vbJson::_getVar('action'),
			'progress' => \vbJson::_getVar('progress'),
			'msg'      => \vbJson::_getVar('msg'),
			'jdata'    => \vbJson::_getVar('jdata'),
		];
		$dir = dirname($file);
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		return $token;
	}

	/**
	 * Restore vbJson state from disk. Returns the operation name so the
	 * caller can verify the token is being used for the same op it was
	 * created for.
	 */
	protected function loadMcpState(string $token): ?array
	{
		if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
			return null;
		}
		$file = $this->stateFilePath($token);
		if (!is_file($file)) {
			return null;
		}
		$state = json_decode((string) file_get_contents($file), true);
		if (!is_array($state)) {
			return null;
		}

		\vbJson::_emptyVars();
		\vbJson::_setVar('action', $state['action'] ?? '');
		\vbJson::_setVar('progress', $state['progress'] ?? 0);
		\vbJson::_setVar('msg', $state['msg'] ?? '');
		if (isset($state['jdata']) && is_array($state['jdata'])) {
			foreach ($state['jdata'] as $k => $v) {
				\vbJson::_setJVar($k, $v);
			}
		}
		// vbJson::_getJPost() reads via internal $post (initialised lazily from
		// $_POST). Prime $_POST['jdata'] with the restored jdata so the next
		// stage's _getJPost('mypos') / _getJPost('chunk') calls return the
		// restored values.
		$_POST['jdata'] = $state['jdata'] ?? [];

		return $state;
	}

	protected function clearMcpState(string $token): void
	{
		if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
			return;
		}
		$file = $this->stateFilePath($token);
		if (is_file($file)) {
			@unlink($file);
		}
	}

	private function stateFilePath(string $token): string
	{
		return JPATH_ADMINISTRATOR . '/logs/stageit_mcp_state_' . $token . '.json';
	}

	/**
	 * Derive the safe per-call time budget. Priority order:
	 *   1. If arg supplied and positive → clamp to (5, ini_max_execution_time - 10)
	 *   2. Otherwise → min(25, ini_max_execution_time - 10), floor at 15
	 *
	 * ini_max_execution_time of 0 = unlimited; treat as "no ceiling from ini",
	 * fall back to 25s default so we still return promptly to the MCP client.
	 */
	protected function resolveTimeBudget(?int $userArg): int
	{
		$iniLimit = (int) ini_get('max_execution_time');
		$safeMax  = $iniLimit > 0 ? max(5, $iniLimit - 10) : 300;

		if ($userArg !== null && $userArg > 0) {
			return max(5, min($safeMax, $userArg));
		}
		return max(15, min($safeMax, 25));
	}

	/**
	 * Drive StageIt's state machine forward for one MCP call. The loop runs
	 * stages until either (a) action becomes empty/'0' (operation done), or
	 * (b) our time budget expires. Returns a normalised array describing the
	 * outcome so the tool wrapper can compose the ToolResult.
	 *
	 * @param 'Deploy'|'Sync'|'Remove'|'RestoreBackup' $op
	 * @param string $initialAction 'init' for a fresh start; any other value
	 *                              means we're resuming and this is the action
	 *                              already loaded by loadMcpState().
	 * @param int $budget seconds
	 * @return array{done: bool, action: string, progress: int, msg: string,
	 *               stages_run: array<int,string>, elapsed: float, error?: string}
	 */
	protected function driveStateMachine(string $op, string $initialAction, int $budget): array
	{
		// Bump limits. set_time_limit(0) resets the PHP execution timer;
		// ignore_user_abort keeps us running if the MCP client disconnects
		// mid-chunk (better to finish the chunk cleanly than half-write state).
		@set_time_limit(0);
		@ignore_user_abort(true);

		// vbAjax::saveJson() reads $_GET['task'] to derive the class name for
		// its recursive chain call. We overrode saveJson so it never gets
		// there, but stagFactor's constructor path might touch it too.
		$_GET['task'] = $op;

		if (\vbJson::_getVar('action') === '' || \vbJson::_getVar('action') === false) {
			\vbJson::_setVar('action', $initialAction);
		}

		$ajax = null; // instantiated once we're safely inside chdir()

		$stagesRun = [];
		$startTime = microtime(true);
		$hardStop  = $startTime + $budget;

		$result = $this->withStageItCwd(function () use ($op, &$ajax, &$stagesRun, $hardStop) {
			$ajax = $this->makeSteppableAjax($op);

			while (true) {
				$action = (string) \vbJson::_getVar('action');
				if ($action === '' || $action === '0') {
					// Operation complete.
					return ['done' => true, 'reason' => 'complete'];
				}
				if (!method_exists($ajax, $action)) {
					return ['done' => false, 'error' => 'Unknown stage "' . $action . '" for op ' . $op];
				}
				if (microtime(true) >= $hardStop) {
					return ['done' => false, 'reason' => 'budget_reached'];
				}

				// Sync $_POST from vbJson::$vars so _getJPost('mypos'), etc.
				// return the state values we just restored / just updated.
				\vbJson::_jsonToPost();

				try {
					$ajax->$action();
				} catch (\Throwable $e) {
					return ['done' => false, 'error' => 'Exception in stage "' . $action . '": ' . $e->getMessage()];
				}

				$stagesRun[] = $action;

				// If StageIt's own code recorded an error via vbJson::_error(),
				// halt the loop and return that error.
				$err = \vbJson::_getVar('error');
				if (is_string($err) && $err !== '') {
					return ['done' => false, 'error' => 'StageIt error: ' . $err];
				}
			}
		});

		$elapsed = round(microtime(true) - $startTime, 3);

		$out = [
			'done'       => (bool) ($result['done'] ?? false),
			'action'     => (string) \vbJson::_getVar('action'),
			'progress'   => (int) \vbJson::_getVar('progress'),
			'msg'        => (string) \vbJson::_getVar('msg'),
			'stages_run' => $stagesRun,
			'elapsed'    => $elapsed,
		];
		if (!empty($result['error'])) {
			$out['error'] = $result['error'];
		}
		return $out;
	}
}
