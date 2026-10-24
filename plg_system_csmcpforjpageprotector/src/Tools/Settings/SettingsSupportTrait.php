<?php
/**
 * @package     MCP for Page Protector
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpageprotector\Tools\Settings;

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\CaptchaHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\ProtectionHelper;
use Joomla\Registry\Registry;

/**
 * Validating and saving Page Protector's Options.
 *
 * ---------------------------------------------------------------------------
 * THE SAVE PATH IS JOOMLA'S OWN OPTIONS SAVE
 * ---------------------------------------------------------------------------
 *
 * Every settings write goes through com_config's ComponentModel, the model
 * behind Options -> Save, in the same two steps its controller takes
 * (administrator/components/com_config/src/Controller/ComponentController.php
 * save()): `validate($form, $data)` against the installed config.xml, then
 * `save(['params' => ..., 'id' => ..., 'option' => ...])`. That matters because:
 *
 *   - ComponentModel::save() fires onExtensionAfterSave with context
 *     `com_config.component`, which is the ONLY trigger for Page Protector's
 *     after-save work (plg_system_cspageprotector Cspageprotector.php:336-389):
 *     shortening every IP already in the log when "Shorten IP addresses" is on,
 *     and cleaning the page, com_content, com_modules and mod_* caches so a
 *     page cached before a module was protected stops being served.
 *   - validate() applies config.xml's own filters (intarray, integer,
 *     safehtml, url) and option lists, so a value the admin form could not
 *     produce cannot be stored either.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE FORM SAVE WOULD DO WRONG FROM OUTSIDE THE FORM, AND THE GUARDS
 * ---------------------------------------------------------------------------
 *
 *   - It REPLACES the params column with whatever it is handed; the admin only
 *     survives that because the form posts every field. So the tools read the
 *     stored params from #__extensions, apply the change, and hand over the
 *     full set.
 *   - Form::filter() keeps only keys that have a field in config.xml. A stored
 *     key with no field would be dropped; any such key is put back after
 *     validation and reported, so a save never loses data.
 *   - Its validation cannot know that a menu item, module, position or user
 *     group exists, and Page Protector reads a malformed allow-list entry as
 *     "nobody" without saying so. Those are checked here first, for values the
 *     call introduces (stored values are left alone even if they have since
 *     gone stale, the way the admin form keeps them).
 *   - Unknown keys are refused, not ignored. Hidden keys are refused with the
 *     reason: `pass_salt` belongs to reset_page_protector_passes, and the
 *     "no guarantee" acceptance (`notice_accepted_*`) is a person's decision
 *     the tools do not make for anyone.
 *   - After the save the params are re-read from the database and compared,
 *     key by key, with what was meant to be stored.
 */
trait SettingsSupportTrait
{
	use IpListSupportTrait;

	/** Fields whose text is an address list read by IpHelper::matchesList(). */
	private const PP_IP_LIST_FIELDS = ['exempt_ips', 'trusted_proxies'];

	/** Menu item types the Options picker disables (config.xml menu_items `disable`). */
	private const PP_MENU_TYPES_REFUSED = ['separator', 'alias', 'heading', 'url'];

	/**
	 * Validate a patch of settings against the installed config.xml and the
	 * site. Returns the values in the shape config.xml's filters store.
	 *
	 * @return array{values:array<string,mixed>,warnings:string[],switches_to_all_except:bool}
	 */
	protected function ppValidatePatch(array $patch, Registry $stored): array
	{
		if ($patch === []) {
			throw new \InvalidArgumentException('settings is empty: pass at least one option to change.');
		}

		$fields   = $this->ppConfigFields();
		$errors   = [];
		$warnings = [];
		$values   = [];

		foreach ($patch as $key => $value) {
			$key = (string) $key;

			if ($key === 'rules') {
				$errors[] = 'rules: permissions are not a setting here; use the core set_component_permission tool for com_cspageprotector.';
				continue;
			}

			if (!isset($fields[$key])) {
				$errors[] = $key . ': not a Page Protector option (config.xml has no such field). Known options: '
					. implode(', ', array_keys(array_filter($fields, static fn (array $f): bool => !$f['hidden']))) . '.';
				continue;
			}

			$field = $fields[$key];

			if ($field['hidden']) {
				$errors[] = $key === 'pass_salt'
					? 'pass_salt: never set directly; reset_page_protector_passes rotates it the way the dashboard button does.'
					: $key . ': records who accepted the "no guarantee" notice. That is a person\'s decision, made in the Page '
						. 'Protector admin; these tools do not accept it on anyone\'s behalf.';
				continue;
			}

			// null = back to the default: the key is removed from storage, as on a fresh install.
			if ($value === null) {
				$values[$key] = null;
				continue;
			}

			try {
				$values[$key] = $this->ppNormaliseValue($key, $field, $value, $stored, $warnings);
			} catch (\InvalidArgumentException $e) {
				$errors[] = $key . ': ' . $e->getMessage();
			}
		}

		if ($errors !== []) {
			throw new \InvalidArgumentException('Refused, nothing was written: ' . implode(' | ', $errors));
		}

		$switches = ($values['protection_mode'] ?? null) === ProtectionHelper::MODE_ALL_EXCEPT
			&& (string) $stored->get('protection_mode', ProtectionHelper::MODE_SELECTED) !== ProtectionHelper::MODE_ALL_EXCEPT;

		return ['values' => $values, 'warnings' => $warnings, 'switches_to_all_except' => $switches];
	}

