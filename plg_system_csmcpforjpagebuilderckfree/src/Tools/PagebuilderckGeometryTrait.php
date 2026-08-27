<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools;

\defined('_JEXEC') or die;

/**
 * Column geometry for Page Builder CK rows.
 *
 * Extracted because a row's widths are stored in FOUR places that must agree or
 * every column in the row renders with no width at all:
 *
 *   - `.rowck[data-gutter]`   e.g. "2%"
 *   - `.rowck[data-nb]`       the row's own column count
 *   - `.blockck[data-width]`  logical width
 *   - `.blockck[data-real-width]`  width minus its share of the gutters
 *   - plus a `<style class="ckcolumnwidth">` block whose attribute selectors are
 *     keyed on all three of the above
 *
 * Two tools write that shape, and having the arithmetic in both invites exactly
 * the divergence that produces a silently collapsed layout. It lives here once.
 *
 * One deliberate deviation from the vendor: `ckSetColumnWidth()`
 * (pagebuilderck.js) computes real-width from `row.find('.blockck').length`,
 * which counts ALL descendant columns including those belonging to nested rows.
 * That makes the widths fail to sum to 100 on any row containing a nested row.
 * We use the row's OWN column count, matching `ckInitBlocksSize()`, which is
 * what actually produces a correct layout.
 */
trait PagebuilderckGeometryTrait
{

	/** One column, matching `ckHtmlBlock()` at pagebuilderck.js:855. */
	protected function pbckBuildColumn(string $columnId, float $width, int $nb, string $gutter): string
	{
		$real = $this->pbckRealWidth($width, $nb, $gutter);

		// Attribute order matches what the builder emits, so a diff against a
		// builder-authored row shows only the values.
		return '<div class="blockck" id="' . $columnId . '"'
			. ' data-real-width="' . $this->pbckNum($real) . '%"'
			. ' data-width="' . $this->pbckNum($width) . '">'
			. '<div class="ckstyle"></div>'
			. '<div class="inner animate resizable"><div class="innercontent"></div></div>'
			. '</div>';
	}

	/**
	 * The `<style class="ckcolumnwidth">` block that goes FIRST inside the row.
	 *
	 * One pair of rules per column, in column order, exactly as
	 * `ckSetColumnsWidth()` appends them (pagebuilderck.js:2107-2111): the
	 * `:not(.ckadvancedlayout)` variant carries the gutter-adjusted real width,
	 * the `.ckadvancedlayout` variant carries the raw logical width. Equal-width
	 * rows therefore emit the same pair once per column; that duplication is the
	 * vendor's own output and is reproduced rather than optimised away, so that
	 * the next builder save is a no-op diff.
	 *
	 * @param array<int,float> $widths
	 */
	protected function pbckColumnWidthStyle(string $gutter, int $nb, array $widths): string
	{
		$css = '';

		foreach ($widths as $width) {
			$logical = $this->pbckNum($width);
			$real    = $this->pbckNum($this->pbckRealWidth($width, $nb, $gutter));
			$key     = '[data-gutter="' . $gutter . '"][data-nb="' . $nb . '"]';

			$css .= $key . ':not(.ckadvancedlayout) [data-width="' . $logical . '"] {width:' . $real . '%;}';
			$css .= $key . '.ckadvancedlayout [data-width="' . $logical . '"] {width:' . $logical . '%;}';
		}

		return '<style class="ckcolumnwidth">' . $css . '</style>';
	}

	/**
	 * `data-real-width`: the logical width less this column's share of the
	 * gutters. From `ckSetColumnWidth()` at pagebuilderck.js:2119.
	 */
	protected function pbckRealWidth(float $width, int $nb, string $gutter): float
	{
		if ($nb < 1) {
			return $width;
		}

		return $width - ((($nb - 1) * (float) $gutter) / $nb);
	}

	/**
	 * Normalise a gutter to the `<number>%` form the builder stores.
	 *
	 * Returns null for anything that would be unsafe or meaningless inside an
	 * attribute selector. `ckUpdateGutter()` (pagebuilderck.js:2086) does the
	 * same `parseFloat(g) + '%'` normalisation.
	 */
	protected function pbckNormaliseGutter(string $raw): ?string
	{
		$raw = trim($raw);

		if ($raw === '') {
			return '2%'; // ckGetRowGutterValue()'s own default, pagebuilderck.js:2080
		}

		if (preg_match('/^(\d+(?:\.\d+)?)\s*%?$/', $raw, $m) !== 1) {
			return null;
		}

		return $this->pbckNum((float) $m[1]) . '%';
	}

	/**
	 * Format a width for an attribute value and a CSS declaration.
	 *
	 * JavaScript would write 100/3 as "33.333333333333336"; six decimal places
	 * is visually identical at any plausible viewport and much easier to read.
	 * This is safe because we emit the attribute and the selector that matches
	 * it from the same value, so they can never drift apart.
	 */
	protected function pbckNum(float $value): string
	{
		$formatted = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

		return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
	}
}
