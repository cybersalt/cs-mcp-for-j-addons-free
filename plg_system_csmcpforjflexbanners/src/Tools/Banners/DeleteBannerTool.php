<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Banners;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

final class DeleteBannerTool extends AbstractTool
{
	use FlexbannersBootTrait;

	public function getName(): string { return 'delete_flexbanner'; }

	public function getDescription(): string
	{
		return 'Permanently delete a FlexBanners banner, its restriction rows and (unless keep_link) its click-through '
			. 'link row when no other banner uses it. Like the FlexBanners admin (FlexbannerModel::canDelete), the '
			. 'banner must be TRASHED first — use set_flexbanner_state {"state": "trashed"}. Its impression and click '
			. 'history goes with it; FlexBanners keeps no separate log.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'id'        => ['type' => 'integer', 'description' => 'Banner id.'],
				'keep_link' => ['type' => 'boolean', 'description' => 'Keep the link row even if nothing else uses it.'],
			],
			'required'             => ['id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->fbInstalled()) {
			return $this->fbNotInstalledError();
		}

		$this->fbRequireAccess($actor, 'core.delete');

		$id  = $this->requirePositiveInt($arguments, 'id');
		$row = $this->fbBanner($id);

		if ($row === null) {
			throw new \InvalidArgumentException('No banner with id ' . $id . '.');
		}

		if ((int) $row['state'] !== -2) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Banner ' . $id . ' is ' . $this->fbStateLabel((int) $row['state']) . '. FlexBanners only deletes trashed banners; trash it first with set_flexbanner_state.',
			], true);
		}

		$db     = $this->db;
		$linkid = (int) $row['linkid'];

		$db->transactionStart();

		try {
			$restrictions = $this->fbCount('in', 'bannerid = ' . $id);
			$db->setQuery('DELETE FROM ' . $db->quoteName($this->fbTable('in')) . ' WHERE bannerid = ' . $id)->execute();
			$db->setQuery('DELETE FROM ' . $db->quoteName($this->fbTable('')) . ' WHERE id = ' . $id)->execute();

			$linkDeleted = false;

			if ($linkid > 0 && empty($arguments['keep_link']) && $this->fbCount('', 'linkid = ' . $linkid) === 0) {
				$db->setQuery('DELETE FROM ' . $db->quoteName($this->fbTable('link')) . ' WHERE linkid = ' . $linkid)->execute();
				$linkDeleted = true;
			}

			$db->transactionCommit();
		} catch (\Throwable $e) {
			$db->transactionRollback();

			throw $e;
		}

		return ToolResult::json([
			'ok'                   => $this->fbBanner($id) === null,
			'deleted'              => $id,
			'name'                 => (string) $row['name'],
			'final_impressions'    => (int) $row['impmade'],
			'final_clicks'         => (int) $row['clicks'],
			'restrictions_removed' => $restrictions,
			'link_deleted'         => $linkDeleted ? $linkid : null,
		]);
	}
}
