<?php
/**
 * @package     MCP for AcyMailing
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Reports;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

/**
 * Find people who exist as more than one subscriber.
 *
 * Exact duplicates cannot exist: #__acym_user has UNIQUE(email(191)) under a
 * case-insensitive utf8mb4_unicode_ci collation, and UserClass::save()
 * lower-cases every address. What does slip through is the same mailbox under
 * different spellings — plus-addressing, Gmail dots / googlemail.com, stray
 * whitespace from imports — each of which receives its own copy of every
 * campaign. Also reported: several subscribers linked to one Joomla account,
 * and links whose Joomla email no longer matches the subscriber.
 */
final class CheckSubscriberDuplicatesTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'check_acym_subscriber_duplicates'; }

	public function getDescription(): string
	{
		return 'Read-only duplicate audit of AcyMailing subscribers. Exact and case-variant duplicates are '
			. 'impossible (unique, case-insensitive email index), so this looks for the same mailbox under '
			. 'different spellings - plus-addressing (name+tag@), Gmail dots and googlemail.com, leading/trailing '
			. 'whitespace - each of which receives its own copy of every campaign. Also: two subscribers linked to '
			. 'one Joomla account, subscribers whose linked Joomla account now has a different email, and '
			. 'syntactically invalid addresses. Optional list_id limits the scan to subscribers of one list.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'list_id' => ['type' => 'integer', 'minimum' => 1],
				'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Max groups per category (default 100).'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->acyAdminBase() === null) {
			return $this->notInstalledError();
		}

		$t     = fn (string $s): string => $this->acyQn($this->acyTable($s));
		$limit = $this->acyLimit($arguments, 100);
		$where = '';
		if (!empty($arguments['list_id'])) {
			$where = ' WHERE u.id IN (SELECT user_id FROM ' . $t('user_has_list') . ' WHERE status = 1 AND list_id = ' . (int) $arguments['list_id'] . ')';
		}

		$rows = $this->db->setQuery('SELECT u.id, u.email, u.active, u.confirmed, u.cms_id FROM ' . $t('user') . ' u' . $where)->loadAssocList() ?: [];

		$groups  = [];
		$invalid = [];
		$spaces  = [];
		foreach ($rows as $r) {
			$email = (string) $r['email'];
			if ($email !== trim($email)) {
				$spaces[] = ['id' => (int) $r['id'], 'email' => $email];
			}
			if (!filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
				$invalid[] = ['id' => (int) $r['id'], 'email' => $email];
				continue;
			}
			$groups[$this->canonical($email)][] = ['id' => (int) $r['id'], 'email' => $email, 'active' => (int) $r['active'], 'confirmed' => (int) $r['confirmed']];
		}

		$variants = [];
		foreach ($groups as $canon => $members) {
			if (count($members) > 1) {
				$variants[] = ['canonical_mailbox' => $canon, 'subscribers' => $members];
			}
		}
		usort($variants, static fn ($a, $b) => count($b['subscribers']) <=> count($a['subscribers']));

		$sharedCms = $this->db->setQuery(
			'SELECT u.cms_id, GROUP_CONCAT(u.id ORDER BY u.id) subscriber_ids, GROUP_CONCAT(u.email ORDER BY u.id SEPARATOR \', \') emails FROM '
			. $t('user') . ' u' . $where . ($where ? ' AND' : ' WHERE') . ' u.cms_id > 0 GROUP BY u.cms_id HAVING COUNT(*) > 1',
			0,
			$limit
		)->loadAssocList() ?: [];

		$drift = $this->db->setQuery(
			'SELECT u.id subscriber_id, u.email subscriber_email, j.id joomla_user_id, j.email joomla_email FROM ' . $t('user') . ' u'
			. ' JOIN #__users j ON j.id = u.cms_id' . ($where ? $where . ' AND' : ' WHERE') . ' LOWER(j.email) <> LOWER(u.email)',
			0,
			$limit
		)->loadAssocList() ?: [];

		return ToolResult::json([
			'scanned'                     => count($rows),
			'same_mailbox_variants'       => array_slice($variants, 0, $limit),
			'same_mailbox_group_count'    => count($variants),
			'extra_copies_per_campaign'   => array_sum(array_map(static fn ($g) => count($g['subscribers']) - 1, $variants)),
			'whitespace_in_email'         => array_slice($spaces, 0, $limit),
			'invalid_email'               => array_slice($invalid, 0, $limit),
			'joomla_account_shared'       => $sharedCms,
			'joomla_email_drift'          => $drift,
			'notes'                       => [
				'Variants are a heuristic: name+tag@ and Gmail dot variants usually reach one inbox but not always (some providers treat + literally). Review before merging.',
				'To merge, unsubscribe/deactivate the extra address (update_acym_subscriber active: 0) rather than deleting it, so the history is kept.',
			],
		]);
	}

	/** Collapse provider-specific spellings of one mailbox. */
	private function canonical(string $email): string
	{
		$email = strtolower(trim($email));
		[$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
		if ($domain === 'googlemail.com') {
			$domain = 'gmail.com';
		}
		$plus = strpos($local, '+');
		if ($plus !== false) {
			$local = substr($local, 0, $plus);
		}
		if ($domain === 'gmail.com') {
			$local = str_replace('.', '', $local);
		}

		return $local . '@' . $domain;
	}
}
