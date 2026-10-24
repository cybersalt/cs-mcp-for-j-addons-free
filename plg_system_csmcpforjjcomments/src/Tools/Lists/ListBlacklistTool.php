<?php
/**
 * @package     MCP for JComments
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Lists;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\JcommentsBootTrait;
use Joomla\CMS\User\User;

/**
 * The JComments blacklist. Note what the vendor actually enforces
 * (site/classes/acl.php:188, site/classes/security.php:170-225): nothing at
 * all unless enable_blacklist is on; a guest is matched by IP (exact or
 * a.b.c.* style wildcards); a logged-in visitor by user id only. `expire` is
 * stored and shown in the admin but no vendor code reads it, so a ban never
 * lapses on its own.
 */
final class ListBlacklistTool extends AbstractTool
{
	use JcommentsBootTrait;

	public function getName(): string { return 'list_jcomments_blacklist'; }

	public function getDescription(): string
	{
		return 'List the JComments blacklist (banned IPs and users), with whether the blacklist is switched on. '
			. 'Filter by IP (contains) or user id. Read-only; mark_jcomments_spam with ban_ip adds entries.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'ip'      => ['type' => 'string', 'description' => 'Contains.'],
				'user_id' => ['type' => 'integer'],
				'limit'   => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'  => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->jcInstalled()) {
			return $this->jcNotInstalledError();
		}

		$this->jcRequireAccess($actor);

		$db    = $this->db;
		$where = [];

		if (($ip = trim((string) ($arguments['ip'] ?? ''))) !== '') {
			$where[] = 'ip LIKE ' . $this->jcLike($ip);
		}

		if (isset($arguments['user_id'])) {
			$where[] = 'userid = ' . (int) $arguments['user_id'];
		}

		$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
		$limit    = $this->jcLimit($arguments);
		$offset   = $this->jcOffset($arguments);
		$table    = $db->quoteName($this->jcTable('_blacklist'));

		$total = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $table . $whereSql)->loadResult();
		$rows  = $db->setQuery(
			'SELECT id, ip, userid, created, created_by, expire, reason, notes FROM ' . $table . $whereSql . ' ORDER BY created DESC, id DESC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		return ToolResult::json([
			'ok'                => true,
			'blacklist_enabled' => $this->jcBlacklistEnabled($this->jcParams()),
			'notes'             => [
				'JComments consults this list only while its "enable_blacklist" option is on.',
				'Guests are matched by IP (exact, or a.b.c.* / a.b.*.* / a.*.*.* wildcard rows); logged-in visitors by user id only, so an IP row never stops a registered user.',
				'"expire" is stored but no JComments code reads it: entries never lapse by themselves.',
			],
			'total'   => $total,
			'limit'   => $limit,
			'offset'  => $offset,
			'entries' => $rows,
		]);
	}
}
