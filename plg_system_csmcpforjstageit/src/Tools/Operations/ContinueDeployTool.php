<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Operations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjstageit\Tools\StageItBootTrait;
use Joomla\CMS\User\User;

/**
 * Advance an in-progress deploy by one budget's worth of stages. The agent
 * MUST keep calling this with the same resume_token until done=true.
 */
final class ContinueDeployTool extends AbstractTool
{
	use StageItBootTrait;
	use OperationRunTrait;

	public function getName(): string { return 'continue_stageit_deploy'; }

	public function getDescription(): string
	{
		return 'Advance an in-progress deploy_stageit by one budget\'s worth of stages. '
			. 'Required: resume_token (from the previous deploy_stageit or continue_stageit_deploy '
			. 'response). Optional: time_budget. Returns done=true when the deploy is complete; '
			. 'otherwise returns done=false + the same resume_token — keep calling with the same '
			. 'token until done=true.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['resume_token'],
			'properties' => [
				'resume_token' => ['type' => 'string'],
				'time_budget'  => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		return $this->runOperation('Deploy', 'continue', $arguments);
	}
}
