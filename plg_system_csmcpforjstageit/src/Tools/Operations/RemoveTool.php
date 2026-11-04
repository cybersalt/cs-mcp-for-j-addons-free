<?php
/**
 * @package     MCP for StageIt
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Operations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjstageit\Tools\StageItBootTrait;
use Joomla\CMS\User\User;

/**
 * Start a remove-staging operation. Deletes the entire <docroot>/stageit/
 * mirror + all stg_<prefix>* DB tables. This is destructive — the staging
 * environment is gone after this completes.
 *
 * Stages: init → rmDb → 0. Usually completes in one call except on very
 * large staging installs.
 */
final class RemoveTool extends AbstractTool
{
	use StageItBootTrait;
	use OperationRunTrait;

	public function getName(): string { return 'remove_stageit'; }

	public function getDescription(): string
	{
		return 'Remove the StageIt staging copy: deletes <docroot>/stageit/ and every stg_ database table, with StageIt\'s own remove steps. Backups in stgbackups/ are kept. '
			. 'Like `php cli/joomla.php stageit:remove`, it refuses while the staging copy has not been pushed to live since it was built, because that would throw away the work on it; force: true removes it anyway. '
			. 'Checks afterwards that the folder and the stg_ tables are really gone. While done is false, call continue_stageit_remove with the resume_token. Needs StageIt 6.4.0+ (Joomla 6) or 5.8.0+ (Joomla 5).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'time_budget' => ['type' => 'integer', 'description' => 'Seconds this call may keep starting new steps before it returns a resume_token. Default: min(25, max_execution_time - 10).'],
				'force' => ['type' => 'boolean', 'description' => 'Remove even though the staging copy has not been pushed since it was built. Changes on the stage are lost.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		return $this->runOperation('Remove', 'start', $arguments, $actor);
	}
}
