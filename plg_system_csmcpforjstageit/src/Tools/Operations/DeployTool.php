<?php

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
		return 'Start a StageIt deploy (live → staging). Copies the live filesystem into '
			. '<docroot>/stageit/ and clones the DB into stg_<prefix>* tables, running '
			. 'the same 6-stage flow as StageIt\'s admin UI (buildStgMap → syncStgFiles → '
			. 'buildFileMap → copyFiles → cloneData → finaliseStage). Small sites complete '
			. 'in one call (done=true). Larger sites return done=false + resume_token — '
			. 'call continue_stageit_deploy({resume_token}) until done=true. Optional '
			. 'time_budget arg (seconds; defaults to a safe value derived from '
			. 'ini max_execution_time). Existing staging is preserved on first call and '
			. 'then updated to match live per your overwrite policy.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'time_budget' => [
					'type' => 'integer',
					'description' => 'Max seconds this call may run before returning a resume_token. Default: min(25, ini_max_execution_time - 10). Cap: max_execution_time - 10.',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		return $this->runOperation('Deploy', 'start', $arguments);
	}
}
