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

/**
 * Creates a session the way Override Checker's REST API does (POST sessions,
 * source "api"). The columns are VARCHAR(64) and VARCHAR(500) and
 * SessionsHelper::create() cuts longer values silently, so the limits are
 * checked here and refused instead.
 */
final class SaveSessionTool extends AbstractTool
{
	use OverrideCheckerBootTrait;

	private const MAX_NAME = 64;

	private const MAX_SUMMARY = 500;

	public function getName(): string { return 'save_override_checker_session'; }

	public function getDescription(): string
	{
		return 'Save a review report as a new Override Checker session, so the site owner keeps it under Override '
			. 'Checker -> Sessions (downloadable, and readable by its follow-up chat). Every call creates a new session. '
			. 'Save the report BEFORE fixing, then pass the returned session_id to apply_override_checker_fix so the '
			. 'backups and the action log are filed under this review.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'report_markdown' => ['type' => 'string', 'description' => 'The review report in Markdown: one entry per override id, with the finding and the recommended action.'],
				'name'            => ['type' => 'string', 'description' => 'Up to 64 characters. Default: the date and time.'],
				'summary'         => ['type' => 'string', 'description' => 'One or two lines, up to 500 characters.'],
			],
			'required'             => ['report_markdown'],
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

		$report  = $this->requireString($arguments, 'report_markdown');
		$name    = isset($arguments['name']) ? trim((string) $arguments['name']) : '';
		$summary = isset($arguments['summary']) ? trim((string) $arguments['summary']) : '';

		if (mb_strlen($name) > self::MAX_NAME) {
			throw new \InvalidArgumentException('name is ' . mb_strlen($name) . ' characters; Override Checker keeps at most ' . self::MAX_NAME . '.');
		}

		if (mb_strlen($summary) > self::MAX_SUMMARY) {
			throw new \InvalidArgumentException('summary is ' . mb_strlen($summary) . ' characters; Override Checker keeps at most ' . self::MAX_SUMMARY . '. Put the detail in report_markdown.');
		}

		$id      = SessionsHelper::create($name !== '' ? $name : null, $summary !== '' ? $summary : null, $report, SessionsHelper::SOURCE_API);
		$session = SessionsHelper::find($id);
		$saved   = $session !== null && (string) $session->report_markdown === $report;

		return ToolResult::json([
			'ok'         => $saved,
			'session_id' => $id,
			'name'       => $session !== null ? (string) $session->name : null,
			'verified'   => $saved,
			'next'       => 'Pass session_id ' . $id . ' to apply_override_checker_fix for each fix the user approves.',
		], !$saved);
	}
}
