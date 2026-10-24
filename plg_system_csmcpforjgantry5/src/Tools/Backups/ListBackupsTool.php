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

final class ListBackupsTool extends AbstractTool
{
	use Gantry5BootTrait;

	public function getName(): string { return 'list_gantry5_backups'; }

	public function getDescription(): string
	{
		return 'The backups the Gantry 5 write tools took before changing a config file, newest first: backup id, theme, outline, '
			. 'which file (layout.yaml, styles.yaml, particles/<type>.yaml), when (UTC), and whether the file existed before. They '
			. 'live in Joomla\'s tmp folder (csmcpforjgantry5-backups/). restore_gantry5_backup puts one back.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'theme'   => ['type' => 'string', 'description' => 'Optional filter by theme.'],
				'outline' => ['type' => 'string', 'description' => 'Optional filter by outline id.'],
				'limit'   => ['type' => 'integer', 'description' => 'Default 50, max 500.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->g5Installed()) {
			return $this->g5NotInstalledError();
		}

		$this->g5RequireAccess($actor);

		$theme   = trim((string) ($arguments['theme'] ?? ''));
		$outline = trim((string) ($arguments['outline'] ?? ''));
		$limit   = max(1, min(500, (int) ($arguments['limit'] ?? 50)));
		$all     = $this->g5ListBackups($theme !== '' ? $theme : null, $outline !== '' ? $outline : null);

		return ToolResult::json([
			'folder'  => $this->g5Rel($this->g5BackupRoot()),
			'total'   => \count($all),
			'backups' => \array_slice($all, 0, $limit),
		]);
	}
}
