<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Coupons;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class UpdateCouponTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'update_dpcalendar_coupon'; }

	public function getDescription(): string
	{
		return 'Update one DPCalendar coupon via CouponModel::save. Required: id. '
			. 'Any of: title, code, value, type, area, limit, state, access, '
			. 'publish_up, publish_down.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object', 'required' => ['id'],
			'properties' => [
				'id' => ['type' => 'integer'],
				'title' => ['type' => 'string'],
				'code' => ['type' => 'string'],
				'value' => ['type' => 'integer'],
				'type' => ['type' => 'string', 'enum' => ['percentage', 'value']],
				'area' => ['type' => 'integer'],
				'limit' => ['type' => 'integer'],
				'state' => ['type' => 'integer', 'enum' => [0, 1, 2, -2]],
				'access' => ['type' => 'integer'],
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
		foreach (['title', 'code', 'type', 'publish_up', 'publish_down'] as $k) {
			if (array_key_exists($k, $arguments)) $data[$k] = (string) $arguments[$k];
		}
		foreach (['value', 'area', 'limit', 'state', 'access'] as $k) {
			if (array_key_exists($k, $arguments)) $data[$k] = (int) $arguments[$k];
		}
		if (count($data) === 1) return ToolResult::error('Nothing to update.');

		$model = $this->getModel('com_dpcalendar', 'Coupon');
		$out = $this->saveAdminModel($model, $data);
		if ($out['id'] <= 0) return ToolResult::error('Coupon update failed: ' . ($out['error'] ?: 'no id returned'));
		return ToolResult::json(['ok' => true, 'id' => $out['id'], 'fields_updated' => array_keys($data), 'save_warnings' => $out['error'] ?: null]);
	}
}
