<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Locations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class CreateLocationTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'create_dpcalendar_location'; }

	public function getDescription(): string
	{
		return 'Create a DPCalendar location via LocationModel::save. Required: title. '
			. 'Optional: alias, country (id), province, city, zip, street, number, '
			. 'latitude, longitude, url, description, color, state (default 1), access. '
			. 'Returns the new id.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object', 'required' => ['title'],
			'properties' => [
				'title' => ['type' => 'string'],
				'alias' => ['type' => 'string'],
				'country' => ['type' => 'integer'],
				'province' => ['type' => 'string'],
				'city' => ['type' => 'string'],
				'zip' => ['type' => 'string'],
				'street' => ['type' => 'string'],
				'number' => ['type' => 'string'],
				'latitude' => ['type' => 'number'],
				'longitude' => ['type' => 'number'],
				'url' => ['type' => 'string'],
				'description' => ['type' => 'string'],
				'color' => ['type' => 'string'],
				'state' => ['type' => 'integer', 'enum' => [0, 1, 2, -2]],
				'language' => ['type' => 'string'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$data = ['id' => 0, 'title' => $this->requireString($arguments, 'title'), 'state' => (int) ($arguments['state'] ?? 1)];
		foreach (['alias', 'province', 'city', 'zip', 'street', 'number', 'url', 'description', 'color', 'language'] as $k) {
			if (array_key_exists($k, $arguments)) $data[$k] = (string) $arguments[$k];
		}
		if (array_key_exists('country', $arguments)) $data['country'] = (int) $arguments['country'];
		foreach (['latitude', 'longitude'] as $k) if (array_key_exists($k, $arguments)) $data[$k] = (float) $arguments[$k];

		$model = $this->getModel('com_dpcalendar', 'Location');
		$out = $this->saveAdminModel($model, $data);
		if ($out['id'] <= 0) return ToolResult::error('Location create failed: ' . ($out['error'] ?: 'no id returned'));
		return ToolResult::json(['ok' => true, 'id' => $out['id'], 'save_warnings' => $out['error'] ?: null]);
	}
}
