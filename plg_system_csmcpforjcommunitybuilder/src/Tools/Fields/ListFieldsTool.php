<?php
/**
 * @package     MCP for Community Builder
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

final class ListFieldsTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use FieldSupportTrait;

	public function getName(): string { return 'list_cb_fields'; }

	public function getDescription(): string
	{
		return 'List Community Builder profile fields (Components -> Community Builder -> Field Management): name '
			. '(the column name, cb_ prefixed for custom fields), title (translated, plus the raw language key), '
			. 'type, tab, the database columns it owns and where (#__comprofiler or #__users), and the display '
			. 'switches (published, required, read-only, searchable; registration / edit / profile display: 0 = '
			. 'hidden, 1 = 1 line with title, 2 = 2 lines with title, 3 = 1 line empty title, 4 = 1 line no '
			. 'title). System fields (name, username, email, password, avatar...) are included unless '
			. 'custom_only. For select/radio/checkbox fields, option_count says how many options exist. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'tab_id'      => ['type' => 'integer'],
				'type'        => ['type' => 'string', 'description' => 'Only this field type, e.g. "select".'],
				'published'   => ['type' => 'boolean'],
				'custom_only' => ['type' => 'boolean', 'description' => 'Exclude CB system fields. Default false.'],
				'search'      => ['type' => 'string', 'description' => 'Matches name or title.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cbInstalled()) {
			return $this->cbNotInstalledError();
		}

		$this->cbRequireAccess($actor, 'core.manage', 'com_comprofiler.fields');

		$translate = true;

		try {
			$this->cbRun($actor, static fn () => null);
		} catch (\Throwable $e) {
			$translate = false;
		}

		$tabs   = $this->db->setQuery('SELECT tabid, title FROM ' . $this->db->quoteName('#__comprofiler_tabs'))->loadAssocList('tabid') ?: [];
		$counts = $this->db->setQuery('SELECT fieldid, COUNT(*) n FROM ' . $this->db->quoteName('#__comprofiler_field_values') . ' GROUP BY fieldid')->loadAssocList('fieldid') ?: [];
		$search = mb_strtolower(trim((string) ($arguments['search'] ?? '')));

		$out = [];

		foreach ($this->cbFieldRows() as $f) {
			if (isset($arguments['tab_id']) && (int) $f['tabid'] !== (int) $arguments['tab_id']) {
				continue;
			}

			if (isset($arguments['type']) && (string) $f['type'] !== (string) $arguments['type']) {
				continue;
			}

			if (\array_key_exists('published', $arguments) && ((int) $f['published'] === 1) !== $this->cbBool($arguments, 'published')) {
				continue;
			}

			if ($this->cbBool($arguments, 'custom_only') && (int) $f['sys'] === 1) {
				continue;
			}

			$summary = $this->cbFieldSummary($f, $translate);

			if ($search !== '' && !str_contains(mb_strtolower($f['name'] . ' ' . $summary['title'] . ' ' . $f['title']), $search)) {
				continue;
			}

			$tabTitle            = isset($tabs[(int) $f['tabid']]) ? (string) $tabs[(int) $f['tabid']]['title'] : null;
			$summary['tab']      = $tabTitle === null ? '(missing tab)' : ($translate ? $this->cbText($tabTitle) : $tabTitle);

			if (\in_array((string) $f['type'], self::CB_OPTION_TYPES, true)) {
				$summary['option_count'] = (int) ($counts[$f['fieldid']]['n'] ?? 0);
			}

			$out[] = $summary;
		}

		return ToolResult::json(['ok' => true, 'count' => \count($out), 'fields' => $out]);
	}
}
