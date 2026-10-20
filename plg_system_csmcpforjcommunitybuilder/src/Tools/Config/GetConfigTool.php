<?php
/**
 * @package     MCP for Community Builder
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\Config;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcommunitybuilder\Tools\CommunitybuilderBootTrait;
use Joomla\CMS\User\User;

final class GetConfigTool extends AbstractTool
{
	use CommunitybuilderBootTrait;
	use CbConfigSchemaTrait;

	public function getName(): string { return 'get_cb_config'; }

	public function getDescription(): string
	{
		return 'Read Community Builder\'s configuration (Components -> Community Builder -> Configuration), which CB '
			. 'stores as one JSON blob in #__comprofiler_plugin.params (row id 1). Grouped by the tabs of CB\'s own '
			. 'Configuration screen (general, registration, user_profile, images, moderation, connections, '
			. 'integrations); every key shows its stored value, or CB\'s default flagged "default" when the key '
			. 'has never been saved. Pass include_schema for labels, types and allowed values. Read straight '
			. 'from the database, never from CB\'s cached config. installFromWebKey / installFromWebToken (the '
			. 'Joomlapolis membership credentials for Install from web) are redacted. For the registration '
			. 'emails with an audit, use get_cb_registration_config. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'tab'            => ['type' => 'string', 'description' => 'Only this tab: general, registration, user_profile, images, moderation, connections, integrations.'],
				'keys'           => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only these keys.'],
				'include_schema' => ['type' => 'boolean', 'description' => 'Add label, type and allowed values for each key. Default false.'],
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

		$this->cbRequireAccess($actor, 'core.admin', 'com_comprofiler');

		$stored  = $this->cbMaskConfig($this->cbRawConfig());
		$schema  = $this->cbConfigSchema();
		$tab     = trim((string) ($arguments['tab'] ?? ''));
		$keys    = array_values(array_filter(array_map('strval', (array) ($arguments['keys'] ?? []))));
		$details = $this->cbBool($arguments, 'include_schema');

		$out      = [];
		$unknownStored = [];

		foreach ($schema as $key => $entry) {
			if (!$this->cbConfigWritable($entry)) {
				continue;
			}

			if ($tab !== '' && $entry['tab'] !== $tab) {
				continue;
			}

			if ($keys !== [] && !\in_array($key, $keys, true)) {
				continue;
			}

			$isStored = \array_key_exists($key, $stored);
			$item     = ['value' => $isStored ? $stored[$key] : $entry['default']];

			if (!$isStored) {
				$item['source'] = 'default';
			}

			if ($details) {
				$item['label'] = $entry['label'];
				$item['type']  = $entry['type'];

				if ($entry['options'] !== null) {
					$item['allowed'] = $entry['options'];
				}
			}

			$out[$entry['tab'] !== '' ? $entry['tab'] : 'other'][$key] = $details || !$isStored ? $item : $item['value'];
		}

		if ($tab === '' && $keys === []) {
			foreach ($stored as $key => $value) {
				if (!isset($schema[$key])) {
					$unknownStored[$key] = $value;
				}
			}
		}

		$response = [
			'ok'     => true,
			'config' => $out,
			'note'   => 'A plain value is stored; an object with "source": "default" has never been saved and '
				. 'CB uses its form default. set_cb_config writes keys.',
		];

		if ($keys !== []) {
			$missing = array_values(array_diff($keys, array_keys($schema)));

			if ($missing !== []) {
				$response['not_in_schema'] = $missing;
			}
		}

		if ($unknownStored !== []) {
			$response['stored_but_not_on_the_form'] = $unknownStored;
			$response['stored_but_not_on_the_form_note'] = 'Keys in the stored blob that CB\'s Configuration form '
				. 'does not render (CB-internal bookkeeping such as version, or leftovers from an older CB). '
				. 'set_cb_config will not write these.';
		}

		return ToolResult::json($response);
	}
}
