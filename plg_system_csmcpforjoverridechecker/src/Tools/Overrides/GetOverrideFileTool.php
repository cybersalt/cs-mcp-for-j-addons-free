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
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\PathResolver;
use Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\OverrideCheckerBootTrait;
use Joomla\CMS\User\User;

final class GetOverrideFileTool extends AbstractTool
{
	use OverrideCheckerBootTrait;

	public function getName(): string { return 'get_override_checker_override_file'; }

	public function getDescription(): string
	{
		return 'Read the template\'s override file for one flagged row: the WHOLE file (never cut short, up to 1 MB), '
			. 'its size, line count and sha256. Read it immediately before apply_override_checker_fix, build the fix from '
			. 'these exact bytes, and pass this sha256 as expected_sha256: the fix is refused if the file has changed '
			. 'since. Compare with get_override_checker_core_file.';
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

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($error = $this->ocCheck()) {
			return $error;
		}

		$this->ocRequireAccess($actor, 'view');

		$row  = $this->ocRequireOverride($arguments);
		$path = PathResolver::overridePath((string) $row['template'], (string) $row['hash_id'], (int) $row['client_id']);

		if ($path === null) {
			throw new \RuntimeException('Could not derive the override file path from row #' . $row['id'] . ' (its hash_id does not decode).');
		}

		if (!is_file($path)) {
			return ToolResult::json([
				'ok'       => false,
				'override' => $this->ocDescribeOverride($row),
				'error'    => 'The override file is not on disk at ' . $this->ocRelative($path) . '. The template no longer '
					. 'overrides this layout (deleted, or the template was removed), so the flag is stale: it can be '
					. 'dismissed with dismiss_override_checker_override once the user agrees.',
			], true);
		}

		return ToolResult::json([
			'ok'       => true,
			'override' => $this->ocDescribeOverride($row),
			'file'     => $this->ocReadFile($path),
			'note'     => $this->ocUntrustedNote(),
		]);
	}
}
