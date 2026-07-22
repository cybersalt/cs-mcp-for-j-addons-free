<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Coupons;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class GetCouponTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'get_dpcalendar_coupon'; }

	public function getDescription(): string
	{
		return 'Get one DPCalendar coupon with its usage count. Required: id.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object', 'required' => ['id'],
			'properties' => ['id' => ['type' => 'integer']],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$p = $this->db->getPrefix();
		$c = $this->db->setQuery(
			$this->db->getQuery(true)->select('*')->from($this->db->quoteName($p . 'dpcalendar_coupons'))->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();
		if (!$c) return ToolResult::error('Coupon ' . $id . ' not found.');

		foreach (['id', 'value', 'area', 'limit', 'state', 'checked_out', 'access', 'ordering', 'created_by', 'modified_by'] as $k) {
			if (array_key_exists($k, $c)) $c[$k] = $c[$k] === null ? null : (int) $c[$k];
		}
		$c['state_label'] = $this->contentStateLabel((int) $c['state']);

		$c['used_count'] = (int) $this->db->setQuery(
			$this->db->getQuery(true)->select('COUNT(*)')
				->from($this->db->quoteName($p . 'dpcalendar_bookings'))
				->where($this->db->quoteName('coupon_id') . ' = ' . $id)
		)->loadResult();

		return ToolResult::json(['ok' => true, 'coupon' => $c]);
	}
}
