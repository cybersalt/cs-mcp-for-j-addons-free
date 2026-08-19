<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\Forms;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjconvertformsfree\Tools\ConvertFormsBootTrait;
use Joomla\CMS\User\User;

final class GetFormTool extends AbstractTool
{
	use ConvertFormsBootTrait;

	public function getName(): string { return 'get_convertforms_form'; }

	public function getDescription(): string
	{
		return 'Get one Convert Forms form in full: the row columns plus the decoded '
			. 'params blob, split into fields / submission behaviour / design / PHP '
			. 'scripts so you do not have to reason about one giant JSON object. '
			. 'Almost the entire form definition lives in params — only id, name, '
			. 'state, created and ordering are real columns.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id' => ['type' => 'integer', 'description' => 'Form id.'],
				'include_design' => [
					'type'        => 'boolean',
					'description' => 'Include the ~45 styling keys. Default false — they are rarely relevant and very verbose.',
				],
				'include_raw_params' => [
					'type'        => 'boolean',
					'description' => 'Also return the complete decoded params blob untouched. Default false.',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	/**
	 * Params keys that belong to the Design tab. Everything not in here and not
	 * a known behaviour key is surfaced under "other" rather than dropped, so a
	 * key added by a future Convert Forms release never goes invisible.
	 */
	private const DESIGN_KEYS = [
		'autowidth', 'width', 'bgcolor', 'bgimage', 'bgurl', 'bgfile', 'bgrepeat',
		'bgsize', 'bgposition', 'text', 'font', 'padding', 'borderradius',
		'borderstyle', 'bordercolor', 'borderwidth', 'image', 'imageurl',
		'imagefile', 'imgposition', 'imageautowidth', 'imagewidth', 'imagesize',
		'imagehposition', 'imagevposition', 'imagealt', 'hideimageonmobile',
		'formposition', 'formsize', 'formbgcolor', 'labelscolor', 'labelsfontsize',
		'labelweight', 'labelposition', 'required_indication', 'inputfontsize',
		'inputcolor', 'inputbg', 'inputalign', 'inputbordercolor',
		'inputborderradius', 'inputvpadding', 'inputhpadding',
		'help_text_position', 'customcss', 'customcode', 'footer', 'classsuffix',
	];

	private const BEHAVIOUR_KEYS = [
		'save_data_to_db', 'submission_state', 'campaign', 'onsuccess', 'successmsg',
		'resetform', 'hideform', 'hidetext', 'successurl', 'redirectmenu',
		'passdata', 'honeypot',
	];

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->cfAdminBase() === null) {
			return $this->notInstalledError();
		}

		$id   = $this->requirePositiveInt($arguments, 'id');
		$form = $this->cfFetchForm($id);

		if ($form === null) {
			return ToolResult::error('Convert Forms form ' . $id . ' not found.');
		}

		$params = $form['params'];
		$fields = $this->cfFields($params);

		$behaviour = [];
		foreach (self::BEHAVIOUR_KEYS as $key) {
			if (array_key_exists($key, $params)) {
				$behaviour[$key] = $params[$key];
			}
		}

		$design = [];
		foreach (self::DESIGN_KEYS as $key) {
			if (array_key_exists($key, $params)) {
				$design[$key] = $params[$key];
			}
		}

		$known = array_merge(self::DESIGN_KEYS, self::BEHAVIOUR_KEYS, ['fields', 'phpscripts']);
		$other = [];
		foreach ($params as $key => $value) {
			if (!in_array((string) $key, $known, true)) {
				$other[$key] = $value;
			}
		}

		$out = [
			'ok'          => true,
			'id'          => $form['id'],
			'name'        => $form['name'],
			'state'       => $form['state'],
			'state_label' => $this->contentStateLabel($form['state']),
			'created'     => $form['created'],
			'ordering'    => $form['ordering'],
			'field_count' => count($fields),
			'fields'      => $fields,
			'behaviour'   => $behaviour,
			'phpscripts'  => $params['phpscripts'] ?? null,
			'other_params' => $other,
			'submission_count' => $this->submissionCount($id),
		];

		if (!empty($arguments['include_design'])) {
			$out['design'] = $design;
		} else {
			$out['design_omitted'] = count($design) . ' styling keys omitted; pass include_design=true for them.';
		}

		if (!empty($arguments['include_raw_params'])) {
			$out['raw_params'] = $params;
		}

		$out['notes'] = [
			'fields is an ORDERED MAP keyed "fields<key>", not a list. Display order '
				. 'is the map order. Each entry\'s "key" property matches its map key.',
			'Submitted values are stored against each field\'s "name", so renaming a '
				. 'field orphans the data already collected under the old name.',
		];

		return ToolResult::json($out);
	}

	private function submissionCount(int $formId): int
	{
		if (!$this->cfTableExists('conversions')) {
			return 0;
		}

		$q = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName($this->cfTableName('conversions')))
			->where($this->db->quoteName('form_id') . ' = ' . $formId);

		return (int) $this->db->setQuery($q)->loadResult();
	}
}
