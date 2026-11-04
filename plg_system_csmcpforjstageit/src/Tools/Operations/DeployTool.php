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
 * Start a deploy (live → staging). Copies the live filesystem into
 * <docroot>/stageit/ and clones the DB into stg_<prefix>* tables.
 *
 * The operation is chunked internally by StageIt: buildStgMap →
 * syncStgFiles → buildFileMap → copyFiles → cloneData → finaliseStage.
 * Small sites finish in one call; larger sites return done=false with a
 * resume_token — call continue_stageit_deploy with the same token until
 * done=true.
 *
 * Overwrite policy is controlled by StageIt's `over` setting in params.php
 * (already configured in the admin UI).
 */
final class DeployTool extends AbstractTool
{
	use StageItBootTrait;
	use OperationRunTrait;

	public function getName(): string { return 'deploy_stageit'; }

	public function getDescription(): string
	{
		return 'Build a StageIt staging copy (live → <docroot>/stageit/ plus stg_ database tables), running StageIt\'s own build steps exactly as its admin Build button does. '
			. 'Refuses to build over a staging copy that has not been pushed since it was built (that would throw its work away) and when a previous StageIt run did not finish, unless force is true. '
			. 'Large sites take several calls: while done is false, call continue_stageit_deploy with the resume_token. Needs StageIt 6.4.0+ (Joomla 6) or 5.8.0+ (Joomla 5).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'time_budget' => ['type' => 'integer', 'description' => 'Seconds this call may keep starting new steps before it returns a resume_token. Default: min(25, max_execution_time - 10).'],
				'force' => ['type' => 'boolean', 'description' => 'Rebuild even though the existing staging copy has not been pushed since it was built, or a previous run did not finish. Changes on the old stage are lost.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		return $this->runOperation('Deploy', 'start', $arguments, $actor);
	}
}
