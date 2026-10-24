<?php
/**
 * @package     MCP for Page Protector
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Settings;

\defined('_JEXEC') or die;

/**
 * Reading and editing Page Protector's two address lists, `exempt_ips` and
 * `trusted_proxies`. Pure string work, no Joomla calls, so it is tested offline.
 *
 * HOW PAGE PROTECTOR READS A LIST (IpHelper::matchesList(), IpHelper.php:165-180)
 *
 *   preg_split('/[\r\n,]+/', $list), then on each piece strip `#.*$` and trim.
 *   Each piece is a single address or `address/bits`, matched by
 *   IpHelper::matches(), which returns false for anything it cannot parse.
 *
 * Two consequences the admin form never shows:
 *
 *   1. A malformed entry is ignored without a word. "203.0.113.300" or
 *      "203.0.113.0/33" in the box exempts nobody, and the admin believes it
 *      does. These tools refuse to write one and flag any already stored.
 *   2. The split on commas happens BEFORE the comment is stripped, so a comma
 *      inside a comment starts a new, live entry. Commenting out the line
 *      "# 198.51.100.7, 198.51.100.0/24" disables only its first entry: the
 *      /24 stays exempt. (Confirmed against IpHelper itself in the offline
 *      test.) The reader flags such entries as live, and the add tool refuses
 *      a comment containing a comma.
 *
 * Editing keeps the text the admin wrote. Lines are only appended (add) or have
 * one entry taken out (remove); comments and everything else stay as they were.
 */
trait IpListSupportTrait
{
	/**
	 * One entry, parsed the way IpHelper::matches() accepts it.
	 *
	 * @return array{family:int,bits:int,network:string,canonical:string}|null  null = Page Protector would ignore it
	 */
	protected function ppIpParseEntry(string $entry): ?array
	{
		$entry = trim($entry);

		if ($entry === '') {
			return null;
		}

		if (!str_contains($entry, '/')) {
			$bin = @inet_pton($entry);

			if ($bin === false) {
				return null;
			}

			$bits = \strlen($bin) * 8;

			return [
				'family'    => \strlen($bin) === 4 ? 4 : 6,
				'bits'      => $bits,
				'network'   => $bin,
				'canonical' => (string) inet_ntop($bin),
			];
		}

		[$subnet, $bits] = explode('/', $entry, 2);
		$bin             = @inet_pton(trim($subnet));
		$bits            = trim($bits);

		// Same refusals as IpHelper::matches(): bad address, non-digit or out-of-range prefix.
		if ($bin === false || $bits === '' || !ctype_digit($bits)) {
			return null;
		}

		$bits    = (int) $bits;
		$maxBits = \strlen($bin) * 8;

		if ($bits > $maxBits) {
			return null;
		}

		$network = $this->ppIpMask($bin, $bits);

		return [
			'family'    => \strlen($bin) === 4 ? 4 : 6,
			'bits'      => $bits,
			'network'   => $network,
			'canonical' => $bits === $maxBits ? (string) inet_ntop($network) : inet_ntop($network) . '/' . $bits,
		];
	}

	/** Zero the host bits, which is what IpHelper::matches() effectively compares. */
	protected function ppIpMask(string $bin, int $bits): string
	{
		$out = '';

		for ($i = 0, $n = \strlen($bin); $i < $n; $i++) {
			$keep = max(0, min(8, $bits - $i * 8));
			$mask = $keep === 0 ? 0 : (0xFF << (8 - $keep)) & 0xFF;
			$out .= \chr(\ord($bin[$i]) & $mask);
		}

		return $out;
	}

	/** Does entry $outer cover every address in entry $inner? */
	protected function ppIpCovers(array $outer, array $inner): bool
	{
		return $outer['family'] === $inner['family']
			&& $outer['bits'] <= $inner['bits']
			&& $this->ppIpMask($inner['network'], $outer['bits']) === $outer['network'];
	}

	/**
	 * Every piece of the list exactly as Page Protector reads it, flagged.
	 *
	 * @return array<int,array{raw:string,entry:string,valid:bool,canonical:?string,from_comment:bool}>
	 */
	protected function ppIpReadList(string $text): array
	{
		$out = [];

		foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
			$commentAt = strpos($line, '#');

			// The vendor's own split: commas and newlines first, comments second.
			foreach (preg_split('/,+/', $line, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [] as [$piece, $piecePos]) {
				$entry = trim(preg_replace('/#.*$/', '', $piece) ?? '');

				if ($entry === '') {
					continue;
				}

				$parsed = $this->ppIpParseEntry($entry);

				$out[] = [
					'raw'          => trim($piece),
					'entry'        => $entry,
					'valid'        => $parsed !== null,
					'canonical'    => $parsed['canonical'] ?? null,
					// A live entry that a person reading the box would take for part of a comment.
					'from_comment' => $commentAt !== false && $piecePos > $commentAt,
				];
			}
		}

		return $out;
	}

	/**
	 * Problems with a whole list as Page Protector would read it; empty = clean.
	 *
	 * @return string[]
	 */
	protected function ppIpListProblems(string $text): array
	{
		$problems = [];

		foreach ($this->ppIpReadList($text) as $piece) {
			if (!$piece['valid']) {
				$problems[] = '"' . $piece['entry'] . '" is not an IP address or CIDR range; Page Protector would ignore it silently'
					. ($piece['from_comment'] ? ' (it comes from text after a comma inside a # comment: Page Protector splits on commas before it strips comments)' : '');
			} elseif ($piece['from_comment']) {
				$problems[] = '"' . $piece['entry'] . '" sits inside a # comment but is LIVE: Page Protector splits on commas before it '
					. 'strips comments, so it would treat this as a real entry. Put it on its own line or remove the comma.';
			}
		}

		return $problems;
	}

