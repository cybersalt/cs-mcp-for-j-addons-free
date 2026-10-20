<?php
/**
 * @package     MCP for FlexBanners
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Reports;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

/**
 * Impressions and clicks.
 *
 * FlexBanners keeps exactly three counters per banner — `impmade`, `clicks`
 * and `dailyimpressions` (reset each day by the module, valid only when
 * `lastreset` is today) — and nothing else. There is NO per-day log: the
 * `filter_tracks.xml` form shipped with the component has no table behind it.
 * So there is no date-range report to give, and this tool says so rather than
 * inventing one from created/modified dates.
 */
final class GetStatsTool extends AbstractTool
{
	use FlexbannersBootTrait;

	public function getName(): string { return 'get_flexbanners_stats'; }

	public function getDescription(): string
	{
		return 'Impressions, clicks and click-through rate, per banner and rolled up per location (group) and per '
			. 'client — the same lifetime counters the FlexBanners Banners list shows, plus today\'s impressions. '
			. 'FlexBanners keeps NO per-day history (no tracking table exists), so date-range statistics are not '
			. 'possible: counters run from the banner\'s creation or its last reset_flexbanner_counters. Impressions skip '
			. 'user agents on FlexBanners\' bot list, but CLICKS are counted for every request to the click URL, bots and '
			. 'repeat clicks included, so CTR can exceed 100%. Module caching stops impressions being counted while a '
			. 'cached copy is served. Filters: location_id, client_id, banner_ids, state (default published + unpublished).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'location_id' => ['type' => 'integer'],
				'client_id'   => ['type' => 'integer'],
				'banner_ids'  => ['type' => 'array', 'items' => ['type' => 'integer']],
				'state'       => ['type' => 'string', 'enum' => ['published', 'unpublished', 'archived', 'trashed', 'all']],
				'sort'        => ['type' => 'string', 'enum' => ['impressions', 'clicks', 'ctr', 'id'], 'description' => 'Banner sort, descending (id ascending). Default id.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->fbInstalled()) {
			return $this->fbNotInstalledError();
		}

		$this->fbRequireAccess($actor);

		$where = [];
		$state = (string) ($arguments['state'] ?? '');

		if ($state === '') {
			$where[] = 'state IN (0, 1)';
		} elseif ($state !== 'all') {
			$where[] = 'state = ' . $this->fbParseState($state);
		}

		if (isset($arguments['location_id'])) {
			$where[] = 'locationid = ' . (int) $arguments['location_id'];
		}

		if (isset($arguments['client_id'])) {
			$where[] = 'clientid = ' . (int) $arguments['client_id'];
		}

		$ids = array_values(array_filter(array_map('intval', (array) ($arguments['banner_ids'] ?? []))));

		if ($ids !== []) {
			$where[] = 'id IN (' . implode(',', $ids) . ')';
		}

		$rows = $this->db->setQuery(
			'SELECT id, name, state, locationid, clientid, impmade, clicks, dailyimpressions, lastreset, maximpressions, maxclicks, created FROM '
			. $this->db->quoteName($this->fbTable('')) . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id'
		)->loadAssocList() ?: [];

		$locations = $this->fbLookup('locations', 'locationid');
		$clients   = $this->fbLookup('client', 'clientid');
		// The module resets with PHP date(), in the request's default timezone (UTC under Joomla).
		$today     = date('Y-m-d');
		$ctr       = static fn (int $i, int $c): ?float => $i > 0 ? round($c * 100 / $i, 2) : null;

		$banners = [];
		$byLoc   = [];
		$byCli   = [];
		$total   = ['impressions' => 0, 'clicks' => 0];

		foreach ($rows as $r) {
			$imp = (int) $r['impmade'];
			$clk = (int) $r['clicks'];
			$loc = (int) $r['locationid'];
			$cli = (int) $r['clientid'];

			$banners[] = [
				'id'                => (int) $r['id'],
				'name'              => (string) $r['name'],
				'state'             => $this->fbStateLabel((int) $r['state']),
				'location_id'       => $loc,
				'client_id'         => $cli,
				'impressions'       => $imp,
				'clicks'            => $clk,
				'ctr_percent'       => $ctr($imp, $clk),
				'impressions_today' => (string) $r['lastreset'] === $today ? (int) $r['dailyimpressions'] : 0,
				'impressions_left'  => (int) $r['maximpressions'] > 0 ? max(0, (int) $r['maximpressions'] - $imp) : null,
				'clicks_left'       => (int) $r['maxclicks'] > 0 ? max(0, (int) $r['maxclicks'] - $clk) : null,
				'counting_since'    => $r['created'],
			];

			$byLoc[$loc] ??= ['banners' => 0, 'impressions' => 0, 'clicks' => 0];
			$byLoc[$loc]['banners']++;
			$byLoc[$loc]['impressions'] += $imp;
			$byLoc[$loc]['clicks']      += $clk;

			$byCli[$cli] ??= ['banners' => 0, 'impressions' => 0, 'clicks' => 0];
			$byCli[$cli]['banners']++;
			$byCli[$cli]['impressions'] += $imp;
			$byCli[$cli]['clicks']      += $clk;

			$total['impressions'] += $imp;
			$total['clicks']      += $clk;
		}

		$sort = (string) ($arguments['sort'] ?? 'id');

		if ($sort === 'impressions' || $sort === 'clicks') {
			usort($banners, static fn (array $a, array $b): int => $b[$sort] <=> $a[$sort]);
		} elseif ($sort === 'ctr') {
			usort($banners, static fn (array $a, array $b): int => ($b['ctr_percent'] ?? -1) <=> ($a['ctr_percent'] ?? -1));
		}

		$roll = static function (array $bucket, array $names) use ($ctr): array {
			$out = [];

			foreach ($bucket as $id => $v) {
				$out[] = ['id' => $id, 'name' => isset($names[$id]) ? (string) $names[$id]['name'] : null] + $v + ['ctr_percent' => $ctr($v['impressions'], $v['clicks'])];
			}

			return $out;
		};

		return ToolResult::json([
			'ok'           => true,
			'totals'       => $total + ['ctr_percent' => $ctr($total['impressions'], $total['clicks']), 'banners' => \count($banners)],
			'by_location'  => $roll($byLoc, $locations),
			'by_client'    => $roll($byCli, $clients),
			'banners'      => $banners,
			'note'         => 'Lifetime counters only; FlexBanners stores no per-day history.',
		]);
	}
}
