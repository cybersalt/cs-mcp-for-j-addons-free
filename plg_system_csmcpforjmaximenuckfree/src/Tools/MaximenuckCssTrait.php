<?php
/**
 * @package     MCP for Maximenu CK
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools;

\defined('_JEXEC') or die;

/**
 * How a Maximenu CK style's custom CSS actually reaches the page — pure logic,
 * no database, so it can be exercised offline.
 *
 * ---------------------------------------------------------------------------
 * THE TWO COPIES
 * ---------------------------------------------------------------------------
 *
 * A row in `#__maximenuck_styles` carries custom CSS twice.
 *
 *   1. RAW, in `customcss`. At render time `Style::getCss()` emits
 *      `layoutcss . customcss`, and `mod_maximenuck.php:513` replaces `|ID|`
 *      with the module's BARE html id (e.g. `maximenuck125`). So in this copy
 *      `|ID| li` becomes `maximenuck125 li` — a type selector that matches
 *      nothing. Only `#|ID|` (→ `#maximenuck125`) works here.
 *
 *   2. BAKED, at the very END of `layoutcss`. The style editor's save
 *      (`controllers/style.php` save() → previewModuleStyles() →
 *      `Style::createModuleCss()`, helpers/style.php:737-738) appends the
 *      custom CSS last, after replacing `|ID|` with
 *      `div#|ID|<orientation class><layout begin>` — e.g.
 *      `div#|ID|.maximenuckh ul.maximenuck` — and then turns every `"` into
 *      `|qq|`. So in this copy a bare `|ID|` works (with the same specificity as
 *      the vendor's own generated rules) and `#|ID|` becomes `#div#…`, which
 *      matches nothing.
 *
 * Every rule is therefore effective in exactly one copy, and which one depends
 * on how it was written. The editor's own "insert selector" buttons insert BARE
 * `|ID|`, i.e. the vendor's convention relies on the baked copy.
 *
 * The trap that motivated this add-on: writing `customcss` directly (as was
 * done on cybersalt.com on 2026-10-16) changes only copy 1. The baked copy at
 * the end of `layoutcss` still holds whatever custom CSS existed at the last
 * editor save — so old rules keep applying, and new bare-`|ID|` rules do
 * nothing, until a human happens to re-save the style.
 *
 * `layoutcss` itself (everything before the baked copy) is generated in the
 * BROWSER from the editor's form fields; it cannot be regenerated server-side.
 * But the baked suffix is a pure function of the custom CSS, the orientation
 * and the layout, so it CAN be replaced exactly — which is what the editor's
 * save would have produced, given unchanged fields.
 */
trait MaximenuckCssTrait
{
	/**
	 * The `menuCSSID` tail createModuleCss() uses, per orientation and layout
	 * (helpers/style.php:77-97). `dropselect` drops the orientation class.
	 *
	 * @return array<string,string> label => text that replaces `|ID|`
	 */
	protected function mxBakeCandidates(): array
	{
		$out = [];

		foreach (['horizontal' => '.maximenuckh', 'vertical' => '.maximenuckv'] as $o => $class) {
			$out[$o . '/default']      = 'div#|ID|' . $class . ' ul.maximenuck';
			$out[$o . '/flatlist']     = 'div#|ID|' . $class . ' ul.maximenuck2';
			$out[$o . '/nativejoomla'] = 'div#|ID|' . $class . ' ul';
		}

		$out['any/dropselect'] = 'div#|ID|';

		return $out;
	}

	/** Exactly what createModuleCss() + previewModuleStyles() append for $custom. */
	protected function mxBake(string $custom, string $idReplacement): string
	{
		if ($custom === '' || $custom === '[]') {
			return '';
		}

		return str_replace('"', '|qq|', str_replace('|ID|', $idReplacement, $custom));
	}

	/**
	 * Which bake prefix generated this layoutcss? Counted from the vendor's own
	 * rules, which all start with the same menuCSSID.
	 */
	protected function mxDetectBakePrefix(string $layoutcss): ?string
	{
		if ($layoutcss === '') {
			return null;
		}

		$best      = null;
		$bestCount = 0;
		$cands     = $this->mxBakeCandidates();

		// Longest first, and count only occurrences followed by whitespace or a
		// combinator, so ' ul.maximenuck' is not credited with ' ul.maximenuck2'
		// hits and ' ul' (nativejoomla) is not credited with ' ul.maximenuck'.
		uasort($cands, static fn ($a, $b) => \strlen($b) <=> \strlen($a));

		foreach ($cands as $label => $prefix) {
			$count = preg_match_all('/' . preg_quote($prefix, '/') . '(?=[\s{>:,~+])/', $layoutcss);

			if ($count > $bestCount) {
				$best      = $label;
				$bestCount = $count;
			}
		}

		return $best;
	}

