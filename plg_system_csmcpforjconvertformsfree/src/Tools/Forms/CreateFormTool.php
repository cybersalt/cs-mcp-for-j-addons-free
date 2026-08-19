<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Forms;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\FormFieldTrait;
use Joomla\CMS\User\User;

final class CreateFormTool extends AbstractTool
{
	use ConvertFormsBootTrait;
	use FormFieldTrait;

	public function getName(): string { return 'create_convertforms_form'; }

	public function getDescription(): string
	{
		return 'Create a Convert Forms form. Supply "fields" as an ordered LIST of '
			. 'field definitions (this tool converts it to the keyed map Convert '
			. 'Forms stores) — a submit field is appended automatically if you omit '
			. 'one, otherwise the form cannot be submitted. Sensible behaviour '
			. 'defaults are applied: store submissions, show a success message, '
			. 'honeypot on. Saves through ConvertFormsModelForm so per-field '
			. 'validation hooks run. Call list_convertforms_field_types first to '
			. 'confirm which types this install supports.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['name', 'fields'],
			'properties' => [
				'name'  => ['type' => 'string', 'description' => 'Form name (admin-facing).'],
				'state' => [
					'type'        => 'integer',
					'enum'        => [0, 1, 2, -2],
					'description' => 'Default 1 (published). The DB column defaults to 0, so this tool sets it explicitly.',
				],
				'fields' => [
					'type'        => 'array',
					'description' => 'Ordered list of fields. Each needs "type"; input fields also take name, label, description, required, placeholder, value, size, cssclass. Choice types (dropdown/radio/checkbox) need "choices": a list of {label, value} objects.',
					'items'       => ['type' => 'object'],
				],
				'success_message' => ['type' => 'string', 'description' => 'Shown after submit when onsuccess=msg. Default a generic thank-you.'],
				'success_url'     => ['type' => 'string', 'description' => 'Redirect here instead of showing a message (sets onsuccess=url).'],
				'store_submissions' => ['type' => 'boolean', 'description' => 'Save submissions to the database. Default true.'],
				'params' => [
					'type'        => 'object',
					'description' => 'Any additional raw params keys (design, phpscripts, etc.) merged over the defaults.',
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

		$name = $this->requireString($arguments, 'name');

		$definitions = $arguments['fields'] ?? null;
		if (!is_array($definitions) || $definitions === []) {
			return ToolResult::error('"fields" must be a non-empty list of field definitions.');
		}

		// Guarantee a submit button — a form without one renders but cannot be
		// submitted, and that failure is invisible until someone tries to use it.
		$hasSubmit = false;
		foreach ($definitions as $definition) {
			if (is_array($definition) && strtolower((string) ($definition['type'] ?? '')) === 'submit') {
				$hasSubmit = true;
				break;
			}
		}
		$appendedSubmit = false;
		if (!$hasSubmit) {
			$definitions[]  = ['type' => 'submit', 'text' => 'Submit', 'size' => 'cf-width-auto'];
			$appendedSubmit = true;
		}

		try {
			$fields = $this->cfBuildFieldMap($definitions);
		} catch (\InvalidArgumentException $e) {
			return ToolResult::error($e->getMessage());
		}

		$params = [
			'fields'            => $fields,
			'save_data_to_db'   => (!array_key_exists('store_submissions', $arguments) || $arguments['store_submissions']) ? '1' : '0',
			'submission_state'  => '1',
			'onsuccess'         => 'msg',
			'successmsg'        => (string) ($arguments['success_message'] ?? 'Thanks for getting in touch! We will be back to you shortly.'),
			'resetform'         => '1',
			'hideform'          => '1',
			'honeypot'          => '1',
		];

		if (!empty($arguments['success_url'])) {
			$params['onsuccess']  = 'url';
			$params['successurl'] = (string) $arguments['success_url'];
		}

		// Legacy campaigns: only meaningful on sites upgraded from 2.x/4.x, where
		// submission processing reads params.campaign. Point at the Demo Campaign
		// the installer seeds so those sites behave; skip entirely on clean 5.x.
		if ($this->cfLegacyCampaignsEnabled()) {
			$params['campaign'] = '1';
		}

		if (!empty($arguments['params']) && is_array($arguments['params'])) {
			$params = $this->cfMergeParams($params, $arguments['params']);
			// Never let a raw params patch clobber the built field map.
			$params['fields'] = $fields;
		}

		$data = [
			'id'       => 0,
			'name'     => $name,
			'state'    => array_key_exists('state', $arguments) ? (int) $arguments['state'] : 1,
			'ordering' => 0,
		];

		$out = $this->cfSaveForm($data, $params);

		if ($out['id'] <= 0) {
			return ToolResult::error('Form create failed: ' . ($out['error'] ?: 'no id returned'));
		}

		$saved = $this->cfFetchForm($out['id']);

		return ToolResult::json([
			'ok'             => true,
			'id'             => $out['id'],
			'name'           => $name,
			'state'          => $saved['state'] ?? null,
			'field_count'    => $saved === null ? count($fields) : count($this->cfFields($saved['params'])),
			'fields'         => $saved === null ? $fields : $this->cfFields($saved['params']),
			'appended_submit_field' => $appendedSubmit,
			'save_warnings'  => $out['error'] ?: null,
			'next_steps'     => 'Publish the form on a page with the {convertforms ' . $out['id'] . '} content plugin tag, '
				. 'a Convert Forms module, or the menu item type. Add an email notification with create_convertforms_task.',
		]);
	}
}
