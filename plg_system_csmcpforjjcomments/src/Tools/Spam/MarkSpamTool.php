<?php
/**
 * @package     MCP for JComments
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Spam;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\JcommentsBootTrait;
use Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Moderation\DeleteSupportTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Deal with spam in one call: unpublish or delete the comments, and
 * optionally put their authors' IPs on the JComments blacklist.
 *
 * JComments has no "spam" state, so "marking" means unpublishing (the comment
 * stays in the admin list, out of sight) or deleting. The ban is the vendor's
 * own: a #__jcomments_blacklist row with the IP, as its front-end "Ban"
 * button writes (site/jcomments.ajax.php BanIP()), including its two refusals
 * (your own IP, an IP already covered).
 *
 * Two vendor facts the response spells out:
 *   - the blacklist is only consulted when the "enable_blacklist" option is
 *     on (site/classes/acl.php:188);
 *   - for a LOGGED-IN visitor JComments checks the blacklist by user id only,
 *     never by IP (site/classes/security.php:185-209), so an IP ban does not
 *     stop a registered spammer. Block the Joomla account for that.
 */
final class MarkSpamTool extends AbstractTool
{
	use JcommentsBootTrait;
	use DeleteSupportTrait;

	public function getName(): string { return 'mark_jcomments_spam'; }

	public function getDescription(): string
	{
		return 'Act on JComments spam: unpublish (default) or delete the given comments, and optionally ban their '
			. 'authors\' IP addresses on the JComments blacklist (ban_ip, needs JComments\' comment.ban permission). '
			. 'JComments has no spam flag, so unpublish is the reversible choice; action "delete" follows JComments\' '
			. 'delete mode (and in "delete" mode also removes every reply beneath). Sends nothing and fires no '
			. 'JComments event. Use check_jcomments_spam first to find candidates and see why they were flagged.';
	}

	public function getMcpAnnotations(): array
	{
		// Can delete, so tell clients it is destructive.
		return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false];
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'ids'    => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Comment ids (up to 200).'],
				'action' => ['type' => 'string', 'enum' => ['unpublish', 'delete', 'none'], 'description' => 'Default unpublish. "none" = only ban.'],
				'ban_ip' => ['type' => 'boolean', 'description' => 'Add each comment\'s IP to the JComments blacklist.'],
				'reason' => ['type' => 'string', 'description' => 'Blacklist reason (shown in JComments\' admin). Default "Spam comment #<id>".'],
				'notes'  => ['type' => 'string', 'description' => 'Optional blacklist note.'],
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

		$action = (string) ($arguments['action'] ?? 'unpublish');
		$ban    = ($arguments['ban_ip'] ?? false) === true;

		if (!\in_array($action, ['unpublish', 'delete', 'none'], true)) {
			throw new \InvalidArgumentException('action must be unpublish, delete or none.');
		}

		if ($action === 'none' && !$ban) {
			throw new \InvalidArgumentException('action "none" with ban_ip false does nothing.');
		}

		$needed = [];

		if ($action === 'unpublish') {
			$needed[] = 'core.edit.state';
		} elseif ($action === 'delete') {
			$needed[] = 'core.delete';
		}

		if ($ban) {
			$needed[] = 'comment.ban';
		}

		$this->jcRequireAccess($actor, ...$needed);

		$ids    = $this->jcIds($arguments);
		$reason = trim((string) ($arguments['reason'] ?? ''));
		$notes  = trim((string) ($arguments['notes'] ?? ''));

		// reason and notes are TINYTEXT: 255 bytes.
		$this->jcRequireFits('reason', $reason, 255, true);
		$this->jcRequireFits('notes', $notes, 255, true);

		$db       = $this->db;
		$rows     = [];
		$missing  = [];
		$warnings = [];

		foreach ($ids as $id) {
			$row = $this->jcComment($id);

			if ($row === null) {
				$missing[] = $id;
			} else {
				$rows[$id] = $row;
			}
		}

		// Ban first, while every row (and its IP) still exists.
		$bans = [];

