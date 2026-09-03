<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\Reviews;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjvirtuemart\Tools\VirtuemartBootTrait;
use Joomla\CMS\User\User;

/**
 * Delete reviews, and optionally the votes they were attached to.
 *
 * The review row and the vote row are separate records joined by
 * `virtuemart_rating_vote_id` with nothing enforcing it, so deleting one
 * without the other is a real choice rather than an oversight: removing an
 * abusive comment while keeping the numeric score is often exactly what a shop
 * wants.
 */
final class DeleteReviewTool extends AbstractTool
{
	use VirtuemartBootTrait;

	public function getName(): string { return 'delete_virtuemart_review'; }

	public function getDescription(): string
	{
		return 'Permanently delete one or more product reviews from #__virtuemart_rating_reviews. Pass id '
			. 'or ids. '
			. 'THE VOTE IS A SEPARATE RECORD. A review row references a #__virtuemart_rating_votes row by '
			. 'virtuemart_rating_vote_id, with no foreign key behind it. Deleting the review leaves the '
			. 'numeric vote in place by default, which is usually what a shop wants when removing an '
			. 'abusive comment: the score still counts, the text is gone. Pass delete_vote: true to '
			. 'remove the score as well. '
			. 'THE AGGREGATE IS NOT RECOMPUTED. #__virtuemart_ratings holds the per-product star rating '
			. 'the shop displays, and VirtueMart does not recompute it when a review or vote is removed. '
			. 'Deleting a one-star review therefore does not raise the displayed average. The current '
			. 'stored aggregate is reported alongside the live counts so the drift is visible. '
			. 'THIS IS IRREVERSIBLE. VirtueMart has no trash state for reviews. Consider '
			. 'set_virtuemart_review_state with published: false instead — an unpublished review is '
			. 'invisible to shoppers, keeps the evidence, and can be restored. That matters when the '
			. 'review is the subject of a dispute. '
			. 'Ids that do not exist cause a refusal rather than a partial batch.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'          => ['type' => 'integer', 'description' => 'A single virtuemart_rating_review_id.'],
				'ids'         => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Several review ids.'],
				'delete_vote' => ['type' => 'boolean', 'description' => 'Also delete the linked #__virtuemart_rating_votes row, removing the numeric score. Default false.'],
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

		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($this->vmTable('rating_reviews')))
				->whereIn($this->db->quoteName('virtuemart_rating_review_id'), $ids)
		)->loadAssocList('virtuemart_rating_review_id') ?: [];

		$missing = array_values(array_diff($ids, array_map('intval', array_keys($rows))));

		if ($missing !== []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'These review ids do not exist: ' . implode(', ', $missing) . '. Nothing was '
					. 'deleted.',
			], true);
		}

		$deleteVote = (bool) ($arguments['delete_vote'] ?? false);

		$voteIds    = [];
		$productIds = [];

		foreach ($rows as $row) {
			$voteId = (int) ($row['virtuemart_rating_vote_id'] ?? 0);

			if ($voteId > 0 && !\in_array($voteId, $voteIds, true)) {
				$voteIds[] = $voteId;
			}

			$productId = (int) $row['virtuemart_product_id'];

			if ($productId > 0 && !\in_array($productId, $productIds, true)) {
				$productIds[] = $productId;
			}
		}

		$this->db->setQuery(
			$this->db->getQuery(true)
				->delete($this->db->quoteName($this->vmTable('rating_reviews')))
				->whereIn($this->db->quoteName('virtuemart_rating_review_id'), $ids)
		)->execute();

		$deletedReviews = $this->db->getAffectedRows();
		$deletedVotes   = 0;

		if ($deleteVote && $voteIds !== [] && $this->vmTableExists('rating_votes')) {
			$this->db->setQuery(
				$this->db->getQuery(true)
					->delete($this->db->quoteName($this->vmTable('rating_votes')))
					->whereIn($this->db->quoteName('virtuemart_rating_vote_id'), $voteIds)
			)->execute();

			$deletedVotes = $this->db->getAffectedRows();
		}

		$response = [
			'ok'               => true,
			'deleted_reviews'  => $deletedReviews,
			'deleted_ids'      => $ids,
			'deleted_votes'    => $deletedVotes,
			'irreversible'     => 'VirtueMart has no trash state for reviews. These rows are gone. '
				. 'set_virtuemart_review_state with published: false is the reversible alternative and '
				. 'keeps the evidence, which matters when a review is the subject of a dispute.',
			'aggregate_untouched' => 'The per-product aggregate in #__virtuemart_ratings was NOT '
				. 'recomputed. VirtueMart does not recompute it on deletion either, so the displayed star '
				. 'rating still includes these reviews. The figures below show the drift.',
			'aggregates'       => $this->aggregateComparison($productIds),
		];

		if (!$deleteVote && $voteIds !== []) {
			$response['votes_kept'] = $voteIds;
			$response['votes_kept_note'] = 'The linked #__virtuemart_rating_votes rows were left in '
				. 'place, so the numeric scores still count towards the product\'s rating even though the '
				. 'comment text is gone. Pass delete_vote: true to remove them as well.';
		}

		$response['component'] = $this->vmComponentNotice();

		return ToolResult::json($response);
	}

	/**
	 * @param  array<int,int> $productIds
	 * @return array<int,array<string,mixed>>
	 */
	private function aggregateComparison(array $productIds): array
	{
		$out = [];

		foreach ($productIds as $productId) {
			$entry = ['virtuemart_product_id' => $productId];

			$entry['remaining_reviews'] = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select('COUNT(*)')
					->from($this->db->quoteName($this->vmTable('rating_reviews')))
					->where($this->db->quoteName('virtuemart_product_id') . ' = ' . $productId)
			)->loadResult();

			if ($this->vmTableExists('rating_votes')) {
				$entry['remaining_votes'] = (int) $this->db->setQuery(
					$this->db->getQuery(true)
						->select('COUNT(*)')
						->from($this->db->quoteName($this->vmTable('rating_votes')))
						->where($this->db->quoteName('virtuemart_product_id') . ' = ' . $productId)
				)->loadResult();
			}

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

				$entry['stored_rating']      = \is_array($rating) ? $rating['rating'] : null;
				$entry['stored_ratingcount'] = \is_array($rating) ? (int) $rating['ratingcount'] : null;
			}

			$out[] = $entry;
		}

		return $out;
	}
}