	/**
	 * One value, checked and shaped like config.xml's filter would store it.
	 *
	 * @param array<string,mixed> $field from ppConfigFields()
	 */
	protected function ppNormaliseValue(string $key, array $field, mixed $value, Registry $stored, array &$warnings): mixed
	{
		$type = $field['type'];

		// Address lists: a string (the textarea) or an array of lines.
		if (\in_array($key, self::PP_IP_LIST_FIELDS, true)) {
			if (\is_array($value)) {
				$value = implode("\n", array_map(static fn ($v): string => (string) $v, $value));
			}

			if (!\is_string($value)) {
				throw new \InvalidArgumentException('must be text (one address or range per line) or an array of lines.');
			}

			$problems = $this->ppIpListProblems($value);

			if ($problems !== []) {
				throw new \InvalidArgumentException(implode('; ', $problems));
			}

			return $value;
		}

		switch ($type) {
			case 'list':
			case 'radio':
				if (\is_bool($value)) {
					$value = $value ? '1' : '0';
				}

				if (!\is_scalar($value) || !\in_array((string) $value, $field['options'], true)) {
					throw new \InvalidArgumentException('must be one of ' . implode(', ', $field['options']) . '; got ' . json_encode($value) . '.');
				}

				return $field['filter'] === 'integer' ? (int) $value : (string) $value;

			case 'menuitem':
				$ids = $this->ppNormaliseIds($value);
				$this->ppCheckNewMenuItems(array_diff($ids, array_map('intval', (array) $stored->get($key, []))), $warnings);

				return $ids;

			case 'sitemodules':
				$ids = $this->ppNormaliseIds($value);
				$this->ppCheckNewModules(array_diff($ids, array_map('intval', (array) $stored->get($key, []))));

				return $ids;

			case 'modulepositions':
				$positions = $this->ppNormalisePositions($value);
				$this->ppCheckNewPositions(array_diff($positions, array_map('strval', (array) $stored->get($key, []))));

				return $positions;

			case 'usergrouplist':
				$ids = $this->ppNormaliseIds($value);

				foreach ($this->ppUserGroups(array_diff($ids, array_map('intval', (array) $stored->get($key, [])))) as $group) {
					if (!empty($group['missing'])) {
						throw new \InvalidArgumentException('user group ' . $group['id'] . ' does not exist.');
					}
				}

				return $ids;

			case 'captchaplugin':
				if (!\is_string($value) || preg_match('/^[a-z0-9_]+$/', $value) !== 1) {
					throw new \InvalidArgumentException('must be a captcha plugin element such as "powcaptcha".');
				}

				$installed = [];

				foreach (CaptchaHelper::getInstalledPlugins() as $plugin) {
					$installed[(string) $plugin->element] = (int) $plugin->enabled === 1;
				}

				if (!isset($installed[$value]) && $value !== CaptchaHelper::DEFAULT_PLUGIN) {
					throw new \InvalidArgumentException('"' . $value . '" is not an installed captcha plugin. Installed: '
						. ($installed === [] ? 'none' : implode(', ', array_keys($installed))) . '.');
				}

				if (CaptchaHelper::getProblem($value) !== CaptchaHelper::PROBLEM_NONE) {
					$warnings[] = 'captcha_plugin "' . $value . '" cannot run right now ('
						. CaptchaHelper::getProblem($value) . '). Until it can, protected pages follow captcha_unavailable '
						. '("allow" lets everyone through, "block" answers 403) and protected modules stay locked.';
				}

				return $value;

			case 'number':
				if (!is_numeric($value) || (string) (int) $value !== trim((string) $value)) {
					throw new \InvalidArgumentException('must be a whole number.');
				}

				$value = (int) $value;

				if (($field['min'] !== null && $value < $field['min']) || ($field['max'] !== null && $value > $field['max'])) {
					throw new \InvalidArgumentException('must be between ' . $field['min'] . ' and ' . $field['max'] . '.');
				}

				return $value;

			case 'email':
				if (!\is_string($value) || ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false)) {
					throw new \InvalidArgumentException('must be an email address (or "" for none).');
				}

				return $value;

			case 'url':
				if (!\is_string($value) || ($value !== '' && preg_match('#^https?://[^\s/]+#i', $value) !== 1)) {
					throw new \InvalidArgumentException('must be an http(s) URL (or "" for none).');
				}

				return $value;

			default:
				// text, textarea, and any type a newer config.xml adds: plain text.
				if (!\is_scalar($value) || \is_bool($value)) {
					throw new \InvalidArgumentException('must be text.');
				}

				$value = (string) $value;

				if ($field['maxlength'] !== null && mb_strlen($value) > $field['maxlength']) {
					throw new \InvalidArgumentException('is ' . mb_strlen($value) . ' characters; the Options form allows '
						. $field['maxlength'] . '. Refusing rather than storing something the form could not.');
				}

				return $value;
		}
	}

	/** @return int[] */
	protected function ppNormaliseIds(mixed $value): array
	{
		if (!\is_array($value)) {
			throw new \InvalidArgumentException('must be an array of positive integer ids ([] for none).');
		}

		$ids = [];

		foreach ($value as $v) {
			if (!is_numeric($v) || (int) $v <= 0 || (string) (int) $v !== trim((string) $v)) {
				throw new \InvalidArgumentException('must contain only positive integer ids; got ' . json_encode($v) . '.');
			}

			$ids[] = (int) $v;
		}

		return array_values(array_unique($ids));
	}

	/** @return string[] */
	protected function ppNormalisePositions(mixed $value): array
	{
		if (!\is_array($value)) {
			throw new \InvalidArgumentException('must be an array of module position names ([] for none).');
		}

		$out = [];

		foreach ($value as $v) {
			if (!\is_string($v) || trim($v) === '') {
				throw new \InvalidArgumentException('must contain only non-empty position names; got ' . json_encode($v) . '.');
			}

			$out[] = trim($v);
		}

		return array_values(array_unique($out));
	}

	/**
	 * The menu items the Options picker would offer: site, not trashed, and
	 * not a separator, alias, heading or URL item.
	 *
	 * @param int[] $ids
	 */
	protected function ppCheckNewMenuItems(array $ids, array &$warnings): void
	{
		foreach ($this->ppMenuItems($ids) as $item) {
			if (!empty($item['missing'])) {
				throw new \InvalidArgumentException('menu item ' . $item['id'] . ' does not exist.');
			}

			if ($item['client_id'] !== 0) {
				throw new \InvalidArgumentException('menu item ' . $item['id'] . ' is an administrator menu item; only site menu items can be protected.');
			}

			if ($item['published'] === -2) {
				throw new \InvalidArgumentException('menu item ' . $item['id'] . ' is trashed.');
			}

			if (\in_array($item['type'], self::PP_MENU_TYPES_REFUSED, true)) {
				throw new \InvalidArgumentException('menu item ' . $item['id'] . ' is a ' . $item['type']
					. ' item, which has no page of its own; the Options picker does not offer it.');
			}

			if ($item['home']) {
				$warnings[] = 'menu item ' . $item['id'] . ' ("' . $item['title'] . '") is the home page.';
			}
		}
	}

	/** @param int[] $ids */
	protected function ppCheckNewModules(array $ids): void
	{
		foreach ($this->ppModules($ids) as $module) {
			if (!empty($module['missing'])) {
				throw new \InvalidArgumentException('module ' . $module['id'] . ' does not exist.');
			}

			if ($module['client_id'] !== 0) {
				throw new \InvalidArgumentException('module ' . $module['id'] . ' is an administrator module; only site modules can be protected.');
			}

			if (!\in_array($module['published'], [0, 1], true)) {
				throw new \InvalidArgumentException('module ' . $module['id'] . ' is trashed; the Options picker only offers published and unpublished modules.');
			}
		}
	}

	/** @param string[] $positions */
	protected function ppCheckNewPositions(array $positions): void
	{
		if ($positions === []) {
			return;
		}

		$inUse = $this->ppPositionsInUse();

		foreach ($positions as $position) {
			if (!isset($inUse[$position])) {
				throw new \InvalidArgumentException('position "' . $position . '" has no published or unpublished site module, so the '
					. 'Options picker would not offer it. Positions in use: ' . implode(', ', array_keys($inUse)) . '.');
			}
		}
	}

	/**
	 * Save a validated patch through com_config's ComponentModel (see the class
	 * comment), then verify it from the database.
	 *
	 * @param array<string,mixed> $values from ppValidatePatch()
	 * @return array<string,mixed>
	 */
	protected function ppSaveSettings(array $values, bool $dryRun = false): array
	{
		$state   = $this->ppState();
		$stored  = $this->ppStoredParams()->toArray();
		$merged  = $stored;
		$removed = [];

		foreach ($values as $key => $value) {
			if ($value === null) {
				unset($merged[$key]);

				if (\array_key_exists($key, $stored)) {
					$removed[] = (string) $key;
				}

				continue;
			}

			$merged[$key] = $value;
		}

		/** @var \Joomla\Component\Config\Administrator\Model\ComponentModel $model */
		$model = $this->bootComponent('com_config')->getMVCFactory()
			->createModel('Component', 'Administrator', ['ignore_request' => true]);

		if (!$model) {
			throw new \RuntimeException('Could not load com_config\'s Component model.');
		}

		$model->setState('component.option', self::PP_COMPONENT);

		// load_data false: with it on, the model reads com_config's session user state, which the API app has no use for.
		$form = $model->getForm([], false);

		if (!$form) {
			throw new \RuntimeException('com_config could not load Page Protector\'s config.xml form.');
		}

		$validated = $model->validate($form, $merged);

		if ($validated === false) {
			$messages = [];

			foreach ((array) $model->getErrors() as $error) {
				$messages[] = $error instanceof \Throwable ? $error->getMessage() : (string) $error;
			}

			throw new \InvalidArgumentException('Joomla\'s Options validation refused the settings, nothing was written: ' . implode('; ', $messages));
		}

		// Form::filter() keeps only keys with a field; put any other stored key back.
		$preserved = [];

		foreach (array_diff_key($merged, $validated) as $key => $value) {
			$validated[$key] = $value;
			$preserved[]     = (string) $key;
		}

		// What the form's filters changed in the values this call sent (safehtml can strip markup).
		$filtered = [];

		foreach ($values as $key => $value) {
			if ($value !== null && $this->ppSame($value, $validated[$key] ?? null) === false) {
				$filtered[$key] = ['sent' => $value, 'stored_as' => $validated[$key] ?? null];
			}
		}

		$changes = [];

		foreach ($validated as $key => $value) {
			if (!\array_key_exists($key, $stored) || !$this->ppSame($stored[$key], $value)) {
				$changes[$key] = [
					'before' => $key === 'pass_salt' ? '(hidden)' : ($stored[$key] ?? null),
					'after'  => $key === 'pass_salt' ? '(hidden)' : $value,
				];
			}
		}

		foreach ($removed as $key) {
			$changes[$key] = ['before' => $stored[$key], 'after' => '(removed: the default applies)'];
		}

		$result = [
			'changes'                   => $changes,
			'changed_by_options_filter' => $filtered,
			'preserved_keys_without_field' => $preserved,
		];

		if ($dryRun || $changes === []) {
			return $result + ['saved' => false, 'reason' => $dryRun ? 'dry_run' : 'nothing to change'];
		}

		$model->save([
			'params' => $validated,
			'id'     => $state['extension_id'],
			'option' => self::PP_COMPONENT,
		]);

		// ComponentModel cleans _system in one cache base only; Page Protector's own helper cleans every base (J5 /cache, J6 administrator/cache).
		ProtectionHelper::cleanCacheGroup('_system');

		$after    = $this->ppStoredParams()->toArray();
		$mismatch = [];

		foreach ($validated as $key => $value) {
			if (!\array_key_exists($key, $after) || !$this->ppSame($after[$key], $value)) {
				$mismatch[] = (string) $key;
			}
		}

		foreach (array_keys($stored) as $key) {
			$wanted = !\in_array((string) $key, $removed, true);

			if ($wanted !== \array_key_exists($key, $after)) {
				$mismatch[] = (string) $key . ($wanted ? ' (lost)' : ' (still stored)');
			}
		}

		return $result + [
			'saved'            => true,
			'verified'         => $mismatch === [],
			'verify_mismatch'  => $mismatch,
			'save_path'        => 'com_config ComponentModel::validate() + save(), the Options -> Save path; onExtensionAfterSave fired',
		];
	}

	/** Loose-typed equality for stored params ("1" vs 1, ["5"] vs [5]). */
	protected function ppSame(mixed $a, mixed $b): bool
	{
		$norm = static function (mixed $v) use (&$norm): mixed {
			if (\is_array($v)) {
				return array_map($norm, $v);
			}

			if (\is_object($v)) {
				return $norm((array) $v);
			}

			return \is_bool($v) ? (string) (int) $v : (string) $v;
		};

		return $norm($a) === $norm($b);
	}

	/**
	 * Every option with its current value and, where it names other rows,
	 * those rows resolved. pass_salt is never returned.
	 *
	 * @return array<string,mixed>
	 */
	protected function ppSettingsView(Registry $params, bool $includeSchema): array
	{
		$fields   = $this->ppConfigFields();
		$stored   = $params->toArray();
		$settings = [];
		$schema   = [];

		foreach ($fields as $name => $field) {
			if ($name === 'pass_salt') {
				continue;
			}

			$isStored        = \array_key_exists($name, $stored);
			$settings[$name] = $isStored ? $stored[$name] : ($field['multiple'] ? [] : $field['default']);

			if ($includeSchema && !$field['hidden']) {
				$schema[$name] = array_filter([
					'tab'       => $field['fieldset'],
					'type'      => $field['type'],
					'default'   => $field['default'],
					'options'   => $field['options'] ?: null,
					'multiple'  => $field['multiple'] ?: null,
					'maxlength' => $field['maxlength'],
					'min'       => $field['min'],
					'max'       => $field['max'],
				], static fn ($v): bool => $v !== null);
			}
		}

		$unstored = array_keys(array_diff_key(array_filter($fields, static fn (array $f): bool => !$f['hidden']), $stored));

		$menuIds   = ProtectionHelper::getMenuItemIds($params);
		$moduleIds = ProtectionHelper::getProtectedModuleIds($params);
		$positions = ProtectionHelper::getProtectedPositions($params);
		$inUse     = $this->ppPositionsInUse();
		$mode      = (string) $params->get('protection_mode', ProtectionHelper::MODE_SELECTED);

		$ipLists = [];

		foreach (self::PP_IP_LIST_FIELDS as $listField) {
			$text = (string) $params->get($listField, '');

			$ipLists[$listField] = [
				'entries'  => array_map(
					static fn (array $p): array => array_filter([
						'entry'        => $p['entry'],
						'valid'        => $p['valid'],
						'canonical'    => $p['canonical'] !== $p['entry'] ? $p['canonical'] : null,
						'from_comment' => $p['from_comment'] ?: null,
					], static fn ($v): bool => $v !== null),
					$this->ppIpReadList($text)
				),
				'problems' => $this->ppIpListProblems($text),
			];
		}

		$view = [
			'settings'          => $settings,
			'defaults_in_force' => $unstored,
			'pass_salt_set'     => (string) $params->get('pass_salt', '') !== '',
			'resolved'          => [
				'protection_mode' => $mode === ProtectionHelper::MODE_ALL_EXCEPT
					? 'all_except: EVERY page is protected except the menu_items below (and content inside an excluded category)'
					: 'selected: only the menu_items below are protected'
						. ((int) $params->get('match_content', 1) === 1 ? ', plus the same content reached by another URL or Itemid' : ''),
				'menu_items'      => array_values($this->ppMenuItems($menuIds)),
				'protect_modules' => array_values($this->ppModules($moduleIds)),
				'protect_positions' => array_map(
					static fn (string $p): array => ['position' => $p, 'modules' => $inUse[$p] ?? 0],
					$positions
				),
				'exempt_groups'   => (int) $params->get('exempt_logged_in', 1) === 1
					? 'not used: exempt_logged_in is on, so every logged-in user skips the check'
					: array_values($this->ppUserGroups(array_map('intval', (array) $params->get('exempt_groups', [])))),
				'captcha'         => [
					'plugin'  => CaptchaHelper::getConfiguredPlugin($params),
					'problem' => CaptchaHelper::getProblem(CaptchaHelper::getConfiguredPlugin($params)) ?: null,
				],
				'ip_lists'        => $ipLists,
			],
		];

		if ($includeSchema) {
			$view['schema'] = $schema;
		}

		return $view;
	}
}
