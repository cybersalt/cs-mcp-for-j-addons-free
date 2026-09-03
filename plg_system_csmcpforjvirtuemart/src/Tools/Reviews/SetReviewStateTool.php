<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Reviews;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Moderate reviews: `published`, `review_ok`, or both.
 *
 * Targeted UPDATE on one or two tinyints. The interesting part is what it does
 * NOT do: it leaves `#__virtuemart_ratings` alone. That aggregate is what the
 * shop displays as a star rating, and VirtueMart never recomputes it in
 * response to moderation either — recomputing it here would be inventing
 * behaviour the component does not have, and would disagree with whatever the
 * shop's own rating configuration intends. The drift is reported instead.
 */
final class SetReviewStateTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'set_virtuemart_review_state'; }

	public function getDescription(): string
	{
		return 'Moderate one or more product reviews by setting published and/or review_ok on rows in '
			. '#__virtuemart_rating_reviews. Pass id or ids, plus published and/or review_ok. '
			. 'THERE ARE TWO FLAGS AND THEY MEAN DIFFERENT THINGS. `published` is the ordinary published '
			. 'flag. `review_ok` is a separate approval flag that defaults to 0 (install.sql:1011), so a '
			. 'review can be published and still unapproved — that combination is exactly what a '
			. 'moderation queue is. Approving a review usually means setting BOTH to true. '
			. 'This is a targeted UPDATE and touches nothing else. In particular it does NOT recompute '
			. '#__virtuemart_ratings, the per-product aggregate the shop displays as a star rating. '
			. 'VirtueMart does not recompute it on moderation either, so unpublishing a one-star review '
			. 'does not raise the displayed average. That is the component\'s behaviour, not an omission '
			. 'here: recomputing it would invent an operation VirtueMart does not have and would '
			. 'disagree with whatever the shop\'s own rating configuration intends. The response reports '
			. 'the current aggregate alongside the count of published, approved reviews so the drift is '
			. 'visible; list_virtuemart_ratings does the same across the shop. '
			. 'Ids that do not exist cause a refusal rather than a partial batch, so a caller never '
			. 'believes it moderated more than it did.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'        => ['type' => 'integer', 'description' => 'A single virtuemart_rating_review_id.'],
				'ids'       => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Several review ids, for a batch.'],
				'published' => ['type' => 'boolean', 'description' => 'The ordinary published flag.'],
				'review_ok' => ['type' => 'boolean', 'description' => 'The separate approval flag. Approving usually means setting this AND published to true.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->vmAdminBase() === null) {
			return $this->vmNotInstalledError();
		}

		if (!$this->vmTableExists('rating_reviews')) {
			return $this->vmMissingTableError('rating_reviews');
		}

		$ids = [];

		foreach (array_merge([$arguments['id'] ?? null], (array) ($arguments['ids'] ?? [])) as $raw) {
			$id = (int) $raw;

			if ($id > 0 && !\in_array($id, $ids, true)) {
				$ids[] = $id;
			}
		}

		if ($ids === []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Supply id or a non-empty ids array.',
			], true);
		}

		$hasPublished = \array_key_exists('published', $arguments);
		$hasReviewOk  = \array_key_exists('review_ok', $arguments);

		if (!$hasPublished && !$hasReviewOk) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Supply published and/or review_ok. They are separate flags: published is the '
					. 'ordinary state, review_ok is the approval flag that defaults to 0. Approving a '
					. 'review usually means setting both to true.',
			], true);
		}

		$existing = $this->db->setQuery(
			$this->db->getQuery(true)
				->select([
					$this->db->quoteName('virtuemart_rating_review_id'),
					$this->db->quoteName('virtuemart_product_id'),
					$this->db->quoteName('published'),
					$this->db->quoteName('review_ok'),
				])
				->from($this->db->quoteName($this->vmTable('rating_reviews')))
				->whereIn($this->db->quoteName('virtuemart_rating_review_id'), $ids)
		)->loadAssocList('virtuemart_rating_review_id') ?: [];

		$missing = array_values(array_diff($ids, array_map('intval', array_keys($existing))));

		if ($missing !== []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'These review ids do not exist: ' . implode(', ', $missing) . '. Nothing was '
					. 'written — a batch that silently skipped part of its input would leave you believing '
					. 'you had moderated more than you did.',
			], true);
		}

		$published = $hasPublished ? ((bool) $arguments['published'] ? 1 : 0) : null;
		$reviewOk  = $hasReviewOk ? ((bool) $arguments['review_ok'] ? 1 : 0) : null;

		$toChange = [];

		foreach ($existing as $reviewId => $row) {
			$differs = ($published !== null && (int) $row['published'] !== $published)
				|| ($reviewOk !== null && (int) $row['review_ok'] !== $reviewOk);

			if ($differs) {
				$toChange[] = (int) $reviewId;
			}
		}

		if ($toChange === []) {
			return ToolResult::json([
				'ok'        => true,
				'changed'   => [],
				'unchanged' => $ids,
				'note'      => 'Every one of these reviews already has the requested flags. Nothing was '
					. 'written.',
			]);
		}

		$sets = [];

		if ($published !== null) {
			$sets[] = $this->db->quoteName('published') . ' = ' . $published;
		}

		if ($reviewOk !== null) {
			$sets[] = $this->db->quoteName('review_ok') . ' = ' . $reviewOk;
		}

		$now = $this->vmNow();

		$this->db->setQuery(
			'UPDATE ' . $this->db->quoteName($this->vmTable('rating_reviews'))
			. ' SET ' . implode(', ', $sets)
			. ', ' . $this->db->quoteName('modified_on') . ' = ' . $this->db->quote($now)
			. ', ' . $this->db->quoteName('modified_by') . ' = ' . (int) $actor->id
			. ' WHERE ' . $this->db->quoteName('virtuemart_rating_review_id')
			. ' IN (' . implode(', ', $toChange) . ')'
		)->execute();

		$productIds = [];

		foreach ($toChange as $reviewId) {
			$productId = (int) $existing[$reviewId]['virtuemart_product_id'];

			if ($productId > 0 && !\in_array($productId, $productIds, true)) {
				$productIds[] = $productId;
			}
		}

		$response = [
			'ok'          => true,
			'changed'     => $toChange,
			'unchanged'   => array_values(array_diff($ids, $toChange)),
			'published'   => $published,
			'review_ok'   => $reviewOk,
			'modified_on' => $now,
			'aggregate_untouched' => 'The per-product aggregate in #__virtuemart_ratings was NOT changed. '
				. 'VirtueMart does not recompute it on moderation either, so unpublishing a low review '
				. 'does not raise the star rating the shop displays. Recomputing it here would invent an '
				. 'operation the component does not have.',
			'aggregates'  => $this->aggregateComparison($productIds),
		];

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/**
	 * The stored aggregate against the reviews that are actually live.
	 *
	 * @param  array<int,int> $productIds
	 * @return array<int,array<string,mixed>>
	 */
	private function aggregateComparison(array $productIds): array
	{
		$out = [];

		foreach ($productIds as $productId) {
			$live = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select('COUNT(*)')
					->from($this->db->quoteName($this->vmTable('rating_reviews')))
					->where($this->db->quoteName('virtuemart_product_id') . ' = ' . $productId)
					->where($this->db->quoteName('published') . ' = 1')
					->where($this->db->quoteName('review_ok') . ' = 1')
			)->loadResult();

			$entry = [
				'virtuemart_product_id'     => $productId,
				'live_approved_reviews'     => $live,
				'stored_rating'             => null,
				'stored_ratingcount'        => null,
			];

			if ($this->vmTableExists('ratings')) {
				$rating = $this->db->setQuery(
					$this->db->getQuery(true)
						->select([
							$this->db->quoteName('rating'),
							$this->db->quoteName('ratingcount'),
						])
						->from($this->db->quoteName($this->vmTable('ratings')))
						->where($this->db->quoteName('virtuemart_product_id') . ' = ' . $productId)
				)->loadAssoc();

				if (\is_array($rating)) {
					$entry['stored_rating']      = $rating['rating'];
					$entry['stored_ratingcount'] = (int) $rating['ratingcount'];
				}
			}

			$out[] = $entry;
		}

		return $out;
	}
}
