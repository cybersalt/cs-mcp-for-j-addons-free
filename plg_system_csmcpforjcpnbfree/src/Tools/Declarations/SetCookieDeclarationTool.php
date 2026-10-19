<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Declarations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\CpnbBootTrait;
use Joomla\CMS\User\User;

/**
 * Upsert one cookie declaration by exact cookie name.
 */
final class SetCookieDeclarationTool extends AbstractTool
{
	use CpnbBootTrait;

	private const UNITS = ['minute', 'hour', 'day', 'month', 'year', 'session'];

	public function getName(): string { return 'set_cpnb_cookie_declaration'; }

	public function getDescription(): string
	{
		return 'Add or update one cookie declaration in Cookies Policy Notification Bar (what visitors see about a '
			. 'cookie in the cookies information table), matched by exact cookie_name — e.g. "_ga", "_gid", '
			. '"_fbp". Creating requires description and expiration_unit (minute, hour, day, month, year, '
			. 'session) and, unless the unit is session, expiration_value (1-365). Updating changes only the '
			. 'fields you pass. Optional: enabled, new_name (rename). Every other declaration is preserved; the '
			. 'list is written through CPNB\'s own settings model and re-read to confirm.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'required'             => ['cookie_name'],
			'properties'           => [
				'cookie_name'      => ['type' => 'string', 'description' => 'Exact cookie name as the browser stores it.'],
				'new_name'         => ['type' => 'string'],
				'description'      => ['type' => 'string'],
				'expiration_value' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365],
				'expiration_unit'  => ['type' => 'string', 'enum' => self::UNITS],
				'enabled'          => ['type' => 'boolean'],
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

		$name = trim((string) ($arguments['cookie_name'] ?? ''));

		if ($name === '') {
			throw new \InvalidArgumentException('cookie_name is required.');
		}

		$rows = $this->cpnbRows($this->cpnbEffective(), 'cookie_descriptions_group');
		$at   = null;

		foreach ($rows as $i => $row) {
			if ((string) ($row['cookie_name'] ?? '') === $name) {
				$at = $i;
				break;
			}
		}

		$creating = $at === null;
		$row      = $creating
			? ['cookie_status' => '1', 'cookie_name' => $name, 'cookie_expiration_time_value' => '', 'cookie_expiration_time_txt' => '', 'cookie_description' => '']
			: $rows[$at];
		$before   = $creating ? null : $row;

		if ($creating && (!isset($arguments['description']) || !isset($arguments['expiration_unit']))) {
			throw new \InvalidArgumentException('No declaration named "' . $name . '" exists; creating one needs description and expiration_unit.');
		}

		if (isset($arguments['expiration_unit'])) {
			if (!\in_array($arguments['expiration_unit'], self::UNITS, true)) {
				throw new \InvalidArgumentException('expiration_unit must be one of ' . implode(', ', self::UNITS) . '.');
			}

			$row['cookie_expiration_time_txt'] = (string) $arguments['expiration_unit'];
		}

		if (isset($arguments['expiration_value'])) {
			$row['cookie_expiration_time_value'] = (string) (int) $arguments['expiration_value'];
		}

		if ($row['cookie_expiration_time_txt'] === 'session') {
			// CPNB's rule requires 1..365 even for session cookies; the value is ignored for display.
			$row['cookie_expiration_time_value'] = $row['cookie_expiration_time_value'] !== '' ? $row['cookie_expiration_time_value'] : '1';
		} elseif ($row['cookie_expiration_time_value'] === '') {
			throw new \InvalidArgumentException('expiration_value (1-365) is required unless expiration_unit is session.');
		}

		if (isset($arguments['description'])) {
			$row['cookie_description'] = (string) $arguments['description'];
		}

		if (\array_key_exists('enabled', $arguments)) {
			$row['cookie_status'] = $arguments['enabled'] ? '1' : '0';
		}

		if (isset($arguments['new_name'])) {
			$new = trim((string) $arguments['new_name']);

			foreach ($rows as $i => $r) {
				if ($i !== $at && (string) ($r['cookie_name'] ?? '') === $new) {
					throw new \InvalidArgumentException('A declaration named "' . $new . '" already exists.');
				}
			}

			$row['cookie_name'] = $new;
		}

		if ($creating) {
			$rows[] = $row;
		} else {
			$rows[$at] = $row;
		}

		$save   = $this->cpnbSave(['cookie_descriptions_group' => $this->cpnbKeyed($rows, 'cookie_descriptions_group')]);
		$stored = $this->cpnbRows($this->cpnbStoredParams(), 'cookie_descriptions_group');
		$found  = null;

		foreach ($stored as $r) {
			if ((string) ($r['cookie_name'] ?? '') === $row['cookie_name']) {
				$found = $r;
			}
		}

		if ($found === null || \count($stored) !== \count($rows)) {
			throw new \RuntimeException('The save completed but the stored declaration list does not match what was written.');
		}

		return ToolResult::json([
			'ok'      => true,
			'action'  => $creating ? 'created' : 'updated',
			'before'  => $before,
			'after'   => $found,
			'total'   => \count($stored),
			'save'    => $save,
		]);
	}
}
