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

final class ClearLogTool extends AbstractTool
{
	use PageProtectorBootTrait;

	public function getName(): string { return 'clear_page_protector_log'; }

	public function getDescription(): string
	{
		return 'Empty Page Protector\'s whole event log, the Event Log\'s "Clear log" button (a TRUNCATE, so it cannot be '
			. 'undone and ids start again from 1). The log is history only: clearing it unblocks nobody and changes no '
			. 'protection, but the dashboard stats and most-challenged IPs start from zero. Needs cspageprotector.write '
			. 'and confirm: true.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'confirm' => ['type' => 'boolean', 'description' => 'Must be true.'],
			],
			'required'             => ['confirm'],
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

		if (($arguments['confirm'] ?? false) !== true) {
			throw new \InvalidArgumentException('confirm must be true: this deletes every event-log row and cannot be undone. Nothing was deleted.');
		}

		$db    = $this->db;
		$count = static fn (): int => (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName(self::PP_LOG_TABLE))->loadResult();

		$before = $count();

		// The same statement as LogsController::purge().
		$db->setQuery('TRUNCATE TABLE ' . $db->quoteName(self::PP_LOG_TABLE))->execute();

		$after = $count();

		return ToolResult::json([
			'ok'          => $after === 0,
			'rows_before' => $before,
			'rows_after'  => $after,
		]);
	}
}
