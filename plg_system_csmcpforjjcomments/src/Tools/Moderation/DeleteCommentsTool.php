<?php
/**
 * @package     MCP for JComments
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Moderation;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\JcommentsBootTrait;
use Joomla\CMS\User\User;

final class DeleteCommentsTool extends AbstractTool
{
	use JcommentsBootTrait;
	use DeleteSupportTrait;

	public function getName(): string { return 'delete_jcomments_comments'; }

	public function getDescription(): string
	{
		return 'Delete JComments comments the way JComments does. By default this follows the site\'s JComments '
			. '"delete mode": "delete" removes the comment AND EVERY REPLY BENEATH IT, with their votes and reports; '
			. '"mark" keeps the row as a "comment deleted" placeholder and leaves the replies. mode overrides the '
			. 'setting for this call. dry_run lists exactly what would go without touching anything. Comments checked '
			. 'out by another user are skipped. Sends nothing.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'ids'     => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Comment ids (up to 200).'],
				'mode'    => ['type' => 'string', 'enum' => ['setting', 'delete', 'mark_deleted'], 'description' => 'Default "setting" (JComments\' delete_mode option).'],
				'dry_run' => ['type' => 'boolean', 'description' => 'Report what would be removed; change nothing.'],
			],
			'required'             => ['ids'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->jcInstalled()) {
			return $this->jcNotInstalledError();
		}

		$this->jcRequireAccess($actor, 'core.delete');

		$ids  = $this->jcIds($arguments);
		$mode = $this->jcResolveDeleteMode((string) ($arguments['mode'] ?? 'setting'));

		$out = $this->jcDeleteComments($ids, $mode, !empty($arguments['dry_run']), $actor);

		return ToolResult::json($out, !$out['ok']);
	}
}
