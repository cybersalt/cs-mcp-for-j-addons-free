<?php
/**
 * @package     MCP for StageIt
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjstageit\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Loading StageIt and asking it about the site.
 *
 * StageIt (com_stageit) is not a table-driven component: build, push, remove
 * and restore are chains of steps that its admin page runs one AJAX request at
 * a time (stageit.js posts each step's jdata back for the next). StageIt 6.4.0
 * (Joomla 6) and 5.8.0 (Joomla 5) added a supported way to run those steps
 * in-process: with vbJson::$cli on, a step throws vbCliStep carrying its result
 * instead of echoing JSON and dying. Its own console plugin
 * (plugins/console/stageit, StageItCli::runSteps()) drives the chain that way,
 * and so does this add-on, a time budget's worth of steps per MCP call
 * (see OperationRunTrait).
 *
 * The read side (stage present, last push, backups, leftovers of an unfinished
 * run) comes from StageItCli itself, so `get_stageit_status` says exactly what
 * `php cli/joomla.php stageit:status --json` says. The push and remove checks
 * below are the console commands' own (AbstractStageItCommand::pushChecks() /
 * removeChecks()), which live on a Symfony command and so can't be called
 * from here.
 */
trait StageItBootTrait
{
	/** StageIt's admin component folder, or null if StageIt isn't installed. */
	protected function stageitAdminBase(): ?string
	{
		$path = JPATH_ADMINISTRATOR . '/components/com_stageit';

		return is_file($path . '/classes/stageIt.class.php') ? $path : null;
	}

	protected function notInstalledError(): ToolResult
	{
		return ToolResult::error('StageIt (com_stageit) is not installed on this site, or the install is incomplete.');
	}

	/**
	 * StageIt's own permission, on top of MCP for J's read/write one: the AI
	 * must never do more than the account could do in StageIt itself.
	 *
	 * Every tool needs core.manage on com_stageit, the gate on StageIt's admin
	 * entry point (stageit.php:12). Build, push, restore and remove also need
	 * core.admin: they replace the whole site or the stage (a restore rolls
	 * #__users back too), and an MCP call has no CSRF token or person clicking
	 * behind it, so they get the permission that already means "can change
	 * anything".
	 *
	 * @return ToolResult|null The refusal, or null when the account may go ahead.
	 */
	protected function stageitRefusal(?User $actor, bool $changesSite): ?ToolResult
	{
		if ($actor === null || $actor->guest) {
			return ToolResult::error('Refused: this tool needs a logged-in Joomla account.');
		}

		if (!$actor->authorise('core.manage', 'com_stageit') && !$actor->authorise('core.admin', 'com_stageit')) {
			return ToolResult::error('Refused: the Joomla account this AI app acts for does not have StageIt\'s "Access Administration Interface" '
				. 'permission (core.manage on com_stageit), which StageIt itself needs before it shows anything.');
		}

		if ($changesSite && !$actor->authorise('core.admin', 'com_stageit')) {
			return ToolResult::error('Refused: building, pushing, restoring and removing replace the whole site or the stage, so through MCP they need '
				. 'StageIt\'s "Configure ACL & Options" permission (core.admin on com_stageit), which Super Users have.');
		}

		return null;
	}

	/**
	 * Load StageIt the way its console plugin does (StageItCli::__construct()):
	 * stageIt.class.php, then framework/init.php, which registers the vb*
	 * autoloader. Idempotent.
	 */
	protected function ensureStageItLoaded(): bool
	{
		$base = $this->stageitAdminBase();

		if ($base === null) {
			return false;
		}

		require_once $base . '/classes/stageIt.class.php';

		if (is_file($base . '/framework/init.php')) {
			require_once $base . '/framework/init.php';
		} else {
			// Before init.php existed the framework classes were included one by one.
			foreach (['vbAjax', 'vbAssist', 'vbArchive', 'vbDb', 'vbFiles', 'vbJson', 'vbLog', 'vbParams'] as $cls) {
				if (is_file($base . '/framework/' . $cls . '.class.php')) {
					require_once $base . '/framework/' . $cls . '.class.php';
				}
			}
		}

		return class_exists('vbJson') && class_exists('stageIt');
	}

