<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\Taxrates;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjdpcalendar\Tools\DPCalendarBootTrait;
use Joomla\CMS\User\User;

final class GetTaxrateTool extends AbstractTool
{
	use DPCalendarBootTrait;

	public function getName(): string { return 'get_dpcalendar_taxrate'; }

	public function getDescription(): string
	{
		return 'Get one DPCalendar tax rate. Required: id.';
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

		$row = $this->db->setQuery(
			$this->db->getQuery(true)->select('*')
				->from($this->db->quoteName($this->db->getPrefix() . 'dpcalendar_taxrates'))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();
		if (!$row) return ToolResult::error('Tax rate ' . $id . ' not found.');

		foreach (['id', 'inclusive', 'state', 'checked_out', 'ordering', 'created_by', 'version', 'modified_by'] as $k) {
			if (array_key_exists($k, $row)) $row[$k] = $row[$k] === null ? null : (int) $row[$k];
		}
		if (array_key_exists('rate', $row) && $row['rate'] !== null) $row['rate'] = (float) $row['rate'];
		$row['state_label'] = $this->contentStateLabel((int) $row['state']);
		return ToolResult::json(['ok' => true, 'taxrate' => $row]);
	}
}
