<?php
/**
 * @package     MCP for Page Protector
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\DisplayHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\LogHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\ProtectionHelper;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * Shared orientation and safety rails for every Page Protector tool.
 *
 * Built against Cybersalt Page Protector 0.2.1 (2026-10-20) on Joomla 6.2.0.
 *
 * ---------------------------------------------------------------------------
 * WHERE THE LOGIC LIVES, AND WHY THE TOOLS CALL IT INSTEAD OF COPYING IT
 * ---------------------------------------------------------------------------
 *
 * Page Protector keeps every decision in `com_cspageprotector/admin/src/Helper/`
 * (ProtectionHelper, CaptchaHelper, LogHelper, IpHelper, DisplayHelper) and the
 * dashboard's numbers in `Model/DashboardModel` and `Model/LogsModel`. The tools
 * call those directly, so the MCP view of "what is protected" or "can the check
 * run" is the same code path the plugin and the dashboard use, not a second
 * opinion that can drift.
 *
 * Two exceptions, both deliberate:
 *
 *   - `DashboardModel::getProtectedItems()` is not called. It builds a site URL
 *     with `Route::link('site', ...)` (DashboardModel.php:206), which under the
 *     API application pulls a SiteRouter (and with it a SiteApplication) out of
 *     the container. Menu items are resolved with a plain query instead.
 *   - Settings are read from `#__extensions` directly, not through
 *     `ComponentHelper::getParams()`. With caching on, ComponentHelper serves
 *     the `_system` cache group, and a write built on a stale copy would undo
 *     the last change (ProtectionHelper.php:191-220 explains the same trap).
 *
 * ---------------------------------------------------------------------------
 * PERMISSIONS
 * ---------------------------------------------------------------------------
 *
 * No new ACL actions. `core.manage` first (the component's dispatcher refuses
 * the admin without it), then the component's own split from
 * PermissionHelper.php: `cspageprotector.view` for reads,
 * `cspageprotector.write` for deleting log rows, `core.options` for settings
 * (what com_config itself checks), `core.admin` for resetting every pass.
 * Super Users pass every check through core.admin, as in the vendor.
 */
trait PageProtectorBootTrait
{
	/**
	 * Oldest Page Protector these tools work with. 0.2.0 added the helpers the
	 * tools call: ProtectionHelper::saveParams(), hasPageProtection(),
	 * cleanCacheGroup() and CaptchaHelper::getProblem().
	 */
	private const PP_MIN_VERSION = '0.2.0';

	private const PP_COMPONENT = 'com_cspageprotector';

	private const PP_LOG_TABLE = '#__cspageprotector_log';

	/** Which ACL actions satisfy each level, any one being enough (PermissionHelper.php). */
	private const PP_ACL = [
		'view'    => ['cspageprotector.view', 'core.admin'],
		'write'   => ['cspageprotector.write', 'core.admin'],
		'options' => ['core.options', 'core.admin'],
		'admin'   => ['core.admin'],
	];

	private static ?array $ppStateCache = null;

	// -----------------------------------------------------------------------
	// Identity
	// -----------------------------------------------------------------------

	/**
	 * One category for every tool. Deriving it from the namespace would give
	 * `settings`, `status` and `log`, which are generic enough to collide with
	 * core MCP for J and with sibling add-ons.
	 */
	public function getCategory(): string
	{
		return 'page_protector';
	}

	/**
	 * add_ and remove_ are not verbs AbstractTool knows, so it would mark them
	 * non-idempotent. Adding a page that is already protected, or removing one
	 * that is not, changes nothing, so say so.
	 */
	public function getMcpAnnotations(): array
	{
		$name = $this->getName();

		if (str_starts_with($name, 'add_') || str_starts_with($name, 'remove_')) {
			return [
				'readOnlyHint'    => false,
				'destructiveHint' => false,
				'idempotentHint'  => true,
				'openWorldHint'   => false,
			];
		}

		return parent::getMcpAnnotations();
	}

	// -----------------------------------------------------------------------
	// Presence
	// -----------------------------------------------------------------------

