<?php
/**
 * @package     MCP for Community Builder
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Tabs;

\defined('_JEXEC') or die;

/**
 * Tab metadata and validation, from CB's own tab form
 * (`xmlcb/views/view.com_comprofiler.edittab.xml`) and its position and
 * display-type lists (`xmlcb/models/model.tabs.xml`).
 */
trait TabSupportTrait
{
	private static ?array $cbTabTypesCache = null;

	/** @return array{position:string[],displaytype:string[]} */
	protected function cbTabChoices(): array
	{
		if (self::$cbTabTypesCache !== null) {
			return self::$cbTabTypesCache;
		}

		$out  = ['position' => [], 'displaytype' => []];
		$file = JPATH_ADMINISTRATOR . '/components/com_comprofiler/xmlcb/models/model.tabs.xml';
		$xml  = is_file($file) ? @simplexml_load_file($file) : false;

		if ($xml !== false) {
			foreach (['position' => 'tab_position', 'displaytype' => 'tab_displaytype'] as $k => $type) {
				foreach ($xml->xpath('//types/type[@name="' . $type . '"]//option') ?: [] as $opt) {
					$out[$k][] = (string) $opt['value'];
				}
			}
		}

		return self::$cbTabTypesCache = $out;
	}

	protected function cbTabSummary(array $t, bool $translate, array $fieldCounts): array
	{
		return [
			'tabid'           => (int) $t['tabid'],
			'title'           => $translate ? $this->cbText((string) $t['title']) : $t['title'],
			'title_raw'       => $t['title'],
			'enabled'         => (int) $t['enabled'] === 1,
			'position'        => $t['position'],
			'displaytype'     => $t['displaytype'],
			'viewaccesslevel' => (int) $t['viewaccesslevel'],
			'ordering'        => (int) $t['ordering'],
			'ordering_edit'   => (int) $t['ordering_edit'],
			'ordering_register' => (int) $t['ordering_register'],
			'system'          => (int) $t['sys'] === 1,
			'plugin_tab'      => $t['pluginclass'] !== null && (string) $t['pluginclass'] !== '' ? ['class' => $t['pluginclass'], 'plugin_id' => (int) $t['pluginid']] : null,
			'holds_fields'    => (int) $t['fields'] === 1,
			'field_count'     => (int) ($fieldCounts[(int) $t['tabid']] ?? 0),
			'cssclass'        => $t['cssclass'],
		];
	}

	/** Validate tab property arguments into TabTable column => value. */
	protected function cbTabProps(array $arguments): array
	{
		$out     = [];
		$choices = $this->cbTabChoices();

		if (\array_key_exists('title', $arguments)) {
			$title = trim((string) $arguments['title']);

			if ($title === '') {
				throw new \InvalidArgumentException('title cannot be empty.');
			}

			$this->cbRequireFits('title', $title, 50);
			$out['title'] = $title;
		}

		if (\array_key_exists('description', $arguments)) {
			$out['description'] = (string) $arguments['description'];
		}

		if (\array_key_exists('enabled', $arguments)) {
			$out['enabled'] = $this->cbBool($arguments, 'enabled') ? 1 : 0;
		}

		foreach (['position', 'displaytype'] as $k) {
			if (!\array_key_exists($k, $arguments)) {
				continue;
			}

			$v = (string) $arguments[$k];

			if ($choices[$k] !== [] && !\in_array($v, $choices[$k], true)) {
				throw new \InvalidArgumentException($k . ' "' . $v . '" is not one CB offers: ' . implode(', ', $choices[$k]) . '.');
			}

			$this->cbRequireFits($k, $v, $k === 'position' ? 200 : 255);
			$out[$k] = $v;
		}

		if (\array_key_exists('viewaccesslevel', $arguments)) {
			$lvl    = (int) $arguments['viewaccesslevel'];
			$exists = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->db->quoteName('#__viewlevels') . ' WHERE id = ' . $lvl)->loadResult();

			if ($exists === 0) {
				throw new \InvalidArgumentException('viewaccesslevel ' . $lvl . ' is not a Joomla view access level.');
			}

			$out['viewaccesslevel'] = $lvl;
		}

		if (\array_key_exists('cssclass', $arguments)) {
			$this->cbRequireFits('cssclass', (string) $arguments['cssclass'], 255);
			$out['cssclass'] = (string) $arguments['cssclass'];
		}

		return $out;
	}

	protected function cbFieldCountsByTab(): array
	{
		$rows = $this->db->setQuery(
			'SELECT tabid, COUNT(*) n FROM ' . $this->db->quoteName('#__comprofiler_fields') . ' GROUP BY tabid'
		)->loadAssocList('tabid') ?: [];

		return array_map(static fn (array $r): int => (int) $r['n'], $rows);
	}

	protected function cbFindTab(int $tabId): ?array
	{
		$row = $this->db->setQuery(
			'SELECT * FROM ' . $this->db->quoteName('#__comprofiler_tabs') . ' WHERE tabid = ' . $tabId
		)->loadAssoc();

		return $row ?: null;
	}
}
