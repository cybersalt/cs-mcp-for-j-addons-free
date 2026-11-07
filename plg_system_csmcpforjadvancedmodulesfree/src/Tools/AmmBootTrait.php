<?php
/**
 * @package     MCP for Advanced Module Manager
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjadvancedmodulesfree\Tools;

\defined('_JEXEC') or die;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use RegularLabs\Component\Conditions\Administrator\Helper\Cache as ConditionsCache;
use RegularLabs\Component\Conditions\Administrator\Model\ItemModel as ConditionsItemModel;

/**
 * Shared orientation and safety rails for every Advanced Module Manager tool.
 * The facts behind them are on the plugin class.
 */
trait AmmBootTrait
{
	use AmmRuleCatalogTrait;

	/** `extension` value in #__conditions_map for a module. */
	private const AMM_EXTENSION = 'com_advancedmodules';

	public function getCategory(): string
	{
		return 'advancedmodules';
	}

	// -----------------------------------------------------------------------
	// Presence, edition, state
	// -----------------------------------------------------------------------

	protected function ammInstalled(): bool
	{
		if (!is_dir(JPATH_ADMINISTRATOR . '/components/com_advancedmodules') || !is_dir(JPATH_ADMINISTRATOR . '/components/com_conditions')) {
			return false;
		}

		$tables = $this->db->getTableList();
		$prefix = $this->db->getPrefix();

		foreach (['advancedmodules', 'conditions', 'conditions_groups', 'conditions_rules', 'conditions_map'] as $table) {
			if (!\in_array($prefix . $table, $tables, true)) {
				return false;
			}
		}

		return true;
	}

	protected function ammNotInstalledError(): \Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult
	{
		return \Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult::json([
			'ok'    => false,
			'error' => 'Advanced Module Manager (Regular Labs) is not installed on this site. Expected com_advancedmodules, com_conditions and their tables.',
		], true);
	}

	/** Version from a manifest on disk; the extensions table can lag behind a failed update. */
	protected function ammManifestVersion(string $path): string
	{
		if (!is_file($path)) {
			return '';
		}

		$xml = @simplexml_load_file($path);

		return $xml ? trim((string) $xml->version) : '';
	}

	protected function ammVersions(): array
	{
		return [
			'advanced_module_manager' => $this->ammManifestVersion(JPATH_ADMINISTRATOR . '/components/com_advancedmodules/advancedmodules.xml'),
			'conditions'              => $this->ammManifestVersion(JPATH_ADMINISTRATOR . '/components/com_conditions/conditions.xml'),
			'system_plugin'           => $this->ammManifestVersion(JPATH_PLUGINS . '/system/advancedmodules/advancedmodules.xml'),
		];
	}

	/**
	 * 'pro' or 'free'. Pro needs the manifest's PRO suffix and Pro's own form
	 * fields on disk, so editing a version string doesn't change the answer.
	 */
	protected function ammEdition(): string
	{
		$version = $this->ammVersions()['advanced_module_manager'];
		$form    = JPATH_ADMINISTRATOR . '/components/com_advancedmodules/forms/extra.xml';

		return str_ends_with(strtoupper($version), 'PRO') && is_file($form) && str_contains((string) file_get_contents($form), 'name="extra1"')
			? 'pro'
			: 'free';
	}

	protected function ammPluginEnabled(): bool
	{
		return $this->ammExtensionEnabled('plugin', 'advancedmodules', 'system') === true;
	}

	/** null when the extension isn't installed. */
	protected function ammExtensionEnabled(string $type, string $element, string $folder = ''): ?bool
	{
		$db    = $this->db;
		$query = 'SELECT ' . $db->quoteName('enabled') . ' FROM ' . $db->quoteName('#__extensions')
			. ' WHERE ' . $db->quoteName('type') . ' = ' . $db->quote($type)
			. ' AND ' . $db->quoteName('element') . ' = ' . $db->quote($element)
			. ($folder !== '' ? ' AND ' . $db->quoteName('folder') . ' = ' . $db->quote($folder) : '');

		$value = $db->setQuery($query)->loadResult();

		return $value === null ? null : (int) $value === 1;
	}

	/** Goes on every write's response: a saved change that can't show must say so. */
	protected function ammEffectNotes(): array
	{
		if ($this->ammPluginEnabled()) {
			return ['plugin_enabled' => true];
		}

		return [
			'plugin_enabled' => false,
			'warning'        => 'The System - Advanced Module Manager plugin is off, so Condition Sets decide nothing right now and this change will not show on the site. Joomla\'s own module access and menu assignment apply until the plugin is on.',
		];
	}

	// -----------------------------------------------------------------------
	// Permissions: the vendor's own, with no fallback
	// -----------------------------------------------------------------------

	/** @throws \RuntimeException */
	protected function ammRequire(User $actor, string $component, string ...$actions): void
	{
		if ($actor->guest || (int) $actor->id === 0) {
			throw new \RuntimeException('Advanced Module Manager tools require an authenticated user.');
		}

		foreach (['core.manage', ...$actions] as $action) {
			if (!$actor->authorise($action, $component)) {
				throw new \RuntimeException('This user is not granted ' . $action . ' on ' . $component . '.');
			}
		}
	}

