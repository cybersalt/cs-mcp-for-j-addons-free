<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Extcalendars;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class CreateExtcalendarTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'create_dpcalendar_extcalendar'; }

	public function getDescription(): string
	{
		return 'Create a DPCalendar external calendar (Google/iCal/etc. feed) via '
			. 'ExtcalendarModel::save. Required: title, plugin (feed-driver identifier — '
			. 'the plg_dpcalendar_* short name, e.g. "ical", "googlecalendar"). Optional: '
			. 'alias, description, color, color_force (0/1), state (default 1), access, '
			. 'language, params (associative array — driver-specific settings like URL, '
			. 'auth credentials). Returns the new id.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object', 'required' => ['title', 'plugin'],
			'properties' => [
				'title' => ['type' => 'string'],
				'plugin' => ['type' => 'string'],
				'alias' => ['type' => 'string'],
				'description' => ['type' => 'string'],
				'color' => ['type' => 'string'],
				'color_force' => ['type' => 'integer', 'enum' => [0, 1]],
				'state' => ['type' => 'integer', 'enum' => [0, 1, 2, -2]],
				'access' => ['type' => 'integer'],
				'language' => ['type' => 'string'],
				'params' => ['type' => 'object', 'description' => 'Driver-specific settings.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$data = [
			'id' => 0,
			'title' => $this->requireString($arguments, 'title'),
			'plugin' => $this->requireString($arguments, 'plugin'),
			'state' => (int) ($arguments['state'] ?? 1),
			'access' => (int) ($arguments['access'] ?? 1),
			'color_force' => (int) ($arguments['color_force'] ?? 0),
		];
		foreach (['alias', 'description', 'color', 'language'] as $k) {
			if (array_key_exists($k, $arguments)) $data[$k] = (string) $arguments[$k];
		}
		if (isset($arguments['params']) && is_array($arguments['params'])) {
			$data['params'] = $arguments['params'];
		}

		$model = $this->getModel('com_dpcalendar', 'Extcalendar');
		$out = $this->saveAdminModel($model, $data);
		if ($out['id'] <= 0) return ToolResult::error('External calendar create failed: ' . ($out['error'] ?: 'no id returned'));
		return ToolResult::json(['ok' => true, 'id' => $out['id'], 'save_warnings' => $out['error'] ?: null]);
	}
}
