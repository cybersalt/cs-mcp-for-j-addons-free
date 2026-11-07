<?php
/**
 * @package     MCP for Advanced Module Manager
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools\AmmBootTrait;
use Joomla\CMS\User\User;


/**
 * One call that says whether Condition Sets are doing what people think they
 * are doing. Every check is read-only.
 */
final class CheckHealthTool extends AbstractTool
{
	use AmmBootTrait;

	public function getName(): string { return 'check_advancedmodules_health'; }

	public function getDescription(): string
	{
		return 'Check Advanced Module Manager end to end and say what needs attention: the plugin being off, a version older '
			. 'than the 12.1.0 security fix, modules whose Joomla access, menu, language or publishing settings are being ignored because '
			. 'they have no Condition Set (or would be ignored if the plugin were turned on), modules on an unpublished or '
			. 'empty set, sets running PHP, rules this edition skips, rules pointing at deleted menu items or categories, '
			. 'nested sets that loop, attachments to deleted or administrator modules, and unused sets. Read-only.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->ammInstalled()) {
			return $this->ammNotInstalledError();
		}

		$this->ammRequire($actor, 'com_advancedmodules');
		$this->ammRequire($actor, 'com_conditions');

		$db     = $this->db;
		$checks = [];
		$add    = static function (string $severity, string $code, string $message, array $data = []) use (&$checks): void {
			$checks[] = ['severity' => $severity, 'code' => $code, 'message' => $message] + ($data ? ['data' => $data] : []);
		};

		$edition  = $this->ammEdition();
		$versions = $this->ammVersions();
		$pluginOn = $this->ammPluginEnabled();

		// --- Install -----------------------------------------------------------
		if (!$pluginOn) {
			$add('warning', 'plugin_off', 'The System - Advanced Module Manager plugin is off, so Condition Sets decide nothing and Joomla\'s own module settings apply.');
		}

		if ($this->ammExtensionEnabled('plugin', 'regularlabs', 'system') !== true) {
			$add('warning', 'regular_labs_plugin_off', 'The System - Regular Labs Library plugin is off or missing. Advanced Module Manager needs it.');
		}

		if ($this->ammExtensionEnabled('component', 'com_conditions') !== true) {
			$add('error', 'conditions_off', 'The Conditions component is off or missing, so sets can\'t be managed.');
		}

		$bare = preg_replace('/[^0-9.]/', '', $versions['advanced_module_manager']);

		if ($bare !== '' && version_compare($bare, '12.1.0', '<')) {
			$add('error', 'old_version', 'Advanced Module Manager ' . $versions['advanced_module_manager'] . ' is older than 12.1.0, which fixes CVE-2026-85188 (Condition Set labels reaching unrelated database fields) and stops failed saves leaving partial changes. Update it.');
		}

		// --- Sets --------------------------------------------------------------
		$setIds = $db->setQuery('SELECT id FROM ' . $db->quoteName('#__conditions') . ' WHERE published <> -2')->loadColumn() ?: [];
		$sets   = $this->ammLoadSets($setIds);
		$usage  = $this->ammUsage($setIds);

		$php = $ignored = $empty = $unused = $dead = $loops = $disabled = [];
		$disabledTypes = $this->ammDisabledRuleTypes();

		foreach ($sets as $id => $set) {
			$modules   = $usage[$id]['modules'] ?? [];
			$ruleCount = 0;

			foreach ($set->groups as $group) {
				foreach ($group->rules as $rule) {
					$ruleCount++;

					if ($rule->type === 'other__php') {
						$php[$id] = $set->name;
					}

					if ($this->ammRuleIgnoredHere($rule)) {
						$ignored[$id] = ['set' => (int) $id, 'name' => $set->name, 'type' => $rule->type];
					}

					if (\in_array($rule->type, $disabledTypes, true)) {
						$disabled[$id] = ['set' => (int) $id, 'name' => $set->name, 'type' => $rule->type];
					}

					foreach ($this->ammDeadReferences($rule) as $reference) {
						$dead[] = ['set' => (int) $id, 'name' => $set->name] + $reference;
					}
				}
			}

			if ($ruleCount === 0 && $modules !== []) {
				$empty[] = ['set' => (int) $id, 'name' => $set->name, 'modules' => array_column($modules, 'id')];
			}

			if ($modules === [] && ($usage[$id]['other_extensions'] ?? []) === []) {
				$unused[] = ['set' => (int) $id, 'name' => $set->name];
			}

			if ($this->ammSetLoops((int) $id)) {
				$loops[] = ['set' => (int) $id, 'name' => $set->name, 'modules' => array_column($modules, 'id')];
			}
		}

		if ($php !== []) {
			$restricted = (bool) ($this->ammComponentParams('com_conditions')->php_security ?? true);

			$add($restricted ? 'info' : 'warning', 'php_rules', \count($php) . ' Condition Set(s) run PHP code on the server. These tools never show, run or write that code and refuse to attach or publish these sets.'
				. ($restricted ? '' : ' The Conditions option that restricts who can save PHP rules is off.'), ['sets' => $php]);
		}

		if ($ignored !== []) {
			$add('warning', 'rules_skipped_on_free', \count($ignored) . ' Condition Set(s) hold Pro rule types, and this site runs the Free edition, which skips them. A set holding only those shows its modules on every page.', ['sets' => array_values($ignored)]);
		}