	/** Attaching a set edits the module, so the module's own edit permission decides (ItemModel::save() checks the same asset). */
	protected function ammRequireModuleEdit(User $actor, array $moduleIds): void
	{
		$this->ammRequire($actor, 'com_advancedmodules');

		foreach ($moduleIds as $id) {
			if (!$actor->authorise('core.edit', 'com_modules.module.' . (int) $id)) {
				throw new \RuntimeException('Nothing was changed. This user may not edit module ' . (int) $id . '.');
			}
		}
	}

	// -----------------------------------------------------------------------
	// Arguments
	// -----------------------------------------------------------------------

	/** A key this tool would ignore must not read as saved. Our own copy, because core only gained one in 2.12. */
	protected function ammRefuseUnknown(array $arguments): void
	{
		$unknown = array_diff(array_keys($arguments), array_keys((array) ($this->getInputSchema()['properties'] ?? [])));

		if ($unknown !== []) {
			throw new \RuntimeException('Nothing was saved. ' . $this->getName() . ' has no argument named ' . implode(', ', $unknown) . '.');
		}
	}

	/** A positive integer, not whatever a cast makes of an array or a word. */
	protected function ammId(array $arguments, string $key): int
	{
		$value = $arguments[$key] ?? null;

		if ((!\is_int($value) && !(\is_string($value) && ctype_digit($value))) || (int) $value <= 0) {
			throw new \RuntimeException($key . ' must be a positive whole number.');
		}

		return (int) $value;
	}

	/** Only a real true confirms; the string "false" must not. */
	protected function ammConfirmed(array $arguments): bool
	{
		return ($arguments['confirm'] ?? false) === true;
	}

	/** A yes or no that changes what gets saved. @throws \RuntimeException */
	protected function ammBool(mixed $value, string $name): bool
	{
		if (\is_bool($value)) {
			return $value;
		}

		if (\in_array($value, [1, '1', 'true'], true)) {
			return true;
		}

		if (\in_array($value, [0, '0', 'false', ''], true)) {
			return false;
		}

		throw new \RuntimeException('Nothing was saved. ' . $name . ' must be true or false.');
	}

	/** @throws \RuntimeException */
	protected function ammIds(array $arguments, string $key, int $max = 500): array
	{
		$given = $arguments[$key] ?? [];

		if (!\is_array($given)) {
			throw new \RuntimeException($key . ' must be a list of ids.');
		}

		foreach ($given as $value) {
			if ((!\is_int($value) && !(\is_string($value) && ctype_digit($value))) || (int) $value <= 0) {
				throw new \RuntimeException($key . ' must hold only positive whole numbers.');
			}
		}

		$ids = array_values(array_unique(array_map('intval', $given)));

		if ($ids === []) {
			throw new \RuntimeException($key . ' must list at least one id.');
		}

		if (\count($ids) > $max) {
			throw new \RuntimeException($key . ' may list at most ' . $max . ' ids in one call.');
		}

		return $ids;
	}

	protected function ammLimit(array $arguments, int $default = 100, int $max = 500): int
	{
		return max(1, min($max, (int) ($arguments['limit'] ?? $default)));
	}

	protected function ammOffset(array $arguments): int
	{
		return max(0, (int) ($arguments['offset'] ?? 0));
	}

	/** Names and labels show in admin lists and reach action logs, so they stay plain text. */
	protected function ammPlainText(string $field, string $value, int $max): string
	{
		$value = trim($value);

		if ($value !== strip_tags($value) || str_contains($value, '<') || str_contains($value, '>')) {
			throw new \RuntimeException('Nothing was saved. ' . $field . ' must be plain text, without HTML.');
		}

		if (mb_strlen($value) > $max) {
			throw new \RuntimeException('Nothing was saved. ' . $field . ' is longer than ' . $max . ' characters.');
		}

		return $value;
	}

	protected function ammColor(string $value): string
	{
		$value = strtolower(trim($value));

		if ($value === 'none' || $value === '') {
			return '';
		}

		if (!preg_match('/^#[0-9a-f]{6}$/', $value)) {
			throw new \RuntimeException('Nothing was saved. color must look like #dc2a28, or "none".');
		}

		return $value;
	}

	/** 'all' => 1, 'any' => 0. */
	protected function ammMatch(mixed $value, int $default = 1): int
	{
		if ($value === null) {
			return $default;
		}

		if (!\in_array($value, ['all', 'any'], true)) {
			throw new \RuntimeException('Nothing was saved. match must be "all" or "any".');
		}

		return $value === 'all' ? 1 : 0;
	}

