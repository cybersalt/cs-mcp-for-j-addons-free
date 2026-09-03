<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Reviews;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * List product reviews awaiting or past moderation.
 *
 * Reviews live in three tables that are related only by convention:
 * `rating_reviews` (the text), `rating_votes` (individual scores) and `ratings`
 * (the per-product aggregate). Nothing recomputes the aggregate when a review
 * is unpublished, so moderation and displayed star ratings drift apart unless
 * something puts them back in step.
 */
final class ListReviewsTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'list_virtuemart_reviews'; }

	public function getDescription(): string
	{
		return 'List product reviews from #__virtuemart_rating_reviews, with the reviewer and the product '
			. 'name resolved. '
			. 'MODERATION USES TWO COLUMNS, NOT ONE. `published` is the ordinary published flag. '
			. '`review_ok` is a separate approval flag that defaults to 0 (install.sql:1011), so a review '
			. 'can be published and still not approved. Both are reported, and pending_moderation is '
			. 'computed as published = 1 AND review_ok = 0 — the state a shop\'s moderation queue is '
			. 'actually made of. '
			. 'Filters: product_id, published, review_ok, pending_only, created_by, search on the comment '
			. 'text, min_rating / max_rating. Supports limit (default 50, max 200) and offset. '
			. 'lastip is the reviewer\'s IP address and IS returned — it is the only spam signal in the '
			. 'table — but it is personal data under GDPR, so treat the response accordingly. '
			. 'review_rating on this table is the rating attached to the review row itself. The '
			. 'per-product aggregate lives separately in #__virtuemart_ratings, and NOTHING recomputes it '
			. 'when a review is unpublished or deleted. list_virtuemart_ratings cross-checks the two and '
			. 'reports where they have drifted. '
			. 'VirtueMart 4.8.0 shipped "rating system security improvements". If this shop is on an '
			. 'earlier 4.x, the review submission path is worth reviewing independently of this add-on.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'product_id'   => ['type' => 'integer'],
				'published'    => ['type' => 'boolean'],
				'review_ok'    => ['type' => 'boolean', 'description' => 'The separate approval flag, which defaults to 0.'],
				'pending_only' => ['type' => 'boolean', 'description' => 'Only published-but-unapproved reviews: the real moderation queue.'],
				'created_by'   => ['type' => 'integer', 'description' => 'Joomla user id of the reviewer.'],
				'search'       => ['type' => 'string', 'description' => 'Substring match on the comment text.'],
				'min_rating'   => ['type' => 'number'],
				'max_rating'   => ['type' => 'number'],
				'limit'        => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'       => ['type' => 'integer'],
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

		if (!$this->vmTableExists('rating_reviews')) {
			return $this->vmMissingTableError('rating_reviews');
		}

		$limit  = $this->vmLimit($arguments);
		$offset = $this->vmOffset($arguments);
		$table  = $this->db->quoteName($this->vmTable('rating_reviews'));

		$where = [];

		if (\array_key_exists('product_id', $arguments)) {
			$where[] = $this->db->quoteName('virtuemart_product_id') . ' = ' . (int) $arguments['product_id'];
		}

		if (\array_key_exists('published', $arguments)) {
			$where[] = $this->db->quoteName('published') . ' = ' . ((bool) $arguments['published'] ? 1 : 0);
		}

		if (\array_key_exists('review_ok', $arguments)) {
			$where[] = $this->db->quoteName('review_ok') . ' = ' . ((bool) $arguments['review_ok'] ? 1 : 0);
		}

		if ((bool) ($arguments['pending_only'] ?? false)) {
			$where[] = $this->db->quoteName('published') . ' = 1';
			$where[] = $this->db->quoteName('review_ok') . ' = 0';
		}

		if (\array_key_exists('created_by', $arguments)) {
			$where[] = $this->db->quoteName('created_by') . ' = ' . (int) $arguments['created_by'];
		}

		if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
			$where[] = $this->db->quoteName('comment') . ' LIKE '
				. $this->db->quote('%' . $this->db->escape($search, true) . '%', false);
		}

		if (\array_key_exists('min_rating', $arguments)) {
			$where[] = $this->db->quoteName('review_rating') . ' >= ' . (float) $arguments['min_rating'];
		}

		if (\array_key_exists('max_rating', $arguments)) {
			$where[] = $this->db->quoteName('review_rating') . ' <= ' . (float) $arguments['max_rating'];
		}

		$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

		$total = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $table . $whereSql)->loadResult();

		$rows = $this->db->setQuery(
			'SELECT * FROM ' . $table . $whereSql
			. ' ORDER BY ' . $this->db->quoteName('created_on') . ' DESC, '
			. $this->db->quoteName('virtuemart_rating_review_id') . ' DESC',
			$offset,
			$limit
		)->loadAssocList() ?: [];

		$productIds   = array_values(array_unique(array_map(
			static fn (array $r): int => (int) $r['virtuemart_product_id'],
			$rows
		)));
		$productNames = $this->productNames($productIds);

		$out     = [];
		$pending = 0;

		foreach ($rows as $row) {
			$productId = (int) $row['virtuemart_product_id'];
			$isPending = (int) $row['published'] === 1 && (int) $row['review_ok'] === 0;

			if ($isPending) {
				$pending++;
			}

			$entry = [
				'virtuemart_rating_review_id' => (int) $row['virtuemart_rating_review_id'],
				'virtuemart_product_id'       => $productId,
				'product_name'                => $productNames[$productId] ?? null,
				'customer'                    => (string) $row['customer'],
				'created_by'                  => (int) $row['created_by'],
				'comment'                     => (string) $row['comment'],
				'review_rating'               => $row['review_rating'],
				'review_rates'                => (int) $row['review_rates'],
				'review_ratingcount'          => (int) $row['review_ratingcount'],
				'review_language'             => (string) $row['review_language'],
				'review_editable'             => (int) $row['review_editable'] === 1,
				'published'                   => (int) $row['published'] === 1,
				'review_ok'                   => (int) $row['review_ok'] === 1,
				'pending_moderation'          => $isPending,
				'lastip'                      => (string) $row['lastip'],
				'created_on'                  => $row['created_on'],
				'modified_on'                 => $row['modified_on'],
			];

			if (!isset($productNames[$productId]) && $productId > 0) {
				$entry['product_missing'] = 'virtuemart_product_id ' . $productId . ' has no product row '
					. '(or no row in the shop default language). This review is attached to a product '
					. 'that no longer exists or is invisible.';
			}

			$out[] = $entry;
		}

		return ToolResult::json([
			'ok'                 => true,
			'total'              => $total,
			'limit'              => $limit,
			'offset'             => $offset,
			'showing'            => \count($out),
			'pending_in_page'    => $pending,
			'reviews'            => $out,
			'moderation_note'    => 'Moderation is two columns. `published` is the ordinary flag; '
				. '`review_ok` is a separate approval flag defaulting to 0 (install.sql:1011). A review '
				. 'that is published but not review_ok is what a moderation queue is made of. '
				. 'set_virtuemart_review_state writes either or both.',
			'aggregate_note'     => 'The per-product star rating shown in the shop lives in '
				. '#__virtuemart_ratings, a separate table that nothing recomputes when a review is '
				. 'unpublished or deleted. list_virtuemart_ratings cross-checks them.',
			'pii_note'           => 'lastip is the reviewer\'s IP address. It is the only spam signal '
				. 'this table carries and it is personal data under GDPR.',
			'component'          => $this->vmComponentNotice(),
		]);
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
