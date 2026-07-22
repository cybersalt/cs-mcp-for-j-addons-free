<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Coupons;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class CreateCouponTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'create_dpcalendar_coupon'; }

	public function getDescription(): string
	{
		return 'Create a DPCalendar coupon via CouponModel::save. Required: title, code '
			. '(unique), value (integer amount or percent). Optional: type (percentage '
			. 'default / value), area (scope int — 1=events by default), limit (max uses; '
			. 'omit = unlimited), state (default 1), access, publish_up, publish_down. '
			. 'Returns the new id.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object', 'required' => ['title', 'code', 'value'],
			'properties' => [
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
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$data = [
			'id' => 0,
			'title' => $this->requireString($arguments, 'title'),
			'code' => $this->requireString($arguments, 'code'),
			'value' => (int) ($arguments['value'] ?? 0),
			'type' => (string) ($arguments['type'] ?? 'percentage'),
			'state' => (int) ($arguments['state'] ?? 1),
			'access' => (int) ($arguments['access'] ?? 1),
		];
		foreach (['area', 'limit'] as $k) if (array_key_exists($k, $arguments)) $data[$k] = (int) $arguments[$k];
		foreach (['publish_up', 'publish_down'] as $k) if (array_key_exists($k, $arguments)) $data[$k] = (string) $arguments[$k];

		$model = $this->getModel('com_dpcalendar', 'Coupon');
		$out = $this->saveAdminModel($model, $data);
		if ($out['id'] <= 0) return ToolResult::error('Coupon create failed: ' . ($out['error'] ?: 'no id returned'));
		return ToolResult::json(['ok' => true, 'id' => $out['id'], 'save_warnings' => $out['error'] ?: null]);
	}
}
