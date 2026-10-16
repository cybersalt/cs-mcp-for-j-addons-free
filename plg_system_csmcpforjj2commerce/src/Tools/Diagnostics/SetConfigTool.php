<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Change named store settings without touching any other.
 *
 * WHY THIS IS NOT A COM_CONFIG SAVE. J2Commerce's Options screen binds the
 * filtered form over the WHOLE params blob — `config.xml:660-662` says so in a
 * comment, which is why the vendor has to declare its bookkeeping markers as
 * hidden fields to survive it. Any key the save does not carry is dropped. A
 * partial save through that path is a reset-the-shop primitive.
 *
 * This follows the vendor's OTHER writer instead, `ComponentParamsHelper`
 * (`src/Helper/ComponentParamsHelper.php:67-148`): re-read the stored blob,
 * change only the named keys, and write back with a compare-and-swap on the
 * exact blob that was read, so a concurrent Options save is never silently
 * overwritten.
 */
final class SetConfigTool extends AbstractTool
{
	use J2commerceBootTrait;
	use ConfigSchemaTrait;

	public function getName(): string { return 'set_j2commerce_config'; }

	public function getDescription(): string
	{
		return 'Change one or more J2Commerce store settings, leaving every other setting exactly as stored. '
			. 'Pass settings as an object of name: value, e.g. {"store_name": "Acme", "enable_inventory": "1"}. '
			. 'Every name is validated against the installed config.xml — an unknown or misspelt name is '
			. 'refused rather than stored, because J2Commerce would ignore it and the setting would silently '
			. 'appear not to work. Values for list and radio fields are validated against the field\'s '
			. 'options. Use get_j2commerce_config to see names, current values and options. '
			. 'WHY THIS EXISTS: J2Commerce\'s Options screen binds the submitted form over the WHOLE params '
			. 'blob (config.xml:660-662), so a partial save drops every setting it does not carry. This tool '
			. 'instead re-reads the stored blob, changes only the named keys, and writes back with a '
			. 'compare-and-swap, the same pattern as the vendor\'s own ComponentParamsHelper. If the blob '
			. 'changed between read and write (someone saved Options at that moment) nothing is written and '
			. 'the call says so. '
			. 'Refused: queue_key (cron secret and payment-hash HMAC key — changing it breaks payments in '
			. 'flight), downloadid (licence), the runtime timestamp cron_last_trigger, '
			. 'attachmentfolderpath (needs J2Commerce\'s file relocation), the hidden bookkeeping markers, '
			. 'and permissions (use core set_component_permission). '
			. 'dry_run: true shows the before/after without writing.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'settings' => [
					'type'                 => 'object',
					'description'          => 'name: value pairs. Values are stored as strings, as the Options screen stores them; multi-select fields take an array.',
					'additionalProperties' => true,
				],
				'dry_run'  => ['type' => 'boolean', 'description' => 'Validate and show the change without writing. Default false.'],
			],
			'required' => ['settings'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->j2Installed()) {
			return $this->j2NotInstalledError();
		}

		$this->j2RequireAccess($actor, 'j2commerce.editsetup');

		if (!$actor->authorise('core.options', 'com_j2commerce') && !$actor->authorise('core.admin', 'com_j2commerce')) {
			throw new \RuntimeException(
				'Changing component options requires core.options or core.admin on com_j2commerce, the same '
				. 'permission the Options screen and J2Commerce\'s own config endpoint require.'
			);
		}

		$settings = $arguments['settings'] ?? null;

		if (!\is_array($settings) || $settings === []) {
			throw new \InvalidArgumentException('settings must be a non-empty object of name: value pairs.');
		}

		$schema   = $this->configSchema();
		$errors   = [];
		$accepted = [];

		foreach ($settings as $name => $value) {
			$name = (string) $name;

			if (isset(self::WRITE_REFUSED_KEYS[$name])) {
				$errors[$name] = 'Refused. ' . self::WRITE_REFUSED_KEYS[$name];
				continue;
			}

			if ($name === 'rules') {
				$errors[$name] = 'Refused. Permissions are an asset, not a setting; use core set_component_permission.';
				continue;
			}

			$def = $schema[$name] ?? null;

			if ($def === null) {
				$errors[$name] = 'Unknown setting: config.xml on this site defines no field named "' . $name . '".'
					. $this->suggest($name, array_keys($schema));
				continue;
			}

			if (!$this->isSettingField($def)) {
				$errors[$name] = 'Refused. "' . $name . '" is a ' . $def['type'] . ' field — '
					. (\in_array($def['type'], self::BOOKKEEPING_TYPES, true)
						? 'a marker J2Commerce maintains itself, not a setting.'
						: 'it stores nothing.');
				continue;
			}

			if (\is_array($value)) {
				if (!$def['multiple']) {
					$errors[$name] = 'This field takes a single value, not a list.';
					continue;
				}

				$value = array_values(array_map(static fn ($v) => (string) $v, $value));
			} elseif (\is_bool($value)) {
				$value = $value ? '1' : '0';
			} elseif ($value === null) {
				$value = '';
			} elseif (\is_scalar($value)) {
				$value = (string) $value;
			} else {
				$errors[$name] = 'Values must be a string, number, boolean or (for multi-select fields) a list.';
				continue;
			}

			// Only list and radio fields have a closed option set. Custom types such
			// as country, zone, currencies or Orderstatus declare a placeholder
			// <option value=""> and fill the real choices at render time.
			if (\in_array($def['type'], self::STATIC_OPTION_TYPES, true) && $def['options'] !== []
				&& !\is_array($value) && !\in_array($value, $def['options'], true)) {
				$errors[$name] = 'Value "' . $value . '" is not one of this field\'s options: '
					. implode(', ', array_map(static fn ($o) => '"' . $o . '"', $def['options'])) . '.';
				continue;
			}

			if (\in_array($def['type'], ['number', 'range'], true) && $value !== '' && !is_numeric($value)) {
				$errors[$name] = 'This is a number field; "' . $value . '" is not numeric.';
				continue;
			}

			$accepted[$name] = $value;
		}

		if ($errors !== []) {
			return ToolResult::json([
				'ok'      => false,
				'error'   => 'Nothing was written: ' . \count($errors) . ' setting(s) were refused. The call is '
					. 'all-or-nothing so a half-applied change cannot leave the shop inconsistent.',
				'refused' => $errors,
				'would_have_accepted' => array_keys($accepted),
			], true);
		}

		// Read the exact stored blob for the compare-and-swap.
		$extensionId = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('extension_id'))
				->from($this->db->quoteName('#__extensions'))
				->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
				->where($this->db->quoteName('element') . ' = ' . $this->db->quote('com_j2commerce'))
		)->loadResult();

		$rawBefore = (string) $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('params'))
				->from($this->db->quoteName('#__extensions'))
				->where($this->db->quoteName('extension_id') . ' = ' . $extensionId)
		)->loadResult();

		$params = json_decode($rawBefore === '' ? '{}' : $rawBefore, true);

		if (!\is_array($params)) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'The stored com_j2commerce params are not valid JSON. Refusing to write over them; '
					. 'nothing was changed. Open and save J2Commerce Options once to repair the blob.',
			], true);
		}

		$changes = [];

		foreach ($accepted as $name => $value) {
			$before = $params[$name] ?? null;

			if ($before === $value || (\is_scalar($before) && !\is_array($value) && (string) $before === $value)) {
				continue;
			}

			$changes[$name] = ['before' => $before, 'after' => $value];
			$params[$name]  = $value;
		}

		if ($changes === []) {
			return ToolResult::json([
				'ok'      => true,
				'changed' => [],
				'note'    => 'Every setting already holds the value supplied. Nothing was written.',
			]);
		}

		if ((bool) ($arguments['dry_run'] ?? false)) {
			return ToolResult::json([
				'ok'      => true,
				'dry_run' => true,
				'changes' => $changes,
				'note'    => 'Nothing was written. Every other setting would be left exactly as stored.',
			]);
		}

		$rawAfter = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		$this->db->setQuery(
			$this->db->getQuery(true)
				->update($this->db->quoteName('#__extensions'))
				->set($this->db->quoteName('params') . ' = ' . $this->db->quote($rawAfter))
				->where($this->db->quoteName('extension_id') . ' = ' . $extensionId)
				->where($this->db->quoteName('params') . ' = ' . $this->db->quote($rawBefore))
		)->execute();

		if ($this->db->getAffectedRows() !== 1) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'The stored configuration changed between reading and writing it — most likely '
					. 'someone saved J2Commerce Options at that moment. Nothing was written. Run the call '
					. 'again to apply it on top of the new configuration.',
			], true);
		}

		// Joomla caches component params in the _system group.
		try {
			Factory::getCache('_system', '')->clean();
		} catch (\Throwable $e) {
			// Best effort; the next request re-reads the row either way.
		}

		$readBack = $this->j2RawParams();
		$verified = [];

		foreach ($changes as $name => $change) {
			$verified[$name] = ($readBack[$name] ?? null) == $change['after'];
		}

		$response = [
			'ok'       => true,
			'changes'  => $changes,
			'verified' => $verified,
			'note'     => 'Only the named settings were changed; every other stored setting was left as it was. '
				. 'Verified by reading the row back directly — J2Commerce\'s ConfigHelper caches params '
				. 'statically, so a read through it in this same request could still show old values.',
		];

		if (isset($changes['enable_inventory'])) {
			$response['inventory_note'] = 'In J2Commerce 6.6.5 this switch does much less than its label '
				. 'suggests. Stock checks and order stock debits/credits ignore it: InventoryHelper::isManagingStock() '
				. 'and ProductHelper::managingStock() both hardcode it on with a "not yet wired" TODO '
				. '(src/Helper/InventoryHelper.php:79-99, ProductHelper.php:2640-2650), so each variant\'s own '
				. 'manage_stock decides. What enable_inventory DOES gate is the automatic cancellation of unpaid '
				. 'orders after hold_stock minutes (src/Model/OrdersModel.php:893-900, '
				. 'plg_system_j2commerce J2Commerce.php:1269) and the inventory fields in two admin variant forms.';
		}

		if (isset($changes['config_currency'])) {
			$response['currency_note'] = 'Changing the base currency does not convert any stored price. '
				. 'Every price in the catalogue is now read as the new currency, and exchange rates are '
				. 'relative to it — run the currency updater, or set the new base to rate 1.';
		}

		return ToolResult::json($response);
	}

	private function suggest(string $name, array $known): string
	{
		$best  = null;
		$score = PHP_INT_MAX;

		foreach ($known as $candidate) {
			$d = levenshtein($name, (string) $candidate);

			if ($d < $score) {
				$score = $d;
				$best  = (string) $candidate;
			}
		}

		return $best !== null && $score <= 4 ? ' Did you mean "' . $best . '"?' : '';
	}
}