	/**
	 * Count `|ID|` tokens by how they will behave.
	 *
	 * @return array{hash:int,bare:int,total:int}
	 */
	protected function mxIdTokenUsage(string $css): array
	{
		$total = substr_count($css, '|ID|');
		$hash  = substr_count($css, '#|ID|');

		return ['hash' => $hash, 'bare' => $total - $hash, 'total' => $total];
	}

	/** Bare `|ID|` → `#|ID|`, leaving `#|ID|` / `div#|ID|` alone. */
	protected function mxRewriteBareId(string $css): string
	{
		return (string) preg_replace('/(?<!#)\|ID\|/', '#|ID|', $css);
	}

	/**
	 * Work out what writing $new over $old does to a style row.
	 *
	 * @return array<string,mixed> keys: sync_state, prefix_label, new_layoutcss (string|null), notes
	 */
	protected function mxPlanCustomCss(string $layoutcss, string $old, string $new, bool $sync): array
	{
		$cands = $this->mxBakeCandidates();
		$plan  = [
			'sync_state'    => 'no_layoutcss',
			'prefix_label'  => null,
			'new_layoutcss' => null,
			'old_baked_bytes' => 0,
		];

		if ($layoutcss === '') {
			return $plan;
		}

		$detected = $this->mxDetectBakePrefix($layoutcss);

		if ($old === '' || $old === '[]') {
			// The editor appends '' for an empty custom CSS, so a layoutcss
			// produced by an editor save with no custom CSS "ends with" it
			// trivially. We can only bake the new CSS if we can tell which
			// prefix the editor would use.
			$plan['sync_state']   = $detected === null ? 'unknown_prefix' : 'in_sync_empty';
			$plan['prefix_label'] = $detected;
		} else {
			// Prefer the detected prefix, then try the others: a menu can be
			// switched between layouts after the style was last saved.
			$order = $detected !== null ? array_merge([$detected], array_diff(array_keys($cands), [$detected])) : array_keys($cands);

			foreach ($order as $label) {
				$baked = $this->mxBake($old, $cands[$label]);

				if ($baked !== '' && str_ends_with($layoutcss, $baked)) {
					$plan['sync_state']      = 'in_sync';
					$plan['prefix_label']    = $label;
					$plan['old_baked_bytes'] = \strlen($baked);
					break;
				}
			}

			if ($plan['sync_state'] !== 'in_sync') {
				$plan['sync_state']   = 'out_of_sync';
				$plan['prefix_label'] = $detected;
			}
		}

		if (!$sync || !\in_array($plan['sync_state'], ['in_sync', 'in_sync_empty'], true)) {
			return $plan;
		}

		$head = $plan['old_baked_bytes'] > 0
			? substr($layoutcss, 0, \strlen($layoutcss) - $plan['old_baked_bytes'])
			: $layoutcss;

		$plan['new_layoutcss'] = $head . $this->mxBake($new, $cands[$plan['prefix_label']]);

		return $plan;
	}

	/**
	 * Will a CSS text reach the page, and through which copy?
	 *
	 * @return array<string,mixed>
	 */
	protected function mxEffectiveness(string $customcss, bool $baked): array
	{
		$usage = $this->mxIdTokenUsage($customcss);

		$out = [
			'id_tokens'      => $usage,
			'baked_in_layoutcss' => $baked,
		];

		if ($usage['total'] === 0) {
			$out['summary'] = 'No |ID| tokens: every rule is global (not scoped to this menu)'
				. ($baked ? ' and is emitted twice (raw and baked) — harmless.' : '.');

			return $out;
		}

		if ($baked) {
			$out['summary'] = 'Bare |ID| rules apply through the baked copy (specificity of '
				. 'div#<id>.maximenuck… — same as the vendor\'s generated rules). #|ID| rules apply through '
				. 'the raw copy only (#<id>, lower specificity — may lose to generated rules without !important).';
			$out['dead_rules'] = 0;
		} else {
			$out['summary'] = 'Only the raw copy is live: #|ID| rules apply, bare |ID| rules match NOTHING '
				. '(they become a type selector like "maximenuck125 li").';
			$out['dead_rules'] = $usage['bare'];
		}

		return $out;
	}
}
