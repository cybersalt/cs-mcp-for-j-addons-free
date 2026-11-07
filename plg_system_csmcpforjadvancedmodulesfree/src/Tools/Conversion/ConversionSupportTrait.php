<?php
/**
 * @package     MCP for Advanced Module Manager
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Conversion;

\defined('_JEXEC') or die;

/**
 * Works out the sets that reproduce core's assignment, access and language.
 *
 * The vendor's Helper\ConvertAssignments isn't reused: it migrates the old
 * `assignto_*` params from Joomla 3 installs, not #__modules_menu, and it can
 * write PHP rules from those params.
 */
trait ConversionSupportTrait
{
	/**
	 * @param  int[]|null  $moduleIds  null for every published front-end module without a set.
	 *
	 * @return array{proposals: array, no_rules_needed: array, needs_a_decision: array, skipped: array}
	 */
	protected function ammConversionPlan(?array $moduleIds): array
	{
		$db = $this->db;

		if ($moduleIds === null) {
			$modules = $db->setQuery(
				'SELECT ' . self::AMM_MODULE_SELECT . $this->ammModuleFrom() . ' WHERE m.client_id = 0 AND m.published = 1 AND map.condition_id IS NULL ORDER BY m.position, m.ordering, m.id'
			)->loadObjectList('id') ?: [];
			$skipped = [];
		} else {
			$modules = $this->ammModules($moduleIds);
			$skipped = [];

			foreach ($moduleIds as $id) {
				if (!isset($modules[$id])) {
					$skipped[] = ['id' => $id, 'why' => 'no such module'];
				}
			}

			foreach ($modules as $id => $module) {
				if ((int) $module->client_id !== 0) {
					$skipped[] = ['id' => (int) $id, 'title' => $module->title, 'why' => 'administrator module'];
					unset($modules[$id]);
				} elseif ($module->condition_id) {
					$skipped[] = ['id' => (int) $id, 'title' => $module->title, 'why' => 'already has Condition Set ' . (int) $module->condition_id];
					unset($modules[$id]);
				}
			}
		}

		$assignments = $this->ammCoreAssignments(array_keys($modules));
		$levels      = $this->ammPublicLevels();
		$existing    = $this->ammPublishedFingerprints();
		$proposals   = [];
		$noRules     = [];
		$decide      = [];

		foreach ($modules as $id => $module) {
			$assignment = $assignments[$id];
			$rules      = [];
			$dropped    = [];

			if ($this->ammTitleHasHtml((string) $module->title)) {
				$decide[] = ['id' => (int) $id, 'title' => strip_tags((string) $module->title), 'position' => $module->position,
					'why' => 'Its title holds HTML, which these tools don\'t accept. Fix the title, then convert it.'];
				continue;
			}

			if ($assignment['mode'] === 'include' || $assignment['mode'] === 'exclude') {
				$live    = array_keys($this->ammLookup('menu_ids', $assignment['menu_item_ids']));
				$dropped = array_values(array_diff($assignment['menu_item_ids'], $live));

				if ($live === [] && $assignment['mode'] === 'include') {
					$assignment['mode'] = 'none';
				} elseif ($live !== []) {
					$rules[] = $this->ammBuildRule([
						'type'    => 'menu__menu_item',
						'exclude' => $assignment['mode'] === 'exclude',
						'params'  => ['selection' => array_map('strval', $live), 'include_children' => '0'],
					]);
				}
			}

			if ($assignment['mode'] === 'none') {
				$decide[] = ['id' => (int) $id, 'title' => $module->title, 'position' => $module->position,
					'why' => 'Joomla has it assigned to no pages, which a Condition Set can\'t say. With the plugin on it shows on every page. Unpublish it, or give it the pages it should have.'];
				continue;
			}

			if (!\in_array((int) $module->access, $levels, true)) {
				$rules[] = $this->ammBuildRule(['type' => 'visitor__access_level', 'params' => ['selection' => [(string) (int) $module->access]]]);
			}

			if (($module->language ?? '*') !== '*' && $this->ammLanguageFilterOn()) {
				$rules[] = $this->ammBuildRule(['type' => 'visitor__language', 'params' => ['selection' => [(string) $module->language]]]);
			}

			[$from, $to] = $this->ammPublishWindow($module);

			if ($from !== null && $to !== null) {
				$rules[] = $this->ammBuildRule(['type' => 'date__date', 'params' => ['comparison' => 'between', 'from' => $from, 'to' => $to]]);
			} elseif ($from !== null) {
				$rules[] = $this->ammBuildRule(['type' => 'date__date', 'params' => ['comparison' => 'after', 'date' => $from]]);
			} elseif ($to !== null) {
				$rules[] = $this->ammBuildRule(['type' => 'date__date', 'params' => ['comparison' => 'before', 'date' => $to]]);
			}

			if ($rules === []) {
				$noRules[] = ['id' => (int) $id, 'title' => $module->title];
				continue;
			}

			foreach ($rules as $i => $rule) {
				$rule->ordering = $i;
			}

			$probe = (object) ['match_all' => 1, 'groups' => [(object) ['match_all' => 1, 'rules' => $rules]]];
			$key   = $this->ammRulesFingerprint($probe);

			$proposals[$key] ??= [
				'rules'        => $rules,
				'summary'      => $this->ammSetSummary($probe),
				'modules'      => [],
				'reuse_set_id' => $existing[$key] ?? null,
			];

			$entry = ['id' => (int) $id, 'title' => $module->title, 'position' => $module->position];

			if ($dropped !== []) {
				$entry['deleted_menu_items_left_out'] = $dropped;
			}

			$proposals[$key]['modules'][] = $entry;
		}

		foreach ($proposals as $key => $proposal) {
			if ($proposal['reuse_set_id'] === null) {
				unset($proposals[$key]['reuse_set_id']);
			}

			$first = (string) $proposal['modules'][0]['title'];
			$more  = \count($proposal['modules']) - 1;

			$proposals[$key]['name'] = mb_substr($first, 0, 80) . ($more > 0 ? ' + ' . $more . ' more' : '');
		}

		return ['proposals' => array_values($proposals), 'no_rules_needed' => $noRules, 'needs_a_decision' => $decide, 'skipped' => $skipped];
	}

	/** The plan without the rule objects, for showing to a person. */
	protected function ammConversionReport(array $plan): array
	{
		return [
			'sets_to_create_or_reuse' => array_map(static fn(array $p) => [
				'name'         => $p['name'],
				'means'        => $p['summary'],
				'reuse_set_id' => $p['reuse_set_id'] ?? null,
				'modules'      => $p['modules'],
			], $plan['proposals']),
			'no_rules_needed'  => $plan['no_rules_needed'],
			'needs_a_decision' => $plan['needs_a_decision'],
			'skipped'          => $plan['skipped'],
		];
	}

	/** Published sets that don't run PHP, by rules fingerprint. */
	private function ammPublishedFingerprints(): array
	{
		$ids = $this->db->setQuery('SELECT id FROM ' . $this->db->quoteName('#__conditions') . ' WHERE published = 1')->loadColumn() ?: [];
		$out = [];

		foreach ($this->ammLoadSets($ids) as $id => $set) {
			if ($set->groups !== [] && !$this->ammSetRunsPhp((int) $id)) {
				$out[$this->ammRulesFingerprint($set)] ??= (int) $id;
			}
		}

		return $out;
	}
}