	/**
	 * @return array{installed:bool,extension_id:int,version:?string,package_version:?string,plugin_version:?string,component_enabled:bool,plugin_installed:bool,plugin_enabled:bool,log_table:bool,helpers:bool}
	 */
	protected function ppState(): array
	{
		if (self::$ppStateCache !== null) {
			return self::$ppStateCache;
		}

		$db   = $this->db;
		$rows = $db->setQuery(
			$db->createQuery()
				->select($db->quoteName(['extension_id', 'type', 'element', 'folder', 'enabled', 'manifest_cache']))
				->from($db->quoteName('#__extensions'))
				->whereIn($db->quoteName('element'), ['com_cspageprotector', 'cspageprotector', 'pkg_cspageprotector'], ParameterType::STRING)
		)->loadAssocList() ?: [];

		$component = null;
		$plugin    = null;
		$package   = null;

		foreach ($rows as $row) {
			if ($row['type'] === 'component') {
				$component = $row;
			} elseif ($row['type'] === 'plugin' && $row['folder'] === 'system') {
				$plugin = $row;
			} elseif ($row['type'] === 'package') {
				$package = $row;
			}
		}

		$version = static function (?array $row): ?string {
			$decoded = json_decode((string) ($row['manifest_cache'] ?? ''), true);

			return \is_array($decoded) && !empty($decoded['version']) ? (string) $decoded['version'] : null;
		};

		$logTable = \in_array($db->getPrefix() . 'cspageprotector_log', $db->getTableList(), true);

		return self::$ppStateCache = [
			'installed'         => $component !== null && is_dir(JPATH_ADMINISTRATOR . '/components/com_cspageprotector') && $logTable,
			'extension_id'      => (int) ($component['extension_id'] ?? 0),
			'version'           => $version($component),
			'package_version'   => $version($package),
			'plugin_version'    => $version($plugin),
			'component_enabled' => (int) ($component['enabled'] ?? 0) === 1,
			'plugin_installed'  => $plugin !== null,
			'plugin_enabled'    => (int) ($plugin['enabled'] ?? 0) === 1,
			'log_table'         => $logTable,
			'helpers'           => class_exists(ProtectionHelper::class),
		];
	}

