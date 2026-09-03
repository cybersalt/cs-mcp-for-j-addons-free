<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Reviews;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * The per-product rating aggregate, cross-checked against the votes it claims
 * to summarise.
 *
 * This is a drift detector. `#__virtuemart_ratings` holds the star rating a
 * shopper sees, and it is maintained only at vote time — nothing recomputes it
 * when a vote or review is moderated or deleted. So the stored aggregate and
 * the live votes diverge quietly over the life of a shop.
 */
final class ListRatingsTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'list_virtuemart_ratings'; }

	public function getDescription(): string
	{
		return 'List the per-product rating aggregates in #__virtuemart_ratings — the star rating a '
			. 'shopper actually sees — and cross-check each one against the individual votes in '
			. '#__virtuemart_rating_votes that it claims to summarise. '
			. 'THIS IS A DRIFT DETECTOR. The aggregate is maintained at vote time and nothing recomputes '
			. 'it afterwards: moderating a review, unpublishing one or deleting a vote all leave it '
			. 'unchanged. Over the life of a shop, stored_rating and the average of the live votes '
			. 'separate, and the shop keeps displaying the stored figure. Rows where they disagree are '
			. 'flagged with drift: true and both numbers are shown. '
			. 'The three tables are related only by convention, with no foreign keys: '
			. '#__virtuemart_ratings (this aggregate, one row per product), #__virtuemart_rating_votes '
			. '(each individual score) and #__virtuemart_rating_reviews (the text, optionally linked to a '
			. 'vote). Any of the three can exist without the others. '
			. 'Filters: product_id, published, drift_only (show only the products where the aggregate '
			. 'disagrees with the votes), min_rating / max_rating. '
			. 'There is no setter. Writing a rating aggregate by hand would put a number in front of '
			. 'shoppers that no vote supports, and would be overwritten the next time anyone rated the '
			. 'product. If the drift matters, the right fix is in the shop, not in this table.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'product_id' => ['type' => 'integer'],
				'published'  => ['type' => 'boolean'],
				'drift_only' => ['type' => 'boolean', 'description' => 'Only products where the stored aggregate disagrees with the live votes.'],
				'min_rating' => ['type' => 'number'],
				'max_rating' => ['type' => 'number'],
				'limit'      => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'     => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'read'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->vmAdminBase() === null) {
			return $this->vmNotInstalledError();
		}

		if (!$this->vmTableExists('ratings')) {
			return $this->vmMissingTableError('ratings');
		}

		$limit  = $this->vmLimit($arguments);
		$offset = $this->vmOffset($arguments);
		$table  = $this->db->quoteName($this->vmTable('ratings'));

		$where = [];

		if (\array_key_exists('product_id', $arguments)) {
			$where[] = $this->db->quoteName('virtuemart_product_id') . ' = ' . (int) $arguments['product_id'];
		}

		if (\array_key_exists('published', $arguments)) {
			$where[] = $this->db->quoteName('published') . ' = ' . ((bool) $arguments['published'] ? 1 : 0);
		}

		if (\array_key_exists('min_rating', $arguments)) {
			$where[] = $this->db->quoteName('rating') . ' >= ' . (float) $arguments['min_rating'];
		}

		if (\array_key_exists('max_rating', $arguments)) {
			$where[] = $this->db->quoteName('rating') . ' <= ' . (float) $arguments['max_rating'];
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		$total = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $table . $whereSql)->loadResult();

		$rows = $this->db->setQuery(
			'SELECT * FROM ' . $table . $whereSql
			. ' ORDER BY ' . $this->db->quoteName('virtuemart_product_id') . ' ASC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$productIds = array_map(static fn (array $r): int => (int) $r['virtuemart_product_id'], $rows);
		$voteStats  = $this->voteStats($productIds);
		$names      = $this->productNames($productIds);
		$driftOnly  = (bool) ($arguments['drift_only'] ?? false);

		$out       = [];
		$driftRows = 0;

		foreach ($rows as $row) {
			$productId = (int) $row['virtuemart_product_id'];
			$stats     = $voteStats[$productId] ?? ['count' => 0, 'sum' => 0, 'average' => null];

			$storedRating = (float) $row['rating'];
			$storedCount  = (int) $row['ratingcount'];

			// The aggregate is decimal(10,1), so compare at that precision
			// rather than exactly — a difference in the third decimal place is
			// rounding, not drift.
			$drift = $storedCount !== $stats['count']
				|| ($stats['average'] !== null && abs($storedRating - $stats['average']) >= 0.05);

			if ($drift) {
				$driftRows++;
			}

			if ($driftOnly && !$drift) {
				continue;
			}

			$entry = [
				'virtuemart_rating_id'  => (int) $row['virtuemart_rating_id'],
				'virtuemart_product_id' => $productId,
				'product_name'          => $names[$productId] ?? null,
				'stored_rating'         => $row['rating'],
				'stored_ratingcount'    => $storedCount,
				'stored_rates'          => (int) $row['rates'],
				'published'             => (int) $row['published'] === 1,
				'live_votes'            => $stats['count'],
				'live_average'          => $stats['average'],
				'drift'                 => $drift,
			];

			if ($drift) {
				$entry['drift_detail'] = 'The stored aggregate says ' . $storedCount . ' vote(s) '
					. 'averaging ' . $row['rating'] . ', but #__virtuemart_rating_votes holds '
					. $stats['count'] . ' vote(s)'
					. ($stats['average'] === null ? '' : ' averaging ' . round($stats['average'], 2))
					. '. VirtueMart maintains the aggregate at vote time only and never recomputes it '
					. 'when a vote or review is moderated or deleted, so this is what a shop that has '
					. 'moderated its reviews looks like. The stored figure is what shoppers see.';
			}

			$out[] = $entry;
		}

		return ToolResult::json([
			'ok'             => true,
			'total'          => $total,
			'limit'          => $limit,
			'offset'         => $offset,
			'showing'        => \count($out),
			'drift_in_page'  => $driftRows,
			'ratings'        => $out,
			'relationship_note' => '#__virtuemart_ratings (one aggregate per product), '
				. '#__virtuemart_rating_votes (individual scores) and #__virtuemart_rating_reviews (the '
				. 'text) are related by convention only — VirtueMart declares no foreign keys anywhere — '
				. 'so any of the three can exist without the others.',
			'no_setter_note' => 'There is deliberately no tool to write these aggregates. A hand-written '
				. 'figure would put a rating in front of shoppers that no vote supports, and would be '
				. 'overwritten the next time anyone rated the product.',
			'component'      => $this->vmComponentNotice(),
		]);
	}

	/**
	 * @param  array<int,int> $ids
	 * @return array<int,array{count:int,sum:int,average:?float}>
	 */
	private function voteStats(array $ids): array
	{
		if ($ids === [] || !$this->vmTableExists('rating_votes')) {
			return [];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('virtuemart_product_id'),
					'COUNT(*) AS ' . $this->db->quoteName('total'),
					'SUM(' . $this->db->quoteName('vote') . ') AS ' . $this->db->quoteName('total_vote'),
				])
				->from($this->db->quoteName($this->vmTable('rating_votes')))
				->whereIn($this->db->quoteName('virtuemart_product_id'), $ids)
				->group($this->db->quoteName('virtuemart_product_id'))
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$count = (int) $row['total'];
			$sum   = (int) $row['total_vote'];

			$out[(int) $row['virtuemart_product_id']] = [
				'count'   => $count,
				'sum'     => $sum,
				'average' => $count > 0 ? $sum / $count : null,
			];
		}

		return $out;
	}

	/**
	 * @param  array<int,int> $ids
	 * @return array<int,string>
	 */
	private function productNames(array $ids): array
	{
		$tag = $this->vmDefaultLangTag();

		if ($ids === [] || !$this->vmLangTableExists('products', $tag)) {
			return [];
		}

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('virtuemart_product_id'),
					$this->db->quoteName('product_name'),
				])
				->from($this->db->quoteName($this->vmLangTable('products', $tag)))
				->whereIn($this->db->quoteName('virtuemart_product_id'), $ids)
		)->loadAssocList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$out[(int) $row['virtuemart_product_id']] = (string) $row['product_name'];
		}

		return $out;
	}
}
