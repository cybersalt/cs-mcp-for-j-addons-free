<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Locations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

final class CreateLocationTool extends AbstractTool
{
	use FlexbannersBootTrait;
	use LocationSupportTrait;

	public function getName(): string { return 'create_flexbanners_location'; }

	public function getDescription(): string
	{
		return 'Create a FlexBanners location (a group of banners). On its own a location shows nothing: a '
			. 'mod_flexbanners module must point at it. To add "another row of ads" in one step — location plus a '
			. 'module cloned from an existing one — use create_flexbanners_location_module instead.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'name'      => ['type' => 'string'],
				'published' => ['type' => 'boolean', 'description' => 'Default true. Unpublished locations are hidden from the admin pickers (the front end ignores this).'],
			],
			'required'             => ['name'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->fbInstalled()) {
			return $this->fbNotInstalledError();
		}

		$this->fbRequireAccess($actor, 'core.create');

		$name = $this->locationRequireName($arguments);
		$this->locationRequireUniqueName($name);

		$id  = $this->locationInsert($name, \array_key_exists('published', $arguments) && empty($arguments['published']) ? 0 : 1);
		$row = $this->fbRow('locations', 'locationid', $id);

		return ToolResult::json([
			'ok'        => $row !== null,
			'created'   => true,
			'id'        => $id,
			'name'      => (string) ($row['name'] ?? ''),
			'state'     => $this->fbStateLabel((int) ($row['state'] ?? 0)),
			'next_step' => 'Nothing shows until a mod_flexbanners module has locationid ' . $id . '. Use update_flexbanners_module on an existing module, or create_flexbanners_location_module next time to do both at once.',
		]);
	}
}
