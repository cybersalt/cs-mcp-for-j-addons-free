<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\Versions;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjreleasemanager\Tools\ReleaseManagerTrait;
use Joomla\CMS\User\User;

final class DeleteReleaseManagerPackageVersionTool extends AbstractTool
{
	use ReleaseManagerTrait;

	public function getName(): string { return 'delete_release_manager_package_version'; }

	public function getDescription(): string
	{
		return 'Delete a cs-release-manager package version by id. Required: id, confirm:true. '
			. 'Destructive — also deletes the zip file from disk. For a non-destructive '
			. '"unpublish" operation use update_release_manager_package_version(id, state: 0).';
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
			return ToolResult::error('confirm:true is required to delete a package version.');
		}

		$id    = $this->requirePositiveInt($arguments, 'id');
		$model = $this->csrmModel('Packageversion');

		try {
			$model->publish($pks = [$id], -2);
		} catch (\Throwable $e) {
			// Some models don't expose publish; the delete() below surfaces real errors.
		}

		$pks = [$id];
		if (!$model->delete($pks)) {
			return ToolResult::error('cs-release-manager rejected the delete: ' . $model->getError());
		}

		return ToolResult::json(['ok' => true, 'id' => $id]);
	}
}
