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
 * Maximenu CK's mega-menu column arithmetic, reproduced from the renderer so it
 * can be inspected without rendering — pure logic, no database.
 *
 * Source: `components/com_maximenuck/helpers/source/menu.php:279-321` and
 * `modules/mod_maximenuck/tmpl/default.php:33-121` (v10.3.0; identical in
 * 10.2.0 Pro).
 *
 * How a dropdown panel gets its width:
 *
 *   - A child with `maximenu_createcolumn = 1` (or `[col=N]` in its title)
 *     STARTS a column of width `maximenu_colwidth` (default 180).
 *   - The parent's `.maxidrop-main` width ("submenuswidth") is the SUM of every
 *     column-start child's width — across all rows, not per row.
 *   - The parent's own `maximenu_submenucontainerwidth` (or `[subwidth=N]` in
 *     its title) OVERRIDES that sum.
 *   - The first column's width comes from the FIRST child being a column start.
 *     If the first child is not marked, column 1 is rendered with no width and
 *     is not counted in the sum, so the panel is narrower than its columns and
 *     the last column drops underneath. This is the gotcha recorded on
 *     cybersalt.com 2026-10-16.
 *   - The `.floatck` wrapper adds the theme/style padding OUTSIDE that width, so
 *     the on-screen panel is wider than submenuswidth.
 *
 * Only items the renderer would show are counted: published = 1 and
 * `menu_show` != 0 (and not beneath a hidden parent).
 */
trait MaximenuckLayoutTrait
{
	/** Registry::get() semantics: null or '' means "use the default". */
	protected function mxParam(array $params, string $key, string $default = ''): string
	{
		$v = $params[$key] ?? null;

		if ($v === null || $v === '' || \is_array($v)) {
			return $default;
		}

		return (string) $v;
	}

	protected function mxTruthy(array $params, string $key): bool
	{
		$v = $params[$key] ?? 0;

		return !\in_array($v, [0, '0', '', null, false], true);
	}

	/**
	 * Parse a width as the renderer would use it.
	 *
	 * @return array{raw:string,px:?float,kind:string}
	 */
	protected function mxWidth(string $raw): array
	{
		$raw = trim($raw);

		if ($raw === '') {
			return ['raw' => $raw, 'px' => null, 'kind' => 'none'];
		}

		if (stristr($raw, '%')) {
			return ['raw' => $raw, 'px' => null, 'kind' => 'percent'];
		}

		if (strtolower($raw) === 'auto') {
			return ['raw' => $raw, 'px' => null, 'kind' => 'auto'];
		}

		if (stristr($raw, 'em')) {
			return ['raw' => $raw, 'px' => null, 'kind' => 'em'];
		}

		return ['raw' => $raw, 'px' => (float) $raw, 'kind' => 'px'];
	}

	/**
	 * Visible items only, as the renderer would iterate them.
	 *
	 * @param array<int,array<string,mixed>> $items tree-ordered #__menu rows with decoded params
	 * @return array<int,array<string,mixed>>
	 */
	protected function mxVisibleItems(array $items, int $startLevel = 0, int $endLevel = 0): array
	{
		$hidden = [];
		$out    = [];

		foreach ($items as $item) {
			$isHidden = (int) $item['published'] !== 1
				|| (string) ($item['params']['menu_show'] ?? '1') === '0'
				|| \in_array((int) $item['parent_id'], $hidden, true);

			if ($isHidden) {
				$hidden[] = (int) $item['id'];
				continue;
			}

			if (($startLevel && $startLevel > (int) $item['level']) || ($endLevel && (int) $item['level'] > $endLevel)) {
				continue;
			}

			$out[] = $item;
		}

		return $out;
	}

