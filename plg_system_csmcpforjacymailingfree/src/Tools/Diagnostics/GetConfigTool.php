<?php
/**
 * @package     MCP for AcyMailing
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjacymailingfree\Tools\AcymailingBootTrait;
use Joomla\CMS\User\User;

/**
 * Read #__acym_configuration (name/value rows) with credentials withheld.
 */
final class GetConfigTool extends AbstractTool
{
	use AcymailingBootTrait;

	public function getName(): string { return 'get_acym_config'; }

	public function getDescription(): string
	{
		return 'Read AcyMailing\'s configuration (#__acym_configuration, one row per setting). Credentials are '
			. 'never returned: any key that looks like a password, secret, token, API/licence/cron/security key '
			. 'is reported only as {redacted: true, set: bool}. Optional `keys` (exact names) or `search` '
			. '(substring) narrow the output. Each key also says whether set_acym_config may write it. Read '
			. 'directly from the table, not through acym_config(), which memoises in a static and cannot see a '
			. 'write made earlier in the same request.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'keys'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Exact config names to return.'],
				'search' => ['type' => 'string', 'description' => 'Case-insensitive substring filter on the config name.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->acyAdminBase() === null) {
			return $this->notInstalledError();
		}

		$all    = $this->acyConfigAll();
		$keys   = array_map('strval', (array) ($arguments['keys'] ?? []));
		$search = strtolower(trim((string) ($arguments['search'] ?? '')));
		ksort($all, SORT_STRING | SORT_FLAG_CASE);

		$out     = [];
		$missing = [];
		foreach ($keys as $k) {
			if (!array_key_exists($k, $all)) {
				$missing[] = $k;
			}
		}

		foreach ($all as $name => $value) {
			if ($keys !== [] && !\in_array($name, $keys, true)) {
				continue;
			}
			if ($search !== '' && !str_contains(strtolower($name), $search)) {
				continue;
			}

			$secret  = $this->acyIsSecretName($name);
			$blocked = $secret || \in_array($name, self::ACYM_CONFIG_WRITE_BLOCKED, true);

			$out[$name] = [
				'value'    => $secret ? ['redacted' => true, 'set' => $value !== ''] : $value,
				'writable' => !$blocked,
			];
		}

		return ToolResult::json([
			'count'        => count($out),
			'config'       => $out,
			'missing_keys' => $missing,
			'notes'        => [
				'Values are stored as strings. ConfigurationClass::saveConfig() strips HTML tags from every value it writes.',
				'#__acym_configuration.name is the PRIMARY KEY and saves are REPLACE INTO per key - the vendor does NOT truncate the table, unlike Events Booking or Membership Pro.',
			],
		]);
	}
}
