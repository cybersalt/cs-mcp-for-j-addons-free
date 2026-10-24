<?php
/**
 * @package     MCP for JComments
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjjcomments\Tools\Spam;

\defined('_JEXEC') or die;

/**
 * Spam heuristics. Every flag names the rule that fired, its weight and why,
 * so a moderator can see the reasoning and disagree with it.
 *
 * JComments itself has no spam score and no spam state. Its anti-spam is all
 * at submission time: captcha, flood interval, forbidden names, the IP/user
 * blacklist, an optional StopForumSpam IP lookup (plg_system_jcomments
 * onJCommentsCommentBeforeAdd) and the censor list, which only REPLACES bad
 * words when a comment is displayed. These rules look at what got through.
 *
 * Two halves:
 *   - jcTextRules() is pure (text in, flags out) so it can be tested offline.
 *   - jcSpamAssess() adds the rules that need the database: duplicates,
 *     bursts from one IP, blacklist hits, author history, user reports.
 *
 * Score bands: 0 clean, 1-2 low, 3-4 suspicious, 5+ likely_spam. They are a
 * sorting aid, not a verdict; nothing is unpublished or deleted by scoring.
 *
 * Deliberately NOT a rule: the script or language of the text. Sites like the
 * one this add-on was requested for publish in Serbian Cyrillic and Latin, and
 * a "non-English" rule would flag every genuine comment there.
 */
trait SpamSupportTrait
{
	/**
	 * Phrases that turn up in comment spam and almost never in a real reply.
	 * Matched case-insensitively on word boundaries. Kept short on purpose:
	 * a site's own vocabulary belongs in JComments' censor list ("badwords"),
	 * which the badwords rule reads too.
	 */
	private const JC_SPAM_PATTERNS = [
		'pharma'   => '/\b(viagra|cialis|levitra|tramadol|xanax|valium|phentermine|kamagra|pharmacy online|online pharmacy|no prescription)\b/iu',
		'gambling' => '/\b(online casino|casino bonus|free spins|sports? betting|betting tips|slot machines?|poker online|bitcoin casino)\b/iu',
		'finance'  => '/\b(payday loans?|cash advance|bad credit loans?|forex signals|binary options|crypto (?:investment|signals)|double your bitcoin)\b/iu',
		'seo'      => '/\b(seo services?|backlinks?|link building|guest post(?:ing)? service|increase (?:your )?traffic|rank (?:your site|#?1 on google))\b/iu',
		'adult'    => '/\b(porn|xxx|adult dating|hot singles|escort service|sex cams?|webcam girls)\b/iu',
		'scam'     => '/\b(work from home|make money (?:fast|online)|earn \$\d+|click here|buy now|limited time offer|100% free|act now|essay writing service|replica (?:watches|bags))\b/iu',
	];

	/** Disposable-address domains. A signal, not proof: real people use them too. */
	private const JC_DISPOSABLE_DOMAINS = [
		'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', '10minutemail.com', 'temp-mail.org',
		'yopmail.com', 'trashmail.com', 'sharklasers.com', 'getnada.com', 'dispostable.com', 'maildrop.cc',
	];

	/** URL-ish things in stored JComments text: bare links, www., [url] BBCode, and HTML anchors that slipped in. */
	private const JC_LINK_REGEX = '~(?:https?://|ftp://|\bwww\.)[^\s<>"\'\[\]]+|\[url(?:=[^\]]*)?\]|<a\s[^>]*href~iu';

