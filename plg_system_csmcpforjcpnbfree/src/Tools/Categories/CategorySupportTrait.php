<?php
/**
 * @package     MCP for Cookies Policy Notification Bar
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Categories;

\defined('_JEXEC') or die;

/**
 * The `cookie_categories_group` subform, translated to and from field names a
 * caller can read.
 *
 * A category's id is the key written into the visitor's consent cookie
 * (`cpnb_cookiesSettings` → `{"v": {"<id>": 0|1}}`) and the key blocking rules
 * point at, so it is immutable here: renaming it would orphan every stored
 * consent and every rule. `cookie_category_checked_by_default` is a tri-state —
 * 2 locked on (the visitor cannot untick it), 1 ticked, 0 unticked.
 */
trait CategorySupportTrait
{
	private const DEFAULT_STATES = ['locked' => '2', 'checked' => '1', 'unchecked' => '0'];

	protected function catFromRow(array $row, array $usage): array
	{
		$id    = (string) ($row['cookie_category_id'] ?? '');
		$state = array_search((string) ($row['cookie_category_checked_by_default'] ?? '1'), self::DEFAULT_STATES, true);

		return [
			'id'            => $id,
			'name'          => (string) ($row['cookie_category_name'] ?? ''),
			'description'   => (string) ($row['cookie_category_description'] ?? ''),
			'default_state' => $state === false ? (string) ($row['cookie_category_checked_by_default'] ?? '') : $state,
			'enabled'       => (string) ($row['cookie_category_status'] ?? '1') === '1',
			'blocking_rules' => $usage[$id] ?? 0,
		];
	}

	/** Apply caller-supplied friendly fields onto a raw row. */
	protected function catApply(array $row, array $args): array
	{
		if (\array_key_exists('name', $args)) {
			$name = trim((string) $args['name']);

			if ($name === '') {
				throw new \InvalidArgumentException('name cannot be empty.');
			}

			$row['cookie_category_name'] = $name;
		}

		if (\array_key_exists('description', $args)) {
			$row['cookie_category_description'] = (string) $args['description'];
		}

		if (\array_key_exists('default_state', $args)) {
			$state = (string) $args['default_state'];

			if (!isset(self::DEFAULT_STATES[$state])) {
				throw new \InvalidArgumentException('default_state must be one of: locked, checked, unchecked.');
			}

			$row['cookie_category_checked_by_default'] = self::DEFAULT_STATES[$state];
		}

		if (\array_key_exists('enabled', $args)) {
			$row['cookie_category_status'] = $args['enabled'] ? '1' : '0';
		}

		return $row;
	}

	/** Blocking rules per category id (all three rule kinds). */
	protected function catUsage(array $settings): array
	{
		$usage = [];

		foreach (['block_cookies_group', 'block_scripts_by_url_group', 'block_elements_group'] as $key) {
			foreach ($this->cpnbRows($settings, $key) as $rule) {
				$cat = (string) ($rule['blockcookiecategory'] ?? '');

				if ($cat !== '') {
					$usage[$cat] = ($usage[$cat] ?? 0) + 1;
				}
			}
		}

		return $usage;
	}

	/** @return array{0: array<int,array>, 1: int|null} rows and the index of $id */
	protected function catFind(array $settings, string $id): array
	{
		$rows = $this->cpnbRows($settings, 'cookie_categories_group');

		foreach ($rows as $i => $row) {
			if ((string) ($row['cookie_category_id'] ?? '') === $id) {
				return [$rows, $i];
			}
		}

		return [$rows, null];
	}

	protected function catSchemaFields(): array
	{
		return [
			'name'          => ['type' => 'string', 'description' => 'Label shown in the Cookies Manager. May be a Joomla language key for multilingual sites (CPNB runs it through Text::_).'],
			'description'   => ['type' => 'string', 'description' => 'Explanation shown under the label. HTML allowed; also passed through Text::_.'],
			'default_state' => ['type' => 'string', 'enum' => ['locked', 'checked', 'unchecked'], 'description' => 'locked = always on, visitor cannot untick (use for strictly necessary cookies); checked = pre-ticked; unchecked = opt-in. Note: pre-ticked non-essential categories are not valid consent under GDPR/ePrivacy.'],
			'enabled'       => ['type' => 'boolean', 'description' => 'Whether the category is offered at all.'],
		];
	}
}
