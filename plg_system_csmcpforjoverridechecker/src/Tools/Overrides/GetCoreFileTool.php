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

final class GetCoreFileTool extends AbstractTool
{
	use OverrideCheckerBootTrait;

	/** Enough to spot a renamed layout without flooding the reply. */
	private const MAX_SIBLINGS = 100;

	public function getName(): string { return 'get_override_checker_core_file'; }

	public function getDescription(): string
	{
		return 'Read the core file (component, module, plugin or layout) that a flagged override shadows, whole, to '
			. 'compare with get_override_checker_override_file and find what the update changed. For an ALTERNATIVE '
			. 'LAYOUT there is no core file: that is expected, not an error. The reply then says so (core_exists false) '
			. 'and lists the core folder\'s files so a renamed layout can be found; review the override on its own.';
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

		$row      = $this->ocRequireOverride($arguments);
		$override = $this->ocDescribeOverride($row);
		$path     = PathResolver::corePath((string) $row['hash_id'], (int) $row['client_id']);

		if ($path === null) {
			return ToolResult::json([
				'ok'          => true,
				'override'    => $override,
				'core_exists' => false,
				'reason'      => 'unmapped',
				'explanation' => 'Override Checker can only map overrides under html/com_*, html/mod_*, html/plg_* and '
					. 'html/layouts/ to a core file. "' . $override['relative_path'] . '" is none of those (for example '
					. 'a template\'s own partial), so there is no core file to compare with. Review the override on its own.',
			]);
		}

		if (is_file($path)) {
			return ToolResult::json([
				'ok'          => true,
				'override'    => $override,
				'core_exists' => true,
				'file'        => $this->ocReadFile($path),
				'note'        => $this->ocUntrustedNote(),
			]);
		}

		$dir = \dirname($path);

		if (!is_dir($dir)) {
			return ToolResult::json([
				'ok'          => true,
				'override'    => $override,
				'core_exists' => false,
				'reason'      => 'core_folder_missing',
				'core_file'   => $this->ocRelative($path),
				'explanation' => 'Neither the core file nor its folder (' . $this->ocRelative($dir) . ') exists. The '
					. 'extension has probably been uninstalled, or it moved its layouts. An override for an extension '
					. 'that is gone is never used.',
			]);
		}

		$siblings = [];

		foreach (new \DirectoryIterator($dir) as $entry) {
			if ($entry->isFile()) {
				$siblings[] = $entry->getFilename();
			}
		}

		sort($siblings);

		return ToolResult::json([
			'ok'               => true,
			'override'         => $override,
			'core_exists'      => false,
			'reason'           => 'no_core_file_with_this_name',
			'core_file'        => $this->ocRelative($path),
			'explanation'      => 'There is no core file with this name. Usually the override is an ALTERNATIVE LAYOUT '
				. '(a layout the template adds under its own name, picked per menu item or module), so there is nothing '
				. 'to compare: review the override on its own. It can also mean the update removed or renamed this '
				. 'layout; if a file in core_folder_files looks like the new name, tell the user, since the override '
				. 'may then need to be recreated from that file.',
			'core_folder'       => $this->ocRelative($dir),
			'core_folder_files' => \array_slice($siblings, 0, self::MAX_SIBLINGS),
			'core_folder_more'  => max(0, \count($siblings) - self::MAX_SIBLINGS),
		]);
	}
}
