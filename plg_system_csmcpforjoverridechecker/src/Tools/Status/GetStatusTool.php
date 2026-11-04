<?php
/**
 * @package     MCP for Override Checker
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\Status;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjoverridechecker\Tools\OverrideCheckerBootTrait;
use Joomla\CMS\User\User;

final class GetStatusTool extends AbstractTool
{
	use OverrideCheckerBootTrait;

	public function getName(): string { return 'get_override_checker_status'; }

	public function getDescription(): string
	{
		return 'Start here. Whether Cybersalt Override Checker is installed and new enough, how many template overrides '
			. 'Joomla has flagged ("Changes found" in System -> Templates) per template, how many backups and review '
			. 'sessions exist, what this user may do, and the review workflow these tools support.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($error = $this->ocCheck()) {
			return $error;
		}

		$this->ocRequireAccess($actor, 'view');

		$state = $this->ocState();
		$db    = $this->db;

		$groups = $db->setQuery(
			$db->createQuery()
				->select([$db->quoteName('template'), $db->quoteName('client_id'), $db->quoteName('state'), 'COUNT(*) AS ' . $db->quoteName('n')])
				->from($db->quoteName('#__template_overrides'))
				->group([$db->quoteName('template'), $db->quoteName('client_id'), $db->quoteName('state')])
		)->loadAssocList() ?: [];

		$templates = [];
		$total     = 0;
		$unchecked = 0;

		foreach ($groups as $group) {
			$key = $group['template'] . '|' . $group['client_id'];
			$n   = (int) $group['n'];

			$templates[$key] ??= [
				'template'    => (string) $group['template'],
				'client'      => (int) $group['client_id'] === 1 ? 'admin' : 'site',
				'flagged'     => 0,
				'not_checked' => 0,
			];

			$templates[$key]['flagged'] += $n;
			$total += $n;

			if ((int) $group['state'] === 0) {
				$templates[$key]['not_checked'] += $n;
				$unchecked += $n;
			}
		}

		$count = static fn (string $table): int => (int) $db->setQuery(
			$db->createQuery()->select('COUNT(*)')->from($db->quoteName($table))
		)->loadResult();

		return ToolResult::json([
			'ok'                => true,
			'override_checker'  => [
				'version'         => $state['version'],
				'package_version' => $state['package_version'],
				'minimum_needed'  => self::OC_MIN_VERSION,
			],
			'flagged_overrides' => [
				'total'        => $total,
				'not_checked'  => $unchecked,
				'by_template'  => array_values($templates),
				'note'         => 'Joomla adds a row when an extension or Joomla update changes a core file that a template '
					. 'overrides. state 0 = not checked (counted in "Changes found"), 1 = marked as checked in Joomla\'s '
					. 'Templates admin. Dismissing a row deletes it.',
			],
			'backups'           => $count('#__csoverridechecker_backups'),
			'sessions'          => $count('#__csoverridechecker_sessions'),
			'permissions'       => [
				'view'  => $this->ocCan($actor, 'view'),
				'write' => $this->ocCan($actor, 'write'),
			],
			'workflow'          => [
				'1. list_override_checker_overrides to see the flagged rows.',
				'2. For each row: get_override_checker_override_file and get_override_checker_core_file, and compare. '
					. 'Look for the core change the override is missing, and for anything that does not belong in a '
					. 'layout file (eval, base64 blobs, remote includes, hidden links).',
				'3. save_override_checker_session with the review report, so the site owner keeps a copy in Override '
					. 'Checker -> Sessions. Pass its id as session_id to the fixes below.',
				'4. Only for rows the user names: apply_override_checker_fix (backs the file up first) or '
					. 'dismiss_override_checker_override (one row per call; there is no dismiss-all).',
				'5. To undo a fix: list_override_checker_backups, then restore_override_checker_backup.',
			],
		]);
	}
}