	/**
	 * The caller's `rules` (one group) or `groups`, as the vendor stores them.
	 *
	 * @throws \RuntimeException
	 */
	protected function ammBuildGroups(array $arguments, int $selfSetId = 0): array
	{
		if (isset($arguments['rules']) && isset($arguments['groups'])) {
			throw new \RuntimeException('Nothing was saved. Send rules or groups, not both.');
		}

		$given = isset($arguments['groups'])
			? (array) $arguments['groups']
			: [['match' => $arguments['rules_match'] ?? 'all', 'rules' => (array) ($arguments['rules'] ?? [])]];

		if ($given === []) {
			throw new \RuntimeException('Nothing was saved. Every set needs at least one rule, because a set with none shows its modules on every page. remove_advancedmodules_rule can remove the last one, with confirm.');
		}

		$groups = [];

		foreach ($given as $i => $group) {
			$group = (array) $group;
			$rules = [];

			foreach (array_values((array) ($group['rules'] ?? [])) as $r => $rule) {
				$built           = $this->ammBuildRule((array) $rule, $selfSetId);
				$built->ordering = $r;
				$rules[]         = $built;
			}

			if ($rules === []) {
				throw new \RuntimeException('Nothing was saved. Every group needs at least one rule, because a group with none is dropped and a set with no rules shows its modules on every page.');
			}

			$groups[] = (object) [
				'match_all' => $this->ammMatch($group['match'] ?? null),
				'ordering'  => $i,
				'rules'     => $rules,
			];
		}

		return $groups;
	}

	// -----------------------------------------------------------------------
	// Condition Sets. Reads are our own SQL, because the vendor's getItem() can
	// answer from its file cache; writes go through the vendor's model.
	// -----------------------------------------------------------------------

	/** @return array<int, object> Keyed by id, with groups and rules attached. */
	protected function ammLoadSets(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

		if ($ids === []) {
			return [];
		}

		$db   = $this->db;
		$list = implode(',', $ids);
		$sets = $db->setQuery('SELECT * FROM ' . $db->quoteName('#__conditions') . ' WHERE id IN (' . $list . ')')->loadObjectList('id') ?: [];

		foreach ($sets as $set) {
			$set->groups    = [];
			$set->match_all = (int) $set->match_all;
			$set->published = (int) $set->published;
		}

		$groups = $db->setQuery(
			'SELECT * FROM ' . $db->quoteName('#__conditions_groups') . ' WHERE condition_id IN (' . $list . ') ORDER BY condition_id, ordering, id'
		)->loadObjectList('id') ?: [];

		foreach ($groups as $group) {
			$group->match_all = (int) $group->match_all;
			$group->rules     = [];
		}

		if ($groups !== []) {
			$rules = $db->setQuery(
				'SELECT * FROM ' . $db->quoteName('#__conditions_rules') . ' WHERE group_id IN (' . implode(',', array_keys($groups)) . ') ORDER BY group_id, ordering, id'
			)->loadObjectList() ?: [];

			foreach ($rules as $rule) {
				$decoded      = json_decode((string) $rule->params);
				$rule->params = \is_object($decoded) ? $decoded : (object) [];

				if (isset($groups[$rule->group_id])) {
					$groups[$rule->group_id]->rules[] = $rule;
				}
			}
		}

		foreach ($groups as $group) {
			if (isset($sets[$group->condition_id])) {
				$sets[$group->condition_id]->groups[] = $group;
			}
		}

		return $sets;
	}

	/** @throws \RuntimeException */
	protected function ammSet(int $id): object
	{
		$set = $this->ammLoadSets([$id])[$id] ?? null;

		if ($set === null) {
			throw new \RuntimeException('There is no Condition Set with id ' . $id . '.');
		}

		return $set;
	}

	/** Every set id reachable from this one through "another Condition Set" rules, itself included. */
	protected function ammReachableSets(int $id, array $seen = []): array
	{
		if (isset($seen[$id])) {
			return $seen;
		}

		$seen[$id] = true;
		$set       = $this->ammLoadSets([$id])[$id] ?? null;

		foreach ($set->groups ?? [] as $group) {
			foreach ($group->rules as $rule) {
				if ($rule->type === 'other__condition') {
					foreach ($this->ammNestedSetIds($rule) as $nested) {
						$seen = $this->ammReachableSets($nested, $seen);
					}
				}
			}
		}

		return $seen;
	}

	/**
	 * The sets an "another Condition Set" rule names. The vendor's getIdByMixed()
	 * takes an alias or a name as well as an id, so a hand-saved row can use one.
	 */
	protected function ammNestedSetIds(object $rule): array
	{
		$db  = $this->db;
		$ids = [];

		foreach ((array) ($rule->params->selection ?? []) as $value) {
			$value = trim((string) $value);

			if (ctype_digit($value) && (int) $value > 0) {
				$ids[] = (int) $value;
			} elseif ($value !== '') {
				$found = (int) $db->setQuery(
					'SELECT id FROM ' . $db->quoteName('#__conditions') . ' WHERE alias = ' . $db->quote($value) . ' OR name = ' . $db->quote($value),
					0,
					1
				)->loadResult();

				if ($found > 0) {
					$ids[] = $found;
				}
			}
		}

		return $ids;
	}

