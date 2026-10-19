<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Locations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

final class UpdateLocationTool extends AbstractTool
{
	use FlexbannersBootTrait;
	use LocationSupportTrait;

	public function getName(): string { return 'update_flexbanners_location'; }

	public function getDescription(): string
	{
		return 'Rename a FlexBanners location or change its published state. Neither affects the front end — '
			. 'mod_flexbanners matches banners by location id only and never reads the location row — but '
			. 'unpublished locations disappear from the admin\'s location pickers.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'id'        => ['type' => 'integer'],
				'name'      => ['type' => 'string'],
				'published' => ['type' => 'boolean'],
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

		$this->fbRequireAccess($actor, 'core.edit');

		$id     = $this->requirePositiveInt($arguments, 'id');
		$before = $this->fbRow('locations', 'locationid', $id);

		if ($before === null) {
			throw new \InvalidArgumentException('No location with id ' . $id . '.');
		}

		$set = [];

		if (\array_key_exists('name', $arguments)) {
			$name = $this->locationRequireName($arguments);
			$this->locationRequireUniqueName($name, $id);
			$set[] = 'name = ' . $this->db->quote($name);
		}

		if (\array_key_exists('published', $arguments)) {
			if (!$actor->authorise('core.edit.state', 'com_flexbanners')) {
				throw new \RuntimeException('Changing published requires core.edit.state on com_flexbanners.');
			}

			$set[] = 'state = ' . (!empty($arguments['published']) ? 1 : 0);
		}

		if ($set === []) {
			throw new \InvalidArgumentException('Nothing to change: pass name and/or published.');
		}

		$this->db->setQuery(
			'UPDATE ' . $this->db->quoteName($this->fbTable('locations')) . ' SET ' . implode(', ', $set) . ' WHERE locationid = ' . $id
		)->execute();

		$after = $this->fbRow('locations', 'locationid', $id);

		return ToolResult::json([
			'ok'     => true,
			'id'     => $id,
			'before' => ['name' => $before['name'], 'state' => $this->fbStateLabel((int) $before['state'])],
			'after'  => ['name' => $after['name'], 'state' => $this->fbStateLabel((int) $after['state'])],
		]);
	}
}
