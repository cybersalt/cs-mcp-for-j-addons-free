<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjj2commerce\Tools\J2commerceBootTrait;
use Joomla\CMS\User\User;

/**
 * Read the store configuration, grouped the way J2Commerce's Options screen
 * groups it, with secrets withheld.
 */
final class GetConfigTool extends AbstractTool
{
	use J2commerceBootTrait;
	use ConfigSchemaTrait;

	public function getName(): string { return 'get_j2commerce_config'; }

	public function getDescription(): string
	{
		return 'Read the J2Commerce store configuration. J2Commerce keeps ALL of it in one place: the '
			. 'com_j2commerce row of #__extensions.params (there is no configurations table). Values are '
			. 'returned grouped by the fieldsets of the installed config.xml — store, product, '
			. 'productsettings, inventory, tax, discounts, cart, checkoutsettings, checkout, checkout_layout, '
			. 'orders, emails, updates, image_upload — with each setting\'s stored value, its default, and '
			. 'whether it is at the default. Pass fieldset to read one group, or keys for specific settings. '
			. 'Two secrets are withheld and reported only as set / not set: queue_key (the cron endpoint '
			. 'secret and the HMAC key for payment return hashes) and downloadid (the J2Commerce licence). '
			. 'J2Commerce\'s own GET /v1/j2commerce/config REST endpoint returns both in cleartext; this does '
			. 'not. Internal bookkeeping markers (onboarding, ACL seed, setup-dismissed flags) are listed '
			. 'separately because the Options screen hides them. Keys stored in params that the installed '
			. 'config.xml no longer defines are reported as unknown_stored_keys — usually left over from an '
			. 'older version. To change a setting use set_j2commerce_config.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'fieldset' => ['type' => 'string', 'description' => 'One fieldset, e.g. "store", "inventory", "tax", "checkout".'],
				'keys'     => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Specific setting names. Overrides fieldset.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->j2Installed()) {
			return $this->j2NotInstalledError();
		}

		$this->j2RequireAccess($actor, 'j2commerce.viewsetup');

		$schema = $this->configSchema();
		$params = $this->j2RawParams();

		if ($schema === []) {
			return ToolResult::json([
				'ok'    => false,
				'error' => 'administrator/components/com_j2commerce/config.xml could not be read, so settings '
					. 'cannot be described. The component install may be damaged.',
			], true);
		}

		$wantKeys = array_values(array_filter(array_map('strval', (array) ($arguments['keys'] ?? []))));
		$wantSet  = trim((string) ($arguments['fieldset'] ?? ''));

		if ($wantSet !== '') {
			$known = array_values(array_unique(array_column($schema, 'fieldset')));

			if (!\in_array($wantSet, $known, true)) {
				return ToolResult::json([
					'ok'        => false,
					'error'     => 'Unknown fieldset "' . $wantSet . '".',
					'fieldsets' => $known,
				], true);
			}
		}

		$groups      = [];
		$bookkeeping = [];
		$unknownAsked = [];

		foreach ($wantKeys as $key) {
			if (!isset($schema[$key]) && !\array_key_exists($key, $params)) {
				$unknownAsked[] = $key;
			}
		}

		foreach ($schema as $name => $def) {
			if ($wantKeys !== [] && !\in_array($name, $wantKeys, true)) {
				continue;
			}

			if ($wantKeys === [] && $wantSet !== '' && $def['fieldset'] !== $wantSet) {
				continue;
			}

			if (\in_array($def['type'], self::NON_SETTING_TYPES, true)) {
				continue;
			}

			$stored = \array_key_exists($name, $params);
			$value  = $stored ? $params[$name] : null;

			if (\in_array($name, self::J2_SECRET_CONFIG_KEYS, true)) {
				$groups[$def['fieldset']][$name] = [
					'value'  => '[withheld]',
					'is_set' => $stored && (string) (\is_scalar($value) ? $value : json_encode($value)) !== '',
					'type'   => $def['type'],
				];
				continue;
			}

			$entry = [
				'value'      => $value,
				'default'    => $def['default'],
				'is_default' => !$stored || (\is_scalar($value) && $def['default'] !== null && (string) $value === $def['default']),
				'type'       => $def['type'],
			];

			if ($def['options'] !== [] && \in_array($def['type'], self::STATIC_OPTION_TYPES, true)) {
				$entry['options'] = $def['options'];
			}

			if (\in_array($def['type'], self::BOOKKEEPING_TYPES, true)) {
				$bookkeeping[$name] = $value;
				continue;
			}

			$groups[$def['fieldset']][$name] = $entry;
		}

		$unknownStored = [];

		if ($wantKeys === [] && $wantSet === '') {
			foreach ($params as $key => $value) {
				if (!isset($schema[$key])) {
					$unknownStored[$key] = \in_array($key, self::J2_SECRET_CONFIG_KEYS, true) ? '[withheld]' : $value;
				}
			}
		} else {
			foreach ($wantKeys as $key) {
				if (!isset($schema[$key]) && \array_key_exists($key, $params)) {
					$unknownStored[$key] = $params[$key];
				}
			}
		}

		$response = [
			'ok'       => true,
			'source'   => '#__extensions.params for com_j2commerce, read directly (not through ConfigHelper, whose static cache can be stale within a request).',
			'settings' => $groups,
		];

		if ($bookkeeping !== []) {
			$response['bookkeeping'] = $bookkeeping;
			$response['bookkeeping_note'] = 'Hidden markers J2Commerce maintains itself. They are declared in '
				. 'config.xml only so that saving the Options screen does not wipe them. Not settings.';
		}

		if ($unknownStored !== []) {
			$response['unknown_stored_keys'] = $unknownStored;
		}

		if ($unknownAsked !== []) {
			$response['not_found'] = $unknownAsked;
		}

		return ToolResult::json($response);
	}
}
