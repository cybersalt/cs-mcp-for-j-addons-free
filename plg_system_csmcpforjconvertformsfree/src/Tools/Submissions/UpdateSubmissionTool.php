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

final class UpdateSubmissionTool extends AbstractTool
{
	use ConvertFormsBootTrait;
	use SubmissionTrait;

	public function getName(): string { return 'update_convertforms_submission'; }

	public function getDescription(): string
	{
		return 'Correct the stored values of a Convert Forms submission, and/or set '
			. 'the admin notes on it. Values are addressed by field name as defined '
			. 'on the form; a name the form does not define is rejected unless you '
			. 'pass allow_unknown_fields=true. Send null to clear a value. This edits '
			. 'stored data only — it does not re-run notifications or Tasks.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'     => ['type' => 'integer', 'description' => 'Submission (conversion) id.'],
				'values' => [
					'type'        => 'object',
					'description' => 'Field name => new value, e.g. {"email":"fixed@example.com"}. null clears the value.',
				],
				'admin_notes' => [
					'type'        => 'string',
					'description' => 'Replace the submission\'s admin notes (stored as params.leadnotes).',
				],
				'allow_unknown_fields' => [
					'type'        => 'boolean',
					'description' => 'Permit writing value keys the form does not define. Default false.',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ensureCfLoaded()) {
			return $this->notInstalledError();
		}

		if (!$this->cfTableExists('conversions')) {
			return ToolResult::error('#__convertforms_conversions does not exist — the Convert Forms install looks incomplete.');
		}

		$id = $this->requirePositiveInt($arguments, 'id');

		$values = $arguments['values'] ?? null;
		$values = is_array($values) ? $values : [];
		$hasNotes = array_key_exists('admin_notes', $arguments);

		if ($values === [] && !$hasNotes) {
			return ToolResult::error('Nothing to do — supply "values" and/or "admin_notes".');
		}

		$table = $this->cfTableName('conversions');

		$row = $this->db->setQuery(
			$this->db->getQuery(true)
				->select(['id', 'form_id', 'params'])
				->from($this->db->quoteName($table))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadAssoc();

		if (!$row) {
			return ToolResult::error('Convert Forms submission ' . $id . ' not found.');
		}

		$formId  = (int) $row['form_id'];
		$params  = $this->cfDecodeParams($row['params']);
		$form    = $this->cfFetchForm($formId);
		$lookup  = $form === null ? [] : $this->cfFieldLookup($form['params']);

		// Reject unknown field names by default: a typo would otherwise write a
		// key nothing reads, and it would look like it worked.
		if (!empty($values) && empty($arguments['allow_unknown_fields'])) {
			$unknown = [];
			foreach (array_keys($values) as $name) {
				if (!isset($lookup[strtolower((string) $name)])) {
					$unknown[] = (string) $name;
				}
			}

			if ($unknown !== []) {
				return ToolResult::error(
					'Form ' . $formId . ' has no field named: ' . implode(', ', $unknown) . '. '
					. ($lookup === []
						? 'The form could not be loaded, so no names could be validated.'
						: 'Valid names: ' . implode(', ', array_column($lookup, 'name')) . '.')
					. ' Pass allow_unknown_fields=true to write them anyway.'
				);
			}
		}

		// Match existing keys case-insensitively so we overwrite rather than
		// duplicate when the stored casing differs from the form's.
		$existingByLower = [];
		foreach ($params as $key => $unused) {
			$existingByLower[strtolower((string) $key)] = (string) $key;
		}

		$applied = [];
		foreach ($values as $name => $value) {
			$lower    = strtolower((string) $name);
			$storeKey = $existingByLower[$lower]
				?? ($lookup[$lower]['name'] ?? (string) $name);

			if ($value === null) {
				unset($params[$storeKey]);
				$applied[$storeKey] = null;
				continue;
			}

			$params[$storeKey]  = $value;
			$applied[$storeKey] = $value;
		}

		if ($hasNotes) {
			$notes = (string) $arguments['admin_notes'];
			if (trim($notes) === '') {
				unset($params[self::LEAD_NOTES_KEY]);
			} else {
				$params[self::LEAD_NOTES_KEY] = $notes;
			}
		}

		$encoded = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($encoded === false) {
			return ToolResult::error('Could not encode the updated submission values as JSON.');
		}

		// Written directly rather than through the Conversion model: that model's
		// save path re-runs field validation and the whole front-end submission
		// pipeline (notifications, Tasks, PHP scripts), which is wrong for an
		// after-the-fact correction. `modified` is stamped here because
		// ConvertFormsTableConversion::check() would only do so via that path.
		$this->db->setQuery(
			$this->db->getQuery(true)
				->update($this->db->quoteName($table))
				->set($this->db->quoteName('params') . ' = ' . $this->db->quote($encoded))
				->set($this->db->quoteName('modified') . ' = ' . $this->db->quote(Factory::getDate()->toSql()))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->execute();

		$shaped = $this->cfShapeAnswers($params, $lookup);

		return ToolResult::json([
			'ok'             => true,
			'id'             => $id,
			'form_id'        => $formId,
			'values_changed' => array_keys($applied),
			'notes_changed'  => $hasNotes,
			'answers'        => $shaped['answers'],
			'admin_notes'    => $shaped['notes'],
			'note'           => 'Stored data only — email notifications and Tasks were not re-run.',
		]);
	}
}
