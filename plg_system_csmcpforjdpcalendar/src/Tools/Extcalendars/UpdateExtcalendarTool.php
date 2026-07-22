<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Extcalendars;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class UpdateExtcalendarTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'update_dpcalendar_extcalendar'; }

	public function getDescription(): string
	{
		return 'Update one DPCalendar external calendar via ExtcalendarModel::save. '
			. 'Required: id. Any of: title, alias, plugin, description, color, '
			. 'color_force, state, access, language, params (object — driver settings).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object', 'required' => ['id'],
			'properties' => [
				'id' => ['type' => 'integer'],
				'title' => ['type' => 'string'],
				'alias' => ['type' => 'string'],
				'plugin' => ['type' => 'string'],
				'description' => ['type' => 'string'],
				'color' => ['type' => 'string'],
				'color_force' => ['type' => 'integer', 'enum' => [0, 1]],
				'state' => ['type' => 'integer', 'enum' => [0, 1, 2, -2]],
				'access' => ['type' => 'integer'],
				'language' => ['type' => 'string'],
				'params' => ['type' => 'object'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$data = ['id' => $id];
		foreach (['title', 'alias', 'plugin', 'description', 'color', 'language'] as $k) {
			if (array_key_exists($k, $arguments)) $data[$k] = (string) $arguments[$k];
		}
		foreach (['color_force', 'state', 'access'] as $k) {
			if (array_key_exists($k, $arguments)) $data[$k] = (int) $arguments[$k];
		}
		if (isset($arguments['params']) && is_array($arguments['params'])) $data['params'] = $arguments['params'];
		if (count($data) === 1) return ToolResult::error('Nothing to update.');

		$model = $this->getModel('com_dpcalendar', 'Extcalendar');
		$out = $this->saveAdminModel($model, $data);
		if ($out['id'] <= 0) return ToolResult::error('External calendar update failed: ' . ($out['error'] ?: 'no id returned'));
		return ToolResult::json(['ok' => true, 'id' => $out['id'], 'fields_updated' => array_keys($data), 'save_warnings' => $out['error'] ?: null]);
	}
}