	/** True when this set runs PHP, directly or through a set it includes. */
	protected function ammSetRunsPhp(int $id): bool
	{
		foreach ($this->ammLoadSets(array_keys($this->ammReachableSets($id))) as $set) {
			foreach ($set->groups as $group) {
				foreach ($group->rules as $rule) {
					if ($rule->type === 'other__php') {
						return true;
					}
				}
			}
		}

		return false;
	}

	/** @throws \RuntimeException */
	protected function ammRefuseIfPhp(int $id, string $action): void
	{
		if ($this->ammSetRunsPhp($id)) {
			throw new \RuntimeException('Nothing was changed. Condition Set ' . $id . ' runs PHP code, directly or through a set it includes, and ' . $action . ' could make that code run where it does not run today. These tools leave that to the Joomla administrator.');
		}
	}

	/**
	 * A set included by one that runs PHP is left alone too, the same as the PHP
	 * set itself.
	 *
	 * @throws \RuntimeException
	 */
	protected function ammRefuseIfFeedsPhp(int $id, array $seen = []): void
	{
		$seen[$id] = true;

		foreach ($this->ammSetsIncluding($id) as $parent) {
			if (isset($seen[$parent['id']])) {
				continue;
			}

			if ($this->ammSetRunsPhp($parent['id'])) {
				throw new \RuntimeException('Nothing was changed. Condition Set ' . $id . ' is included by set ' . $parent['id'] . ', which runs PHP code, so a change here could make that code run where it does not run today. These tools leave that to the Joomla administrator.');
			}

			$this->ammRefuseIfFeedsPhp($parent['id'], $seen);
		}
	}

	/** A rule pointing at a missing, PHP-running or looping set would be saved fine and misbehave later. */
	protected function ammCheckNestedSet(int $nestedId, int $selfSetId): void
	{
		if ($selfSetId > 0 && $nestedId === $selfSetId) {
			throw new \RuntimeException('Nothing was saved. A Condition Set can\'t include itself: the vendor fails the whole check on a loop, which hides the module everywhere.');
		}

		if (!isset($this->ammLoadSets([$nestedId])[$nestedId])) {
			throw new \RuntimeException('Nothing was saved. There is no Condition Set with id ' . $nestedId . '.');
		}

		if ($selfSetId > 0 && isset($this->ammReachableSets($nestedId)[$selfSetId])) {
			throw new \RuntimeException('Nothing was saved. Condition Set ' . $nestedId . ' already includes set ' . $selfSetId . ', so this would make a loop, and the vendor hides the module everywhere on a loop.');
		}

		$this->ammRefuseIfPhp($nestedId, 'including it in another set');
	}

	/**
	 * Save through the vendor's ItemModel::saveByObject(), which runs in a
	 * transaction, stores the hash and rebuilds groups and rules. A save
	 * replaces every rule row, so rule ids change.
	 *
	 * @return int  The set's id.
	 * @throws \RuntimeException
	 */
	protected function ammSaveSet(object $set): int
	{
		$model = $this->getModel('com_conditions', 'Item');

		$object = (object) [
			'id'          => (int) ($set->id ?? 0),
			'alias'       => (string) ($set->alias ?? ''),
			'name'        => (string) $set->name,
			'description' => (string) ($set->description ?? ''),
			'category'    => (string) ($set->category ?? ''),
			'color'       => $set->color ?? null,
			'published'   => (int) ($set->published ?? 1),
			'match_all'   => (int) ($set->match_all ?? 1),
			'groups'      => $set->groups,
		];

		if (!$model->saveByObject($object, '', 0)) {
			throw new \RuntimeException('Advanced Module Manager refused the save: ' . ((string) $model->getError() ?: 'no reason given') . ' Nothing was saved.');
		}

		$this->ammClearCaches();

		return (int) $object->id;
	}

	/** The vendor's condition cache, plus what Joomla may have rendered before the change. */
	protected function ammClearCaches(): void
	{
		try {
			(new ConditionsCache())->resetAllFiles();
		} catch (\Throwable $e) {
			// Best effort: the next vendor save resets it too.
		}

		foreach (['com_modules', 'page'] as $group) {
			try {
				Factory::getContainer()->get(CacheControllerFactoryInterface::class)
					->createCacheController('callback', ['defaultgroup' => $group])
					->cache->clean();
			} catch (\Throwable $e) {
				// Best effort, like BaseDatabaseModel::cleanCache().
			}
		}
	}

	/**
	 * Attach through the vendor's map(), which replaces any set the module had
	 * and fires its event. Boots the component first so the class autoloads.
	 */
	protected function ammMap(int $setId, array $moduleIds): void
	{
		$this->bootComponent('com_conditions');

		foreach ($moduleIds as $moduleId) {
			ConditionsItemModel::map($setId, self::AMM_EXTENSION, (int) $moduleId, 'modules', 'title');
		}

		$this->ammClearCaches();
	}

