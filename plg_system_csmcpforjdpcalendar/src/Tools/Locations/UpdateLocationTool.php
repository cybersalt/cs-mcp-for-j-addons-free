<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Locations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class UpdateLocationTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'update_dpcalendar_location'; }

	public function getDescription(): string
	{
		return 'Update one DPCalendar location via LocationModel::save. Required: id. '
			. 'Any of: title, alias, country, province, city, zip, street, number, '
			. 'latitude, longitude, url, description, color, state, language.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object', 'required' => ['id'],
			'properties' => [
				'id' => ['type' => 'integer'],
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
		$id = $this->requirePositiveInt($arguments, 'id');
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$data = ['id' => $id];
		foreach (['title', 'alias', 'province', 'city', 'zip', 'street', 'number', 'url', 'description', 'color', 'language'] as $k) {
			if (array_key_exists($k, $arguments)) $data[$k] = (string) $arguments[$k];
		}
		foreach (['country', 'state'] as $k) if (array_key_exists($k, $arguments)) $data[$k] = (int) $arguments[$k];
		foreach (['latitude', 'longitude'] as $k) if (array_key_exists($k, $arguments)) $data[$k] = (float) $arguments[$k];
		if (count($data) === 1) return ToolResult::error('Nothing to update — supply at least one field besides id.');

		$model = $this->getModel('com_dpcalendar', 'Location');
		$out = $this->saveAdminModel($model, $data);
		if ($out['id'] <= 0) return ToolResult::error('Location update failed: ' . ($out['error'] ?: 'no id returned'));
		return ToolResult::json(['ok' => true, 'id' => $out['id'], 'fields_updated' => array_keys($data), 'save_warnings' => $out['error'] ?: null]);
	}
}