	/**
	 * Every dropdown panel with its columns, as the renderer would build it.
	 *
	 * @param array<int,array<string,mixed>> $items
	 * @return array<int,array<string,mixed>> panels keyed by nothing, in tree order
	 */
	protected function mxComputePanels(array $items, int $startLevel = 0, int $endLevel = 0): array
	{
		$visible = $this->mxVisibleItems($items, $startLevel, $endLevel);
		$byId    = [];

		foreach ($visible as $item) {
			$byId[(int) $item['id']] = $item;
		}

		$panels = [];

		foreach ($visible as $item) {
			$pid = (int) $item['parent_id'];

			if (!isset($byId[$pid])) {
				continue; // top-level item of this module
			}

			if (!isset($panels[$pid])) {
				$parent  = $byId[$pid];
				$markers = $this->mxTitleMarkers((string) $parent['title']);
				$explicit = $this->mxParam($parent['params'], 'maximenu_submenucontainerwidth', '');

				if ($explicit === '' && isset($markers['subwidth'])) {
					$explicit = (string) $markers['subwidth'];
				}

				$liclass = ' ' . $this->mxParam($parent['params'], 'maximenu_liclass', '') . ' ';

				$panels[$pid] = [
					'parent_id'        => $pid,
					'parent_title'     => (string) $parent['title'],
					'parent_level'     => (int) $parent['level'],
					'explicit_width'   => $explicit,
					'fullwidth'        => str_contains($liclass, ' fullwidth '),
					'tab'              => str_contains($liclass, ' maximenucktab '),
					'submenuswidth'    => null,   // vendor's running sum (string|float|null)
					'columns'          => [],
					'children'         => [],
				];
			}

			$panel   = &$panels[$pid];
			$params  = $item['params'];
			$markers = $this->mxTitleMarkers((string) $item['title']);

			$colwidth  = $this->mxParam($params, 'maximenu_colwidth', '180');
			$newrow    = $this->mxTruthy($params, 'maximenu_createnewrow') || !empty($markers['newrow']);
			$isColumn  = false;
			$width     = null;
			$via       = null;

			if ($this->mxTruthy($params, 'maximenu_createcolumn')) {
				$isColumn = true;
				$width    = $colwidth;
				$via      = 'params';
			} elseif (isset($markers['col'])) {
				$isColumn = true;
				$width    = (string) $markers['col'];
				$via      = 'title [col=N]';
			}

			if ($isColumn) {
				$w   = $this->mxWidth((string) $width);
				$cur = $panel['submenuswidth'];

				if ($cur !== null) {
					$curIsLength = !stristr((string) $cur, '%') && (string) $cur !== 'auto';

					if ($w['kind'] === 'px' && $curIsLength) {
						$panel['submenuswidth'] = (float) $cur + (float) $w['px'];
					}
				} elseif ($w['kind'] === 'px') {
					$panel['submenuswidth'] = (float) $w['px'];
				}

				$panel['columns'][] = [
					'index'          => \count($panel['columns']) + 1,
					'start_item_id'  => (int) $item['id'],
					'start_title'    => (string) $item['title'],
					'width'          => (string) $width,
					'width_kind'     => $w['kind'],
					'width_px'       => $w['px'],
					'new_row'        => $newrow,
					'set_via'        => $via,
					'items'          => [],
				];
			} elseif ($panel['columns'] === []) {
				// Children before the first column start form an unmarked column 1.
				$panel['columns'][] = [
					'index'          => 1,
					'start_item_id'  => (int) $item['id'],
					'start_title'    => (string) $item['title'],
					'width'          => null,
					'width_kind'     => 'unset',
					'width_px'       => null,
					'new_row'        => false,
					'set_via'        => 'implicit (first child is not a column start)',
					'items'          => [],
				];
			}

			if ((int) $item['level'] === (int) $panel['parent_level'] + 1) {
				$panel['children'][] = (int) $item['id'];
				$panel['columns'][\count($panel['columns']) - 1]['items'][] = [
					'id'    => (int) $item['id'],
					'title' => (string) $item['title'],
				];
			}

			unset($panel);
		}

		// Explicit container width overrides the running sum (menu.php:319-321).
		foreach ($panels as &$p) {
			$p['computed_sum'] = $p['submenuswidth'];

			if ($p['explicit_width'] !== '') {
				$p['submenuswidth'] = $p['explicit_width'];
			}

			if ($p['fullwidth']) {
				$p['note'] = 'Full-width item: the panel stretches to the menu bar width.';
			}

			// Only direct children make columns; drop panels with no direct children.
			$p['columns'] = array_values(array_filter($p['columns'], static fn ($c) => $c['items'] !== []));
		}

		unset($p);

		return array_values(array_filter($panels, static fn ($p) => $p['children'] !== []));
	}

