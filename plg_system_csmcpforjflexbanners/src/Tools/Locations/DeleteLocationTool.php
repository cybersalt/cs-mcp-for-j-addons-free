<?php
/**
 * @package     MCP for FlexBanners
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Locations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

final class DeleteLocationTool extends AbstractTool
{
	use FlexbannersBootTrait;
	use LocationSupportTrait;

	public function getName(): string { return 'delete_flexbanners_location'; }

	public function getDescription(): string
	{
		return 'Delete a FlexBanners location. Refuses while ANY banner (in any state, trashed included) is assigned '
			. 'to it or any mod_flexbanners module (published or not) points at it, and lists them — move the '
			. 'banners with update_flexbanner and repoint or delete the modules first. FlexBanners itself does not '
			. 'check this, and a deleted location leaves its banners pointing at nothing.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => ['id' => ['type' => 'integer']],
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
		$row = $this->fbRow('locations', 'locationid', $id);

		if ($row === null) {
			throw new \InvalidArgumentException('No location with id ' . $id . '.');
		}

		$usage = $this->locationUsage($id);

		if ($usage['banners'] !== [] || $usage['modules'] !== []) {
			$ids = array_map('intval', $this->db->setQuery(
				'SELECT id FROM ' . $this->db->quoteName($this->fbTable('')) . ' WHERE locationid = ' . $id . ' ORDER BY id'
			)->loadColumn() ?: []);

			return ToolResult::json([
				'ok'         => false,
				'error'      => 'Location ' . $id . ' ("' . $row['name'] . '") is still in use. Nothing was deleted.',
				'banner_ids' => $ids,
				'banners'    => $usage['banners'],
				'modules'    => $usage['modules'],
			], true);
		}

		$this->db->setQuery('DELETE FROM ' . $this->db->quoteName($this->fbTable('locations')) . ' WHERE locationid = ' . $id)->execute();

		return ToolResult::json([
			'ok'      => $this->fbRow('locations', 'locationid', $id) === null,
			'deleted' => $id,
			'name'    => (string) $row['name'],
		]);
	}
}
