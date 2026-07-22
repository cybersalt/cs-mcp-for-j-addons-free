<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Taxrates;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class UpdateTaxrateTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'update_dpcalendar_taxrate'; }

	public function getDescription(): string
	{
		return 'Update one DPCalendar tax rate via TaxrateModel::save. Required: id. '
			. 'Any of: title, rate, inclusive, countries (array of country ids), '
			. 'state, publish_up, publish_down.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object', 'required' => ['id'],
			'properties' => [
				'id' => ['type' => 'integer'],
				'title' => ['type' => 'string'],
				'rate' => ['type' => 'number'],
				'inclusive' => ['type' => 'integer', 'enum' => [0, 1]],
				'countries' => ['type' => 'array', 'items' => ['type' => 'integer']],
				'state' => ['type' => 'integer', 'enum' => [0, 1, 2, -2]],
				'publish_up' => ['type' => 'string'],
				'publish_down' => ['type' => 'string'],
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
		if (array_key_exists('title', $arguments)) $data['title'] = (string) $arguments['title'];
		if (array_key_exists('rate', $arguments))  $data['rate']  = (float) $arguments['rate'];
		foreach (['inclusive', 'state'] as $k) if (array_key_exists($k, $arguments)) $data[$k] = (int) $arguments[$k];
		foreach (['publish_up', 'publish_down'] as $k) if (array_key_exists($k, $arguments)) $data[$k] = (string) $arguments[$k];
		if (isset($arguments['countries']) && is_array($arguments['countries'])) $data['countries'] = array_map('intval', $arguments['countries']);
		if (count($data) === 1) return ToolResult::error('Nothing to update.');

		$model = $this->getModel('com_dpcalendar', 'Taxrate');
		$out = $this->saveAdminModel($model, $data);
		if ($out['id'] <= 0) return ToolResult::error('Tax rate update failed: ' . ($out['error'] ?: 'no id returned'));
		return ToolResult::json(['ok' => true, 'id' => $out['id'], 'fields_updated' => array_keys($data), 'save_warnings' => $out['error'] ?: null]);
	}
}
