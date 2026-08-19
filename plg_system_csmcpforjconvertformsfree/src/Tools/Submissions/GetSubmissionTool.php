<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Submissions;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\SubmissionTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

final class GetSubmissionTool extends AbstractTool
{
	use ConvertFormsBootTrait;
	use SubmissionTrait;

	public function getName(): string { return 'get_convertforms_submission'; }

	public function getDescription(): string
	{
		return 'Get one Convert Forms submission with its answers resolved against '
			. 'the form definition: each answer carries the field label and type, '
			. 'fields left blank appear with a null value, and values submitted '
			. 'under names the form no longer has are listed separately rather than '
			. 'dropped. Also returns the submitter (Joomla user if logged in) and '
			. 'any analytics metadata the install records.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id' => ['type' => 'integer', 'description' => 'Submission (conversion) id.'],
				'include_meta' => [
					'type'        => 'boolean',
					'description' => 'Include #__convertforms_submission_meta rows (addon data such as generated PDF paths). Default false.',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->cfAdminBase() === null) {
			return $this->notInstalledError();
		}

		if (!$this->cfTableExists('conversions')) {
			return ToolResult::error('#__convertforms_conversions does not exist — the Convert Forms install looks incomplete.');
		}

		$id = $this->requirePositiveInt($arguments, 'id');

		$columns   = $this->cfExistingColumns('conversions', self::SUBMISSION_COLUMNS);
		$select    = $columns;
		$select[]  = 'params';

		$q = $this->db->getQuery(true)
			->select(array_map([$this->db, 'quoteName'], $select))
			->from($this->db->quoteName($this->cfTableName('conversions')))
			->where($this->db->quoteName('id') . ' = ' . $id);

		$row = $this->db->setQuery($q)->loadAssoc();

		if (!$row) {
			return ToolResult::error('Convert Forms submission ' . $id . ' not found.');
		}

		$params = $this->cfDecodeParams($row['params']);
		unset($row['params']);

		$formId = (int) ($row['form_id'] ?? 0);
		$form   = $this->cfFetchForm($formId);
		$lookup = $form === null ? [] : $this->cfFieldLookup($form['params']);

		$shaped = $this->cfShapeAnswers($params, $lookup);
		$out    = $this->cfShapeSubmissionRow($row);

		$out = array_merge(['ok' => true], $out);

		$out['form_name'] = $form['name'] ?? null;

		if ($form === null) {
			$out['warning'] = 'Form ' . $formId . ' no longer exists, so these values could not be '
				. 'matched to field labels. The raw submitted values are under unmapped_values.';
		}

		$out['answers']     = $shaped['answers'];
		$out['admin_notes'] = $shaped['notes'];

		if ($shaped['unmapped'] !== []) {
			$out['unmapped_values'] = $shaped['unmapped'];
			$out['unmapped_note']   = 'Submitted under field names the form no longer defines — '
				. 'typically a field that was renamed or deleted after this submission was made.';
		}

		$userId = (int) ($out['user_id'] ?? 0);
		if ($userId > 0) {
			$user = Factory::getUser($userId);
			$out['user'] = $user->id
				? ['id' => (int) $user->id, 'name' => $user->name, 'username' => $user->username, 'email' => $user->email]
				: null;
		} else {
			$out['user'] = null;
		}

		if (!empty($arguments['include_meta'])) {
			$out['meta'] = $this->meta($id);
		}

		return ToolResult::json($out);
	}

	/**
	 * Rows from #__convertforms_submission_meta. This is addon metadata (the PDF
	 * plugin stores generated file paths here) — never the submitted field
	 * values, which live entirely in the conversion's own params blob.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function meta(int $submissionId): array
	{
		if (!$this->cfTableExists('submission_meta')) {
			return [];
		}

		$q = $this->db->getQuery(true)
			->select(['id', 'meta_type', 'meta_key', 'meta_value', 'params', 'date_created', 'date_modified'])
			->from($this->db->quoteName($this->cfTableName('submission_meta')))
			->where($this->db->quoteName('submission_id') . ' = ' . $submissionId)
			->order($this->db->quoteName('id') . ' ASC');

		$rows = $this->db->setQuery($q)->loadAssocList() ?: [];

		foreach ($rows as &$row) {
			$row['id']     = (int) $row['id'];
			$row['params'] = $this->cfDecodeParams($row['params']);
		}

		return $rows;
	}
}
