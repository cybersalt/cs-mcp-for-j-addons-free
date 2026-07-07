<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjstageit\Tools\Operations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjstageit\Tools\StageItBootTrait;
use Joomla\CMS\User\User;

/**
 * Advance an in-progress remove-staging operation.
 */
final class ContinueRemoveTool extends AbstractTool
{
	use StageItBootTrait;
	use OperationRunTrait;

	public function getName(): string { return 'continue_stageit_remove'; }

	public function getDescription(): string
	{
		return 'Advance an in-progress remove_stageit by one budget\'s worth of stages. '
			. 'Required: resume_token. Optional: time_budget. Keep calling with the same '
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
		return $this->runOperation('Remove', 'continue', $arguments);
	}
}