	/**
	 * Rules that need only the comment's own fields.
	 *
	 * @param array{comment?:?string,name?:?string,email?:?string,homepage?:?string,userid?:int|string} $row
	 * @param string $badwords JComments' censor list, raw (comma/newline separated regex fragments)
	 * @return array<int,array{rule:string,weight:int,why:string}>
	 */
	public static function jcTextRules(array $row, string $badwords = ''): array
	{
		$flags = [];
		$raw   = (string) ($row['comment'] ?? '');
		$text  = trim(html_entity_decode(strip_tags(str_replace(['<br />', '<br/>', '<br>'], "\n", $raw)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$guest = (int) ($row['userid'] ?? 0) === 0;

		// Links.
		$links = preg_match_all(self::JC_LINK_REGEX, $raw, $m) ?: 0;

		if ($links > 0) {
			$domains = [];

			foreach ($m[0] as $l) {
				if (preg_match('~(?:https?://|ftp://)?(?:www\.)?([a-z0-9.-]+\.[a-z]{2,})~iu', $l, $d)) {
					$domains[strtolower($d[1])] = true;
				}
			}

			$weight  = $links >= 3 ? 3 : ($links === 2 ? 2 : 1);
			$flags[] = [
				'rule'   => 'links',
				'weight' => $weight,
				'why'    => $links . ' link' . ($links === 1 ? '' : 's') . ' in the text'
					. ($domains !== [] ? ' (' . implode(', ', array_keys($domains)) . ')' : ''),
			];

			$withoutLinks = trim(preg_replace(self::JC_LINK_REGEX, '', $text) ?? '');
			$withoutLinks = trim(preg_replace('~\[/?url[^\]]*\]~iu', '', $withoutLinks) ?? '');

			if (mb_strlen($withoutLinks) < 20) {
				$flags[] = ['rule' => 'link_only', 'weight' => 2, 'why' => 'apart from the link(s) there are fewer than 20 characters of text'];
			}
		}

		// Known spam phrases.
		foreach (self::JC_SPAM_PATTERNS as $family => $regex) {
			if (preg_match($regex, $text, $pm)) {
				$flags[] = ['rule' => 'known_pattern', 'weight' => 3, 'why' => $family . ' phrase "' . $pm[0] . '"'];
			}
		}

		// The site's own censor list, which JComments treats as regex fragments
		// matched with '#<word>#ismu' (site/classes/text.php censor()). A broken
		// fragment is skipped, as the vendor's dummy-word guard would.
		foreach (preg_split('/[,\r\n]+/', $badwords) ?: [] as $word) {
			$word = trim($word);

			if ($word === '') {
				continue;
			}

			$word = str_replace('#', '\#', str_replace('\#', '#', $word));

			if (@preg_match('#' . $word . '#ismu', $text, $bm) === 1 && trim($bm[0]) !== '') {
				$flags[] = ['rule' => 'badwords', 'weight' => 2, 'why' => 'matches the site\'s JComments censor list entry "' . $word . '"'];
				break;
			}
		}

		// Author fields that carry a link.
		$name = (string) ($row['name'] ?? '');

		if ($name !== '' && preg_match('~(https?://|\bwww\.|\.(com|net|org|info|biz|ru|xyz|top|shop|online|site)\b)~iu', $name)) {
			$flags[] = ['rule' => 'name_is_link', 'weight' => 3, 'why' => 'the author name "' . $name . '" contains a URL or domain'];
		}

		if ($guest && trim((string) ($row['homepage'] ?? '')) !== '') {
			$flags[] = ['rule' => 'guest_homepage', 'weight' => 1, 'why' => 'guest left a homepage link (' . $row['homepage'] . ')'];
		}

		$email  = strtolower(trim((string) ($row['email'] ?? '')));
		$domain = str_contains($email, '@') ? substr($email, strrpos($email, '@') + 1) : '';

		if ($domain !== '' && \in_array($domain, self::JC_DISPOSABLE_DOMAINS, true)) {
			$flags[] = ['rule' => 'disposable_email', 'weight' => 1, 'why' => 'email is at the disposable-address domain ' . $domain];
		}

		// Repetition inside the comment.
		if (preg_match('/(.)\1{9,}/u', $text, $rm)) {
			$flags[] = ['rule' => 'repeated_text', 'weight' => 1, 'why' => 'the character "' . $rm[1] . '" repeated ' . mb_strlen($rm[0]) . ' times'];
		} else {
			$words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
			$words = array_values(array_filter($words, static fn (string $w): bool => mb_strlen($w) > 2));

			if (\count($words) >= 8) {
				$counts = array_count_values($words);
				arsort($counts);
				$top   = (string) array_key_first($counts);
				$share = $counts[$top] / \count($words);

				if ($counts[$top] >= 5 && $share >= 0.3) {
					$flags[] = ['rule' => 'repeated_text', 'weight' => 1, 'why' => 'the word "' . $top . '" is ' . (int) round($share * 100) . '% of the text'];
				}
			}
		}

		// Shouting.
		$letters = preg_replace('/[^\p{L}]/u', '', $text) ?? '';
		$upper   = preg_replace('/[^\p{Lu}]/u', '', $text) ?? '';

		if (mb_strlen($letters) >= 20 && mb_strlen($upper) / mb_strlen($letters) > 0.7) {
			$flags[] = ['rule' => 'all_caps', 'weight' => 1, 'why' => 'more than 70% of the letters are capitals'];
		}

		return $flags;
	}

	/** Score to band. */
	public static function jcSpamBand(int $score): string
	{
		return match (true) {
			$score >= 5 => 'likely_spam',
			$score >= 3 => 'suspicious',
			$score >= 1 => 'low',
			default     => 'clean',
		};
	}

	/**
	 * All rules for one comment: the text rules plus the ones that need the
	 * database. Read-only.
	 *
	 * @return array{score:int,band:string,flags:array<int,array{rule:string,weight:int,why:string}>}
	 */
	protected function jcSpamAssess(array $row, string $badwords): array
	{
		$flags = self::jcTextRules($row, $badwords);
		$db    = $this->db;
		$table = $db->quoteName($this->jcTable(''));
		$id    = (int) $row['id'];

		// Same text elsewhere. Normalised the cheap way: compared as stored.
		$plain = trim((string) $row['comment']);

		if (mb_strlen($plain) >= 15) {
			$dups = $db->setQuery(
				'SELECT id FROM ' . $table . ' WHERE id <> ' . $id . ' AND comment = ' . $db->quote($plain),
				0,
				20
			)->loadColumn() ?: [];

			if ($dups !== []) {
				$flags[] = [
					'rule'   => 'duplicate_text',
					'weight' => 3,
					'why'    => 'the same text was posted as ' . (\count($dups) >= 20 ? '20+' : \count($dups)) . ' other comment(s): ' . implode(', ', array_slice(array_map('intval', $dups), 0, 10)),
				];
			}
		}

		// A burst from one IP: three or more comments within 10 minutes either side.
		$ip = (string) $row['ip'];

		if ($ip !== '' && !empty($row['date'])) {
			$burst = (int) $db->setQuery(
				'SELECT COUNT(*) FROM ' . $table . ' WHERE ip = ' . $db->quote($ip)
				. ' AND date BETWEEN DATE_SUB(' . $db->quote($row['date']) . ', INTERVAL 10 MINUTE)'
				. ' AND DATE_ADD(' . $db->quote($row['date']) . ', INTERVAL 10 MINUTE)'
			)->loadResult();

			if ($burst >= 3) {
				$flags[] = ['rule' => 'burst', 'weight' => 2, 'why' => $burst . ' comments from IP ' . $ip . ' within 10 minutes of this one'];
			}
		}

		// The blacklist JComments itself would consult.
		$userId = (int) $row['userid'];

		if ($userId > 0 && $this->jcUserBlacklisted($userId)) {
			$flags[] = ['rule' => 'blacklisted', 'weight' => 3, 'why' => 'user ' . $userId . ' is on the JComments blacklist'];
		} elseif ($ip !== '' && $this->jcIpBlacklisted($ip)) {
			$flags[] = ['rule' => 'blacklisted', 'weight' => 3, 'why' => 'IP ' . $ip . ' is on the JComments blacklist'];
		}

		// New author: nothing else of theirs has ever been published.
		if ($userId > 0) {
			$prior = (int) $db->setQuery(
				'SELECT COUNT(*) FROM ' . $table . ' WHERE id <> ' . $id . ' AND published = 1 AND userid = ' . $userId
			)->loadResult();

			$registered = (string) $db->setQuery(
				'SELECT registerDate FROM ' . $db->quoteName('#__users') . ' WHERE id = ' . $userId
			)->loadResult();

			if ($prior === 0 && $registered !== '' && !empty($row['date'])
				&& strtotime((string) $row['date']) - strtotime($registered) < 7 * 86400) {
				$flags[] = ['rule' => 'new_author', 'weight' => 1, 'why' => 'account registered ' . $registered . ' UTC, less than 7 days before this comment, and no published comments yet'];
			}
		} else {
			$conds = [];

			if (trim((string) $row['email']) !== '') {
				$conds[] = 'email = ' . $db->quote((string) $row['email']);
			}

			if ($ip !== '') {
				$conds[] = 'ip = ' . $db->quote($ip);
			}

			$prior = $conds === [] ? 0 : (int) $db->setQuery(
				'SELECT COUNT(*) FROM ' . $table . ' WHERE id <> ' . $id . ' AND published = 1 AND (' . implode(' OR ', $conds) . ')'
			)->loadResult();

			if ($prior === 0) {
				$flags[] = ['rule' => 'new_author', 'weight' => 1, 'why' => 'guest with no published comment from the same email or IP'];
			}
		}

		// Readers reported it.
		if ($this->jcTableExists('_reports')) {
			$reports = $db->setQuery(
				'SELECT reason FROM ' . $db->quoteName($this->jcTable('_reports')) . ' WHERE commentid = ' . $id
			)->loadColumn() ?: [];

			if ($reports !== []) {
				$reasons = array_values(array_filter(array_map(static fn ($r): string => mb_substr(trim((string) $r), 0, 80), $reports)));
				$flags[] = [
					'rule'   => 'reported',
					'weight' => 2,
					'why'    => \count($reports) . ' reader report(s)' . ($reasons !== [] ? ': "' . implode('", "', array_slice($reasons, 0, 3)) . '"' : ''),
				];
			}
		}

		if ((int) $row['ispoor'] >= 3 && (int) $row['ispoor'] > (int) $row['isgood']) {
			$flags[] = ['rule' => 'poor_votes', 'weight' => 1, 'why' => (int) $row['ispoor'] . ' "poor" votes against ' . (int) $row['isgood'] . ' "good"'];
		}

		$score = array_sum(array_column($flags, 'weight'));

		return ['score' => $score, 'band' => self::jcSpamBand($score), 'flags' => $flags];
	}
}
