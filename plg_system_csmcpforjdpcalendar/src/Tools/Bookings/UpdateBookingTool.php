<?php

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
			. 'Any of: state (0=pending, 1=confirmed, 2=cancelled, 3=refunded, 4=denied, '
			. '5=cancelled_by_user), first_name, name, email, telephone, country, '
			. 'province, city, zip, street, number, transaction_id, invoice (0/1), '
			. 'payment_provider. State transitions may trigger DPCalendar\'s '
			. 'notification emails per the plugin\'s configuration.';
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
				'transaction_id'   => ['type' => 'string'],
				'invoice'          => ['type' => 'integer', 'enum' => [0, 1]],
				'payment_provider' => ['type' => 'string'],
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
		foreach (['first_name', 'name', 'email', 'telephone', 'country', 'province',
			'city', 'zip', 'street', 'number', 'transaction_id', 'payment_provider'] as $k) {
			if (array_key_exists($k, $arguments)) $data[$k] = (string) $arguments[$k];
		}
		foreach (['state', 'invoice'] as $k) {
			if (array_key_exists($k, $arguments)) $data[$k] = (int) $arguments[$k];
		}
		if (count($data) === 1) return ToolResult::error('Nothing to update — supply at least one field besides id.');

		$model = $this->getModel('com_dpcalendar', 'Booking');
		$out = $this->saveAdminModel($model, $data);
		if ($out['id'] <= 0) {
			return ToolResult::error('Booking update failed: ' . ($out['error'] ?: 'no id returned'));
		}
		return ToolResult::json([
			'ok' => true, 'id' => $out['id'],
			'fields_updated' => array_keys($data),
			'save_warnings' => $out['error'] ?: null,
		]);
	}
}
