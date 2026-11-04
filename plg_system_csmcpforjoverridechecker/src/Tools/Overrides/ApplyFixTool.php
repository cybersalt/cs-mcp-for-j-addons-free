<?php
/**
 * @package     MCP for Override Checker
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\Overrides;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\BackupsHelper;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\OverridesHelper;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\PathResolver;
use Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\OverrideCheckerBootTrait;
use Joomla\CMS\User\User;

/**
 * Three checks run before Override Checker's applyFix() is called, all because
 * the caller is an AI working from a copy of the file it read earlier:
 *
 *   1. expected_sha256 must match the file on disk. A fix built from a cut-off
 *      or stale read would otherwise overwrite the lines the AI never saw.
 *   2. A .php file must still parse. A layout with a syntax error is a white
 *      page (or a 500) wherever it renders. token_get_all(TOKEN_PARSE) parses
 *      without running anything.
 *   3. Writing the bytes already there is a no-op, not a new backup and write.
 *
 * The write itself, with its backup first, is OverridesHelper::applyFix().
 */
final class ApplyFixTool extends AbstractTool
{
	use OverrideCheckerBootTrait;

	/** Below this share of the old size, the reply carries a warning. */
	private const SHRINK_WARNING = 0.5;

	public function getName(): string { return 'apply_override_checker_fix'; }

	public function getDescription(): string
	{
		return 'Write a fixed version of one override file, through Override Checker, which first saves the current file '
			. 'as a backup (pre_fix_backup_id, restorable with restore_override_checker_backup). Only for a row the user '
			. 'has named. contents is the COMPLETE new file built from get_override_checker_override_file; pass that '
			. 'read\'s sha256 as expected_sha256 or the write is refused. Make the minimum change, no reformatting. A '
			. '.php file that does not parse is refused. Does not dismiss the row; ask the user, then '
			. 'dismiss_override_checker_override.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'override_id'     => ['type' => 'integer', 'description' => 'Row id from list_override_checker_overrides.'],
				'contents'        => ['type' => 'string', 'description' => 'The complete new file contents.'],
				'expected_sha256' => ['type' => 'string', 'description' => 'The sha256 returned by get_override_checker_override_file for the bytes the fix was built from.'],
				'session_id'      => ['type' => 'integer', 'description' => 'Optional review session (from save_override_checker_session) to link the backup and the action log entry to.'],
			],
			'required'             => ['override_id', 'contents', 'expected_sha256'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($error = $this->ocCheck()) {
			return $error;
		}

		$this->ocRequireAccess($actor, 'write');

		$row      = $this->ocRequireOverride($arguments);
		$contents = $arguments['contents'] ?? null;

		if (!\is_string($contents) || $contents === '') {
			throw new \InvalidArgumentException('contents is required: the complete new file.');
		}

		$expected = strtolower(trim((string) ($arguments['expected_sha256'] ?? '')));

		if (preg_match('/^[0-9a-f]{64}$/', $expected) !== 1) {
			throw new \InvalidArgumentException('expected_sha256 is required: the 64-character sha256 from get_override_checker_override_file.');
		}

		$sessionId = $this->ocSessionId($arguments);
		$path      = PathResolver::overridePath((string) $row['template'], (string) $row['hash_id'], (int) $row['client_id']);

		if ($path === null || !is_file($path)) {
			throw new \RuntimeException('The override file for row #' . $row['id'] . ' is not on disk, so there is nothing to fix.');
		}

		$current     = (string) @file_get_contents($path);
		$currentHash = hash('sha256', $current);

		if (!hash_equals($currentHash, $expected)) {
			return ToolResult::json([
				'ok'             => false,
				'written'        => false,
				'error'          => 'The file on disk is not the one the fix was built from (sha256 differs). It changed '
					. 'since it was read, or the fix was built from an incomplete copy. Nothing was written. Read it '
					. 'again with get_override_checker_override_file and rebuild the fix from the whole file.',
				'path'           => $this->ocRelative($path),
				'current_sha256' => $currentHash,
				'current_size'   => \strlen($current),
			], true);
		}

		$newHash = hash('sha256', $contents);

		if (hash_equals($currentHash, $newHash)) {
			return ToolResult::json([
				'ok'      => true,
				'written' => false,
				'note'    => 'contents is identical to the file on disk. Nothing to write, no backup taken.',
				'path'    => $this->ocRelative($path),
				'sha256'  => $currentHash,
			]);
		}

		if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
			try {
				token_get_all($contents, TOKEN_PARSE);
			} catch (\ParseError $e) {
				return ToolResult::json([
					'ok'      => false,
					'written' => false,
					'error'   => 'The new contents are not valid PHP: ' . $e->getMessage() . ' on line ' . $e->getLine()
						. '. Nothing was written. A layout that does not parse breaks every page that uses it.',
					'path'    => $this->ocRelative($path),
				], true);
			}
		}

		// Backup, size cap, allow-list, write, opcache, action log: all Override Checker's.
		$result = OverridesHelper::applyFix((int) $row['id'], $contents, $sessionId);

		clearstatcache(true, $path);
		$writtenHash = hash('sha256', (string) @file_get_contents($path));
		$backup      = BackupsHelper::find((int) $result['pre_fix_backup_id']);

		$warnings = [];

		if (\strlen($contents) < \strlen($current) * self::SHRINK_WARNING) {
			$warnings[] = 'The new file is ' . round(\strlen($contents) / max(1, \strlen($current)) * 100) . '% of the '
				. 'old size. If that was not intended, restore backup #' . $result['pre_fix_backup_id'] . '.';
		}

		$verified = hash_equals($newHash, $writtenHash) && $backup !== null && hash_equals((string) $backup->file_hash, $currentHash);

		if (!$verified) {
			$warnings[] = 'Check failed after the write: the file on disk or the backup row does not match what was '
				. 'expected. Look at the file and at backup #' . $result['pre_fix_backup_id'] . ' before going on.';
		}

		return ToolResult::json([
			'ok'                => $verified,
			'written'           => true,
			'override_id'       => (int) $row['id'],
			'path'              => $result['path'],
			'bytes_written'     => $result['bytes_written'],
			'sha256'            => $writtenHash,
			'pre_fix_backup_id' => $result['pre_fix_backup_id'],
			'session_id'        => $sessionId,
			'verified'          => $verified,
			'warnings'          => $warnings,
			'undo'              => 'restore_override_checker_backup with backup_id ' . $result['pre_fix_backup_id'] . '.',
			'note'              => 'The flagged row is still in the list. Dismiss it with dismiss_override_checker_override '
				. 'only if the user agrees the override is now right.',
		], !$verified);
	}
}
