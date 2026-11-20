<?php
/**
 * @package     MCP for DPCalendar
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Bookings;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

/**
 * Update a booking through DPCalendar's BookingModel::save so notification
 * emails fire on state transitions (booking confirmed, cancelled, etc.).
 * Only the fields you actually supply are changed.
 */
final class UpdateBookingTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'update_dpcalendar_booking'; }

	public function getDescription(): string
	{
		return 'Update one DPCalendar booking via BookingModel::save. Required: id. '
			. 'Any of: state (DPCalendar\'s codes: 0 inactive, 1 active, 2 tickets reviewed, 3 confirmation, '
			. '4 on hold, 5 invited, 6 cancelled, 7 refunded, 8 waiting, 10 partially paid; to cancel a '
			. 'booking use 6), first_name, name, email, telephone, country, '
			. 'province, city, zip, street, number, invoice (0/1), payment_provider. The coupon stays as it is. '
			. 'State transitions may trigger DPCalendar\'s notification emails per the plugin\'s configuration.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'               => ['type' => 'integer'],
				'state'            => ['type' => 'integer'],
				'first_name'       => ['type' => 'string'],
				'name'             => ['type' => 'string'],
				'email'            => ['type' => 'string'],
				'telephone'        => ['type' => 'string'],
				'country'          => ['type' => 'string'],
				'province'         => ['type' => 'string'],
				'city'             => ['type' => 'string'],
				'zip'              => ['type' => 'string'],
				'street'           => ['type' => 'string'],
				'number'           => ['type' => 'string'],
				'invoice'          => ['type' => 'integer', 'enum' => [0, 1]],
				'payment_provider' => ['type' => 'string'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		// DPCalendar's admin save needs Edit, and Admin Booking for state and price fields.
		$this->dpRequireAccess($actor, 'dpcalendar.admin.book');
		$this->dpRequireAccess($actor, 'core.edit');

		$id = $this->requirePositiveInt($arguments, 'id');
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$data = ['id' => $id];
		foreach (['first_name', 'name', 'email', 'telephone', 'country', 'province',
			'city', 'zip', 'street', 'number', 'payment_provider'] as $k) {
			if (array_key_exists($k, $arguments)) $data[$k] = (string) $arguments[$k];
		}
		foreach (['state', 'invoice'] as $k) {
			if (array_key_exists($k, $arguments)) $data[$k] = (int) $arguments[$k];
		}
		if (count($data) === 1) return ToolResult::error('Nothing to update — supply at least one field besides id.');

		$data = $this->dpClean($data, []);
		if ($data instanceof ToolResult) return $data;

		// DPCalendar clears the coupon when coupon_id is missing from a save (its admin form always
		// posts it), so send the stored one back.
		$stored = $this->db->setQuery(
			$this->db->getQuery(true)->select($this->db->quoteName(['id', 'coupon_id']))
				->from($this->db->quoteName($this->db->getPrefix() . 'dpcalendar_bookings'))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();
		if (!$stored) return ToolResult::error('Booking ' . $id . ' not found.');
		$data['coupon_id'] = (int) ($stored['coupon_id'] ?? 0);

		$model = $this->getModel('com_dpcalendar', 'Booking');
		$out = $this->dpWithSiteRoutes(fn () => $this->saveAdminModel($model, $data));
		$error = $this->dpModelError($model, $actor);
		if ($out['id'] <= 0) {
			return ToolResult::error('Booking update failed: ' . ($error ?: 'no id returned'));
		}
		return ToolResult::json([
			'ok' => true, 'id' => $out['id'],
			'fields_updated' => array_values(array_diff(array_keys($data), ['coupon_id'])),
			'save_warnings' => $error ?: null,
		]);
	}
}
