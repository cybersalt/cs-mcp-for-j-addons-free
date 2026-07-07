<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Status;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjstageit\Tools\StageItBootTrait;
use Joomla\CMS\User\User;

/**
 * Run StageIt's own precheck battery: disk space, memory, PHP timeout,
 * file permissions, PHP version, required extensions (json / mbstring /
 * openssl / zip).
 *
 * Same source-of-truth StageIt's admin dashboard uses (stageIt::_prechecks
 * with $showerror=1). If prechecks fail, the deploy tool will fail too —
 * run this before firing a big operation.
 */
final class GetPrechecksTool extends AbstractTool
{
	use StageItBootTrait;

	public function getName(): string { return 'get_stageit_prechecks'; }

	public function getDescription(): string
	{
		return 'Run StageIt\'s system-compat prechecks (disk space / memory / PHP timeout / '
			. 'file permissions / PHP version / required extensions). Returns each check '
			. 'with pass/fail + measured value. Same battery StageIt\'s admin dashboard '
			. 'runs. If any check fails, deploy/sync/remove/restore-backup will fail too — '
			. 'call this before firing a long-runner. No input required.';
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
		if (!$this->ensureStageItLoaded()) {
			return $this->notInstalledError();
		}

		// StageIt::_prechecks returns an HTML string (for the admin dashboard)
		// or the error message if $showerror=1. We want structured output, so
		// call the underlying vbAssist helpers directly — same source of truth,
		// clean values, no HTML wrapping.
		return $this->withStageItCwd(function () {
			$live  = '../';
			$stage = '../' . \stageIt::_getStgFolder();

			$diskFree = (int) \vbAssist::_checkDiskSpace();
			$memoryMb = (int) \vbAssist::_checkMemory();
			$timeout  = (int) \vbAssist::_checkTimeout();
			$permsErr = (string) \vbAssist::_checkFile('LICENSE.txt', $live, $stage);
			$phpVer   = (string) \vbAssist::_checkVersion();
			$configOk = \vbAssist::_checkConfig();

			$missing = [];
			foreach (['json', 'mbstring', 'openssl', 'zip'] as $ext) {
				if (!extension_loaded($ext)) $missing[] = $ext;
			}

			$minMemory  = 256;
			$minTimeout = 60;
			$minPhp     = '8.1.0';

			$checks = [
				'disk_space' => [
					'pass'    => $diskFree > 0,
					'value'   => $diskFree,
					'unit'    => 'bytes',
					'message' => $diskFree > 0
						? 'Available disk space: ' . number_format($diskFree) . ' bytes'
						: 'Cannot determine available disk space or none free.',
				],
				'memory' => [
					'pass'    => $memoryMb >= $minMemory,
					'value'   => $memoryMb,
					'unit'    => 'MB',
					'required' => $minMemory,
					'message' => $memoryMb >= $minMemory
						? $memoryMb . 'MB memory limit — sufficient'
						: $memoryMb . 'MB memory limit — needs at least ' . $minMemory . 'MB',
				],
				'php_timeout' => [
					'pass'    => $timeout >= $minTimeout,
					'value'   => $timeout,
					'unit'    => 'seconds',
					'required' => $minTimeout,
					'message' => $timeout >= $minTimeout
						? $timeout . 's PHP timeout — sufficient'
						: $timeout . 's PHP timeout — needs at least ' . $minTimeout . 's for reliable chunking',
				],
				'file_permissions' => [
					'pass'    => (int) $permsErr >= 1,
					'value'   => $permsErr,
					'message' => (int) $permsErr >= 1
						? 'File permissions OK — StageIt can read + write to the staging area'
						: 'File permissions error: ' . $permsErr,
				],
				'php_version' => [
					'pass'    => version_compare($phpVer, $minPhp, '>='),
					'value'   => $phpVer,
					'required' => $minPhp,
					'message' => version_compare($phpVer, $minPhp, '>=')
						? 'PHP ' . $phpVer . ' — meets minimum ' . $minPhp
						: 'PHP ' . $phpVer . ' — needs at least ' . $minPhp,
				],
				'php_extensions' => [
					'pass'    => $configOk === true && empty($missing),
					'missing' => $missing,
					'message' => empty($missing)
						? 'All required PHP extensions loaded (json, mbstring, openssl, zip)'
						: 'Missing PHP extensions: ' . implode(', ', $missing),
				],
			];

			$allPassed = true;
			foreach ($checks as $c) {
				if (!($c['pass'] ?? false)) {
					$allPassed = false;
					break;
				}
			}

			return ToolResult::json([
				'ok'         => true,
				'all_passed' => $allPassed,
				'checks'     => $checks,
			]);
		});
	}
}
