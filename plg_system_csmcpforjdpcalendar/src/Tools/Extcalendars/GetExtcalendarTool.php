<?php
/**
 * @package     MCP for DPCalendar
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Extcalendars;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class GetExtcalendarTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'get_dpcalendar_extcalendar'; }

	public function getDescription(): string
	{
		return 'Get one DPCalendar external calendar (sync feed) with its full params '
			. '(feed URL, auth credentials, etc. — stored in the params JSON). '
			. 'Passwords, tokens and keys in params are masked unless reveal is true. '
			. 'Required: id.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object', 'required' => ['id'],
			'properties' => [
				'id'     => ['type' => 'integer'],
				'reveal' => ['type' => 'boolean', 'description' => 'Show passwords, tokens and keys in params in full instead of masked. Needs a Super User or DPCalendar\'s "Configure ACL & Options" permission. Default false.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$this->dpRequireAccess($actor);

		$id = $this->requirePositiveInt($arguments, 'id');
		if ($this->dpcalendarAdminBase() === null) return $this->notInstalledError();

		$row = $this->db->setQuery(
			$this->db->getQuery(true)->select('*')
				->from($this->db->quoteName($this->db->getPrefix() . 'dpcalendar_extcalendars'))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();
		if (!$row) return ToolResult::error('External calendar ' . $id . ' not found.');
		if (!$this->dpSeesAllLevels($actor) && !in_array((int) $row['access'], $this->dpViewLevels($actor), true)) {
			return ToolResult::error('External calendar ' . $id . ' not found.');
		}
		// The settings as DPCalendar reads them (its table un-scrambles the stored password).
		$row['params'] = json_encode($this->dpExtcalendarParams($id) ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		foreach (['id', 'asset_id', 'color_force', 'state', 'ordering', 'created_by', 'version',
			'modified_by', 'access', 'access_content'] as $k) {
			if (array_key_exists($k, $row)) $row[$k] = $row[$k] === null ? null : (int) $row[$k];
		}
		$row['state_label'] = $this->contentStateLabel((int) $row['state']);

		// Feed passwords, tokens and keys stay masked unless a Super User asks.
		$reveal = (bool) ($arguments['reveal'] ?? false);
		if ($reveal && !$this->dpCanReveal($actor)) {
			return ToolResult::error(
				'Refused: showing external calendar credentials in full needs DPCalendar\'s "Configure ACL & Options" '
				. 'permission (core.admin), or a Super User. Leave reveal off to get them masked.'
			);
		}
		if (!$reveal && array_key_exists('params', $row)) {
			$row['params'] = $this->dpMaskSecrets($row['params']);
		}

		return ToolResult::json(['ok' => true, 'extcalendar' => $row, 'secrets_revealed' => $reveal]);
	}
}
