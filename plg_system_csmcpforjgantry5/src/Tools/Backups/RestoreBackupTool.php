<?php
/**
 * @package     MCP for Gantry 5
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Backups;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjgantry5\Tools\Gantry5BootTrait;
use Joomla\CMS\User\User;

/**
 * Puts a backed-up config file back byte for byte (or deletes the file again
 * when the backup records that it did not exist). The file being replaced is
 * itself backed up first, so a restore can be undone the same way.
 */
final class RestoreBackupTool extends AbstractTool
{
	use Gantry5BootTrait;

	public function getName(): string { return 'restore_gantry5_backup'; }

	public function getDescription(): string
	{
		return 'Undo a Gantry 5 write: put a backed-up config file back exactly as it was (or remove the file again if the change '
			. 'created it). The current file is backed up first, so the restore itself can be undone. Clears Gantry\'s cache, and '
			. 'for styles.yaml also the compiled CSS. Backup ids come from the write tools\' responses or list_gantry5_backups.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'backup'  => ['type' => 'string', 'description' => 'Backup id, e.g. "g5_helium/16/layout.yaml__20261024-101500123456-a1b2c3.bak".'],
				'dry_run' => $this->g5DryRunProp(),
			],
			'required'             => ['backup'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->g5Installed()) {
			return $this->g5NotInstalledError();
		}

		$this->g5RequireAccess($actor, true);

		$backup  = $this->g5ParseBackupId($this->requireString($arguments, 'backup'));
		$theme   = $this->g5RequireTheme(['theme' => $backup['theme']]);
		$outline = $backup['outline'];
		$known   = isset($this->g5Outlines($theme)[$outline]) || is_dir($this->g5CustomConfigDir($theme, $outline));

		if (!$known) {
			throw new \InvalidArgumentException('Outline "' . $outline . '" no longer exists in ' . $theme['element'] . '. Nothing was restored.');
		}

		$root = $this->g5BackupRoot();
		$file = $root . '/' . $backup['id'];

		if (!is_file($file)) {
			throw new \InvalidArgumentException('Backup ' . $backup['id'] . ' does not exist. list_gantry5_backups lists them.');
		}

		$this->g5RequireInside($file, $root);

		$target = $this->g5CustomConfigDir($theme, $outline) . '/' . $backup['rel'];
		$this->g5RequireInside($target, $this->g5CustomPath($theme) . '/config');

		$bytes = $backup['kind'] === 'copy' ? (string) file_get_contents($file) : null;

		if ($bytes !== null) {
			// Never put back something Gantry cannot read.
			$this->g5ParseYaml($bytes, 'backup ' . $backup['id']);
		}

		$result = [
			'ok'      => true,
			'dry_run' => !empty($arguments['dry_run']),
			'backup'  => array_diff_key($backup, ['sort_key' => 1]),
			'target'  => $this->g5Rel($target),
			'action'  => $bytes !== null ? (is_file($target) ? 'replace with the backed-up copy' : 'recreate from the backed-up copy') : (is_file($target) ? 'delete (the file did not exist before the change)' : 'nothing to do: the file does not exist, as before the change'),
		];

		if (!empty($arguments['dry_run'])) {
			return ToolResult::json($result);
		}

		$outlines = $this->g5Outlines($theme);
		$styleFix = isset($outlines[$outline]) ? $this->g5EnsureStyleConfiguration($outlines[$outline]) : null;
		$safety   = null;

		if (is_file($target)) {
			$safety = $this->g5Backup($theme, $outline, $backup['rel'], $target);
		}

		if ($bytes !== null) {
			if (!is_dir(\dirname($target)) && !@mkdir(\dirname($target), 0755, true) && !is_dir(\dirname($target))) {
				throw new \RuntimeException('Cannot create ' . $this->g5Rel(\dirname($target)) . '.');
			}

			$tmp = $target . '.csmcp-' . bin2hex(random_bytes(4)) . '.tmp';

			if (@file_put_contents($tmp, $bytes, LOCK_EX) === false || !@rename($tmp, $target)) {
				@unlink($tmp);

				throw new \RuntimeException('Restoring ' . $this->g5Rel($target) . ' failed. Nothing was changed.');
			}
		} elseif (is_file($target) && !@unlink($target)) {
			throw new \RuntimeException('Deleting ' . $this->g5Rel($target) . ' failed. Nothing was changed.');
		}

		clearstatcache(true, $target);

		$css = [];

		if ($backup['rel'] === 'styles.yaml') {
			$css = $this->g5InvalidateCss($theme, $outline === 'default' ? null : [$outline]);
		}

		return ToolResult::json($result + [
			'restored'             => $bytes !== null ? is_file($target) && file_get_contents($target) === $bytes : !is_file($target),
			'previous_state_backup' => $safety['id'] ?? null,
			'style_fix'            => $styleFix,
			'compiled_css_removed' => $css,
			'cache'                => $this->g5ClearCache($theme),
		]);
	}
}
