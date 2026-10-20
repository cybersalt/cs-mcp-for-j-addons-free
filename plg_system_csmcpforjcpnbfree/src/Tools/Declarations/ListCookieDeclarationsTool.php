<?php
/**
 * @package     MCP for Cookies Policy Notification Bar
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\Declarations;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjcpnbfree\Tools\CpnbBootTrait;
use Joomla\CMS\User\User;

/**
 * `cookie_descriptions_group`: the per-cookie descriptions and lifetimes CPNB
 * shows in its cookies-information table. CPNB matches them to the cookies a
 * visitor's browser actually holds BY NAME, so a declaration whose name does
 * not exactly match the real cookie is never shown.
 */
final class ListCookieDeclarationsTool extends AbstractTool
{
	use CpnbBootTrait;

	public function getName(): string { return 'list_cpnb_cookie_declarations'; }

	public function getDescription(): string
	{
		return 'List the cookie declarations Cookies Policy Notification Bar shows visitors in its cookies '
			. 'information table and Cookies Manager: cookie name, lifetime (value + unit), description and '
			. 'whether it is enabled. CPNB matches a declaration to the cookies a visitor\'s browser actually holds '
			. 'by exact name, so these are only displayed for cookies that are present. Optional: search (substring '
			. 'of name or description). Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => ['search' => ['type' => 'string']],
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

		$search = mb_strtolower(trim((string) ($arguments['search'] ?? '')));
		$out    = [];

		foreach ($this->cpnbRows($this->cpnbEffective(), 'cookie_descriptions_group') as $i => $row) {
			$item = [
				'position'    => $i + 1,
				'cookie_name' => (string) ($row['cookie_name'] ?? ''),
				'lifetime'    => ($row['cookie_expiration_time_txt'] ?? '') === 'session'
					? 'session'
					: trim(($row['cookie_expiration_time_value'] ?? '') . ' ' . ($row['cookie_expiration_time_txt'] ?? '')),
				'expiration_value' => (string) ($row['cookie_expiration_time_value'] ?? ''),
				'expiration_unit'  => (string) ($row['cookie_expiration_time_txt'] ?? ''),
				'description' => (string) ($row['cookie_description'] ?? ''),
				'enabled'     => (string) ($row['cookie_status'] ?? '1') === '1',
			];

			if ($search !== '' && !str_contains(mb_strtolower($item['cookie_name'] . ' ' . $item['description']), $search)) {
				continue;
			}

			$out[] = $item;
		}

		return ToolResult::json([
			'ok'           => true,
			'source'       => $this->cpnbSource('cookie_descriptions_group'),
			'count'        => \count($out),
			'declarations' => $out,
		]);
	}
}
