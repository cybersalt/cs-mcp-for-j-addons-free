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
use Joomla\CMS\User\User;

final class CheckSpamTool extends AbstractTool
{
	use JcommentsBootTrait;
	use SpamSupportTrait;

	public function getName(): string { return 'check_jcomments_spam'; }

	public function getDescription(): string
	{
		return 'Scan JComments comments for likely spam and explain every flag: links (count and domains), link-only '
			. 'text, known spam phrases (pharma, gambling, loans, SEO, adult, scam), the site\'s own JComments censor '
			. 'list, a link in the author name, a guest homepage, disposable email, repeated or shouted text, the same '
			. 'text posted elsewhere, a burst of comments from one IP, blacklisted IP or user, a new author with '
			. 'nothing published, reader reports and poor votes. Each comment gets a score and a band (low 1-2, '
			. 'suspicious 3-4, likely_spam 5+). Read-only: act on the result with mark_jcomments_spam or '
			. 'set_jcomments_comment_state. Default scope: comments waiting for approval from the last 30 days.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'ids'       => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Check these comments (ignores the scope filters).'],
				'state'     => ['type' => 'string', 'enum' => ['unpublished', 'published', 'all'], 'description' => 'Default unpublished (the approval queue).'],
				'days'      => ['type' => 'integer', 'description' => 'Look back this many days. Default 30, 0 = no limit.'],
				'object_id' => ['type' => 'integer', 'description' => 'Only comments on this item.'],
				'min_score' => ['type' => 'integer', 'description' => 'Only return comments scoring at least this. Default 1 (anything flagged); 0 returns clean ones too.'],
				'limit'     => ['type' => 'integer', 'description' => 'Comments to examine, newest first. Default 100, max 500.'],
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
		$scope = [];

		if (!empty($arguments['ids'])) {
			$ids     = $this->jcIds($arguments, 500);
			$where[] = 'id IN (' . implode(',', $ids) . ')';
			$scope   = ['ids' => $ids];
		} else {
			$state = (string) ($arguments['state'] ?? 'unpublished');

			if ($state === 'unpublished') {
				$where[] = 'published = 0 AND deleted = 0';
			} elseif ($state === 'published') {
				$where[] = 'published = 1 AND deleted = 0';
			} elseif ($state !== 'all') {
				throw new \InvalidArgumentException('state must be unpublished, published or all.');
			}

			$days = (int) ($arguments['days'] ?? 30);

			if ($days > 0) {
				$where[] = 'date >= ' . $db->quote(gmdate('Y-m-d H:i:s', time() - $days * 86400));
			}

			if (isset($arguments['object_id'])) {
				$where[] = 'object_id = ' . (int) $arguments['object_id'];
			}

			$scope = ['state' => $state, 'days' => $days > 0 ? $days : 'all'] + (isset($arguments['object_id']) ? ['object_id' => (int) $arguments['object_id']] : []);
		}

		$limit    = $this->jcLimit($arguments, 100, 500);
		$minScore = max(0, (int) ($arguments['min_score'] ?? 1));
		$rows     = $db->setQuery(
			'SELECT * FROM ' . $db->quoteName($this->jcTable('')) . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY date DESC, id DESC',
			0,
			$limit
		)->loadAssocList() ?: [];

		$badwords = (string) $this->jcParams()->get('badwords', '');
		$objects  = $this->jcObjectInfo($rows);
		$bands    = ['likely_spam' => 0, 'suspicious' => 0, 'low' => 0, 'clean' => 0];
		$hits     = [];

		foreach ($rows as $row) {
			$assess = $this->jcSpamAssess($row, $badwords);
			$bands[$assess['band']]++;

			if ($assess['score'] < $minScore) {
				continue;
			}

			$hits[] = ['score' => $assess['score'], 'band' => $assess['band'], 'flags' => $assess['flags']] + $this->jcFormatComment($row, $objects);
		}

		usort($hits, static fn (array $a, array $b): int => [$b['score'], $b['id']] <=> [$a['score'], $a['id']]);

		return ToolResult::json([
			'ok'        => true,
			'scope'     => $scope,
			'examined'  => \count($rows),
			'bands'     => $bands,
			'min_score' => $minScore,
			'note'      => 'Scores sort comments for review; they decide nothing. Every flag says which rule fired and why.'
				. ($badwords === '' ? ' The site\'s JComments censor list (badwords) is empty, so that rule never fires.' : ''),
			'comments'  => $hits,
		]);
	}
}
