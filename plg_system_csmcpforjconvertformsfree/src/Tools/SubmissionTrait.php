<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools;

\defined('_JEXEC') or die;

/**
 * Shared shaping for `#__convertforms_conversions` rows.
 *
 * A submission row is mostly metadata; the answers themselves are a flat JSON
 * object in `params`, keyed by each field's `name`:
 *
 *     {"email":"a@b.com","fullName":"Ada","briefDescription":"hello"}
 *
 * Two things make that blob awkward to read straight:
 *
 *   - Convert Forms writes the keys verbatim but reads them back through
 *     array_change_key_case(), so a form with a field named "fullName" yields
 *     submissions whose key case cannot be relied on. Matching here is
 *     case-insensitive in both directions.
 *
 *   - Fields submitted empty are omitted entirely rather than stored as "",
 *     so a key's absence means "left blank", not "field did not exist".
 *     cfShapeAnswers() re-inserts the form's known fields with a null value so
 *     the caller sees the full question set instead of a ragged object.
 *
 * There is also one non-field key: `leadnotes`, the admin note attached from
 * the submission edit screen. It is separated out rather than presented as if
 * a visitor had typed it.
 */
trait SubmissionTrait
{
	/** Params key holding admin-entered notes rather than submitted data. */
	protected const LEAD_NOTES_KEY = 'leadnotes';

	/**
	 * Metadata columns worth returning, in a sensible order. Intersected with
	 * the real table columns by cfExistingColumns() before use — the last nine
	 * only exist from Convert Forms 5.2.0.
	 */
	protected const SUBMISSION_COLUMNS = [
		'id', 'form_id', 'campaign_id', 'state', 'created', 'modified',
		'user_id', 'visitor_id', 'ip', 'country_code', 'user_agent',
		'page_title', 'source_url', 'referrer_url', 'device', 'os', 'browser',
	];

	/**
	 * Build a name => {label, type} lookup for a form, used to give submitted
	 * values their human labels.
	 *
	 * @param array<string, mixed> $formParams Decoded form params.
	 * @return array<string, array{name: string, label: string, type: string}>
	 *         keyed by LOWERCASED field name.
	 */
	protected function cfFieldLookup(array $formParams): array
	{
		$lookup  = [];
		$noInput = $this->cfNoInputFieldTypes();

		foreach ($this->cfFields($formParams) as $field) {
			$name = trim((string) ($field['name'] ?? ''));
			$type = strtolower((string) ($field['type'] ?? ''));

			if ($name === '' || in_array($type, $noInput, true)) {
				continue;
			}

			$lookup[strtolower($name)] = [
				'name'  => $name,
				'label' => trim((string) ($field['label'] ?? '')) ?: $name,
				'type'  => $type,
			];
		}

		return $lookup;
	}

	/**
	 * Turn a submission's raw params blob into an ordered, labelled answer list.
	 *
	 * @param array<string, mixed> $submissionParams
	 * @param array<string, array{name: string, label: string, type: string}> $lookup
	 * @return array{answers: array<int, array<string, mixed>>, notes: ?string, unmapped: array<string, mixed>}
	 */
	protected function cfShapeAnswers(array $submissionParams, array $lookup): array
	{
		// Index the submitted values case-insensitively.
		$submitted = [];
		foreach ($submissionParams as $key => $value) {
			$submitted[strtolower((string) $key)] = ['key' => (string) $key, 'value' => $value];
		}

		$notes = null;
		if (isset($submitted[self::LEAD_NOTES_KEY])) {
			$raw   = $submitted[self::LEAD_NOTES_KEY]['value'];
			$notes = is_scalar($raw) ? (string) $raw : json_encode($raw);
			unset($submitted[self::LEAD_NOTES_KEY]);
		}

		$answers = [];

		// Walk the FORM's fields so the order matches the form and blank answers
		// are visible as nulls rather than missing keys.
		foreach ($lookup as $lower => $meta) {
			$has   = array_key_exists($lower, $submitted);
			$value = $has ? $submitted[$lower]['value'] : null;

			$answers[] = [
				'name'    => $meta['name'],
				'label'   => $meta['label'],
				'type'    => $meta['type'],
				'value'   => $value,
				'answered' => $has,
			];

			unset($submitted[$lower]);
		}

		// Anything left over was submitted under a name the form no longer has —
		// a renamed or deleted field. Surfacing it separately keeps the data
		// visible instead of quietly dropping it.
		$unmapped = [];
		foreach ($submitted as $entry) {
			$unmapped[$entry['key']] = $entry['value'];
		}

		return ['answers' => $answers, 'notes' => $notes, 'unmapped' => $unmapped];
	}

	/**
	 * Cast the integer-ish columns of a submission row and add a state label.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	protected function cfShapeSubmissionRow(array $row): array
	{
		foreach (['id', 'form_id', 'campaign_id', 'state', 'user_id'] as $key) {
			if (array_key_exists($key, $row)) {
				$row[$key] = $row[$key] === null ? null : (int) $row[$key];
			}
		}

		if (array_key_exists('state', $row)) {
			$row['state_label'] = $this->contentStateLabel((int) $row['state']);
		}

		return $row;
	}

	/**
	 * A compact one-line preview of a submission's answers, for list views.
	 *
	 * @param array<int, array<string, mixed>> $answers
	 */
	protected function cfAnswerSummary(array $answers, int $max = 3): string
	{
		$parts = [];

		foreach ($answers as $answer) {
			if (count($parts) >= $max) {
				break;
			}
			if (!$answer['answered']) {
				continue;
			}

			$value = $answer['value'];
			$value = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
			$value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

			if ($value === '') {
				continue;
			}
			if (mb_strlen($value) > 60) {
				$value = mb_substr($value, 0, 57) . '…';
			}

			$parts[] = $answer['label'] . ': ' . $value;
		}

		return implode(' | ', $parts);
	}
}
