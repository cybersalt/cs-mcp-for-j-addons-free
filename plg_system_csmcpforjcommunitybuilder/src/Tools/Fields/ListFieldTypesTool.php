<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

final class ListFieldTypesTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use FieldSupportTrait;

	public function getName(): string { return 'list_cb_field_types'; }

	public function getDescription(): string
	{
		return 'List the field types this Community Builder install can create, as registered by its published '
			. 'CB plugins at runtime (CB core ships about 35; CB add-ons register more). "unique" types (name, '
			. 'username, primary email, password, connections, online status...) exist once per site and cannot be '
			. 'created. Also says which types update_cb_user_fields can write values for. Read-only.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cbInstalled()) {
			return $this->cbNotInstalledError();
		}

		$this->cbRequireAccess($actor, 'core.manage', 'com_comprofiler.fields');

		$run   = $this->cbRun($actor, fn (): array => $this->cbFieldTypes());
		$types = [];

		foreach ($run['result'] as $type => $info) {
			$types[] = [
				'type'          => $type,
				'label'         => $info['label'],
				'creatable'     => !$info['unique'],
				'value_writable_by_update_cb_user_fields' => isset(self::CB_WRITABLE_TYPES[$type]) && $type !== 'predefined',
				'has_options'   => \in_array($type, self::CB_OPTION_TYPES, true),
				'plugin_id'     => $info['plugin_id'],
			];
		}

		return ToolResult::json(['ok' => true, 'count' => \count($types), 'types' => $types]);
	}
}
