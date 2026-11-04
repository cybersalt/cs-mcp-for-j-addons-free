<?php
/**
 * @package     MCP for Override Checker
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\Overrides;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\OverridesHelper;
use Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\OverrideCheckerBootTrait;
use Joomla\CMS\User\User;

/**
 * One row per call, on purpose. Override Checker removed dismiss-all from its
 * own chat in 2.3.3 after a model read "mark these as checked" (a few rows) as
 * "clear everything" and wiped 70 rows in testing.
 */
final class DismissOverrideTool extends AbstractTool
{
	use OverrideCheckerBootTrait;

	public function getName(): string { return 'dismiss_override_checker_override'; }

	public function getDescription(): string
	{
		return 'Dismiss ONE flagged override row: Override Checker\'s "Dismiss", the same as marking it checked. It '
			. 'deletes the #__template_overrides row, so it leaves Joomla\'s "Changes found" list; the override file '
			. 'itself is not touched. Only for a row id the user has named. If the user says "all of them" or "those", '
			. 'ask for the ids; there is deliberately no dismiss-all.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'override_id' => ['type' => 'integer', 'description' => 'Row id from list_override_checker_overrides.'],
			],
			'required'             => ['override_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($error = $this->ocCheck()) {
			return $error;
		}

		$this->ocRequireAccess($actor, 'write');

		$row       = $this->ocRequireOverride($arguments);
		$dismissed = $this->ocDescribeOverride($row);
		$deleted   = OverridesHelper::dismissOne((int) $row['id']);
		$gone      = $this->ocLoadOverride((int) $row['id']) === null;

		return ToolResult::json([
			'ok'        => $deleted && $gone,
			'dismissed' => $dismissed,
			'note'      => 'The row is gone from the "Changes found" list; the file is unchanged. Joomla adds a new row if '
				. 'a later update changes the core file again.',
		], !($deleted && $gone));
	}
}
