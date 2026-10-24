<?php
/**
 * @package     MCP for Page Protector
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Log;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\PageProtectorBootTrait;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;

final class DeleteEventsTool extends AbstractTool
{
	use PageProtectorBootTrait;

	private const MAX_IDS = 1000;

	public function getName(): string { return 'delete_page_protector_events'; }

	public function getDescription(): string
	{
		return 'Delete Page Protector event-log rows by id (from list_page_protector_events), the Event Log\'s "Delete" '
			. 'button. Up to 1000 ids per call. The log is history only: deleting rows unblocks nobody and changes no '
			. 'protection. Needs cspageprotector.write. Ids that do not exist are reported.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Log row ids.'],
			],
			'required'             => ['ids'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($error = $this->ppCheck()) {
			return $error;
		}

		$this->ppRequireAccess($actor, 'write');

		$ids = $this->ppIdList($arguments, 'ids');

		if (\count($ids) > self::MAX_IDS) {
			throw new \InvalidArgumentException('At most ' . self::MAX_IDS . ' ids per call; got ' . \count($ids) . '. Use clear_page_protector_log to empty the whole log.');
		}

		$db       = $this->db;
		$existing = array_map('intval', $db->setQuery(
			$db->createQuery()
				->select($db->quoteName('id'))
				->from($db->quoteName(self::PP_LOG_TABLE))
				->whereIn($db->quoteName('id'), $ids, ParameterType::INTEGER)
		)->loadColumn() ?: []);

		if ($existing === []) {
			return ToolResult::json(['ok' => true, 'deleted' => 0, 'not_found' => $ids]);
		}

		// The same statement as LogsController::delete().
		$db->setQuery(
			$db->createQuery()
				->delete($db->quoteName(self::PP_LOG_TABLE))
				->whereIn($db->quoteName('id'), $existing, ParameterType::INTEGER)
		)->execute();

		$left = (int) $db->setQuery(
			$db->createQuery()
				->select('COUNT(*)')
				->from($db->quoteName(self::PP_LOG_TABLE))
				->whereIn($db->quoteName('id'), $existing, ParameterType::INTEGER)
		)->loadResult();

		return ToolResult::json([
			'ok'        => $left === 0,
			'deleted'   => \count($existing) - $left,
			'ids'       => $existing,
			'not_found' => array_values(array_diff($ids, $existing)),
		]);
	}
}