	/**
	 * Run $fn from live's administrator/ folder, the working folder of
	 * StageIt's admin page, whose relative paths ("../" = site root) assume it.
	 * (1.0.2 used administrator/components/com_stageit/, which sent every
	 * "../" one level too deep.)
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	protected function withStageItCwd(callable $fn)
	{
		$original = getcwd();

		if (!chdir(JPATH_ADMINISTRATOR)) {
			throw new \RuntimeException('Could not change to ' . JPATH_ADMINISTRATOR);
		}

		try {
			\stageIt::_initParams();

			return $fn();
		} finally {
			if ($original !== false) {
				@chdir($original);
			}
		}
	}

	/**
	 * The outcome of the last build/push/remove/restore started through MCP.
	 * The last part of a push or restore runs in one long call, and a client
	 * that gave up waiting never sees its result, so it is kept here for
	 * get_stageit_status. Written after the run, so a restore of
	 * administrator/logs/ can't roll it back.
	 */
	protected function lastOpFile(): string
	{
		return JPATH_ADMINISTRATOR . '/logs/stageit_mcp_last.php';
	}

	protected function saveLastOp(array $result): void
	{
		@file_put_contents($this->lastOpFile(), "<?php die('Forbidden.'); ?>\n" . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG));
	}

	protected function loadLastOp(): ?array
	{
		if (!is_file($this->lastOpFile())) {
			return null;
		}

		$lines = explode("\n", (string) file_get_contents($this->lastOpFile()), 2);
		$data  = json_decode($lines[1] ?? '', true);

		return \is_array($data) ? $data : null;
	}

	/** The installed StageIt version, from its component manifest. */
	protected function stageitVersion(): ?string
	{
		$manifest = (string) $this->db->setQuery(
			'SELECT manifest_cache FROM ' . $this->db->quoteName('#__extensions')
			. " WHERE type = 'component' AND element = 'com_stageit'"
		)->loadResult();

		$decoded = json_decode($manifest, true);

		return \is_array($decoded) && !empty($decoded['version']) ? (string) $decoded['version'] : null;
	}

	/**
	 * Does this StageIt run its steps in-process (6.4.0+ on Joomla 6, 5.8.0+ on
	 * Joomla 5)? Older versions can only be driven by their admin page.
	 */
	protected function stageitHasCliMode(): bool
	{
		return class_exists('vbJson') && property_exists('vbJson', 'cli') && method_exists('vbJson', '_cliBeginStep') && class_exists('vbCliStep');
	}

	protected function cliModeMissingError(): ToolResult
	{
		return ToolResult::json([
			'ok'      => false,
			'error'   => 'This StageIt (' . ($this->stageitVersion() ?? 'unknown version') . ') is too old to be driven from MCP. '
				. 'Update StageIt to 6.4.0 or later on Joomla 6 (5.8.0 or later on Joomla 5), which can run its steps in-process. '
				. 'Older versions can only build, push and remove from their own admin page.',
		], true);
	}

	/**
	 * StageIt's console helper (plugins/console/stageit/src/StageItCli.php),
	 * loaded from its file so it works whether or not that plugin is enabled.
	 * Null when the file is missing (StageIt older than 6.4.0 / 5.8.0, or the
	 * plugin was uninstalled).
	 */
	protected function stageitCli(): ?object
	{
		$dir = JPATH_PLUGINS . '/console/stageit/src';

		if (!class_exists('StageIt\\Plugin\\Console\\StageIt\\StageItCli', false)) {
			if (!is_file($dir . '/StageItCli.php') || !is_file($dir . '/StepFailedException.php')) {
				return null;
			}

			require_once $dir . '/StepFailedException.php';
			require_once $dir . '/StageItCli.php';
		}

		$class = 'StageIt\\Plugin\\Console\\StageIt\\StageItCli';

		return new $class();
	}

	protected function cliHelperMissingError(): ToolResult
	{
		return ToolResult::json([
			'ok'    => false,
			'error' => 'StageIt\'s command-line plugin (plugins/console/stageit) is missing from this site. It comes with '
				. 'StageIt 6.4.0+ (Joomla 6) and 5.8.0+ (Joomla 5); reinstall the StageIt package to restore it.',
		], true);
	}

	/**
	 * Why a push must not start, and what to warn about: the checks of
	 * `stageit:push` (AbstractStageItCommand::pushChecks()).
	 *
	 * @return array{0: string[], 1: string[]}
	 */
	protected function stageitPushChecks(object $cli, bool $force, bool $noBackup): array
	{
		$errors   = [];
		$warnings = [];

		if ($cli->isInsideStage()) {
			return [['This is the staging copy. Run StageIt from the live site.'], []];
		}

		if (!$cli->stageExists()) {
			return [['No staging copy found at ' . $cli->stageRoot() . '/.'], []];
		}

		if (!$cli->stageComplete()) {
			$errors[] = 'The staging copy has no configuration.php, so it was not built completely. Rebuild it.';
		} else {
			$expected = \stageIt::_getStgPrefix() . $cli->livePrefix();
			$actual   = (string) $cli->stageConfigValue('dbprefix');

			if ($actual !== $expected) {
				$errors[] = 'The staging copy uses database prefix "' . $actual . '", expected "' . $expected . '".';
			}
		}

		if (!$cli->stgTables()) {
			$errors[] = 'No ' . \stageIt::_getStgPrefix() . ' tables found in the database: the staging copy has no data to push.';
		}

		foreach ($cli->systemChecks() as $check) {
			if (!$check['ok']) {
				$errors[] = $check['label'] . ': ' . $check['detail'];
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

		if ($cli->stageBackupDisabled()) {
			if ($noBackup) {
				$warnings[] = 'Backups are switched off in the staging copy\'s StageIt options, and no_backup was given: live will NOT be backed up first.';
			} else {
				$errors[] = 'Backups are switched off in the staging copy\'s StageIt options, so live would not be backed up first. Turn backups on, or pass no_backup: true to push without one.';
			}
		}

		if (!$cli->stageHasCli()) {
			$warnings[] = 'The staging copy was built before StageIt\'s command line was installed. The push copies the stage\'s StageIt over live\'s, '
				. 'so live will run that older StageIt afterwards, which these tools cannot drive, until StageIt is reinstalled.';
		}

		return [$errors, $warnings];
	}

	/**
	 * Why removing the stage must not start: the checks of `stageit:remove`
	 * (AbstractStageItCommand::removeChecks()). A stage that has not been
	 * pushed since it was built holds work that would be thrown away.
	 *
	 * @return array{0: string[], 1: string[]}
	 */
	protected function stageitRemoveChecks(object $cli, bool $force): array
	{
		$errors   = [];
		$warnings = [];

		if ($cli->isInsideStage()) {
			return [['This is the staging copy. Run StageIt from the live site.'], []];
		}

		$tables = $cli->stgTables();

		if (!is_dir($cli->stageRoot()) && !$tables) {
			return [['No staging copy found: no ' . $cli->stageRoot() . '/ folder and no ' . \stageIt::_getStgPrefix() . ' tables.'], []];
		}

		if (is_dir($cli->stageRoot()) && !$cli->pushCompletedSinceBuild()) {
			$push = $cli->lastPush();
			$msg  = $push === null
				? 'No completed push to live is recorded for this staging copy.'
				: 'The last completed push (' . date('Y-m-d H:i:s', (int) $push['time']) . ') is older than the staging copy (built '
					. date('Y-m-d H:i:s', (int) $cli->stageBuiltAt()) . ').';

			if ($force) {
				$warnings[] = $msg . ' Removing anyway because force was given: changes made on the stage will be lost.';
			} else {
				$errors[] = $msg . ' Removing it now would throw away the work on the stage. Push first (sync_stageit), or pass force: true to remove it anyway.';
			}
		}

		return [$errors, $warnings];
	}

	/**
	 * Derive the per-call time budget. A step is never cut short: the budget
	 * only decides whether to START another step in this call. Each StageIt
	 * step limits itself to the site's "speed" setting.
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
}
