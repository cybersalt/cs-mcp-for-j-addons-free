<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Packages;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\ReleaseManagerTrait;
use Joomla\CMS\User\User;

final class DeleteReleaseManagerPackageTool extends AbstractTool
{
	use ReleaseManagerTrait;

	public function getName(): string { return 'delete_release_manager_package'; }

	public function getDescription(): string
	{
		return 'Delete a cs-release-manager Package by id. Required: id, confirm:true. '
			. 'Destructive — also drops every linked package_version row AND the on-disk '
			. 'downloads folder for this package. For a non-destructive "hide" operation '
			. 'use update_release_manager_package(id, state: -2) which trashes without unlinking files.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id', 'confirm'],
			'properties' => [
				'id'      => ['type' => 'integer'],
				'confirm' => ['type' => 'boolean', 'enum' => [true]],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($refusal = $this->requireReleaseManager()) {
			return $refusal;
		}

		if (empty($arguments['confirm'])) {
			return ToolResult::error('confirm:true is required to delete a Package.');
		}

		$id    = $this->requirePositiveInt($arguments, 'id');
		$model = $this->csrmModel('Package');

		// Joomla's AdminModel::delete() expects state -2 (trashed) before hard delete.
		// cs-release-manager's PackageModel may not enforce this; publish to trash first
		// as a no-op safety net.
		try {
			$model->publish($pks = [$id], -2);
		} catch (\Throwable $e) {
			// Ignore — some models don't expose publish(); the delete() call below
			// will surface the real error if it actually fails.
		}

		$pks = [$id];
		if (!$model->delete($pks)) {
			return ToolResult::error('cs-release-manager rejected the delete: ' . $model->getError());
		}

		return ToolResult::json(['ok' => true, 'id' => $id]);
	}
}