		if ($ban) {
			$params = $this->jcParams();
			$ownIp  = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
			$done   = [];

			if (!$this->jcBlacklistEnabled($params)) {
				$warnings[] = 'JComments\' blacklist is switched OFF (Settings, "enable_blacklist"), so these bans are stored but not enforced until it is turned on.';
			}

			foreach ($rows as $id => $row) {
				$ip = trim((string) $row['ip']);

				if ($ip === '') {
					$bans[] = ['comment_id' => $id, 'ok' => false, 'error' => 'the comment has no IP recorded'];
					continue;
				}

				if (isset($done[$ip])) {
					$bans[] = ['comment_id' => $id, 'ip' => $ip, 'ok' => true, 'skipped' => 'banned in this call already (blacklist row ' . $done[$ip] . ')'];
					continue;
				}

				if ($ip === $ownIp) {
					$bans[] = ['comment_id' => $id, 'ip' => $ip, 'ok' => false, 'error' => 'that is the IP this request comes from; JComments refuses to ban your own IP'];
					continue;
				}

				if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
					$bans[] = ['comment_id' => $id, 'ip' => $ip, 'ok' => false, 'error' => 'not a valid IP address'];
					continue;
				}

				if ($this->jcIpBlacklisted($ip)) {
					$bans[] = ['comment_id' => $id, 'ip' => $ip, 'ok' => true, 'skipped' => 'already covered by a blacklist row'];
					continue;
				}

				$entry = (object) [
					'ip'         => $ip,
					'userid'     => 0,
					'created'    => Factory::getDate()->toSql(),
					'created_by' => (int) $actor->id,
					'expire'     => null,
					'reason'     => $reason !== '' ? $reason : 'Spam comment #' . $id,
					'notes'      => $notes !== '' ? $notes : null,
				];

				$db->insertObject($this->jcTable('_blacklist'), $entry, 'id');

				$done[$ip] = (int) $entry->id;
				$bans[]    = ['comment_id' => $id, 'ip' => $ip, 'ok' => $this->jcIpBlacklisted($ip), 'blacklist_id' => (int) $entry->id];

				if ((int) $row['userid'] > 0) {
					$warnings[] = 'Comment ' . $id . ' is by registered user ' . (int) $row['userid'] . '. JComments checks logged-in visitors against the blacklist by user id only, so the IP ban will not stop them; block the Joomla account instead.';
				}
			}
		}

		// Then the comments.
		$results = [];

		if ($action === 'unpublish') {
			foreach ($rows as $id => $row) {
				if ($this->jcLockedByOther($row, $actor)) {
					$results[] = ['id' => $id, 'ok' => false, 'error' => 'checked out by user ' . (int) $row['checked_out'] . '; check it in first'];
					continue;
				}

				if ((int) $row['published'] === 0) {
					$results[] = ['id' => $id, 'ok' => true, 'skipped' => 'already unpublished'];
					continue;
				}

				$db->setQuery('UPDATE ' . $db->quoteName($this->jcTable('')) . ' SET published = 0 WHERE id = ' . (int) $id)->execute();

				$after     = $this->jcComment($id);
				$results[] = ['id' => $id, 'ok' => (int) $after['published'] === 0, 'state' => $this->jcStateLabel($after)];
			}

			$this->jcCleanCache();
		} elseif ($action === 'delete' && $rows !== []) {
			$del     = $this->jcDeleteComments(array_keys($rows), $this->jcResolveDeleteMode('setting'), false, $actor);
			$results = $del['results'];
		}

		foreach ($missing as $id) {
			$results[] = ['id' => $id, 'ok' => false, 'error' => 'no such comment'];
		}

		usort($results, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

		$ok = !\in_array(false, array_column($results, 'ok'), true) && !\in_array(false, array_column($bans, 'ok'), true);

		return ToolResult::json([
			'ok'       => $ok,
			'action'   => $action,
			'comments' => $results,
			'bans'     => $ban ? $bans : 'not requested',
			'mail'     => 'none',
			'warnings' => array_values(array_unique($warnings)),
		], !$ok);
	}
}