	protected function ammUnmap(array $moduleIds): void
	{
		$this->bootComponent('com_conditions');

		ConditionsItemModel::removeMapping(self::AMM_EXTENSION, array_map('intval', $moduleIds));

		$this->ammClearCaches();
	}

	// -----------------------------------------------------------------------
	// Modules
	// -----------------------------------------------------------------------

	private const AMM_MODULE_SELECT = 'm.id, m.title, m.module, m.position, m.published, m.access, m.client_id, m.language, m.publish_up, m.publish_down, '
		. 'am.category AS amm_category, am.color AS amm_color, map.condition_id';

	protected function ammModuleFrom(): string
	{
		$db = $this->db;

		return ' FROM ' . $db->quoteName('#__modules', 'm')
			. ' LEFT JOIN ' . $db->quoteName('#__advancedmodules', 'am') . ' ON am.module_id = m.id'
			. ' LEFT JOIN ' . $db->quoteName('#__conditions_map', 'map') . ' ON map.item_id = m.id AND map.extension = ' . $db->quote(self::AMM_EXTENSION);
	}

	/** @return array<int, object> Keyed by id. */
	protected function ammModules(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

		if ($ids === []) {
			return [];
		}

		return $this->db->setQuery(
			'SELECT ' . self::AMM_MODULE_SELECT . $this->ammModuleFrom() . ' WHERE m.id IN (' . implode(',', $ids) . ')'
		)->loadObjectList('id') ?: [];
	}

	/**
	 * Modules a set can act on, or refuse. Sets only run on the front end
	 * (the plugin's handleOnPrepareModuleList() returns on admin).
	 *
	 * @throws \RuntimeException
	 */
	protected function ammRequireSiteModules(array $ids): array
	{
		$modules = $this->ammModules($ids);

		if ($missing = array_diff($ids, array_keys($modules))) {
			throw new \RuntimeException('Nothing was changed. There is no module with id ' . implode(', ', $missing) . '.');
		}

		$admin = array_keys(array_filter($modules, static fn($m) => (int) $m->client_id !== 0));

		if ($admin !== []) {
			throw new \RuntimeException('Nothing was changed. Module ' . implode(', ', $admin) . ' is an administrator module, and Condition Sets only act on front-end modules.');
		}

		$html = array_keys(array_filter($modules, fn($m) => $this->ammTitleHasHtml((string) $m->title)));

		if ($html !== []) {
			throw new \RuntimeException('Nothing was changed. The title of module ' . implode(', ', $html) . ' holds HTML, which these tools don\'t accept. Fix the title first.');
		}

		return $modules;
	}

	protected function ammTitleHasHtml(string $title): bool
	{
		return str_contains($title, '<') || str_contains($title, '>');
	}

	/** Core's menu assignment for each module: mode all, none, include or exclude. */
	protected function ammCoreAssignments(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
		$out = array_fill_keys($ids, ['mode' => 'none', 'menu_item_ids' => []]);

		if ($ids === []) {
			return $out;
		}

		$rows = $this->db->setQuery(
			'SELECT moduleid, menuid FROM ' . $this->db->quoteName('#__modules_menu') . ' WHERE moduleid IN (' . implode(',', $ids) . ')'
		)->loadAssocList() ?: [];

		$by = [];

		foreach ($rows as $row) {
			$by[(int) $row['moduleid']][] = (int) $row['menuid'];
		}

		foreach ($by as $id => $menuIds) {
			$positive = array_values(array_filter($menuIds, static fn($v) => $v > 0));
			$negative = array_values(array_filter($menuIds, static fn($v) => $v < 0));

			if (\in_array(0, $menuIds, true)) {
				$out[$id] = ['mode' => 'all', 'menu_item_ids' => []];
			} elseif ($negative !== []) {
				$out[$id] = ['mode' => 'exclude', 'menu_item_ids' => array_map('abs', $negative)];
			} elseif ($positive !== []) {
				$out[$id] = ['mode' => 'include', 'menu_item_ids' => $positive];
			}
		}

		return $out;
	}

	/** Access levels every visitor has, which need no rule. */
	protected function ammPublicLevels(): array
	{
		$rows = $this->db->setQuery('SELECT id, rules FROM ' . $this->db->quoteName('#__viewlevels'))->loadAssocList() ?: [];
		$out  = [];

		foreach ($rows as $row) {
			if (\in_array(1, (array) json_decode((string) $row['rules'], true), true)) {
				$out[] = (int) $row['id'];
			}
		}

		return $out;
	}

	/** True when core's settings limit where or for whom this module shows. */
	protected function ammCoreLimits(object $module, array $assignment, array $publicLevels): array
	{
		$limits = [];

		if ($assignment['mode'] === 'none') {
			$limits[] = 'assigned to no pages';
		} elseif ($assignment['mode'] !== 'all') {
			$limits[] = 'menu assignment (' . $assignment['mode'] . ' ' . implode(', ', $assignment['menu_item_ids']) . ')';
		}

		if (!\in_array((int) $module->access, $publicLevels, true)) {
			$limits[] = 'access level ' . (int) $module->access;
		}

		if (($module->language ?? '*') !== '*' && $this->ammLanguageFilterOn()) {
			$limits[] = 'language ' . $module->language;
		}

		[$from, $to] = $this->ammPublishWindow($module);

		if ($from !== null || $to !== null) {
			$limits[] = 'publishing dates (' . ($from ?? 'any time') . ' to ' . ($to ?? 'no end') . ')';
		}

		return $limits;
	}

