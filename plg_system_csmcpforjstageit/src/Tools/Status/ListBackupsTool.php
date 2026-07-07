<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Status;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjstageit\Tools\StageItBootTrait;
use Joomla\CMS\User\User;

/**
 * List the backup snapshots StageIt has taken. Each backup is a folder
 * under <docroot>/stgbackups/ containing a file+DB dump of the live site
 * captured at deploy or sync time. Used as the restore source for the
 * restore_stageit_backup tool.
 */
final class ListBackupsTool extends AbstractTool
{
	use StageItBootTrait;

	public function getName(): string { return 'list_stageit_backups'; }

	public function getDescription(): string
	{
		return 'List StageIt backup snapshots. Each backup is a folder under '
			. '<docroot>/stgbackups/ containing a file + DB dump of the live site '
			. 'captured at deploy or sync time. Returns name, path, size_bytes, '
			. 'modified (ISO 8601 UTC). Use the returned `name` as the backup_name '
			. 'argument to restore_stageit_backup. No input required.';
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
		if ($this->stageitAdminBase() === null) {
			return $this->notInstalledError();
		}

		$backupsFolder = JPATH_ROOT . '/stgbackups/';
		if (!is_dir($backupsFolder)) {
			return ToolResult::json([
				'ok'      => true,
				'count'   => 0,
				'backups' => [],
				'note'    => 'No stgbackups/ folder yet — no backups have been taken.',
			]);
		}

		$backups = [];
		$entries = @scandir($backupsFolder) ?: [];
		foreach ($entries as $e) {
			if ($e === '.' || $e === '..') continue;
			$path = $backupsFolder . $e;
			if (!is_dir($path)) continue;

			$size = $this->folderSize($path);
			$mt   = @filemtime($path);
			$backups[] = [
				'name'     => $e,
				'path'     => $path,
				'size_bytes' => $size,
				'size_readable' => $this->humanSize($size),
				'modified' => $mt ? gmdate('Y-m-d\TH:i:s\Z', $mt) : null,
			];
		}

		usort($backups, static fn($a, $b) => strcmp((string) $b['modified'], (string) $a['modified']));

		return ToolResult::json([
			'ok'      => true,
			'count'   => count($backups),
			'backups' => $backups,
		]);
	}

	/**
	 * Cheap recursive folder-size — sums direct + subdirectory bytes. Only
	 * called for top-level backup folders (dozens, not thousands), so a
	 * naive scandir loop is fine.
	 */
	private function folderSize(string $dir): int
	{
		$total = 0;
		$entries = @scandir($dir) ?: [];
		foreach ($entries as $e) {
			if ($e === '.' || $e === '..') continue;
			$p = $dir . '/' . $e;
			if (is_dir($p)) {
				$total += $this->folderSize($p);
			} elseif (is_file($p)) {
				$total += @filesize($p) ?: 0;
			}
		}
		return $total;
	}

	private function humanSize(int $bytes): string
	{
		if ($bytes < 1024) return $bytes . ' B';
		$units = ['KB', 'MB', 'GB', 'TB'];
		$v = $bytes / 1024;
		foreach ($units as $u) {
			if ($v < 1024) return sprintf('%.1f %s', $v, $u);
			$v /= 1024;
		}
		return sprintf('%.1f PB', $v);
	}
}
