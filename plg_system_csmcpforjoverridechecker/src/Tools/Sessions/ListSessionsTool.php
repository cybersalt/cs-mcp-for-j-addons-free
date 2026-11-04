<?php
/**
 * @package     MCP for Override Checker
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\Sessions;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\SessionsHelper;
use Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\OverrideCheckerBootTrait;
use Joomla\CMS\User\User;

final class ListSessionsTool extends AbstractTool
{
	use OverrideCheckerBootTrait;

	public function getName(): string { return 'list_override_checker_sessions'; }

	public function getDescription(): string
	{
		return 'List Override Checker\'s review sessions (Sessions in its admin), newest first: saved review reports from '
			. 'its automated scans, pasted reports and earlier MCP reviews, with name, summary and source. The report '
			. 'itself: get_override_checker_session.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'order'     => ['type' => 'string', 'enum' => ['created_at', 'id', 'name', 'source'], 'description' => 'Default created_at.'],
				'direction' => ['type' => 'string', 'enum' => ['desc', 'asc'], 'description' => 'Default desc.'],
				'limit'     => ['type' => 'integer', 'description' => '1-200, default 50.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($error = $this->ocCheck()) {
			return $error;
		}

		$this->ocRequireAccess($actor, 'view');

		$rows = SessionsHelper::listRecent(
			$this->ocLimit($arguments, 50, 200),
			$this->ocEnum($arguments, 'order', ['created_at', 'id', 'name', 'source'], 'created_at'),
			$this->ocEnum($arguments, 'direction', ['desc', 'asc'], 'desc')
		);

		$out = array_map(static fn (\stdClass $row): array => [
			'id'         => (int) $row->id,
			'name'       => (string) $row->name,
			'summary'    => (string) $row->summary,
			'source'     => (string) $row->source,
			'state'      => (int) $row->state,
			'created_by' => (int) $row->created_by,
			'created_at' => (string) $row->created_at,
		], $rows);

		return ToolResult::json([
			'ok'       => true,
			'returned' => \count($out),
			'sessions' => $out,
		]);
	}
}
