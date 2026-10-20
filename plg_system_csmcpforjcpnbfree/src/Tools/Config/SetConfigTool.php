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
use Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\CpnbProRequiredException;
use Joomla\CMS\User\User;

/**
 * Targeted settings write through CPNB's own SettingsModel (validation + cache
 * purge), with the injected-params guard and a database read-back.
 */
final class SetConfigTool extends AbstractTool
{
	use CpnbBootTrait;
	use ConfigSectionsTrait;

	public function getName(): string { return 'set_cpnb_config'; }

	public function getDescription(): string
	{
		return 'Change Cookies Policy Notification Bar settings. Pass settings: {key: value} using the keys '
			. 'get_cpnb_config returns, e.g. {"position": "bottom-left", "overlay_state": "1", "backgroundColor": '
			. '"rgba(20,20,20,0.95)", "duration": "0", "hide_after_time": "display_always"}. Positions: top, bottom, '
			. 'center, top-left, top-right, bottom-left, bottom-right. Only the keys you send change. Values are '
			. 'validated by CPNB\'s own rules, the save goes through CPNB\'s own settings model (which purges the '
			. 'page cache), and every key is then re-read from the database. Optional button_order: an array of '
			. 'button ids (ok, decline, cancel, settings, moreinfo) to reorder the bar\'s buttons. Refused here: '
			. 'texts (use set_cpnb_texts), cookie categories and declarations (their own tools), blocking-rule '
			. 'lists (Pro add-on), and on the free build of CPNB any PRO setting (cookie blocking, consent logs, '
			. 'GeoIP). custom_js / custom_css inject code into every page and need allow_custom_code: true. '
			. 'Follow with check_cpnb_frontend to confirm the live page changed.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'settings'          => ['type' => 'object', 'description' => 'Setting key => value. Scalars as strings ("1"/"0" for switches); multi-value settings as arrays.'],
				'button_order'      => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Button ids in display order. Must name every existing button exactly once.'],
				'allow_custom_code' => ['type' => 'boolean', 'description' => 'Required to write custom_js or custom_css, which are output on every front-end page.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->cpnbInstalled()) {
			return $this->cpnbNotInstalledError();
		}

		$this->cpnbRequireAccess($actor);

		$settings = $arguments['settings'] ?? [];

		if (!\is_array($settings)) {
			throw new \InvalidArgumentException('settings must be an object of key => value.');
		}

		$localized = $this->cpnbLocalizedBaseKeys();

		foreach (array_keys($settings) as $key) {
			$key  = (string) $key;
			$base = (string) preg_replace('/_[a-z]{2,3}_[A-Z]{2}$/', '', $key);

			if (\in_array($base, $localized, true)) {
				throw new \InvalidArgumentException('"' . $key . '" is a per-language text. Use set_cpnb_texts, which knows which language the bar will actually show.');
			}

			if ($key === 'cookie_categories_group') {
				throw new \InvalidArgumentException('Cookie categories are managed with create/update/delete_cpnb_category, which edit one row and preserve the rest.');
			}

			if ($key === 'cookie_descriptions_group') {
				throw new \InvalidArgumentException('Cookie declarations are managed with set_cpnb_cookie_declaration / delete_cpnb_cookie_declaration.');
			}

			if ($key === 'buttons_ordering_group') {
				throw new \InvalidArgumentException('Use the button_order argument (a list of button ids) rather than the raw subform.');
			}

			if (\in_array($key, ['block_cookies_group', 'block_scripts_by_url_group', 'block_elements_group'], true)) {
				return $this->cpnbIsPro()
					? ToolResult::json(['ok' => false, 'error' => '"' . $key . '" is a list of cookie-blocking rules. Rules are edited one at a time with the Cybersalt Pro add-on for CPNB (set_cpnb_blocking_rule), which keeps the rest of the list intact.'], true)
					: $this->cpnbProRequiredError('cookie blocking');
			}

			if (\in_array($key, self::CPNB_CODE_KEYS, true) && empty($arguments['allow_custom_code'])) {
				throw new \InvalidArgumentException('"' . $key . '" is output verbatim on every front-end page. Pass allow_custom_code: true to confirm this is intended.');
			}
		}

		if (isset($arguments['button_order'])) {
			$settings['buttons_ordering_group'] = $this->reorderButtons((array) $arguments['button_order']);
		}

		if ($settings === []) {
			throw new \InvalidArgumentException('Pass settings and/or button_order.');
		}

		$before = $this->cpnbEffective();

		try {
			$saved = $this->cpnbSave($settings);
		} catch (CpnbProRequiredException $e) {
			return $this->cpnbProRequiredError($e->capability, ['key' => $e->key]);
		}

		$after = $this->cpnbStoredParams();
		$diff  = [];

		foreach (array_keys($settings) as $key) {
			$diff[$key] = ['before' => $before[$key] ?? null, 'after' => $after[$key] ?? null, 'section' => $this->cpnbSectionOf((string) $key)];
		}

		return ToolResult::json([
			'ok'      => true,
			'changes' => $diff,
			'save'    => $saved,
			'next'    => 'check_cpnb_frontend shows what a new visitor now gets.',
		]);
	}

	/** Re-sequence the stored button rows by id, keeping each row's own name. */
	private function reorderButtons(array $order): array
	{
		$rows = $this->cpnbRows($this->cpnbEffective(), 'buttons_ordering_group');
		$byId = [];

		foreach ($rows as $row) {
			$byId[(string) ($row['button_id'] ?? '')] = $row;
		}

		$order = array_map('strval', $order);

		if (\count($order) !== \count(array_unique($order)) || array_diff($order, array_keys($byId)) !== [] || array_diff(array_keys($byId), $order) !== []) {
			throw new \InvalidArgumentException('button_order must list each existing button id exactly once: ' . implode(', ', array_keys($byId)) . '.');
		}

		return $this->cpnbKeyed(array_map(static fn (string $id): array => $byId[$id], $order), 'buttons_ordering_group');
	}
}
