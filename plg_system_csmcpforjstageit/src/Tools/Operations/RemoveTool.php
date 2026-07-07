<?php

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
		return 'Start a StageIt remove-staging operation. DESTRUCTIVE: deletes the '
			. 'entire <docroot>/stageit/ mirror folder + all stg_<prefix>* DB tables. '
			. 'The staging environment is gone after this completes. Chunked internally '
			. '(init → rmDb → 0) — usually completes in one call except on very large '
			. 'staging installs; may return done=false + resume_token. Backups '
			. '(stgbackups/) are NOT touched. Optional time_budget.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'time_budget' => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		return $this->runOperation('Remove', 'start', $arguments);
	}
}