	/**
	 * Findings for one panel.
	 *
	 * @return array<int,array{severity:string,code:string,message:string}>
	 */
	protected function mxPanelIssues(array $panel, ?float $availableWidth, float $padding): array
	{
		$issues  = [];
		$columns = $panel['columns'];
		$starts  = array_filter($columns, static fn ($c) => $c['width_kind'] !== 'unset');

		if ($starts !== [] && $columns !== [] && $columns[0]['width_kind'] === 'unset') {
			$issues[] = [
				'severity' => 'error',
				'code'     => 'first_child_not_column_start',
				'message'  => sprintf(
					'"%s" (item %d) is the first visible child but is not a column start, while later '
						. 'children are. Column 1 is rendered with no width and is NOT counted in the panel '
						. 'width, so the panel is narrower than its columns and the last column drops '
						. 'underneath. Mark item %d as a column start with its width.',
					$columns[0]['start_title'],
					$columns[0]['start_item_id'],
					$columns[0]['start_item_id']
				),
			];
		}

		$kinds = array_unique(array_map(static fn ($c) => $c['width_kind'], $starts));

		if (\count($kinds) > 1) {
			$issues[] = [
				'severity' => 'warning',
				'code'     => 'mixed_width_units',
				'message'  => 'Column widths mix units (' . implode(', ', $kinds) . '). The renderer only sums '
					. 'pixel widths, and stops summing once a % or auto width is involved, so the panel width '
					. 'will not match the columns.',
			];
		}

		// Rows: the vendor sums EVERY column, so with rows the panel is as wide as
		// all rows laid end to end unless an explicit width is set.
		$rows = [[]];

		foreach ($columns as $c) {
			if ($c['new_row'] && $rows[\count($rows) - 1] !== []) {
				$rows[] = [];
			}

			$rows[\count($rows) - 1][] = $c;
		}

		$rowWidths = array_map(static function ($row) {
			$sum = 0.0;

			foreach ($row as $c) {
				$sum += (float) ($c['width_px'] ?? 0);
			}

			return $sum;
		}, $rows);

		$explicit = $this->mxWidth((string) $panel['explicit_width']);
		$panelPx  = $explicit['kind'] === 'px' ? $explicit['px'] : (is_numeric($panel['submenuswidth']) ? (float) $panel['submenuswidth'] : null);

		if (\count($rows) > 1 && $explicit['kind'] === 'none') {
			$issues[] = [
				'severity' => 'warning',
				'code'     => 'rows_inflate_panel_width',
				'message'  => sprintf(
					'This panel has %d rows of columns, but Maximenu sums every column across all rows, so the '
						. 'panel is %spx wide while the widest row needs only %spx. Set the parent\'s submenu '
						. 'width to the widest row.',
					\count($rows),
					(string) $panel['submenuswidth'],
					(string) max($rowWidths)
				),
			];
		}

		if ($panelPx !== null) {
			foreach ($rowWidths as $i => $rw) {
				if ($rw > $panelPx + 0.5) {
					$issues[] = [
						'severity' => 'error',
						'code'     => 'columns_wider_than_panel',
						'message'  => sprintf(
							'Row %d\'s columns total %spx but the panel is only %spx wide (explicit submenu '
								. 'width). The last column(s) will wrap or spill out of the panel.',
							$i + 1,
							(string) $rw,
							(string) $panelPx
						),
					];
				}
			}
		}

		if ($availableWidth !== null && $panelPx !== null && ($panelPx + $padding) > $availableWidth) {
			$issues[] = [
				'severity' => 'error',
				'code'     => 'panel_wider_than_available',
				'message'  => sprintf(
					'The panel is %spx (+%spx wrapper padding = %spx), wider than the %spx available. It will '
						. 'run off the edge of the viewport/container at that width. Narrow the columns or add a '
						. 'media query to the style\'s custom CSS for that range.',
					(string) $panelPx,
					(string) $padding,
					(string) ($panelPx + $padding),
					(string) $availableWidth
				),
			];
		}

		return $issues;
	}

	/**
	 * Level-1 column starts "kill the layout" (the vendor's own Pro UI warns,
	 * joomlamenu.js ckToggleColumn).
	 *
	 * @param array<int,array<string,mixed>> $items
	 * @return array<int,array<string,mixed>>
	 */
	protected function mxRootColumnIssues(array $items, int $startLevel = 0): array
	{
		$root   = $startLevel > 0 ? $startLevel : 1;
		$issues = [];

		foreach ($this->mxVisibleItems($items, $startLevel) as $item) {
			if ((int) $item['level'] === $root && $this->mxTruthy($item['params'], 'maximenu_createcolumn')) {
				$issues[] = [
					'severity' => 'error',
					'code'     => 'column_on_root_item',
					'message'  => sprintf(
						'Item %d "%s" is a top-level item marked as a column start. Maximenu\'s own UI warns '
							. 'this "will kill your layout": columns belong on items inside a dropdown.',
						(int) $item['id'],
						(string) $item['title']
					),
				];
			}
		}

		return $issues;
	}
}
