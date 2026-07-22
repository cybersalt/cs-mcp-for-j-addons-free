<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Taxrates;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class CreateTaxrateTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'create_dpcalendar_taxrate'; }

	public function getDescription(): string
	{
		return 'Create a DPCalendar tax rate via TaxrateModel::save. Required: title, '
			. 'rate (decimal — 0.20 = 20%). Optional: inclusive (0=tax added on top of '
			. 'ticket price, 1=tax already baked in), countries (array of country ids '
			. 'this rate applies to; empty = worldwide), state (default 1), publish_up, '
			. 'publish_down. Returns the new id.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object', 'required' => ['title', 'rate'],
			'properties' => [
				'title' => ['type' => 'string'],
				'rate'  => ['type' => 'number'],
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
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$data = [
			'id' => 0,
			'title' => $this->requireString($arguments, 'title'),
			'rate' => (float) ($arguments['rate'] ?? 0),
			'state' => (int) ($arguments['state'] ?? 1),
			'inclusive' => (int) ($arguments['inclusive'] ?? 0),
		];
		foreach (['publish_up', 'publish_down'] as $k) if (array_key_exists($k, $arguments)) $data[$k] = (string) $arguments[$k];
		if (isset($arguments['countries']) && is_array($arguments['countries'])) {
			$data['countries'] = array_map('intval', $arguments['countries']);
		}

		$model = $this->getModel('com_dpcalendar', 'Taxrate');
		$out = $this->saveAdminModel($model, $data);
		if ($out['id'] <= 0) return ToolResult::error('Tax rate create failed: ' . ($out['error'] ?: 'no id returned'));
		return ToolResult::json(['ok' => true, 'id' => $out['id'], 'save_warnings' => $out['error'] ?: null]);
	}
}
