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

final class DeleteCookieDeclarationTool extends AbstractTool
{
	use CpnbBootTrait;

	public function getName(): string { return 'delete_cpnb_cookie_declaration'; }

	public function getDescription(): string
	{
		return 'Remove one cookie declaration from Cookies Policy Notification Bar, by exact cookie_name. The '
			. 'cookie itself is unaffected — only the description visitors see for it. Every other declaration is '
			. 'preserved and the result is re-read from the database.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'required'             => ['cookie_name'],
			'properties'           => ['cookie_name' => ['type' => 'string']],
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

		$name    = trim((string) ($arguments['cookie_name'] ?? ''));
		$rows    = $this->cpnbRows($this->cpnbEffective(), 'cookie_descriptions_group');
		$kept    = [];
		$deleted = null;

		foreach ($rows as $row) {
			if ($deleted === null && (string) ($row['cookie_name'] ?? '') === $name) {
				$deleted = $row;
				continue;
			}

			$kept[] = $row;
		}

		if ($deleted === null) {
			throw new \InvalidArgumentException('No cookie declaration named "' . $name . '". list_cpnb_cookie_declarations shows them.');
		}

		$save   = $this->cpnbSave(['cookie_descriptions_group' => $this->cpnbKeyed($kept, 'cookie_descriptions_group')]);
		$stored = $this->cpnbRows($this->cpnbStoredParams(), 'cookie_descriptions_group');

		if (\count($stored) !== \count($kept)) {
			throw new \RuntimeException('The save completed but the stored declaration count is ' . \count($stored) . ', expected ' . \count($kept) . '.');
		}

		return ToolResult::json([
			'ok'        => true,
			'deleted'   => $deleted,
			'remaining' => \count($stored),
			'save'      => $save,
		]);
	}
}
