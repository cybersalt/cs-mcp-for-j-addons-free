<?php
/**
 * @package     MCP for Cookies Policy Notification Bar
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Config;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\CpnbBootTrait;
use Joomla\CMS\User\User;

final class GetConfigTool extends AbstractTool
{
	use CpnbBootTrait;
	use ConfigSectionsTrait;

	public function getName(): string { return 'get_cpnb_config'; }

	public function getDescription(): string
	{
		return 'Read Cookies Policy Notification Bar\'s configuration, grouped the way its dashboard groups it: '
			. 'banner (position — top, bottom, center, top-left, top-right, bottom-left, bottom-right — timers, '
			. 'overlay, body cover), styling (colours, fonts, button classes), consent cookies (names, lifetimes, '
			. 'subdomain sharing), cookies manager (the settings modal and its floating icon), visibility (menu '
			. 'include/exclude), shortcode, custom code, and the PRO sections (cookie blocking switches, consent '
			. 'logging, GeoIP). Each value says whether it is stored or a vendor default. Also returns the button '
			. 'order. Texts are NOT here (they are per language) — use get_cpnb_texts; categories and cookie '
			. 'declarations have their own list tools. Optional: section to return just one, keys to return '
			. 'specific settings. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'section' => ['type' => 'string', 'description' => 'One section name as returned (e.g. "banner", "styling", "consent cookies", "cookies manager", "visibility", "pro: GeoIP targeting").'],
				'keys'    => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Return only these setting keys.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cpnbInstalled()) {
			return $this->cpnbNotInstalledError();
		}

		$this->cpnbRequireAccess($actor);

		$stored    = $this->cpnbStoredParams();
		$effective = $this->cpnbEffective();
		$rules     = $this->cpnbRules();
		$only      = array_map('strval', (array) ($arguments['keys'] ?? []));
		$section   = trim((string) ($arguments['section'] ?? ''));
		$sections  = [];

		foreach ($rules as $key => $rule) {
			$key = (string) $key;

			if (!empty($rule['localized']) || \in_array($key, self::CPNB_SUBFORM_KEYS, true)) {
				continue;
			}

			if ($only !== [] && !\in_array($key, $only, true)) {
				continue;
			}

			$name = $this->cpnbSectionOf($key);

			if ($section !== '' && $name !== $section) {
				continue;
			}

			$entry = [
				'value'  => $effective[$key] ?? null,
				'source' => \array_key_exists($key, $stored) ? 'stored' : (\array_key_exists($key, $effective) ? 'vendor_default' : 'unset'),
				'type'   => $rule['type'] ?? 'text',
			];

			if (isset($rule['options'])) {
				$entry['allowed'] = $rule['options'];
			}

			if (!empty($rule['multiple'])) {
				$entry['multiple'] = true;
			}

			if (isset($rule['min']) || isset($rule['max'])) {
				$entry['range'] = [$rule['min'] ?? null, $rule['max'] ?? null];
			}

			if (isset($rule['format'])) {
				$entry['format'] = $rule['format'];
			}

			$sections[$name][$key] = $entry;
		}

		ksort($sections);

		$buttons = [];

		foreach ($this->cpnbRows($effective, 'buttons_ordering_group') as $row) {
			$buttons[] = ['id' => (string) ($row['button_id'] ?? ''), 'name' => (string) ($row['button_name'] ?? '')];
		}

		$isPro = $this->cpnbIsPro();

		return ToolResult::json([
			'ok'            => true,
			'edition'       => $isPro ? 'pro' : 'free',
			'sections'      => $sections,
			'button_order'  => $buttons,
			'notes'         => [
				'Write with set_cpnb_config (non-text settings) or set_cpnb_texts (texts).',
				$isPro
					? 'This is a CPNB PRO site: the "pro:" sections are live and writable.'
					: 'This is the free build of CPNB: "pro:" settings are shown for completeness but have no effect and cannot be written.',
				'GeoIP lists only apply when enableGeoIP2Webservice is "1", and only the first non-empty list (include_continents, exclude_continents, include_countries, exclude_countries) is evaluated.',
			],
		]);
	}
}