	/**
	 * Null when the tools can run, otherwise the error to return. Called first by
	 * every tool, before any Page Protector class is touched.
	 */
	protected function ppCheck(): ?ToolResult
	{
		$state = $this->ppState();

		if (!$state['installed']) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Cybersalt Page Protector (com_cspageprotector) is not installed on this site. Expected '
					. 'administrator/components/com_cspageprotector, its #__extensions row and the #__cspageprotector_log table.',
				'note'  => 'Page Protector is free from cybersalt.com (https://www.cybersalt.com/extensions/page-protector). '
					. 'These tools need version ' . self::PP_MIN_VERSION . ' or later.',
			], true);
		}

		if ($state['version'] !== null && version_compare($state['version'], self::PP_MIN_VERSION, '<')) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Page Protector ' . $state['version'] . ' is installed, but these tools need '
					. self::PP_MIN_VERSION . ' or later (they call helpers added in 0.2.0). Update Page Protector first.',
			], true);
		}

		if (!$state['helpers']) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'Page Protector is registered but its helper classes cannot be loaded '
					. '(Cybersalt\\Component\\Cspageprotector\\Administrator\\Helper\\ProtectionHelper). The install '
					. 'may be half-finished, or Joomla\'s autoload map is stale: delete administrator/cache/autoload_psr4.php.',
			], true);
		}

		$this->ppLoadLanguage();

		return null;
	}

	/** The dashboard checks and some messages come back as language strings. */
	protected function ppLoadLanguage(): void
	{
		try {
			$lang = Factory::getApplication()->getLanguage();
			$lang->load('com_cspageprotector', JPATH_ADMINISTRATOR)
				|| $lang->load('com_cspageprotector', JPATH_ADMINISTRATOR . '/components/com_cspageprotector');
		} catch (\Throwable $e) {
			// Untranslated keys are still readable.
		}
	}

	// -----------------------------------------------------------------------
	// Access control
	// -----------------------------------------------------------------------

	/**
	 * @param string $level view | write | options | admin (see PP_ACL)
	 */
	protected function ppRequireAccess(User $actor, string $level): void
	{
		if ($actor->guest || (int) $actor->id === 0) {
			throw new \RuntimeException('Page Protector tools require an authenticated user.');
		}

		if (!$actor->authorise('core.manage', self::PP_COMPONENT)) {
			throw new \RuntimeException('This user lacks core.manage on com_cspageprotector, which the Page Protector admin requires.');
		}

		$actions = self::PP_ACL[$level] ?? ['core.admin'];

		foreach ($actions as $action) {
			if ($actor->authorise($action, self::PP_COMPONENT)) {
				return;
			}
		}

		throw new \RuntimeException(
			'This user is not granted ' . implode(' or ', $actions) . ' on com_cspageprotector, which Page '
			. 'Protector requires for this (' . $level . ').'
		);
	}

	// -----------------------------------------------------------------------
	// Settings storage
	// -----------------------------------------------------------------------

	/** The component params straight from #__extensions (never the _system cache). */
	protected function ppStoredParams(): Registry
	{
		$db = $this->db;

		return new Registry((string) $db->setQuery(
			$db->createQuery()
				->select($db->quoteName('params'))
				->from($db->quoteName('#__extensions'))
				->where($db->quoteName('type') . ' = ' . $db->quote('component'))
				->where($db->quoteName('element') . ' = ' . $db->quote(self::PP_COMPONENT))
		)->loadResult());
	}

	/**
	 * The fields of the INSTALLED config.xml, so a newer Page Protector with more
	 * options works without an add-on update. Brand headers and the
	 * permissions field are left out: neither holds a setting.
	 *
	 * @return array<string,array{fieldset:string,type:string,default:?string,filter:string,multiple:bool,options:string[],maxlength:?int,min:?int,max:?int,validate:string,hidden:bool}>
	 */
	protected function ppConfigFields(): array
	{
		$file = JPATH_ADMINISTRATOR . '/components/com_cspageprotector/config.xml';
		$xml  = is_file($file) ? @simplexml_load_file($file) : false;

		if ($xml === false) {
			throw new \RuntimeException('Could not read ' . $file . '.');
		}

		$fields = [];

		foreach ($xml->xpath('//fieldset') ?: [] as $fieldset) {
			foreach ($fieldset->field as $field) {
				$name = (string) $field['name'];
				$type = strtolower((string) $field['type']);

				if ($name === '' || $type === 'brandheader' || $type === 'rules') {
					continue;
				}

				$options = [];

				foreach ($field->option as $option) {
					$options[] = (string) $option['value'];
				}

				$fields[$name] = [
					'fieldset'  => (string) $fieldset['name'],
					'type'      => $type,
					'default'   => isset($field['default']) ? (string) $field['default'] : null,
					'filter'    => strtolower((string) $field['filter']),
					'multiple'  => (string) $field['multiple'] === 'true',
					'options'   => $options,
					'maxlength' => isset($field['maxlength']) ? (int) $field['maxlength'] : null,
					'min'       => isset($field['min']) ? (int) $field['min'] : null,
					'max'       => isset($field['max']) ? (int) $field['max'] : null,
					'validate'  => (string) $field['validate'],
					'hidden'    => $type === 'hidden',
				];
			}
		}

		return $fields;
	}

	// -----------------------------------------------------------------------
	// Lookups
	// -----------------------------------------------------------------------

	/**
	 * Menu items by id, with the menu they sit in. Missing ids come back with
	 * `missing: true`, the way the dashboard lists a deleted protected item.
	 *
	 * @param int[] $ids
	 * @return array<int,array<string,mixed>>
	 */
	protected function ppMenuItems(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

		if ($ids === []) {
			return [];
		}

		$db   = $this->db;
		$rows = $db->setQuery(
			$db->createQuery()
				->select($db->quoteName(['m.id', 'm.title', 'm.alias', 'm.path', 'm.menutype', 'm.type', 'm.link', 'm.published', 'm.client_id', 'm.home', 'm.access', 'm.language']))
				->select($db->quoteName('t.title', 'menu_title'))
				->from($db->quoteName('#__menu', 'm'))
				->join('LEFT', $db->quoteName('#__menu_types', 't'), $db->quoteName('t.menutype') . ' = ' . $db->quoteName('m.menutype'))
				->whereIn($db->quoteName('m.id'), $ids)
		)->loadAssocList('id') ?: [];

		$out = [];

		foreach ($ids as $id) {
			$row = $rows[$id] ?? null;

			if ($row === null) {
				$out[$id] = ['id' => $id, 'missing' => true, 'note' => 'no such menu item (deleted?); Page Protector ignores it'];
				continue;
			}

			$out[$id] = [
				'id'         => $id,
				'title'      => (string) $row['title'],
				'menu'       => (string) ($row['menu_title'] ?? $row['menutype']),
				'menutype'   => (string) $row['menutype'],
				'type'       => (string) $row['type'],
				'link'       => (string) $row['link'],
				'path'       => (string) $row['path'],
				'published'  => (int) $row['published'],
				'home'       => (int) $row['home'] === 1,
				'client_id'  => (int) $row['client_id'],
				'site_url'   => 'index.php?Itemid=' . $id,
			];
		}

		return $out;
	}

	/**
	 * Site modules by id. Missing or trashed ones are flagged: the dashboard
	 * (DashboardModel::getProtectedModules) only counts published and
	 * unpublished modules.
	 *
	 * @param int[] $ids
	 * @return array<int,array<string,mixed>>
	 */
	protected function ppModules(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

		if ($ids === []) {
			return [];
		}

		$db   = $this->db;
		$rows = $db->setQuery(
			$db->createQuery()
				->select($db->quoteName(['id', 'title', 'position', 'module', 'published', 'client_id']))
				->from($db->quoteName('#__modules'))
				->whereIn($db->quoteName('id'), $ids)
		)->loadAssocList('id') ?: [];

		$out = [];

		foreach ($ids as $id) {
			$row = $rows[$id] ?? null;

			if ($row === null) {
				$out[$id] = ['id' => $id, 'missing' => true, 'note' => 'no such module (deleted?); Page Protector ignores it'];
				continue;
			}

			$out[$id] = [
				'id'        => $id,
				'title'     => (string) $row['title'],
				'position'  => (string) $row['position'],
				'module'    => (string) $row['module'],
				'published' => (int) $row['published'],
				'client_id' => (int) $row['client_id'],
			];
		}

		return $out;
	}

	/**
	 * Site module positions with how many published or unpublished modules
	 * sit in each: the same list the Options picker offers
	 * (Field/ModulepositionsField.php).
	 *
	 * @return array<string,int>
	 */
	protected function ppPositionsInUse(): array
	{
		$db   = $this->db;
		$rows = $db->setQuery(
			$db->createQuery()
				->select([$db->quoteName('position'), 'COUNT(*) AS ' . $db->quoteName('n')])
				->from($db->quoteName('#__modules'))
				->where($db->quoteName('client_id') . ' = 0')
				->where($db->quoteName('published') . ' IN (0, 1)')
				->where($db->quoteName('position') . ' <> ' . $db->quote(''))
				->group($db->quoteName('position'))
		)->loadAssocList('position', 'n') ?: [];

		return array_map('intval', $rows);
	}

	/**
	 * @param int[] $ids
	 * @return array<int,array<string,mixed>>
	 */
	protected function ppUserGroups(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

		if ($ids === []) {
			return [];
		}

		$db   = $this->db;
		$rows = $db->setQuery(
			$db->createQuery()
				->select($db->quoteName(['id', 'title']))
				->from($db->quoteName('#__usergroups'))
				->whereIn($db->quoteName('id'), $ids)
		)->loadAssocList('id', 'title') ?: [];

		$out = [];

		foreach ($ids as $id) {
			$out[$id] = isset($rows[$id])
				? ['id' => $id, 'title' => (string) $rows[$id]]
				: ['id' => $id, 'missing' => true, 'note' => 'no such user group'];
		}

		return $out;
	}

	// -----------------------------------------------------------------------
	// Log presentation
	// -----------------------------------------------------------------------

	protected function ppIpsShortened(Registry $params): bool
	{
		return LogHelper::anonymizeEnabled($params);
	}

	/** Said in every response that carries logged IPs, so they are not read as exact visitor addresses. */
	protected function ppIpNote(Registry $params): string
	{
		if ($this->ppIpsShortened($params)) {
			return 'IP shortening is ON (Page Protector\'s default, for GDPR): the log stores only the network part '
				. 'of each address (IPv4 /24, e.g. 203.0.113.0 for 203.0.113.7; IPv6 /48). These are NOT exact visitor '
				. 'IPs. block_target is the range to use in a firewall rule, the same value the admin\'s copy button gives.';
		}

		return 'IP shortening is OFF: new log rows hold exact addresses. Rows written while shortening was on '
			. 'are still network addresses, so the log can be a mix.';
	}

	/** @return array{ip:string,block_target:string} */
	protected function ppIpShape(string $ip, bool $shortened): array
	{
		return ['ip' => $ip, 'block_target' => DisplayHelper::blockTarget($ip, $shortened)];
	}

	protected function ppLimit(array $arguments, int $default = 50, int $max = 200): int
	{
		return max(1, min($max, (int) ($arguments['limit'] ?? $default)));
	}

	protected function ppOffset(array $arguments): int
	{
		return max(0, (int) ($arguments['offset'] ?? 0));
	}

	/** Positive integer ids from an array argument; refuses anything else rather than dropping it. */
	protected function ppIdList(array $arguments, string $key, bool $required = true): array
	{
		$raw = $arguments[$key] ?? [];

		if (!\is_array($raw)) {
			throw new \InvalidArgumentException($key . ' must be an array of positive integer ids.');
		}

		$ids = [];

		foreach ($raw as $value) {
			if (!is_numeric($value) || (int) $value <= 0 || (string) (int) $value !== trim((string) $value)) {
				throw new \InvalidArgumentException($key . ' must contain only positive integer ids; got ' . json_encode($value) . '.');
			}

			$ids[] = (int) $value;
		}

		$ids = array_values(array_unique($ids));

		if ($required && $ids === []) {
			throw new \InvalidArgumentException($key . ' is required (an array of positive integer ids).');
		}

		return $ids;
	}
}