		if ($disabled !== []) {
			$add('warning', 'rule_types_disabled', \count($disabled) . ' Condition Set(s) use a rule type switched off in the Conditions options, so that rule always passes.', ['sets' => array_values($disabled)]);
		}

		if ($empty !== []) {
			$add('warning', 'empty_sets', \count($empty) . ' attached Condition Set(s) have no rules, so their modules show on every page.', ['sets' => $empty]);
		}

		if ($dead !== []) {
			$add('warning', 'dead_references', \count($dead) . ' rule value(s) point at menu items, categories or sets that no longer exist.', ['rules' => \array_slice($dead, 0, 100)]);
		}

		if ($loops !== []) {
			$add('error', 'set_loops', \count($loops) . ' Condition Set(s) include themselves through another set. The vendor fails the whole check on a loop, so their modules are hidden everywhere.', ['sets' => $loops]);
		}

		if ($unused !== []) {
			$add('info', 'unused_sets', \count($unused) . ' Condition Set(s) are attached to nothing.', ['sets' => \array_slice($unused, 0, 100)]);
		}

		// --- Attachments -------------------------------------------------------
		$rows = $db->setQuery(
			'SELECT map.item_id, map.condition_id, m.id AS module_id, m.title, m.client_id, c.id AS set_id, c.published AS set_state, c.name AS set_name'
			. ' FROM ' . $db->quoteName('#__conditions_map', 'map')
			. ' LEFT JOIN ' . $db->quoteName('#__modules', 'm') . ' ON m.id = map.item_id'
			. ' LEFT JOIN ' . $db->quoteName('#__conditions', 'c') . ' ON c.id = map.condition_id'
			. ' WHERE map.extension = ' . $db->quote('com_advancedmodules')
		)->loadObjectList() ?: [];

		$noModule = $noSet = $unpublished = $admin = [];

		foreach ($rows as $row) {
			if ($row->module_id === null) {
				$noModule[] = (int) $row->item_id;
			} elseif ((int) $row->client_id !== 0) {
				$admin[] = (int) $row->item_id;
			}

			if ($row->set_id === null) {
				$noSet[] = ['module' => (int) $row->item_id, 'set' => (int) $row->condition_id];
			} elseif ((int) $row->set_state !== 1 && $row->module_id !== null) {
				$unpublished[] = ['module' => (int) $row->item_id, 'title' => $row->title, 'set' => (int) $row->set_id, 'set_name' => $row->set_name, 'set_state' => $this->ammStateLabel((int) $row->set_state)];
			}
		}

		if ($unpublished !== []) {
			$add('warning', 'modules_on_unpublished_sets', \count($unpublished) . ' module(s) are attached to a set that is not published, so they show on every page.', ['modules' => \array_slice($unpublished, 0, 100)]);
		}

		if ($noModule !== []) {
			$add('info', 'attached_to_deleted_modules', \count($noModule) . ' attachment(s) point at modules that no longer exist.', ['module_ids' => \array_slice($noModule, 0, 100)]);
		}

		if ($noSet !== []) {
			$add('warning', 'attached_to_deleted_sets', \count($noSet) . ' module(s) are attached to a set that no longer exists, so they show on every page.', ['attachments' => \array_slice($noSet, 0, 100)]);
		}

		if ($admin !== []) {
			$add('info', 'administrator_modules', \count($admin) . ' administrator module(s) have a Condition Set, which does nothing there.', ['module_ids' => \array_slice($admin, 0, 100)]);
		}

		// --- Core settings being ignored ---------------------------------------
		$plan = $db->setQuery(
			'SELECT ' . self::AMM_MODULE_SELECT . $this->ammModuleFrom() . ' WHERE m.client_id = 0 AND m.published = 1 AND map.condition_id IS NULL'
		)->loadObjectList('id') ?: [];

		$assignments = $this->ammCoreAssignments(array_keys($plan));
		$levels      = $this->ammPublicLevels();
		$limited     = [];

		foreach ($plan as $id => $module) {
			$limits = $this->ammCoreLimits($module, $assignments[$id], $levels);

			if ($limits !== []) {
				$limited[] = ['id' => (int) $id, 'title' => $module->title, 'position' => $module->position, 'limits' => $limits];
			}
		}

		if ($limited !== []) {
			$add(
				$pluginOn ? 'error' : 'warning',
				$pluginOn ? 'core_limits_ignored' : 'core_limits_would_be_ignored',
				\count($limited) . ' published module(s) rely on Joomla\'s own access, menu, language or publishing settings and have no Condition Set. '
					. ($pluginOn ? 'The plugin is on, so those settings are being ignored and these modules show on every page, to every visitor.' : 'If the plugin is turned on, those settings will be ignored and these modules will show on every page, to every visitor.')
					. ' get_advancedmodules_core_conversion_preview works out the sets that would keep them as they are.',
				['modules' => \array_slice($limited, 0, 200)]
			);
		}

		$order = ['error' => 0, 'warning' => 1, 'info' => 2];
		usort($checks, static fn($a, $b) => $order[$a['severity']] <=> $order[$b['severity']]);

		return ToolResult::json([
			'ok'             => true,
			'edition'        => $edition,
			'versions'       => $versions,
			'plugin_enabled' => $pluginOn,
			'summary'        => [
				'errors'   => \count(array_filter($checks, static fn($c) => $c['severity'] === 'error')),
				'warnings' => \count(array_filter($checks, static fn($c) => $c['severity'] === 'warning')),
				'sets'     => \count($sets),
			],
			'checks' => $checks,
		]);
	}
}