	/** Core only filters modules by language when the System - Language Filter plugin is on. */
	protected function ammLanguageFilterOn(): bool
	{
		return $this->ammExtensionEnabled('plugin', 'languagefilter', 'system') === true;
	}

	/**
	 * A module's publish dates in the site's time zone, which is how the vendor's
	 * date rule reads a date; core stores them as UTC.
	 *
	 * @return array{0: ?string, 1: ?string}
	 */
	protected function ammPublishWindow(object $module): array
	{
		$zone  = new \DateTimeZone((string) (Factory::getApplication()->get('offset') ?: 'UTC'));
		$local = static function (?string $value) use ($zone): ?string {
			if ($value === null || $value === '' || str_starts_with($value, '0000')) {
				return null;
			}

			return (new \DateTime($value, new \DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d H:i:s');
		};

		return [$local($module->publish_up ?? null), $local($module->publish_down ?? null)];
	}

	/** Who uses a set: modules, plus other Regular Labs extensions that share the same sets. */
	protected function ammUsage(array $setIds): array
	{
		$setIds = array_values(array_unique(array_filter(array_map('intval', $setIds))));
		$out    = [];

		foreach ($setIds as $id) {
			$out[$id] = ['modules' => [], 'other_extensions' => []];
		}

		if ($setIds === []) {
			return $out;
		}

		$db   = $this->db;
		$rows = $db->setQuery(
			'SELECT map.condition_id, map.extension, map.item_id, m.title, m.position, m.published, m.client_id'
			. ' FROM ' . $db->quoteName('#__conditions_map', 'map')
			. ' LEFT JOIN ' . $db->quoteName('#__modules', 'm') . ' ON m.id = map.item_id AND map.extension = ' . $db->quote(self::AMM_EXTENSION)
			. ' WHERE map.condition_id IN (' . implode(',', $setIds) . ') ORDER BY map.extension, map.item_id'
		)->loadObjectList() ?: [];

		foreach ($rows as $row) {
			if ($row->extension !== self::AMM_EXTENSION) {
				$out[(int) $row->condition_id]['other_extensions'][] = ['extension' => $row->extension, 'item_id' => (int) $row->item_id];
				continue;
			}

			$out[(int) $row->condition_id]['modules'][] = [
				'id'        => (int) $row->item_id,
				'title'     => $row->title,
				'position'  => $row->position,
				'published' => $row->published === null ? null : (int) $row->published,
				'exists'    => $row->title !== null,
			];
		}

		return $out;
	}

	/** Update or create a module's #__advancedmodules row, leaving everything not named alone. */
	protected function ammSaveModuleRow(int $moduleId, array $columns, array $extras): void
	{
		$db  = $this->db;
		$row = $db->setQuery(
			'SELECT category, color, params FROM ' . $db->quoteName('#__advancedmodules') . ' WHERE module_id = ' . $moduleId
		)->loadObject();

		$params = \is_object($row) ? (array) json_decode((string) $row->params, true) : [];
		$record = (object) [
			'module_id' => $moduleId,
			'category'  => array_key_exists('category', $columns) ? $columns['category'] : ($row->category ?? ''),
			'color'     => array_key_exists('color', $columns) ? ($columns['color'] ?: null) : ($row->color ?? null),
			'params'    => json_encode(array_merge($params, $extras), JSON_UNESCAPED_UNICODE),
		];

		if (\is_object($row)) {
			$db->updateObject('#__advancedmodules', $record, 'module_id', true);
		} else {
			$db->insertObject('#__advancedmodules', $record);
		}
	}

	protected function ammComponentParams(string $component): object
	{
		$db    = $this->db;
		$value = json_decode((string) $db->setQuery(
			'SELECT params FROM ' . $db->quoteName('#__extensions') . ' WHERE type = ' . $db->quote('component') . ' AND element = ' . $db->quote($component)
		)->loadResult());

		return \is_object($value) ? $value : (object) [];
	}

	/** Rule types switched off in the Conditions options; the vendor passes those rules without checking them. */
	protected function ammDisabledRuleTypes(): array
	{
		$value = $this->ammComponentParams('com_conditions')->disabled_rule_types ?? [];

		return array_values(array_filter(array_map('strval', \is_array($value) ? $value : explode(',', (string) $value)), static fn($v) => $v !== '' && $v !== '0'));
	}

	/** True when a set can reach itself through "another Condition Set" rules. */
	protected function ammSetLoops(int $id): bool
	{
		$set = $this->ammLoadSets([$id])[$id] ?? null;

		foreach ($set->groups ?? [] as $group) {
			foreach ($group->rules as $rule) {
				if ($rule->type !== 'other__condition') {
					continue;
				}

				foreach ($this->ammNestedSetIds($rule) as $nested) {
					if (isset($this->ammReachableSets($nested)[$id])) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/** Other sets holding an "another Condition Set" rule that names this one. */
	protected function ammSetsIncluding(int $id): array
	{
		$db   = $this->db;
		$rows = $db->setQuery(
			'SELECT g.condition_id, c.name, r.params FROM ' . $db->quoteName('#__conditions_rules', 'r')
			. ' JOIN ' . $db->quoteName('#__conditions_groups', 'g') . ' ON g.id = r.group_id'
			. ' JOIN ' . $db->quoteName('#__conditions', 'c') . ' ON c.id = g.condition_id'
			. ' WHERE r.type = ' . $db->quote('other__condition')
		)->loadObjectList() ?: [];

		$out = [];

		foreach ($rows as $row) {
			$rule = (object) ['params' => json_decode((string) $row->params) ?: (object) []];

			if (\in_array($id, $this->ammNestedSetIds($rule), true)) {
				$out[(int) $row->condition_id] = ['id' => (int) $row->condition_id, 'name' => $row->name];
			}
		}

		return array_values($out);
	}

	/** Values in a rule that point at something deleted. */
	protected function ammDeadReferences(object $rule): array
	{
		$kind = ['menu__menu_item' => 'menu_ids', 'content__category' => 'category_ids'][$rule->type] ?? null;
		$out  = [];

		if ($kind !== null) {
			$ids   = array_map('intval', array_filter((array) ($rule->params->selection ?? []), static fn($v) => ctype_digit((string) $v)));
			$found = $this->ammLookup($kind, $ids);

			foreach (array_diff($ids, array_keys($found)) as $id) {
				$out[] = ['rule_type' => $rule->type, 'missing_id' => $id];
			}
		}

		if ($rule->type === 'other__condition') {
			foreach ((array) ($rule->params->selection ?? []) as $nested) {
				if (ctype_digit((string) $nested) && !isset($this->ammLoadSets([(int) $nested])[(int) $nested])) {
					$out[] = ['rule_type' => $rule->type, 'missing_id' => (int) $nested];
				}
			}
		}

		return $out;
	}

	/** Other published sets with the same rules as this one. */
	protected function ammIdenticalSets(object $set): array
	{
		$ids  = $this->db->setQuery('SELECT id FROM ' . $this->db->quoteName('#__conditions') . ' WHERE published = 1 AND id <> ' . (int) $set->id)->loadColumn() ?: [];
		$mine = $this->ammRulesFingerprint($set);
		$out  = [];

		foreach ($this->ammLoadSets($ids) as $id => $other) {
			if ($this->ammRulesFingerprint($other) === $mine) {
				$out[] = ['id' => (int) $id, 'name' => $other->name];
			}
		}

		return $out;
	}

	/**
	 * Find a rule by id.
	 *
	 * @return array{0: object, 1: int, 2: int}  The set, group index, rule index.
	 * @throws \RuntimeException
	 */
	protected function ammFindRule(int $ruleId): array
	{
		$db    = $this->db;
		$setId = (int) $db->setQuery(
			'SELECT g.condition_id FROM ' . $db->quoteName('#__conditions_rules', 'r')
			. ' JOIN ' . $db->quoteName('#__conditions_groups', 'g') . ' ON g.id = r.group_id WHERE r.id = ' . $ruleId
		)->loadResult();

		if ($setId > 0) {
			$set = $this->ammSet($setId);

			foreach ($set->groups as $g => $group) {
				foreach ($group->rules as $r => $rule) {
					if ((int) $rule->id === $ruleId) {
						return [$set, $g, $r];
					}
				}
			}
		}

		throw new \RuntimeException('There is no rule with id ' . $ruleId . '. Rule ids change every time a set is saved, so read them again with get_advancedmodules_condition_set.');
	}

	/**
	 * Put modules on a set, reporting what each had before.
	 *
	 * @throws \RuntimeException
	 */
	protected function ammAttach(int $setId, array $moduleIds, bool $dryRun): array
	{
		$set = $this->ammSet($setId);

		if ((int) $set->published === -2) {
			throw new \RuntimeException('Nothing was changed. Condition Set ' . $setId . ' is trashed.');
		}

		$this->ammRefuseIfPhp($setId, 'attaching it to more modules');

		$modules  = $this->ammRequireSiteModules($moduleIds);
		$previous = $this->ammLoadSets(array_column($modules, 'condition_id'));
		$out      = [];

		foreach ($modules as $id => $module) {
			$was   = $previous[$module->condition_id] ?? null;
			$out[] = [
				'id'       => (int) $id,
				'title'    => $module->title,
				'replaces' => $was && (int) $was->id !== $setId ? ['id' => (int) $was->id, 'name' => $was->name] : null,
			];
		}

		$formerIds = array_values(array_diff(array_map('intval', array_keys($previous)), [$setId]));

		if (!$dryRun) {
			$this->ammMap($setId, $moduleIds);
		}

		$result = [
			'ok'      => true,
			'dry_run' => $dryRun,
			'set'     => ['id' => $setId, 'name' => $set->name, 'effect' => $this->ammSetEffect($set)],
			'modules' => $out,
		];

		if (!$dryRun && $formerIds !== []) {
			$unused = [];

			foreach ($this->ammUsage($formerIds) as $id => $usage) {
				if ($usage['modules'] === [] && $usage['other_extensions'] === []) {
					$unused[] = ['id' => (int) $id, 'name' => $previous[$id]->name];
				}
			}

			if ($unused !== []) {
				$result['sets_now_unused'] = $unused;
			}
		}

		if ((int) $set->published !== 1) {
			$result['warning_set_state'] = 'This set is ' . $this->ammStateLabel((int) $set->published) . ', so until it is published these modules show on every page.';
		}

		return $result + $this->ammEffectNotes();
	}

	// -----------------------------------------------------------------------
	// Output
	// -----------------------------------------------------------------------

	/** One module and what decides where it shows. */
	protected function ammFormatModule(object $module, ?object $set, array $assignment, array $publicLevels, bool $pluginOn): array
	{
		$limits = $this->ammCoreLimits($module, $assignment, $publicLevels);

		if (!$pluginOn) {
			$decidedBy = 'joomla';
			$text      = 'The plugin is off, so Joomla\'s own access and menu assignment decide.';
		} elseif ($set !== null && (int) $set->published === 1) {
			$decidedBy = 'condition_set';
			$text      = $this->ammSetSummary($set);
		} else {
			$decidedBy = 'nothing';
			$text      = ($set !== null ? 'Its set is ' . $this->ammStateLabel((int) $set->published) . ', so it' : 'No Condition Set, so it') . ' shows on every page, to every visitor.';
		}

		$out = [
			'id'         => (int) $module->id,
			'title'      => $module->title,
			'module'     => $module->module,
			'position'   => $module->position,
			'state'      => $this->ammStateLabel((int) $module->published),
			'category'   => (string) ($module->amm_category ?? ''),
			'color'      => $module->amm_color ?? null,
			'decided_by' => $decidedBy,
			'shows'      => $text,
			'set'        => $set !== null ? ['id' => (int) $set->id, 'name' => $set->name, 'state' => $this->ammStateLabel((int) $set->published)] : null,
		];

		if ($pluginOn && $limits !== []) {
			$out['joomla_settings_ignored'] = $limits;
		}

		return $out;
	}

	protected function ammStateLabel(int $state): string
	{
		return match ($state) {
			1       => 'published',
			0       => 'unpublished',
			-2      => 'trashed',
			default => 'state ' . $state,
		};
	}

	/** What a set does for the modules on it, right now. */
	protected function ammSetEffect(object $set): string
	{
		if ((int) $set->published !== 1) {
			return 'This set is ' . $this->ammStateLabel((int) $set->published) . ', so the vendor treats its modules as having no set: they show on every page.';
		}

		return $this->ammSetSummary($set);
	}

	/** Rule types this site's edition skips (the vendor's passGroup() ignores them). */
	protected function ammRuleIgnoredHere(object $rule): bool
	{
		return $this->ammEdition() === 'free' && !\in_array($rule->type, $this->ammFreeRuleTypes(), true);
	}

	protected function ammFormatRule(object $rule): array
	{
		$params = (array) $rule->params;
		$out    = [
			'id'      => (int) ($rule->id ?? 0),
			'type'    => $rule->type,
			'label'   => $this->ammRuleLabel((string) $rule->type),
			'exclude' => (bool) $rule->exclude,
		];

		if ($rule->type === 'other__php') {
			$out['php'] = $this->ammPhpFacts($params) + ['note' => 'Not shown and never run by these tools. Review it in the Joomla administrator.'];
		} else {
			$out['params'] = $params;
		}

		$out['summary'] = $this->ammRuleSummary($rule);

		if ($this->ammRuleIgnoredHere($rule)) {
			$out['ignored_on_this_edition'] = true;
		}

		return $out;
	}

	protected function ammFormatSet(object $set, array $usage = []): array
	{
		$out = [
			'id'          => (int) $set->id,
			'name'        => $set->name,
			'alias'       => $set->alias,
			'description' => $set->description,
			'category'    => $set->category,
			'color'       => $set->color,
			'state'       => $this->ammStateLabel((int) $set->published),
			'match'       => $set->match_all ? 'all' : 'any',
			'effect'      => $this->ammSetEffect($set),
			'groups'      => array_map(fn($g) => [
				'match' => $g->match_all ? 'all' : 'any',
				'rules' => array_map(fn($r) => $this->ammFormatRule($r), $g->rules),
			], $set->groups),
		];

		if ($usage !== []) {
			$out['used_by'] = $usage;
		}

		return $out;
	}
}