	/**
	 * Valid, live entries of a list (what actually matches).
	 *
	 * @return array<int,array{family:int,bits:int,network:string,canonical:string}>
	 */
	protected function ppIpLiveEntries(string $text): array
	{
		$out = [];

		foreach ($this->ppIpReadList($text) as $piece) {
			if ($piece['valid']) {
				$out[] = $this->ppIpParseEntry($piece['entry']);
			}
		}

		return $out;
	}

	/**
	 * Append entries, skipping any an existing entry already covers.
	 *
	 * @param string[] $entries
	 * @return array{text:string,added:string[],skipped:array<int,array{entry:string,reason:string}>,makes_redundant:array<int,array{entry:string,existing:string}>}
	 */
	protected function ppIpAdd(string $text, array $entries, string $comment = ''): array
	{
		if (str_contains($comment, ',') || preg_match('/[\r\n]/', $comment)) {
			throw new \InvalidArgumentException(
				'comment must be one line with no commas: Page Protector splits the list on commas before it strips '
				. 'comments, so text after a comma would become a live entry.'
			);
		}

		$parsed = [];

		foreach ($entries as $entry) {
			$p = $this->ppIpParseEntry((string) $entry);

			if ($p === null) {
				throw new \InvalidArgumentException('"' . $entry . '" is not an IP address or CIDR range (e.g. 203.0.113.7, 198.51.100.0/24, 2001:db8::/32). Nothing was written.');
			}

			$parsed[] = ['entry' => trim((string) $entry), 'p' => $p];
		}

		$live      = $this->ppIpLiveEntries($text);
		$added     = [];
		$skipped   = [];
		$redundant = [];
		$lines     = $text === '' ? [] : (preg_split('/\r\n|\r|\n/', rtrim($text, "\r\n")) ?: []);

		foreach ($parsed as $item) {
			$p = $item['p'];

			foreach ($live as $existing) {
				if ($this->ppIpCovers($existing, $p)) {
					$skipped[] = [
						'entry'  => $item['entry'],
						'reason' => $existing['canonical'] === $p['canonical']
							? 'already in the list'
							: 'already covered by ' . $existing['canonical'],
					];
					continue 2;
				}
			}

			foreach ($live as $existing) {
				if ($this->ppIpCovers($p, $existing)) {
					$redundant[] = ['entry' => $p['canonical'], 'existing' => $existing['canonical']];
				}
			}

			$lines[] = $p['canonical'] . (trim($comment) !== '' ? ' # ' . trim($comment) : '');
			$live[]  = $p;
			$added[] = $p['canonical'];
		}

		return [
			'text'            => implode("\n", $lines),
			'added'           => $added,
			'skipped'         => $skipped,
			'makes_redundant' => $redundant,
		];
	}

	/**
	 * Take out entries that match exactly (same network and prefix, however
	 * written). An address that stays covered by a wider range is reported,
	 * because removing its own line does not end its exemption.
	 *
	 * @param string[] $entries
	 * @return array{text:string,removed:string[],not_found:string[],still_covered:array<int,array{entry:string,by:string}>,covers_narrower:array<int,array{entry:string,narrower:string}>}
	 */
	protected function ppIpRemove(string $text, array $entries): array
	{
		$targets = [];

		foreach ($entries as $entry) {
			$p = $this->ppIpParseEntry((string) $entry);

			if ($p === null) {
				throw new \InvalidArgumentException('"' . $entry . '" is not an IP address or CIDR range. Nothing was written.');
			}

			$targets[$p['canonical']] = $p;
		}

		$lines   = $text === '' ? [] : (preg_split('/\r\n|\r|\n/', $text) ?: []);
		$removed = [];
		$out     = [];

		foreach ($lines as $line) {
			$commentAt = strpos($line, '#');
			$body      = $commentAt === false ? $line : substr($line, 0, $commentAt);
			$comment   = $commentAt === false ? '' : substr($line, $commentAt);
			$pieces    = explode(',', $body);
			$kept      = [];
			$hit       = false;

			foreach ($pieces as $piece) {
				$p = $this->ppIpParseEntry($piece);

				if ($p !== null && isset($targets[$p['canonical']])) {
					$removed[] = $p['canonical'];
					$hit       = true;
					continue;
				}

				$kept[] = $piece;
			}

			if (!$hit) {
				$out[] = $line;
				continue;
			}

			$keptBody = implode(',', array_filter($kept, static fn (string $s): bool => trim($s) !== ''));

			// The line held only removed entries: drop it with its comment, which described them.
			if (trim($keptBody) === '') {
				continue;
			}

			$out[] = rtrim(trim($keptBody) . ($comment !== '' ? ' ' . $comment : ''));
		}

		$newText  = implode("\n", $out);
		$live     = $this->ppIpLiveEntries($newText);
		$notFound = [];
		$covered  = [];
		$narrower = [];

		foreach ($targets as $canonical => $p) {
			if (!\in_array($canonical, $removed, true)) {
				$notFound[] = $canonical;
			}

			foreach ($live as $existing) {
				if ($this->ppIpCovers($existing, $p)) {
					$covered[] = ['entry' => $canonical, 'by' => $existing['canonical']];
				} elseif ($this->ppIpCovers($p, $existing)) {
					$narrower[] = ['entry' => $canonical, 'narrower' => $existing['canonical']];
				}
			}
		}

		return [
			'text'            => $newText,
			'removed'         => array_values(array_unique($removed)),
			'not_found'       => $notFound,
			'still_covered'   => $covered,
			'covers_narrower' => $narrower,
		];
	}
}
